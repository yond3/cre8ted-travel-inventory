"""Back up the configured database; rehearse the upgrade ONLY on an isolated copy.

Uses installed PHP/MySQL and Python's standard library. Source connections are
read-only. No credentials appear in arguments, reports or console output.
"""
import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time
import uuid

from phase1_security import available_port, Client, NO_WINDOW

ROOT = Path(__file__).resolve().parents[1]
MANILA = timezone(timedelta(hours=8))


def ident(value):
    return '`' + value.replace('`', '``') + '`'


def literal(value):
    return "CONVERT(0x" + value.encode().hex() + " USING utf8mb4)"


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


class Database:
    def __init__(self, binary, host, port, name, user, password, *, source=False, data_dir=None):
        self.command = [str(binary), '--no-defaults', '--protocol=tcp', '--host=' + host,
                        '--port=' + str(port), '--user=' + user, '--connect-timeout=5',
                        '--default-character-set=utf8mb4', '--batch', '--raw', '--skip-column-names']
        self.environment = os.environ.copy()
        self.environment['MYSQL_PWD'] = password
        self.name, self.source, self.data_dir = name, source, data_dir

    def execute(self, statements, *, write=False, use_database=True, timeout=90):
        if self.source:
            assert not write, 'Source database writes are forbidden'
            assert all(re.match(r'^(SELECT|SHOW)\b', s.strip(), re.I) for s in statements)
        elif write:
            self.verify_target()
        prefix = "SET SESSION time_zone='+00:00';\n"
        if use_database:
            prefix += 'USE ' + ident(self.name) + ';\n'
        if self.source:
            prefix += 'SET SESSION TRANSACTION READ ONLY; START TRANSACTION WITH CONSISTENT SNAPSHOT;\n'
        sql = prefix + ';\n'.join(statements) + ';\n' + ('COMMIT;\n' if self.source else '')
        result = subprocess.run(self.command, input=sql.encode(), stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, env=self.environment, timeout=timeout,
                                creationflags=NO_WINDOW)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors='replace')[-1500:])
        return result.stdout.decode('utf-8').strip()

    def verify_target(self):
        assert not self.source and self.data_dir is not None
        actual = self.execute(['SELECT @@datadir'], use_database=False)
        assert Path(actual).resolve() == self.data_dir.resolve(), 'Refusing to write outside the isolated data directory'

    def columns(self):
        rows = self.execute(["SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA="
            + literal(self.name) + " AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA="
            + literal(self.name) + " AND TABLE_TYPE='BASE TABLE') ORDER BY TABLE_NAME,ORDINAL_POSITION"])
        columns = {}
        for row in rows.splitlines():
            table, column = row.split('\t')
            columns.setdefault(table, []).append(column)
        return columns

    def fingerprint(self, columns):
        # Row hashes preserve nulls, binary data, duplicate rows and every original
        # column. Only hashes leave the mysql client, not private record contents.
        statements = []
        for table, names in columns.items():
            statements.append("SELECT 'TABLE'," + literal(table))
            parts = ["IF(" + ident(c) + " IS NULL,'N',CONCAT('V',HEX(" + ident(c) + ')))' for c in names]
            statements.append("SELECT SHA2(CONCAT_WS('#'," + ','.join(parts) + '),256) AS h FROM ' + ident(table) + ' ORDER BY h')
        output = self.execute(statements, timeout=180)
        result, table, hashes, count = {}, None, None, 0
        for line in output.splitlines() + ['TABLE\tEND']:
            if line.startswith('TABLE\t'):
                if table is not None:
                    result[table] = {'rows': count, 'sha256': hashes.hexdigest()}
                table, hashes, count = line[6:], hashlib.sha256(), 0
            else:
                assert re.fullmatch('[a-f0-9]{64}', line), 'Unexpected fingerprint response'
                hashes.update((line + '\n').encode())
                count += 1
        return result


def receipt_manifest(directory):
    if not directory.is_dir():
        return {}
    return {str(file.relative_to(directory)).replace('\\', '/'): {'bytes': file.stat().st_size, 'sha256': digest(file)}
            for file in sorted(directory.rglob('*')) if file.is_file()}


