"""Operator recovery tests, called only after the isolated runner verifies its DB."""
import base64
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor


def run_emergency_recovery(*, Client, base, db, check, root, ctx, create, grant_for, otp):
    assert db('SELECT DATABASE()') == 'phase1_security_test'
    private = ctx['sessions'].parent / 'emergency-recovery'
    install = private / 'installation'
    api = install / 'php' / 'api'
    api.mkdir(parents=True)
    (install / 'scripts').mkdir()
    for source in (ctx['web'] / 'api').glob('*.php'):
        shutil.copyfile(source, api / source.name)
    tool = install / 'scripts' / 'recover_admin_mfa.php'
    shutil.copyfile(root / 'scripts' / tool.name, tool)
    probe = ctx['web'] / 'api' / 'emergency-web-probe.php'
    shutil.copyfile(tool, probe)
    markers = [api / '.inventory-maintenance', ctx['web'] / 'api' / '.inventory-maintenance']
    original_policy = db('SELECT enforce_mfa FROM auth_policy WHERE id=1')

    def good(response):
        assert response[0] == 200, response[:2]
        return response[1]

    def setup(name, token):
        client = Client(base)
        result = good(client.call('auth.php', 'POST', dict(action='enroll_begin', username=name, password='MfaPassword123', grant=token)))
        client.csrf = result['csrf_token']
        return client, result['secret']

    def begin(name):
        client = Client(base)
        result = good(client.call('auth.php', 'POST', dict(username=name, password='MfaPassword123')))
        client.csrf = result['csrf_token']
        return client

    def enroll(name, token):
        client, secret = setup(name, token)
        codes = good(client.call('auth.php', 'POST', dict(action='verify', code=otp(secret))))['recovery_codes']
        client = begin(name)
        good(client.call('auth.php', 'POST', dict(action='verify', recovery_code=codes[0])))
        return client, secret, codes

    def maintain(enabled):
        for marker in markers:
            if enabled:
                marker.write_text('Disposable emergency recovery test; no normal system maintenance.\n')
            else:
                marker.unlink(missing_ok=True)

    def security_snapshot():
        return db('CHECKSUM TABLE users,user_authenticators,user_recovery_codes,auth_enrollment_grants,auth_challenges,auth_policy,audit_log')

    def command(output=None, username='emergency_admin', execute=True, extras=(), env=None, omit=(), php_options=()):
        args = [str(ctx['php']), '-d', 'session.save_path=' + str(ctx['sessions']), *php_options, str(tool),
                '--username=' + username, '--confirm-database=phase1_security_test']
        if execute:
            args += ['--execute', '--confirm-username=' + username, '--acknowledge-all-admins-locked-out',
                     '--operator=Disposable Recovery Operator', '--reason=All administrators lost their test factors and recovery codes',
                     '--output-dir=' + str(output)]
        args = [arg for arg in args if arg.split('=', 1)[0] not in omit]
        args += list(extras)
        return subprocess.run(args, env=env or ctx['environment'], stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))

    def rejected(label, output, **kwargs):
        before = security_snapshot()
        result = command(output, **kwargs)
        check(label, result.returncode != 0 and security_snapshot() == before and not output.exists())
        return result

    try:
        target = create('emergency_admin', 'super_admin')
        other = create('emergency_other_admin', 'super_admin')
        create('emergency_unenrolled', 'super_admin')
        inactive = create('emergency_inactive', 'super_admin')
        db('UPDATE users SET active=0 WHERE id=' + str(inactive['id']))
        shared = create('emergency_legacy', 'super_admin')
        db("UPDATE users SET account_kind='unclassified' WHERE id=" + str(shared['id']))
        session, old_secret, old_codes = enroll('emergency_admin', grant_for(target['id']))
        other_session, _, _ = enroll('emergency_other_admin', grant_for(other['id']))
        uid = str(target['id'])
        other_before = db('SELECT * FROM users WHERE id=' + str(other['id'])) + db('SELECT * FROM user_authenticators WHERE user_id=' + str(other['id']))
        tables = db('SHOW TABLES').splitlines()
        business = [name for name in tables if name not in {'users', 'user_authenticators', 'user_recovery_codes',
                    'auth_enrollment_grants', 'auth_challenges', 'auth_policy', 'audit_log', 'login_attempts'}]
        business_query = 'CHECKSUM TABLE ' + ','.join('`' + name + '`' for name in business)
        business_before = db(business_query)
        identity_before = db('SELECT id,username,password_hash,name,role,department,active,account_kind FROM users WHERE id=' + uid)
        version_before = int(db('SELECT security_version FROM users WHERE id=' + uid))
        old_pending = begin('emergency_admin')
        replacement = grant_for(target['id'], 'replace')
        old_setup, old_setup_secret = setup('emergency_admin', replacement)

        state = security_snapshot()
        result = command(execute=False)
        check('Emergency read-only check reports target without changing security records', result.returncode == 0
              and json.loads(result.stdout)['mode'] == 'read_only_check' and state == security_snapshot())
        rejected('Emergency execution requires maintenance', private / 'no-maintenance')
        rejected('Emergency rejects database confirmation mismatch', private / 'wrong-database', omit=['--confirm-database'], extras=['--confirm-database=not_this_database'])
        # Duplicate getopt values are arrays and must never become a bypass.
        rejected('Emergency rejects missing or mismatched target confirmation', private / 'wrong-target', omit=['--confirm-username'], extras=['--confirm-username=someone_else'])
        rejected('Emergency requires explicit all-admin lockout acknowledgement', private / 'no-acknowledgement', omit=['--acknowledge-all-admins-locked-out'])
        rejected('Emergency requires an operator identity and incident reason', private / 'no-operator', omit=['--operator'])
        rejected('Emergency rejects output in public PHP root', api / 'public-handover')
        # A junction/symlink ancestor resolves through realpath; an existing
        # directory and its sentinel must also be retained without overwriting.
        existing = private / 'existing-output'
        existing.mkdir(); sentinel = existing / 'sentinel.txt'; sentinel.write_text('preserve')
        result = command(existing)
        check('Emergency never overwrites an existing output directory', result.returncode != 0 and sentinel.read_text() == 'preserve')

        maintain(True)
        rejected('Emergency refuses preparation policy', private / 'preparation-policy')
        db('UPDATE auth_policy SET enforce_mfa=1 WHERE id=1')
        for username in ['no_such_admin', 'mfa_staff', 'emergency_unenrolled', 'emergency_inactive', 'emergency_legacy']:
            rejected('Emergency rejects ineligible target ' + username, private / ('reject-' + username), username=username)
        rejected('Emergency rejects missing encryption configuration', private / 'missing-key', env={**ctx['environment'], 'MFA_ENCRYPTION_KEY': ''})
        rejected('Emergency rejects a different valid-length encryption key', private / 'wrong-key', env={**ctx['environment'], 'MFA_ENCRYPTION_KEY': base64.b64encode(bytes(32)).decode()})
        state = security_snapshot()
        check('Emergency script refuses HTTP execution even during maintenance', Client(base).call(probe.name)[0] == 404 and security_snapshot() == state)
        check('Emergency maintenance still blocks public APIs and query-string spoofing', Client(base).call('config.php?SCIM_EMERGENCY_MFA_OPERATOR=1')[0] == 503)
        program = "define('SCIM_EMERGENCY_MFA_OPERATOR',true);$_SERVER['REQUEST_METHOD']='CLI';require " + repr(str(api / 'config.php').replace('\\', '/')) + ';'
        arbitrary = subprocess.run([str(ctx['php']), '-r', program], env=ctx['environment'], stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        check('Emergency maintenance exception requires direct invocation of the actual tool', b'Inventory maintenance is in progress' in arbitrary.stdout)
        state = security_snapshot()
        dry = command(execute=False)
        check('Emergency readiness works under maintenance without mutations', dry.returncode == 0 and json.loads(dry.stdout)['maintenance_enabled'] and security_snapshot() == state)

        db("DELIMITER //\nCREATE TRIGGER emergency_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.action='mfa.emergency_recovery' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected emergency audit failure'; END IF; END//\nDELIMITER ;")
        try:
            rejected('Emergency audit failure rolls back factor, version and grant changes', private / 'audit-failure')
        finally:
            db('DROP TRIGGER emergency_audit_failure')
        rejected('Emergency private directory creation failure rolls back all recovery changes', private / ('x' * 280))
        # Fail after the private file has actually been created/written. Append
        # to (never replace) the fixture's existing outbound function restrictions.
        ini = Path(ctx['environment']['PHPRC']) / 'php.ini'
        disabled = next(line.split('=', 1)[1] for line in reversed(ini.read_text(encoding='utf-8-sig').splitlines()) if line.startswith('disable_functions='))
        rejected('Emergency private file flush failure rolls back and removes its output', private / 'flush-failure', php_options=['-d', 'disable_functions=' + disabled + ',fflush'])
        output = private / 'successful-handover'
        result = command(output)
        check('Emergency operator commits a private reset handover under enforced policy', result.returncode == 0 and (output / 'handover.json').is_file())
        handover = json.loads((output / 'handover.json').read_text())
        token = handover['grant']
        check('Emergency authorization never appears in console output or audit', token.encode() not in result.stdout + result.stderr
              and token not in db('SELECT after_json FROM audit_log'))
        check('Emergency increments only the target security version and preserves its identity/password', int(db('SELECT security_version FROM users WHERE id=' + uid)) == version_before + 1
              and db('SELECT id,username,password_hash,name,role,department,active,account_kind FROM users WHERE id=' + uid) == identity_before)
        check('Emergency revokes old factor, codes, grants and challenges', db('SELECT COUNT(*) FROM user_authenticators WHERE user_id=' + uid + ' AND revoked_at IS NULL') == '0'
              and db('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id=' + uid + ' AND consumed_at IS NULL AND revoked_at IS NULL') == '0'
              and db('SELECT COUNT(*) FROM auth_challenges WHERE user_id=' + uid + ' AND consumed_at IS NULL') == '0'
              and db('SELECT COUNT(*) FROM auth_enrollment_grants WHERE user_id=' + uid + ' AND consumed_at IS NULL AND revoked_at IS NULL') == '1')
        check('Emergency reset grant is hashed, current-version bound and valid for at most fifteen minutes', db('SELECT COUNT(*) FROM auth_enrollment_grants g JOIN users u ON u.id=g.user_id WHERE g.user_id=' + uid
              + " AND g.purpose='reset' AND g.token_hash='" + hashlib.sha256(token.encode()).hexdigest() + "' AND g.security_version=u.security_version AND g.expires_at>NOW() AND g.expires_at<=NOW()+INTERVAL 15 MINUTE") == '1')
        check('Emergency required audit identifies operator, reason and grant without secrets', db("SELECT COUNT(*) FROM audit_log WHERE action='mfa.emergency_recovery' AND actor_username='operator_emergency' AND actor_name='Disposable Recovery Operator' AND JSON_EXTRACT(after_json,'$.grant_id')=" + str(handover['grant_id'])) == '1')
        check('Emergency leaves policy and maintenance enabled', db('SELECT enforce_mfa FROM auth_policy WHERE id=1') == '1' and all(marker.exists() for marker in markers)
              and session.call('users.php')[0] == 503)
        bootstrap_output = private / 'forbidden-bootstrap.json'
        subprocess.run([str(ctx['php']), str(root / 'scripts' / 'bootstrap_mfa.php'), '--username=emergency_admin',
                        '--operator=Disposable Operator', '--reason=Bootstrap must remain closed', '--output=' + str(bootstrap_output)],
                       env=ctx['environment'], stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                       creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        check('Emergency does not reopen the first-admin bootstrap', not bootstrap_output.exists())
        maintain(False)
        check('Emergency revokes the recovered account session and pending challenges', session.call('users.php')[0] == 401
              and old_pending.call('auth.php', 'POST', dict(action='verify', recovery_code=old_codes[1]))[0] == 401
              and old_setup.call('auth.php', 'POST', dict(action='verify', code=otp(old_setup_secret)))[0] == 401)
        check('Emergency preserves another enrolled administrator and their session', other_session.call('users.php')[0] == 200
              and other_before == db('SELECT * FROM users WHERE id=' + str(other['id'])) + db('SELECT * FROM user_authenticators WHERE user_id=' + str(other['id'])))
        password_only = begin('emergency_admin')
        check('Emergency reset never permits password-only access', password_only.call('users.php')[0] == 401
              and password_only.call('auth.php', 'POST', dict(action='verify', recovery_code=old_codes[1]))[0] == 409)
        check('Emergency old replacement authorization cannot restart enrollment', Client(base).call('auth.php', 'POST', dict(action='enroll_begin', username='emergency_admin', password='MfaPassword123', grant=replacement))[0] == 401)
        check('Emergency handover still requires the existing password', Client(base).call('auth.php', 'POST', dict(action='enroll_begin', username='emergency_admin', password='WrongPassword123', grant=token))[0] == 401)
        db('UPDATE auth_enrollment_grants SET expires_at=NOW()-INTERVAL 1 SECOND WHERE id=' + str(handover['grant_id']))
        check('Emergency expired handover cannot start enrollment', Client(base).call('auth.php', 'POST', dict(action='enroll_begin', username='emergency_admin', password='MfaPassword123', grant=token))[0] == 401)
        maintain(True)
        concurrent_dirs = [private / 'concurrent-a', private / 'concurrent-b']
        version = int(db('SELECT security_version FROM users WHERE id=' + uid))
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(command, concurrent_dirs))
        check('Emergency concurrent operator resets serialize without leaving two usable grants', all(r.returncode == 0 for r in results)
              and int(db('SELECT security_version FROM users WHERE id=' + uid)) == version + 2
              and db('SELECT COUNT(*) FROM auth_enrollment_grants WHERE user_id=' + uid + ' AND consumed_at IS NULL AND revoked_at IS NULL') == '1')
        candidates = [json.loads((directory / 'handover.json').read_text()) for directory in concurrent_dirs]
        current_hash = db('SELECT token_hash FROM auth_enrollment_grants WHERE user_id=' + uid + ' AND consumed_at IS NULL AND revoked_at IS NULL')
        matches = [h for h in candidates if hashlib.sha256(h['grant'].encode()).hexdigest() == current_hash]
        # Preserve the original comparison: a second diagnostic read must never
        # turn a failed result into a passing retry. Only numeric state and
        # lengths/booleans are logged; never print a grant or its stored hash.
        diagnostic = {
            'hash_result_length': len(current_hash),
            'hash_result_rows': len(current_hash.splitlines()),
            'hash_result_is_lower_hex_64': len(current_hash) == 64
                and all(character in '0123456789abcdef' for character in current_hash),
            'candidates': [{'grant_id': int(h['grant_id']), 'grant_length': len(h['grant']),
                            'matches_original_result': h in matches} for h in candidates],
        }
        try:
            state = db('SELECT id,security_version,consumed_at IS NOT NULL,revoked_at IS NOT NULL'
                       ' FROM auth_enrollment_grants WHERE user_id=' + uid + ' ORDER BY id')
            rows = [line.split('\t') for line in state.splitlines()]
            valid = all(len(row) == 4 and all(value.isdigit() for value in row) for row in rows)
            diagnostic['state_rows_valid'] = valid
            diagnostic['state_row_count'] = len(rows)
            if valid:
                diagnostic['grant_state'] = [dict(zip(('id', 'security_version', 'consumed', 'revoked'),
                                                      map(int, row))) for row in rows]
        except Exception as error:
            diagnostic['state_read_error_type'] = type(error).__name__
        print('DIAGNOSTIC Emergency concurrent handover match: ' + json.dumps(diagnostic, sort_keys=True), flush=True)
        check('Emergency concurrent active authorization matches exactly one original handover', len(matches) == 1)
        winning = matches[0]
        superseded = next(h for h in candidates if h is not winning)
        maintain(False)
        db("DELETE FROM login_attempts WHERE username='emergency_admin'")
        check('Emergency superseded handover cannot enroll', Client(base).call('auth.php', 'POST', dict(action='enroll_begin', username='emergency_admin', password='MfaPassword123', grant=superseded['grant']))[0] == 401)
        client, new_secret = setup('emergency_admin', winning['grant'])
        check('Emergency enrollment stays pending without protected API access', client.call('inventory.php')[0] == 401)
        enrolled = good(client.call('auth.php', 'POST', dict(action='verify', code=otp(new_secret))))
        check('Emergency recovery completes standard enrollment with ten new codes and fresh-login requirement', enrolled['requires_login']
              and len(set(enrolled['recovery_codes'])) == 10 and client.call('users.php')[0] == 401)
        check('Emergency consumed handover cannot be reused', Client(base).call('auth.php', 'POST', dict(action='enroll_begin', username='emergency_admin', password='MfaPassword123', grant=winning['grant']))[0] == 401)
        login = begin('emergency_admin')
        fresh = otp(new_secret, int(time.time() // 30) + 1)
        check('Emergency recovered administrator completes a fresh TOTP login', login.call('auth.php', 'POST', dict(action='verify', code=fresh))[0] == 200 and login.call('users.php')[0] == 200)
        replay = begin('emergency_admin')
        check('Emergency recovered authenticator retains replay protection', replay.call('auth.php', 'POST', dict(action='verify', code=fresh))[0] == 401)
        recovery = begin('emergency_admin')
        check('Emergency new recovery code works once', recovery.call('auth.php', 'POST', dict(action='verify', recovery_code=enrolled['recovery_codes'][0]))[0] == 200)
        reuse = begin('emergency_admin')
        check('Emergency new recovery code rejects reuse', reuse.call('auth.php', 'POST', dict(action='verify', recovery_code=enrolled['recovery_codes'][0]))[0] == 401)
        check('Emergency operations preserve all business tables and mandatory MFA', db(business_query) == business_before and db('SELECT enforce_mfa FROM auth_policy WHERE id=1') == '1')
        if ctx.get('preview'):
            # Negative tests intentionally leave four MFA failures. Remove only
            # this synthetic user's attempts after all enforcement checks, never
            # from an application database or from other fixture accounts.
            fixture = ctx['sessions'].parent.resolve()
            assert db('SELECT DATABASE()') == 'phase1_security_test'
            assert Path(db('SELECT @@datadir')).resolve() == (fixture / 'database').resolve()
            assert ctx['web'].resolve() == fixture / 'web'
            assert (ctx['web'] / 'api' / 'phase1-instance.json').is_file()
            before_cleanup = security_snapshot()
            others = db("SELECT * FROM login_attempts WHERE username<>'emergency_admin' ORDER BY id")
            failed = int(db("SELECT COUNT(*) FROM login_attempts WHERE username='emergency_admin' AND stage='mfa' AND success=0"))
            db("DELETE FROM login_attempts WHERE username='emergency_admin'")
            check('Emergency preview clears only its synthetic account attempts after negative tests', failed >= 4
                  and db("SELECT COUNT(*) FROM login_attempts WHERE username='emergency_admin'") == '0'
                  and security_snapshot() == before_cleanup
                  and db("SELECT * FROM login_attempts WHERE username<>'emergency_admin' ORDER BY id") == others)
            check('Emergency preview retains shared IP rate protection with an available budget',
                  int(db("SELECT COUNT(*) FROM login_attempts WHERE ip_address='127.0.0.1' AND stage='mfa' AND success=0 AND created_at>=NOW()-INTERVAL 15 MINUTE")) < 30
                  # Reserve starts for the preflight and the manual checklist;
                  # a budget with only one remaining start is not a usable handoff.
                  and int(db("SELECT COUNT(*) FROM login_attempts WHERE ip_address='127.0.0.1' AND stage='password' AND success=1 AND created_at>=NOW()-INTERVAL 5 MINUTE")) + 9 < 60)
            maintain(True)
            manual = private / 'manual-browser-handover'
            assert command(manual).returncode == 0
            maintain(False)
            handover = json.loads((manual / 'handover.json').read_text())
            # Exercise the exact grant/password path the manual tester will use,
            # then cancel the challenge without consuming the authorization.
            ready, _ = setup('emergency_admin', handover['grant'])
            check('Emergency preview authorization starts enrollment without a rate-limit error or protected access', ready.call('inventory.php')[0] == 401)
            good(ready.call('auth.php', 'POST', dict(action='cancel')))
            check('Emergency preview hands over a fresh unused authorization and zero failed account verifications',
                  len(handover['grant']) == 64
                  and db('SELECT COUNT(*) FROM auth_enrollment_grants WHERE id=' + str(handover['grant_id'])
                         + ' AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>NOW()+INTERVAL 14 MINUTE') == '1'
                  and db("SELECT COUNT(*) FROM login_attempts WHERE username='emergency_admin' AND success=0") == '0')
            print('ISOLATED emergency manual test: ' + base + '/login.html; account emergency_admin / MfaPassword123; private authorization file ' + str(manual / 'handover.json') + ' (15 minutes). Never use these disposable credentials on the normal system.', flush=True)
            print('Open that JSON file and copy its full 64-character grant value into Enrollment authorization. The temporary folder identifier is not the authorization.', flush=True)
    finally:
        maintain(False)
        probe.unlink(missing_ok=True)
        db('UPDATE auth_policy SET enforce_mfa=' + original_policy + ' WHERE id=1')
