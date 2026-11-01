"""Integrated real-API MFA tests. Only disposable instances receive fixtures/writes."""
import base64,hashlib,hmac,json,struct,time,subprocess
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor

def otp(secret,step=None):
    counter=int(time.time()//30) if step is None else step
    key=base64.b32decode(secret+'='*((8-len(secret)%8)%8))
    digest=hmac.new(key,struct.pack('>Q',counter),hashlib.sha1).digest();offset=digest[-1]&15
    return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)

def run_phase6b(*,Client,base,db,check,root,**ctx):
    db((root/'sql/migration_authenticator_mfa.sql').read_text(encoding='utf-8'))
    admin=Client(base);admin.login('admin','admin123')
    def good(response):
        assert response[0]==200,response[:2]
        return response[1]
    def create(name,role='staff'):
        data=dict(username=name,password='MfaPassword123',name='Mfa Test Person',role=role)
        if role=='department':data['department']='Tour Operations'
        return good(admin.call('users.php','POST',data))
    def begin(client,name,password='MfaPassword123'):
        r=good(client.call('auth.php','POST',dict(username=name,password=password)));client.csrf=r.get('csrf_token');return r
    def setup(client,name,grant,password='MfaPassword123'):
        r=good(client.call('auth.php','POST',dict(action='enroll_begin',username=name,password=password,grant=grant)));client.csrf=r['csrf_token'];return r
    def verify(client,**body):return client.call('auth.php','POST',dict(action='verify',**body))
    def recovery_login(name,code,password='MfaPassword123',client=None):
        client=client or Client(base);begin(client,name,password);good(verify(client,recovery_code=code));client.csrf=good(client.call('auth.php'))['csrf_token'];return client
    def reset_step(uid):db('UPDATE user_authenticators SET last_step=NULL WHERE user_id='+str(uid))
    def failcount_clear(name):db("DELETE FROM login_attempts WHERE username='"+name+"' AND (stage IN ('mfa','reauth') OR (stage='password' AND success=1))")
    primary=create('mfa_admin','super_admin');uid=primary['id']
    from phase6c_readiness import before_bootstrap,after_bootstrap
    import_sql,import_routine=before_bootstrap(Client=Client,base=base,db=db,check=check,root=root,ctx=ctx,admin=admin,uid=uid)
    output=ctx['sessions'].parent/'first-admin-private.json'
    boot=subprocess.run([str(ctx['php']),'-d','session.save_path='+str(ctx['sessions']),str(root/'scripts/bootstrap_mfa.php'),'--username=mfa_admin','--operator=Disposable Test Operator','--reason=Verified disposable bootstrap test','--output='+str(output)],env=ctx['environment'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert boot.returncode==0 and output.is_file(),boot.stderr.decode(errors='replace')
    grant=json.loads(output.read_text())['grant']
    check('Phase6B bootstrap creates authorization without authenticating a browser',Client(base).call('inventory.php')[0]==401 and grant not in boot.stdout.decode())
    enrolled=Client(base);started=setup(enrolled,'mfa_admin',grant);secret=started['secret']
    check('Phase6B setup is pending and cannot access inventory',enrolled.call('inventory.php')[0]==401 and not good(enrolled.call('auth.php'))['authenticated'])
    check('Phase6B setup key is standard TOTP with local issuer','otpauth://totp/' in started['uri'] and 'Cre8ted' in started['uri'])
    check('Phase6B verification requires pre-auth CSRF',enrolled.call('auth.php','POST',{'action':'verify','code':otp(secret)},csrf=False)[0]==403)
    result=good(verify(enrolled,code=otp(secret)));codes=result['recovery_codes']
    check('Phase6B enrollment provides ten distinct recovery codes once',len(codes)==10 and len(set(codes))==10 and result['requires_login'])
    check('Phase6B enrollment does not auto-authenticate',enrolled.call('inventory.php')[0]==401)
    check('Phase6B used setup grant cannot be reused',Client(base).call('auth.php','POST',dict(action='enroll_begin',username='mfa_admin',password='MfaPassword123',grant=grant))[0]==401)
    pending=Client(base);r=begin(pending,'mfa_admin')
    check('Phase6B valid password does not complete an enrolled login',r['status']=='challenge' and pending.call('users.php')[0]==401)
    check('Phase6B setup code cannot be reused for login',verify(pending,code=otp(secret))[0]==401)
    verified=recovery_login('mfa_admin',codes[0]);check('Phase6B password plus recovery code completes MFA',good(verified.call('auth.php'))['authenticated'])
    reused=Client(base);begin(reused,'mfa_admin');check('Phase6B consumed recovery code cannot be reused',verify(reused,recovery_code=codes[0])[0]==401)
    failcount_clear('mfa_admin')
    # Recovery-code groups are not independent codes. Reject incomplete input
    # without consuming the actual code, then accept that whole code exactly once.
    complete=Client(base);begin(complete,'mfa_admin')
    partial=verify(complete,recovery_code=codes[1].split('-')[0])
    check('Phase6B partial recovery group is rejected with recovery-specific guidance',partial[0]==401 and 'complete unused recovery code' in partial[1]['error'] and 'time window' not in partial[1]['error'])
    check('Phase6B rejected partial group does not consume a full recovery code',int(db('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id='+str(uid)+' AND consumed_at IS NOT NULL'))==1)
    full=verify(complete,recovery_code=codes[1])
    check('Phase6B full recovery line succeeds after an incomplete group fails',full[0]==200 and good(complete.call('auth.php'))['authenticated'])
    repeat=Client(base);begin(repeat,'mfa_admin');rejected=verify(repeat,recovery_code=codes[1])
    check('Phase6B full recovery line is still single-use',rejected[0]==401 and 'Recovery code' in rejected[1]['error'])
    combined=Client(base);begin(combined,'mfa_admin');bad=verify(combined,recovery_code=codes[2]+'\n'+codes[3])
    check('Phase6B pasting multiple recovery lines cannot authenticate',bad[0]==401)
    check('Phase6B one uppercase unhyphenated recovery line retains compatibility',verify(combined,recovery_code=codes[2].replace('-','').upper())[0]==200)
    failcount_clear('mfa_admin')
    after_bootstrap(db=db,check=check,sql=import_sql,routine=import_routine,uid=uid)
    # Bootstrap closes permanently while an enrolled administrator exists.
    boot2=subprocess.run([str(ctx['php']),'-d','session.save_path='+str(ctx['sessions']),str(root/'scripts/bootstrap_mfa.php'),'--username=mfa_admin','--operator=Disposable Test Operator','--reason=Repeat attempt rejected','--output='+str(output.with_name('must-not-exist.json'))],env=ctx['environment'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check('Phase6B first-admin bootstrap cannot bypass an enrolled administrator',not output.with_name('must-not-exist.json').exists())
    worker=create('mfa_staff');wid=worker['id']
    def grant_for(target,purpose='initial',actor=verified,password='MfaPassword123'):
        return good(actor.call('mfa.php','POST',dict(action='grant',user_id=target,purpose=purpose,reason='Identity verified for disposable test',password=password)))['grant']
    token=grant_for(wid);staff_setup=Client(base);started=setup(staff_setup,'mfa_staff',token);staff_secret=started['secret'];staff_codes=good(verify(staff_setup,code=otp(staff_secret)))['recovery_codes']
    check('Phase6B enrolled admin authorizes another named employee',len(staff_codes)==10)
    reset_step(wid);staff=Client(base);begin(staff,'mfa_staff');good(verify(staff,code=otp(staff_secret)));staff.csrf=good(staff.call('auth.php'))['csrf_token']
    check('Phase6B authenticator login preserves Staff permissions',staff.call('inventory.php')[0]==200 and staff.call('users.php')[0]==403)
    replay=Client(base);begin(replay,'mfa_staff');check('Phase6B successful login code is replay protected',verify(replay,code=otp(staff_secret))[0]==401)
    failcount_clear('mfa_staff')
    for i in range(5):
        c=Client(base);begin(c,'mfa_staff');verify(c,code='not-a-code')
    c=Client(base);begin(c,'mfa_staff');check('Phase6B restarting challenges cannot reset the verification budget',verify(c,code=otp(staff_secret))[0]==429)
    failcount_clear('mfa_staff')
    # Secret binding: ciphertext cannot be transplanted to a different user ID.
    program="require 'php/api/config.php'; $blob=mfa_encrypt(1,'JBSWY3DPEHPK3PXP'); try{mfa_decrypt(2,$blob);echo 'bad';}catch(Throwable $e){echo 'bound';}"
    probe=subprocess.run([str(ctx['php']),'-d','session.save_path='+str(ctx['sessions']),'-r',"$_SERVER['REQUEST_METHOD']='CLI';"+program],cwd=root,env=ctx['environment'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check('Phase6B encrypted secrets are bound to the correct user',probe.stdout.decode().strip()=='bound')
    missing_env={**ctx['environment'],'MFA_ENCRYPTION_KEY':''}
    probe=subprocess.run([str(ctx['php']),'-d','session.save_path='+str(ctx['sessions']),'-r',"$_SERVER['REQUEST_METHOD']='CLI';require 'php/api/config.php';mfa_ready(get_pdo());"],cwd=root,env=missing_env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check('Phase6B missing encryption key cannot downgrade authentication',b'MFA encryption is unavailable' in probe.stdout)
    # RFC 6238 SHA1 8-digit published vector: independently validate the dependency.
    program="require 'vendor/autoload.php'; echo OTPHP\\TOTP::create('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',30,'sha1',8)->at(59);"
    probe=subprocess.run([str(ctx['php']),'-d','session.save_path='+str(ctx['sessions']),'-r',program],cwd=root,env=ctx['environment'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check('Phase6B TOTP dependency passes RFC6238 vector',probe.stdout.decode().strip()=='94287082')
    # Clock tolerance is bounded, and counters are reset only in this disposable DB.
    for delta in (-1,1):
        reset_step(wid);c=Client(base);begin(c,'mfa_staff');r=verify(c,code=otp(staff_secret,int(time.time()//30)+delta))
        check('Phase6B accepts allowed clock offset '+str(delta),r[0]==200)
    reset_step(wid);c=Client(base);begin(c,'mfa_staff')
    outside=otp(staff_secret,int(time.time()//30)+3)
    check('Phase6B rejects excessive clock offset',verify(c,code=outside)[0]==401)
    failcount_clear('mfa_staff')
    c=Client(base);begin(c,'mfa_staff');db('UPDATE auth_challenges SET expires_at=NOW()-INTERVAL 1 SECOND WHERE user_id='+str(wid)+' AND consumed_at IS NULL')
    check('Phase6B expired challenges cannot authenticate',verify(c,recovery_code=staff_codes[4])[0]==401)
    c=Client(base);begin(c,'mfa_staff');good(c.call('auth.php','POST',{'action':'cancel'}))
    check('Phase6B cancellation invalidates the pending authentication',not good(c.call('auth.php'))['authenticated'] and verify(c,recovery_code=staff_codes[4])[0]==403)
    # A reset supersedes an open setup page and invalidates all older recovery material.
    replacement=grant_for(wid,'reset');oldsetup=Client(base);oldsecret=setup(oldsetup,'mfa_staff',replacement)['secret']
    newer=grant_for(wid,'reset');check('Phase6B reset invalidates the old setup page',verify(oldsetup,code=otp(oldsecret))[0]==401)
    check('Phase6B reset revokes existing authenticated sessions',staff.call('inventory.php')[0]==401)
    fresh=Client(base);begin(fresh,'mfa_staff');check('Phase6B reset revokes old recovery codes',verify(fresh,recovery_code=staff_codes[1])[0]==409)
    newer_setup=Client(base);staff_secret=setup(newer_setup,'mfa_staff',newer)['secret'];staff_codes=good(verify(newer_setup,code=otp(staff_secret)))['recovery_codes']
    # Deactivation mid-login and reactivation cannot restore the factor.
    p=Client(base);begin(p,'mfa_staff');good(admin.call('users.php?id='+str(wid),'PUT',{'active':False}))
    check('Phase6B offboarding during OTP blocks session creation',verify(p,recovery_code=staff_codes[0])[0]==401)
    good(admin.call('users.php?id='+str(wid),'PUT',{'active':True}));p=Client(base);begin(p,'mfa_staff')
    check('Phase6B reactivation requires fresh enrollment',verify(p,recovery_code=staff_codes[0])[0]==409)
    token=grant_for(wid,'reset');s=Client(base);staff_secret=setup(s,'mfa_staff',token)['secret'];staff_codes=good(verify(s,code=otp(staff_secret)))['recovery_codes']
    # A valid handover cannot be applied to another account or after expiry.
    extra=create('mfa_other');wrong_token=grant_for(extra['id'])
    check('Phase6B enrollment authorization is bound to its user',Client(base).call('auth.php','POST',dict(action='enroll_begin',username='mfa_staff',password='MfaPassword123',grant=wrong_token))[0]==401)
    db('UPDATE auth_enrollment_grants SET expires_at=NOW()-INTERVAL 1 SECOND WHERE user_id='+str(extra['id']))
    check('Phase6B expired authorizations are rejected',Client(base).call('auth.php','POST',dict(action='enroll_begin',username='mfa_other',password='MfaPassword123',grant=wrong_token))[0]==401)
    failcount_clear('mfa_staff');failcount_clear('mfa_other')
    # Password reset changes the password, not the registered factor.
    good(admin.call('users.php?id='+str(wid),'PUT',{'action':'set_password','password':'ChangedMfa123'}));p=Client(base);r=begin(p,'mfa_staff','ChangedMfa123')
    check('Phase6B password reset does not remove MFA',r['status']=='challenge')
    good(verify(p,recovery_code=staff_codes[0]));p.csrf=good(p.call('auth.php'))['csrf_token']
    # Audit failure must not consume a recovery code or create a session.
    a=Client(base);begin(a,'mfa_staff','ChangedMfa123')
    db("CREATE TRIGGER phase6b_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected MFA audit failure'")
    failure=verify(a,recovery_code=staff_codes[1]);db('DROP TRIGGER phase6b_audit_failure')
    check('Phase6B audit failure blocks authentication',failure[0]==503)
    check('Phase6B audit rollback preserves recovery code for retry',verify(a,recovery_code=staff_codes[1])[0]==200)
    # Two real PHP processes consume the same recovery credential.
    port=ctx['available_port']();other_base='http://127.0.0.1:'+str(port)
    server=subprocess.Popen([str(ctx['php']),'-d','session.save_path='+str(ctx['sessions']),'-d','display_errors=0','-S','127.0.0.1:'+str(port),'-t',str(ctx['web'])],stdout=ctx['php_log'],stderr=subprocess.STDOUT,env=ctx['environment'],creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0));ctx['processes'].append(server)
    ca,cb=Client(base),Client(other_base)
    begin(ca,'mfa_staff','ChangedMfa123')
    for attempt in range(50):
        try:begin(cb,'mfa_staff','ChangedMfa123');break
        except Exception:
            if attempt==49:raise
            time.sleep(.1)
    with ThreadPoolExecutor(max_workers=2)as pool:
        fa=pool.submit(verify,ca,recovery_code=staff_codes[2]);fb=pool.submit(verify,cb,recovery_code=staff_codes[2]);ra,rb=fa.result(),fb.result()
    check('Phase6B concurrent recovery use succeeds exactly once',sorted([ra[0],rb[0]])==[200,401])
    failcount_clear('mfa_staff');reset_step(wid);ca,cb=Client(base),Client(other_base);begin(ca,'mfa_staff','ChangedMfa123');begin(cb,'mfa_staff','ChangedMfa123');code=otp(staff_secret)
    with ThreadPoolExecutor(max_workers=2)as pool:
        fa=pool.submit(verify,ca,code=code);fb=pool.submit(verify,cb,code=code);ra,rb=fa.result(),fb.result()
    check('Phase6B concurrent TOTP use succeeds exactly once',sorted([ra[0],rb[0]])==[200,401])
    failcount_clear('mfa_staff')
    strict_port=ctx['available_port']();strict_base='http://127.0.0.1:'+str(strict_port)
    strict_server=subprocess.Popen([str(ctx['php']),'-d','session.save_path='+str(ctx['sessions']),'-d','display_errors=0','-S','127.0.0.1:'+str(strict_port),'-t',str(ctx['web'])],stdout=ctx['php_log'],stderr=subprocess.STDOUT,env={**ctx['environment'],'MFA_ALLOW_LOCAL_HTTP':'0'},creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0));ctx['processes'].append(strict_server)
    for attempt in range(50):
        try:
            transport=Client(strict_base).call('auth.php','POST',{'username':'mfa_staff','password':'ChangedMfa123'});break
        except Exception:
            if attempt==49:raise
            time.sleep(.1)
    check('Phase6B HTTPS is mandatory unless loopback development is explicitly allowed',transport[0]==403 and 'HTTPS' in transport[1]['error'])
    from phase6b_http import run_http_compat
    run_http_compat(Client=Client,base=base,check=check,root=root,ctx=ctx,create=create,grant_for=grant_for,otp=otp)
    # Normal authorization remains centralized after MFA; test enrolled Department account.
    department=create('mfa_department','department');token=grant_for(department['id']);d=Client(base);ds=setup(d,'mfa_department',token)['secret'];dc=good(verify(d,code=otp(ds)))['recovery_codes'];d=recovery_login('mfa_department',dc[0])
    check('Phase6B MFA Department retains departmental scope',d.call('stock_requests.php')[0]==200 and d.call('inventory.php')[0]==403 and d.call('users.php')[0]==403)
    db('UPDATE auth_policy SET enforce_mfa=1 WHERE id=1')
    check('Phase6B enforced policy blocks old password-only sessions',admin.call('users.php')[0]==401)
    check('Phase6B enforced policy protects the last enrolled administrator',verified.call('users.php?id='+str(uid),'PUT',{'active':False})[0]==409)
    db('UPDATE auth_policy SET enforce_mfa=0 WHERE id=1');admin=Client(base);admin.login('admin','admin123')
    manager_row=create('mfa_manager','manager');manager_token=grant_for(manager_row['id']);manager_setup=Client(base);manager_secret=setup(manager_setup,'mfa_manager',manager_token)['secret'];manager_codes=good(verify(manager_setup,code=otp(manager_secret)))['recovery_codes'];manager=recovery_login('mfa_manager',manager_codes[0])
    check('Phase6B enrolled Manager retains management permissions without account administration',manager.call('inventory.php')[0]==200 and manager.call('users.php')[0]==403 and 'users' not in good(manager.call('mfa.php')))
    items=[]
    for label in ['MFA manager paper','MFA manager pens']:
        items.append(good(manager.call('inventory.php','POST',{'label':label,'item_type':'consumable','unit':'pcs','current_qty':10})))
    request=good(manager.call('stock_requests.php','POST',{'department':'Tour Operations','purpose':'Enrolled Manager and Staff regression','items':[{'item_key':item['item_key'],'requested_qty':2}for item in items]}))
    request=good(manager.call('stock_requests.php?id='+str(request['id']),'PUT',{'action':'approve','request_revision':request['request_revision'],'items':[{'id':line['id'],'approved_qty':2}for line in request['items']]}))
    check('Phase6B MFA Manager approval retains self-approved label and reservations',request['self_approved'] and request['status']=='Approved')
    issued=good(p.call('stock_requests.php?id='+str(request['id']),'PUT',{'action':'issue','request_revision':request['request_revision'],'items':[{'id':line['id'],'qty':2}for line in request['items']]}))
    check('Phase6B MFA Staff issues approved multi-item request exactly once',issued['status']=='Fulfilled' and all(good(manager.call('inventory.php?item='+item['item_key']))['current_qty']==8 for item in items))
    # Preparation policy is deliberately not global enforcement. Enrolled users always require MFA.
    check('Phase6B no new shared accounts accepted',admin.call('users.php','POST',dict(username='new_shared',password='MfaPassword123',name='Shared Test',role='staff',account_kind='shared'))[0]==400)
    check('Phase6B recovery audit excludes codes and setup secrets',secret not in db('SELECT after_json FROM audit_log WHERE action LIKE \'mfa.%\'') and staff_codes[3] not in db('SELECT after_json FROM audit_log'))
    failcount_clear('mfa_staff');starts=[]
    for i in range(10):starts.append(Client(base).call('auth.php','POST',{'username':'mfa_staff','password':'ChangedMfa123'})[0])
    check('Phase6B valid stolen credentials cannot create unlimited pending challenges',starts[-1]==429 and 200 in starts)
    failcount_clear('mfa_staff')
    db('RENAME TABLE auth_policy TO unavailable_policy')
    check('Phase6B missing MFA policy fails closed',p.call('inventory.php')[0]==503)
    db('RENAME TABLE unavailable_policy TO auth_policy')
    from phase6_emergency_recovery import run_emergency_recovery
    run_emergency_recovery(Client=Client,base=base,db=db,check=check,root=root,ctx=ctx,create=create,grant_for=grant_for,otp=otp)
    # Disposable-only manual handover, never a normal-system enrollment bypass.
    if ctx.get('preview'):
        preview=create('preview_admin','super_admin');token=grant_for(preview['id'])
        browser=create('browser_mfa','super_admin');browser_token=grant_for(browser['id'])
        (ctx['web']/'api'/'phase6b-preview-handover.json').write_text(json.dumps({'username':'preview_admin','password':'MfaPassword123','grant':token,'expires_in':900,'browser':{'username':'browser_mfa','password':'MfaPassword123','grant':browser_token}}))
        print('DISPOSABLE MFA SETUP: preview_admin / MfaPassword123. Private sample handover: '+base+'/api/phase6b-preview-handover.json (15-minute authorization; fixture-only file, never shipped).',flush=True)