def check_copied_application(php, isolated, temporary, processes, checks, backup, *, phase3=False, phase4=False, phase5=False, phase6b=False):
    """Only the isolated database receives fixture accounts and workflow writes."""
    isolated.verify_target()
    token = uuid.uuid4().hex
    password = uuid.uuid4().hex
    program = 'echo password_hash(' + json.dumps(password) + ', PASSWORD_BCRYPT);'
    password_hash = subprocess.run([str(php), '-r', program], stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, check=True, creationflags=NO_WINDOW).stdout.decode()
    manager_name, staff_name = 'reh_' + token[:12] + '_m', 'reh_' + token[:12] + '_s'
    isolated.execute(['INSERT INTO users (username,name,password_hash,role,active) VALUES (' +
        ','.join([literal(username), literal(display), literal(password_hash), literal(role), '1']) + ')'
        for username, display, role in [(manager_name, 'Rehearsal Manager', 'manager'),
                                         (staff_name, 'Rehearsal Staff', 'staff')]], write=True)
    web = temporary / 'web'
    (web / 'api').mkdir(parents=True)
    for file in (ROOT / 'php' / 'api').glob('*.php'):
        shutil.copyfile(file, web / 'api' / file.name)
    for file in (ROOT / 'php').glob('*.html'):
        shutil.copyfile(file, web / file.name)
    for file in (ROOT/'php').glob('*.js'):
        shutil.copyfile(file,web/file.name)
    for directory, destination_root in [(ROOT/'vendor',temporary/'vendor'),(ROOT / 'php' / 'assets', web / 'assets'),
                                         (backup / 'receipts', web / 'uploads' / 'receipts')]:
        if directory.is_dir():
            for file in directory.rglob('*'):
                if file.is_file():
                    target = destination_root / file.relative_to(directory)
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(file, target)
    sessions, uploads = temporary / 'sessions', temporary / 'upload-temp'
    sessions.mkdir()
    uploads.mkdir()
    port = available_port()
    base = 'http://127.0.0.1:' + str(port)
    (web / 'api' / 'rehearsal-instance.json').write_text(json.dumps({'instance': token}), encoding='utf-8')
    (web / 'api' / 'rehearsal-db.php').write_text("<?php require __DIR__ . '/config.php'; echo json_encode(['data_dir' => get_pdo()->query('SELECT @@datadir')->fetchColumn()]);", encoding='utf-8')
    environment = os.environ.copy()
    environment.update({'DB_HOST': '127.0.0.1', 'DB_PORT': next(value.split('=', 1)[1] for value in isolated.command if value.startswith('--port=')),
        'DB_NAME': isolated.name, 'DB_USER': 'root', 'DB_PASSWORD': '', 'MYSQL_PWD': '',
        'APP_BASE_URL': base, 'CORS_ALLOWED_ORIGINS': '', 'FINANCE_MODE': 'stub',
        'FINANCE_WEBHOOK_SECRET': token, 'FORECAST_SERVICE_URL': 'http://127.0.0.1:1', 'OCR_SERVICE_URL': 'http://127.0.0.1:1'})
    if phase6b:
        import base64
        environment['MFA_ENCRYPTION_KEY']=base64.b64encode(os.urandom(32)).decode();environment['MFA_SCHEMA_REQUIRED']='1';environment['MFA_ALLOW_LOCAL_HTTP']='1'
    with (temporary / 'php.log').open('w+') as log:
        process = subprocess.Popen([str(php), '-d', 'session.save_path=' + str(sessions), '-d', 'display_errors=0',
            '-d', 'log_errors=1', '-d', 'upload_tmp_dir=' + str(uploads), '-S', '127.0.0.1:' + str(port), '-t', str(web)],
            stdout=log, stderr=subprocess.STDOUT, env=environment, creationflags=NO_WINDOW)
        processes.append(process)
        anonymous = Client(base)
        deadline = time.monotonic() + 12
        while True:
            try:
                assert process.poll() is None
                assert anonymous.call('rehearsal-instance.json')[1] == {'instance': token}
                break
            except Exception:
                if process.poll() is not None or time.monotonic() > deadline:
                    raise RuntimeError('Copied PHP application could not start')
                time.sleep(0.1)
        identity = anonymous.call('rehearsal-db.php')
        assert identity[0] == 200 and Path(identity[1]['data_dir']).resolve() == isolated.data_dir.resolve(), 'Refusing API writes: copied PHP is not using the isolated database'
        manager, staff = Client(base), Client(base)
        manager.login(manager_name, password)
        staff.login(staff_name, password)
        def good(result):
            assert result[0] == 200, 'Copied API rejected an expected action: ' + str(result[0]) + ' ' + str(result[1].get('error', '') if isinstance(result[1], dict) else '')
            return result[1]
        for endpoint in ('inventory.php', 'items.php', 'stock_requests.php', 'stock_issues.php',
                         'purchase_requests.php', 'purchase_orders.php', 'suppliers.php', 'equipment_movements.php'):
            good(staff.call(endpoint))
        for kind in ('inventory', 'stock_issues', 'purchase_orders', 'month_closes', 'equipment_movements'):
            good(manager.call('reports.php?type=' + kind + '&format=html'))
        checks.append('Copied application reads and five report types load existing migrated records through the PHP APIs.')
        if phase5:
            for historical_id in (18,26,27,31):
                historical = good(manager.call('stock_requests.php?id=' + str(historical_id)))
                assert historical['status'] == 'Closed' and historical['legacy_status'] == 'Fulfilled'
                assert historical['history_warning'] is None and all(line['remaining_qty'] == 0 for line in historical['items'])
                assert not historical['can_issue'] and not historical['can_review']
            checks.append('Corrected historical requests load as Closed with preserved legacy status, no unresolved warning, no reservation and no approval/issuance action.')
        references = isolated.execute(["SELECT id,HEX(receipt_filename) FROM purchase_orders WHERE receipt_filename IS NOT NULL AND receipt_filename<>'' ORDER BY id"])
        readable, missing = [], 0
        for row in references.splitlines():
            po_id, encoded = row.split('\t')
            filename = bytes.fromhex(encoded).decode('utf-8')
            if (web / 'uploads' / 'receipts' / filename).is_file():
                readable.append(po_id)
            else:
                missing += 1
        if readable:
            with staff.opener.open(base + '/api/receipts.php?po_id=' + readable[0], timeout=10) as response:
                assert response.status == 200 and len(response.read()) > 0
            checks.append('An existing backed-up receipt is still downloadable through its authenticated API.')
        checks.append('Existing receipt references without a corresponding source backup file: ' + str(missing) + '. No source files were changed.')
        item = good(manager.call('inventory.php', 'POST', {'label': 'Rehearsal fixture ' + token[:10], 'unit': 'pcs',
                                                          'item_type': 'consumable', 'current_qty': 10}))['item_key']
        if phase4:
            taxonomy = good(staff.call('inventory_categories.php'))
            assert len(taxonomy) == 15
            old_item = good(manager.call('inventory.php?item=' + item))
            classified = good(manager.call('inventory.php?item=' + item, 'PUT', {'category_id':6,'category_version':old_item['category_version']}))
            assert classified['category_id'] == 6 and classified['current_qty'] == 10
            assert any(row['item_key'] == item for row in good(staff.call('inventory.php?category=1')))
            checks.append('Category assignment and parent filtering work on native MySQL without changing stock; downstream request and receiving use the same classified item.')
        if phase5:
            request=good(staff.call('stock_requests.php','POST',{'item_key':item,'qty':2,'department':'Tour Operations','purpose':'Isolated native workflow verification'}))
            request=good(manager.call('stock_requests.php?id='+str(request['id']),'PUT',{'action':'approve','request_revision':request['request_revision'],'items':[{'id':request['items'][0]['id'],'approved_qty':2}]}))
            fulfilled=good(staff.call('stock_requests.php?id='+str(request['id']),'PUT',{'action':'issue','request_revision':request['request_revision'],'items':[{'id':request['items'][0]['id'],'qty':2}]}))
            assert good(manager.call('inventory.php?item='+item))['current_qty']==8
            issue=next(x for x in good(staff.call('stock_issues.php?item='+item)) if x['request_item_id']==request['items'][0]['id'])
            good(manager.call('stock_issues.php?id='+str(issue['id']),'PUT',{'action':'void','reason':'Isolated rehearsal reversal','request_revision':fulfilled['request_revision'],'reservation_disposition':'release'}))
            assert good(manager.call('inventory.php?item='+item))['current_qty']==10
        else:
            request = good(staff.call('stock_requests.php', 'POST', {'item_key': item, 'qty': 2, 'department': 'Tour Operations'}))
            fulfilled = good(staff.call('stock_requests.php?id=' + str(request['id']), 'PUT', {'action': 'fulfill'}))
            assert good(manager.call('inventory.php?item=' + item))['current_qty'] == 8
            good(manager.call('stock_issues.php?id=' + str(fulfilled['fulfilled_issue_id']), 'PUT', {'action': 'void', 'reason': 'Isolated rehearsal reversal'}))
            assert good(manager.call('inventory.php?item=' + item))['current_qty'] == 10
        assert staff.call('stock.php?item=' + item, 'POST', {'current': 1})[0] == 403
        checks.append('Test-only request fulfillment, stock deduction, reversal and Staff write restrictions work on the migrated copy.')
        purchase = good(staff.call('purchase_requests.php', 'POST', {'item_key': item, 'qty': 3, 'request_type': 'consumable'}))
        good(manager.call('purchase_requests.php?id=' + str(purchase['id']), 'PUT', {'action': 'approve'}))
        order = good(manager.call('purchase_orders.php', 'POST', {'request_id': purchase['id'], 'amount': 30}))
        good(manager.call('purchase_orders.php?id=' + str(order['id']), 'PUT', {'action': 'declare_receipt_lost',
            'actual_amount': 30, 'note': 'Isolated rehearsal proof; no actual purchase'}))
        good(manager.call('purchase_orders.php?id=' + str(order['id']), 'PUT', {'action': 'receive'}))
        assert manager.call('purchase_orders.php?id=' + str(order['id']), 'PUT', {'action': 'receive'})[0] == 409
        assert good(manager.call('inventory.php?item=' + item))['current_qty'] == 13
        checks.append('Test-only procurement, Finance stub, proof review and full receiving work; repeated receiving is rejected.')
        assert isolated.execute(["SELECT COUNT(*) FROM items i WHERE i.current_qty<>COALESCE((SELECT SUM(quantity_delta) FROM stock_movements sm WHERE sm.item_key=i.item_key AND sm.balance_scope='storage'),0)"]) == '0'
        checks.append('All copied item balances still reconcile to movement history after the test workflows.')
        if phase3:
            month = isolated.execute(["SELECT DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH), '%Y-%m-01')"])
            before_month = isolated.execute(["SELECT current_qty,stock_version FROM items WHERE HEX(item_key)='" + item.encode().hex().upper() + "'"])
            response = good(manager.call('close_month.php?item=' + item, 'POST', {'month':month,
                'opening_qty':10, 'received_qty':0, 'closing_qty':8}))
            assert response['computed_usage'] == 2 and response['stock_changed'] is False
            assert isolated.execute(["SELECT current_qty,stock_version FROM items WHERE HEX(item_key)='" + item.encode().hex().upper() + "'"]) == before_month
            assert manager.call('usage.php?item=' + item, 'POST', {'month':month,'usage':99})[0] == 409
            good(manager.call('close_month.php?item=' + item, 'POST', {'month':month,
                'opening_qty':10,'received_qty':0,'closing_qty':7,'reason':'Isolated historical correction'}))
            assert any(row['month'] == month and row['usage'] == 3 for row in good(staff.call('usage.php?item=' + item)))
            checks.append('Test-only historical close, correction and direct-edit guard work on the actual-data copy without changing current stock or its revision.')
        if phase6b:
            from phase6b_mfa import otp
            mfa_name='reh_mfa_'+token[:10]
            isolated.execute(["INSERT INTO users(username,name,password_hash,role,active,account_kind) VALUES("+','.join([literal(mfa_name),literal('Rehearsal MFA Admin'),literal(password_hash),"'super_admin'",'1',"'named'"])+')'],write=True)
            handover=temporary/'native-bootstrap-private.json'
            result=subprocess.run([str(php),'-d','session.save_path='+str(sessions),str(ROOT/'scripts/bootstrap_mfa.php'),'--username='+mfa_name,'--operator=Native Rehearsal','--reason=Verified disposable native rehearsal','--output='+str(handover)],cwd=ROOT,env=environment,stdout=subprocess.PIPE,stderr=subprocess.PIPE,creationflags=NO_WINDOW)
            assert result.returncode==0 and handover.is_file(),'Native bootstrap failed'
            grant=json.loads(handover.read_text())['grant'];mfa=Client(base)
            started=good(mfa.call('auth.php','POST',{'action':'enroll_begin','username':mfa_name,'password':password,'grant':grant}));mfa.csrf=started['csrf_token']
            enrolled=good(mfa.call('auth.php','POST',{'action':'verify','code':otp(started['secret'])}))
            challenge=good(mfa.call('auth.php','POST',{'username':mfa_name,'password':password}));mfa.csrf=challenge['csrf_token']
            assert challenge['status']=='challenge' and mfa.call('inventory.php')[0]==401
            completed=good(mfa.call('auth.php','POST',{'action':'verify','recovery_code':enrolled['recovery_codes'][0]}));mfa.csrf=completed['csrf_token']
            request=good(mfa.call('stock_requests.php','POST',{'department':'Tour Operations','purpose':'Native MFA connected workflow','items':[{'item_key':item,'requested_qty':1}]}))
            request=good(mfa.call('stock_requests.php?id='+str(request['id']),'PUT',{'action':'approve','request_revision':request['request_revision'],'items':[{'id':request['items'][0]['id'],'approved_qty':1}]}))
            issued=good(mfa.call('stock_requests.php?id='+str(request['id']),'PUT',{'action':'issue','request_revision':request['request_revision'],'items':[{'id':request['items'][0]['id'],'qty':1}]}))
            assert issued['status']=='Fulfilled'
            checks.append('Native MySQL MFA bootstrap/enrollment, password-only API denial, recovery authentication and connected request approval/issuance passed on the restored copy.')



