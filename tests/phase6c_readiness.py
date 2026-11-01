"""Readiness changes tested only inside the existing disposable runner."""
import hashlib,json,re,subprocess


def before_bootstrap(*,Client,base,db,check,root,ctx,admin,uid):
    def good(r):
        assert r[0]==200,r[:2]
        return r[1]
    for kind in ['shared','unclassified']:
        check('Phase6C new '+kind+' account rejected',admin.call('users.php','POST',dict(username='reject_'+kind,password='NamedPassword123',name='Named Test',role='staff',account_kind=kind))[0]==400)
        check('Phase6C account cannot be edited into '+kind,admin.call('users.php?id='+str(uid),'PUT',dict(account_kind=kind))[0]==400)
    legacy=good(admin.call('users.php','POST',dict(username='legacy_readiness',password='NamedPassword123',name='Legacy Test',role='department',department='Tour Operations')))
    lid=legacy['id'];db("UPDATE users SET account_kind='shared' WHERE id="+str(lid))
    before=db('SELECT username FROM users WHERE id='+str(lid))
    check('Phase6C legacy metadata remains readable',next(u for u in good(admin.call('users.php')) if u['id']==lid)['account_kind']=='shared')
    check('Phase6C legacy deactivation requires no identity rewrite',admin.call('users.php?id='+str(lid),'PUT',{'active':False})[0]==200 and db('SELECT username FROM users WHERE id='+str(lid))==before)
    check('Phase6C legacy shared reactivation blocked',admin.call('users.php?id='+str(lid),'PUT',{'active':True})[0]==409)
    check('Phase6C confirmed individual can reactivate without changing identity',admin.call('users.php?id='+str(lid),'PUT',{'account_kind':'named','active':True,'name':'Individual Tester'})[0]==200)
    person=Client(base);person.login('legacy_readiness','NamedPassword123')
    check('Phase6C individual Department role keeps scope',person.call('stock_requests.php')[0]==200 and person.call('inventory.php')[0]==403)
    # Retiring a legacy identity must not rewrite old request/ledger/audit records.
    exported=ctx['sessions'].parent/'offline-first-admin'
    env={**ctx['environment'],'DB_HOST':'invalid.example.test','DB_PORT':'1'}
    command=[str(ctx['php']),str(root/'scripts/export_first_admin.php'),'--username=mfa_admin','--operator=Disposable Operator','--reason=Verified offline operator import','--output-dir='+str(exported)]
    run=subprocess.run(command,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert run.returncode==0,run.stderr.decode(errors='replace')
    sql=(exported/'bootstrap.sql').read_text(encoding='utf-8');handover=json.loads((exported/'handover.json').read_text(encoding='utf-8'));token=handover['grant'];digest=hashlib.sha256(token.encode()).hexdigest()
    check('Phase6C offline export requires no database and hides raw grant from SQL/stdout',len(token)==64 and token not in sql and token.encode() not in run.stdout and digest.encode().hex() in sql)
    repeat=subprocess.run(command,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check('Phase6C exporter refuses to overwrite private handover',repeat.returncode!=0 and json.loads((exported/'handover.json').read_text())['grant']==token)
    unsafe=subprocess.run(command[:-1]+['--output-dir='+str(root/'php'/'forbidden-export')],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check('Phase6C exporter rejects public-root output',unsafe.returncode!=0 and not (root/'php'/'forbidden-export').exists())
    routine=re.search(r'CREATE PROCEDURE `(phase6_first_admin_[0-9a-f]+)`',sql)[1]
    def reject(statement):
        failed=False
        try:db(statement)
        except Exception:failed=True
        db('DROP PROCEDURE IF EXISTS `'+routine+'`')
        return failed
    db('UPDATE auth_policy SET enforce_mfa=1 WHERE id=1')
    check('Phase6C SQL import refuses enforced policy',reject(sql) and int(db('SELECT COUNT(*) FROM auth_enrollment_grants'))==0)
    db('UPDATE auth_policy SET enforce_mfa=0 WHERE id=1')
    db("CREATE TRIGGER phase6c_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected readiness audit failure'")
    rejected=reject(sql);db('DROP TRIGGER phase6c_audit_failure')
    check('Phase6C SQL audit failure rolls back imported grant',rejected and int(db('SELECT COUNT(*) FROM auth_enrollment_grants'))==0)
    db(sql)
    check('Phase6C guarded import writes one existing-table grant and required audit',int(db('SELECT COUNT(*) FROM auth_enrollment_grants WHERE user_id='+str(uid)))==1 and int(db("SELECT COUNT(*) FROM audit_log WHERE action='mfa.grant' AND actor_username='operator_bootstrap'"))==1)
    check('Phase6C import binds current identity version and expiry',int(db('SELECT COUNT(*) FROM auth_enrollment_grants g JOIN users u ON u.id=g.user_id WHERE g.user_id='+str(uid)+" AND g.security_version=u.security_version AND g.expires_at>NOW() AND g.expires_at<=NOW()+INTERVAL 15 MINUTE"))==1)
    check('Phase6C importing same handover twice fails without duplication',reject(sql) and int(db('SELECT COUNT(*) FROM auth_enrollment_grants WHERE user_id='+str(uid)))==1)
    c=Client(base);start=good(c.call('auth.php','POST',dict(action='enroll_begin',username='mfa_admin',password='MfaPassword123',grant=token)));c.csrf=start['csrf_token']
    check('Phase6C imported grant starts existing enrollment without inventory access',start['status']=='challenge' and c.call('inventory.php')[0]==401)
    good(c.call('auth.php','POST',{'action':'cancel'}))
    return sql, routine


def after_bootstrap(*,db,check,sql,routine,uid):
    before=db('SELECT COUNT(*) FROM auth_enrollment_grants')
    # Replace token hash to distinguish enrolled-admin guard from duplicate-token guard.
    old_hash=re.search(r'WHERE token_hash=(CONVERT\(0x[0-9a-f]{128} USING utf8mb4\))',sql)[1]
    fresh_hash='CONVERT(0x'+hashlib.sha256(b'never-issued-test-token').hexdigest().encode().hex()+' USING utf8mb4)'
    new_sql=sql.replace(old_hash,fresh_hash)
    failed=False
    try:db(new_sql)
    except Exception:failed=True
    db('DROP PROCEDURE IF EXISTS `'+routine+'`')
    check('Phase6C SQL provisioning closes once an administrator is enrolled',failed and db('SELECT COUNT(*) FROM auth_enrollment_grants')==before)