def run(args):
    phase6b=getattr(args,'phase6b',False)
    phase5 = getattr(args, 'phase5', False)
    source_root=Path(getattr(args,'source_root',None) or ROOT).resolve()
    phase4 = getattr(args, 'phase4', False)
    phase3 = getattr(args, 'phase3', False)
    phase = 6 if phase6b else 5 if phase5 else 4 if phase4 else 3 if phase3 else 2
    php, binaries = Path(args.php).resolve(), Path(args.mysql_bin).resolve()
    for binary in [php, *[binaries / name for name in ('mysql.exe', 'mysqldump.exe', 'mysqld.exe')]]:
        assert binary.is_file(), 'An installed PHP/MariaDB binary is unavailable'
    # This requires only the env loader, never config.php/session/auth side effects.
    program = "require 'php/api/env.php'; echo json_encode([env('DB_HOST','127.0.0.1'),env('DB_PORT','3306'),env('DB_NAME','cre8ted_inventory'),env('DB_USER','root'),env('DB_PASSWORD','')]);"
    config = subprocess.run([str(php), '-r', program], cwd=source_root, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, check=True, creationflags=NO_WINDOW)
    host, port, name, user, password = json.loads(config.stdout)
    source = Database(binaries / 'mysql.exe', host, port, name, user, password, source=True)
    source_identity = source.execute(['SELECT @@datadir,@@port,VERSION()'])
    charset, collation = source.execute(['SELECT DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM information_schema.schemata WHERE SCHEMA_NAME=' + literal(name)]).split('\t')
    assert re.fullmatch(r'[A-Za-z0-9_]+', charset) and re.fullmatch(r'[A-Za-z0-9_]+', collation)
    binary_version = subprocess.run([str(binaries / 'mysqld.exe'), '--version'], stdout=subprocess.PIPE,
                                    stderr=subprocess.PIPE, check=True, creationflags=NO_WINDOW).stdout.decode()
    maria = 'MariaDB' in binary_version
    print('Connected to the configured database through a read-only session.', flush=True)
    columns = source.columns()
    assert columns, 'Configured database has no application tables'
    before = source.fingerprint(columns)
    if args.backup_directory:
        backup = Path(args.backup_directory).resolve()
        assert backup.parent == (ROOT / 'backups').resolve(), 'Only an existing project backup may be reused'
        manifest = json.loads((backup / 'manifest.json').read_text(encoding='utf-8'))
        dump = backup / 'database.sql'
        assert manifest['database'] == name and manifest['source_columns'] == columns and manifest['source_tables'] == before, 'Source no longer matches this backup'
        assert digest(dump) == manifest['dump_sha256'], 'Backup SQL checksum mismatch'
        receipt_before = manifest['receipts']
        assert receipt_manifest(backup / 'receipts') == receipt_before, 'Backup receipt checksum mismatch'
        assert receipt_manifest(source_root / 'php/uploads/receipts') == receipt_before, 'Current receipts no longer match this backup'
    else:
        stamp = datetime.now(MANILA).strftime('%Y%m%d_%H%M%S') + '_' + uuid.uuid4().hex[:6]
        backup = ROOT / 'backups' / ('phase' + str(phase) + '_before_' + stamp)
        assert subprocess.run(['git', 'check-ignore', '-q', 'backups/probe.sql'], cwd=ROOT).returncode == 0, 'Backups must be Git-ignored first'
        backup.mkdir(parents=True)
        dump = backup / 'database.sql'
        command = [str(binaries / 'mysqldump.exe'), '--no-defaults', '--protocol=tcp', '--host=' + host,
                   '--port=' + str(port), '--user=' + user, '--single-transaction', '--quick', '--skip-lock-tables',
                   '--routines', '--events', '--triggers', '--hex-blob', '--default-character-set=utf8mb4',
                   '--skip-add-drop-table', '--', name]
        if not maria:
            command[2:2] = ['--set-gtid-purged=OFF', '--column-statistics=0', '--no-tablespaces']
        with dump.open('wb') as output:
            exported = subprocess.run(command, stdout=output, stderr=subprocess.PIPE, env=source.environment,
                                      timeout=180, creationflags=NO_WINDOW)
        assert exported.returncode == 0, exported.stderr.decode(errors='replace')[-1500:]
        assert dump.stat().st_size > 100, 'Backup unexpectedly empty'
        receipts = source_root / 'php' / 'uploads' / 'receipts'
        receipt_before = receipt_manifest(receipts)
        for relative in receipt_before:
            destination = backup / 'receipts' / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(receipts / relative, destination)
        assert receipt_manifest(backup / 'receipts') == receipt_before, 'Receipt backup verification failed'
        source_after = source.fingerprint(columns)
        assert before == source_after and receipt_manifest(receipts) == receipt_before, 'Source activity changed during backup; repeat in a quiet window'
        manifest = {'created_at': datetime.now(MANILA).isoformat(), 'database': name,
                    'server_version': source_identity.split('\t')[-1], 'dump_sha256': digest(dump),
                    'dump_bytes': dump.stat().st_size, 'source_columns': columns, 'source_tables': before,
                    'receipts': receipt_before, 'consistency': 'no source changes observed across backup'}
        (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    print(f'Verified local database backup and {len(receipt_before)} receipt files.', flush=True)

    temporary = Path(args.temp_parent or tempfile.gettempdir()).resolve() / ('inventory-rehearsal-' + uuid.uuid4().hex)
    temporary.mkdir()
    data_dir = temporary / 'database'
    isolated_port = available_port()
    processes, checks, findings = [], [], []
    status = 'INCOMPLETE'
    try:
        backup_sql = dump.read_text(encoding='utf-8')
        assert not re.search(r'\b(?:DATA|INDEX)\s+DIRECTORY\s*=', backup_sql, re.I), 'External table-directory clauses need manual review before restoring'
        assert set(engine.lower() for engine in re.findall(r'ENGINE\s*=\s*([A-Za-z0-9_]+)', backup_sql, re.I)) <= {'innodb'}, 'Non-InnoDB backup engines require review before restoring'
        initialize = ([str(binaries / 'mysql_install_db.exe'), '--datadir=' + str(data_dir), '--port=' + str(isolated_port), '--silent']
            if maria else [str(binaries / 'mysqld.exe'), '--no-defaults', '--initialize-insecure',
                           '--basedir=' + str(binaries.parent), '--datadir=' + str(data_dir), '--innodb-buffer-pool-size=32M'])
        initialized = subprocess.run(initialize, stdout=subprocess.PIPE,
                                     stderr=subprocess.STDOUT, timeout=90, creationflags=NO_WINDOW)
        assert initialized.returncode == 0, 'Isolated MySQL initialization failed: ' + initialized.stdout.decode(errors='replace')[-1500:]
        with (temporary / 'database.log').open('w+') as log:
            process = subprocess.Popen([str(binaries / 'mysqld.exe'), '--no-defaults', '--basedir=' + str(binaries.parent),
                '--datadir=' + str(data_dir), '--port=' + str(isolated_port), '--bind-address=127.0.0.1',
                '--event-scheduler=OFF', '--innodb-buffer-pool-size=32M',
                *(['--skip-name-resolve'] if maria else ['--mysqlx=OFF'])],
                stdout=log, stderr=subprocess.STDOUT, creationflags=NO_WINDOW)
            processes.append(process)
            isolated = Database(binaries / 'mysql.exe', '127.0.0.1', isolated_port, name, 'root', '', data_dir=data_dir)
            deadline = time.monotonic() + 25
            while True:
                try:
                    isolated.verify_target()
                    break
                except Exception as startup_error:
                    if process.poll() is not None or time.monotonic() > deadline:
                        raise RuntimeError('Isolated connection verification failed: ' + str(startup_error))
                    time.sleep(0.1)
            assert isolated_port != int(port) or host not in ('127.0.0.1', 'localhost'), 'Source and isolated endpoints must differ'
            isolated.execute(['CREATE DATABASE ' + ident(name) + ' CHARACTER SET ' + charset + ' COLLATE ' + collation], write=True, use_database=False)
            isolated.verify_target()
            with dump.open('rb') as incoming:
                restored = subprocess.run(isolated.command + [name], stdin=incoming, stdout=subprocess.PIPE,
                                          stderr=subprocess.PIPE, env=isolated.environment, timeout=180, creationflags=NO_WINDOW)
            assert restored.returncode == 0, 'Restore failed: ' + restored.stderr.decode(errors='replace')[-1500:]
            restored_fingerprints=isolated.fingerprint(columns)
            if phase6b and restored_fingerprints!=before:
                from json_restore_evidence import verify_audit_json
                verify_audit_json(source,isolated,columns,checks)
                checks.append('Restore verified with explicit JSON numeric-rendering equivalence; no business values differ.')
            else:
                assert restored_fingerprints == before, 'Restored backup differs in tables: '+','.join(t for t in before if restored_fingerprints.get(t)!=before[t])
                checks.append('Restore verified: every original row and column matches the source snapshot by SHA-256.')
            print('Restored backup verified against every original table.', flush=True)

            engines = isolated.execute(["SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=" + literal(name) + " AND TABLE_TYPE='BASE TABLE'"])
            non_innodb = [row.split('\t')[0] for row in engines.splitlines() if row.split('\t')[1] != 'InnoDB']
            if non_innodb:
                findings.append('Non-InnoDB tables prevent a consistent transactional rollout: ' + ', '.join(non_innodb))
            expected = {'items', 'equipment_deployments', 'stock_issues', 'stock_requests', 'purchase_requests',
                        'purchase_orders', 'equipment_movements', 'inventory_retirements', 'documents', 'users'}
            missing = expected - columns.keys()
            if missing:
                findings.append('Prerequisite tables missing: ' + ', '.join(sorted(missing)))
            if not missing:
                invalid = isolated.execute(["SELECT COUNT(*) FROM items WHERE current_qty<0 OR (item_type='equipment' AND MOD(current_qty,1)<>0)",
                    'SELECT COUNT(*) FROM equipment_deployments WHERE qty<0 OR MOD(qty,1)<>0'])
                if any(int(count) for count in invalid.splitlines()):
                    findings.append('Invalid existing storage or department quantities need review before rollout.')
            phase2_tables = {'stock_movements', 'inventory_operations', 'code_counters'}
            already_present = phase2_tables & columns.keys()
            has_version = 'stock_version' in columns.get('items', [])
            if not phase3 and not phase4 and not phase5 and not phase6b and (already_present or has_version):
                findings.append('Phase 2 structures already exist wholly or partially; this is not a clean pre-upgrade database. Review before applying again.')
            if phase6b:
                if not {'stock_request_items','inventory_flow_lock','inventory_categories'} <= columns.keys():findings.append('Phase 5 is required first.')
                if 'security_version' in columns.get('users',[]) or 'auth_policy' in columns:findings.append('Phase 6 already or partially installed; inspect instead of reapplying.')
            elif phase5:
                if 'inventory_categories' not in columns or not {'category_id','category_version'} <= set(columns.get('items',[])):
                    findings.append('Phase 4 is required first.')
                if 'stock_request_items' in columns or 'protected_qty' in columns.get('items',[]):
                    findings.append('Phase 5 already exists wholly or partially.')
            if phase4:
                if not (phase2_tables <= columns.keys() and has_version and {"usage_version","usage_source"} <= set(columns.get("usage_log", []))):
                    findings.append("Phases 2 and 3 are required first.")
                if "inventory_categories" in columns or {"category_id","category_version"} & set(columns.get("items", [])):
                    findings.append("Phase 4 exists wholly or partially; do not reapply.")
            if phase3:
                if not (phase2_tables <= columns.keys() and has_version):
                    findings.append('Phase 2 stock integrity upgrade is required first.')
                if not {'usage_log','month_closes','audit_log'} <= columns.keys():
                    findings.append('Monthly usage/audit prerequisite tables are missing.')
                if {'usage_version','usage_source'} & set(columns.get('usage_log', [])):
                    findings.append('Phase 3 upgrade already exists wholly or partially; do not reapply.')
            assert not findings, '; '.join(findings)
            checks.append('Migration preflight passed: prerequisites, InnoDB engines, quantities and unapplied schema.')

            migration = (ROOT / 'sql' / ('migration_stock_request_items.sql' if phase5 else 'migration_inventory_categories.sql' if phase4 else 'migration_usage_consistency.sql' if phase3 else 'migration_stock_integrity.sql')).read_text(encoding='utf-8')
            if phase6b:
                migration=(ROOT/'sql/migration_account_security.sql').read_text(encoding='utf-8')+'\n'+(ROOT/'sql/migration_authenticator_mfa.sql').read_text(encoding='utf-8')
            if phase5:
                import sys
                sys.path.insert(0, str(ROOT / 'sql'))
                from phase5_rollout import snapshot, verify_corrected
                operator_before = snapshot(isolated)
            isolated.execute([migration], write=True)
            assert isolated.fingerprint(columns) == restored_fingerprints, 'Migration changed original restored records'
            checks.append('Migration preserved every original record, not only row counts or stock totals.')
            if phase6b:
                assert isolated.execute(["SELECT COUNT(*) FROM users WHERE security_version<>0 OR account_kind<>'unclassified' OR mfa_required<>0"])=='0'
                assert isolated.execute(['SELECT COUNT(*) FROM user_authenticators'])=='0'
                checks.append('Phase 6A/6B additive migration preserves all original user credentials, roles, history and stock; no account is automatically enrolled/classified and no global MFA enforcement is enabled.')
            elif phase5:
                assert isolated.execute(['SELECT COUNT(*) FROM stock_requests']) == isolated.execute(['SELECT COUNT(*) FROM stock_request_items'])
                assert isolated.execute(['SELECT COUNT(*) FROM items WHERE protected_qty IS NOT NULL']) == '0'
                checks.append('Every existing request has one authoritative child line; no protected-stock policy or historical manager approval was invented.')
                from phase5_historical_correction import check_correction
                check_correction(isolated, checks)
                verify_corrected(isolated, operator_before)
                from phase5_operator import check_publication
                check_publication(ROOT, temporary, checks)
                checks.append('Production operator preservation/linkage/stock verifier passed against the corrected native restore.')
            elif phase4:
                assert isolated.execute(['SELECT COUNT(*) FROM items WHERE category_id IS NOT NULL OR category_version<>0']) == '0'
                assert isolated.columns()['items'] == columns['items'] + ['category_id','category_version']
                assert set(isolated.columns()) == set(columns) | {'inventory_categories'}
                assert isolated.execute(['SELECT COUNT(*) FROM inventory_categories']) == '15'
                checks.append('All original items remain uncategorized at category revision zero; 15 initial categories exist.')
            elif phase3:
                assert isolated.execute(["SELECT COUNT(*) FROM usage_log WHERE usage_version<>0 OR usage_source<>'legacy'"]) == '0'
                after_columns = isolated.columns()
                assert after_columns['usage_log'] == columns['usage_log'] + ['usage_version','usage_source']
                assert set(after_columns) == set(columns)
                conflict_count = isolated.execute(["SELECT COUNT(*) FROM month_closes mc LEFT JOIN usage_log u ON u.item_key=mc.item_key AND u.month=mc.month WHERE u.item_key IS NULL OR u.usage_qty<>mc.usage_qty"])
                adjacent_count = isolated.execute(["SELECT COUNT(*) FROM month_closes mc JOIN month_closes prev ON prev.item_key=mc.item_key AND prev.month=DATE_SUB(mc.month,INTERVAL 1 MONTH) WHERE mc.opening_qty<>prev.closing_qty"])
                checks.append('Existing usage keeps legacy provenance and initial revision zero; no stock baseline is recreated.')
                checks.append('Read-only historical review: ' + conflict_count + ' close/usage disagreements and ' + adjacent_count + ' adjacent-month inconsistencies. No automatic corrections were made.')
            else:
                mismatches = isolated.execute(["SELECT COUNT(*) FROM items i LEFT JOIN stock_movements sm ON sm.item_key=i.item_key AND sm.balance_scope='storage' AND sm.movement_type='opening_balance' WHERE sm.id IS NULL OR sm.quantity_after<>i.current_qty OR sm.quantity_delta<>i.current_qty",
                    "SELECT COUNT(*) FROM equipment_deployments ed LEFT JOIN stock_movements sm ON sm.item_key=ed.item_key AND sm.department=ed.department AND sm.balance_scope='department' AND sm.movement_type='opening_balance' WHERE sm.id IS NULL OR sm.quantity_after<>ed.qty OR sm.quantity_delta<>ed.qty",
                    'SELECT COUNT(*) FROM items WHERE stock_version<>0'])
                assert all(int(value) == 0 for value in mismatches.splitlines()), 'Opening balance/revision verification failed'
                checks.append('Every cutover storage/deployment baseline and initial stock revision matches existing data.')
            check_copied_application(php, isolated, temporary, processes, checks, backup, phase3=(phase3 or phase4 or phase5 or phase6b), phase4=phase4, phase5=(phase5 or phase6b),phase6b=phase6b)
            assert source.fingerprint(columns) == before, 'Application data changed during rehearsal; compare before rollout'
            assert source.columns() == columns, 'Application schema changed during rehearsal'
            assert source.execute(['SELECT @@datadir,@@port,VERSION()']) == source_identity
            checks.append('Application database records, schema and server identity remain unchanged after rehearsal.')
            status = 'PASSED'
    except Exception as error:
        import traceback
        findings.append(str(error) or repr(error))
        traceback.print_exc()
        print('Rehearsal stopped safely; application database was not migrated.', flush=True)
    finally:
        for process in reversed(processes):
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
        report = ['# Phase ' + str(phase) + ' migration rehearsal', '', 'Status: **' + status + '**', '',
                  'Application database was not migrated. No commit or push was performed.', '',
                  '## Verified local backup', '', '- Backup directory: `' + str(backup.relative_to(ROOT)).replace('\\', '/') + '`',
                  '- Database SQL: `' + str(dump.relative_to(ROOT)).replace('\\', '/') + '`',
                  '- SQL SHA-256: `' + manifest['dump_sha256'] + '`',
                  '- Database tables backed up: ' + str(len(before)),
                  '- Original records: ' + str(sum(t['rows'] for t in before.values())),
                  '- Receipt files backed up: ' + str(len(receipt_before)),
                  '- Backup manifest includes per-table record fingerprints and per-file checksums.',
                  '- `backups/` is Git-ignored; the SQL contains private application data and password hashes.', '',
                  '## Rehearsal checks', '', *['- ' + check for check in checks], '',
                  '## Findings / limits', '', *['- ' + finding for finding in findings],
                  '- This rehearsal uses a separate local MySQL process/data directory with its event scheduler disabled.',
                  '- Runtime checks create disposable accounts and fixture records only inside that isolated copy. Existing application accounts/passwords are unchanged.',
                  '- Finance is stubbed, and copied forecast/OCR URLs do not target live services.',
                  '- Comparing backup-time and post-rehearsal fingerprints detected no source changes only when the corresponding checks passed.',
                  '- A normal application-database migration still needs an approved maintenance window and matching code/frontend deployment.', '',
                  '## Existing record inventory', '', '| Table | Original rows |', '|---|---:|',
                  *['| `' + table + '` | ' + str(info['rows']) + ' |' for table, info in before.items()], '']
        path = ROOT / ('PHASE6B_MIGRATION_REHEARSAL.md' if phase6b else 'PHASE' + str(phase) + '_MIGRATION_REHEARSAL.md')
        path.write_text('\n'.join(report), encoding='utf-8')
        (backup / 'rehearsal_result.json').write_text(json.dumps({'phase': phase, 'migration_sha256': hashlib.sha256(((ROOT/'sql/migration_account_security.sql').read_bytes()+(ROOT/'sql/migration_authenticator_mfa.sql').read_bytes())).hexdigest() if phase6b else digest(ROOT / 'sql' / ('migration_stock_request_items.sql' if phase5 else 'migration_inventory_categories.sql' if phase4 else 'migration_usage_consistency.sql' if phase3 else 'migration_stock_integrity.sql')), 'status': status, 'checks': checks, 'findings': findings}, indent=2), encoding='utf-8')
        if phase5:
            result_path = backup / 'rehearsal_result.json'
            result = json.loads(result_path.read_text(encoding='utf-8'))
            result.update(historical_correction_sha256=digest(ROOT / 'sql/migration_close_reversed_requests.sql'),
                          operator_rehearsal_sha256=digest(ROOT / 'sql/phase5_rollout.py'))
            result_path.write_text(json.dumps(result,indent=2),encoding='utf-8')
        # Keep isolated diagnostics for inspection; no broad cleanup or application-file deletion.
        print('Backup: ' + str(backup), flush=True)
        print('Report: ' + str(path), flush=True)
        print('Rehearsal result: ' + status, flush=True)
    return 0 if status == 'PASSED' else 1


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='C:/xampp/php/php.exe')
    parser.add_argument('--mysql-bin', default=str(Path(shutil.which('mysql.exe')).parent) if shutil.which('mysql.exe') else 'C:/xampp/mysql/bin')
    parser.add_argument('--phase6b',action='store_true')
    parser.add_argument('--phase5',action='store_true')
    parser.add_argument('--source-root',help='Explicit read-only source configuration/receipt root; implementation remains in this project')
    parser.add_argument('--phase4', action='store_true', help='Rehearse categories on a Phase 3 database copy')
    parser.add_argument('--phase3', action='store_true', help='Rehearse the Phase 3 monthly upgrade on an already Phase 2 database copy')
    parser.add_argument('--backup-directory', help='Reuse an existing verified project backup; source must still match')
    parser.add_argument('--temp-parent', default='C:/Users/Dion/AppData/Local/Temp')
    raise SystemExit(run(parser.parse_args()))
