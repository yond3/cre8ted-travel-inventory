"""HTTP deployment compatibility against disposable servers; no public network."""
import json, subprocess, time


def run_http_compat(*, Client, base, check, root, ctx, create, grant_for, otp):
    # Unit probes exercise HTTPS and remote-host policy without changing runtime globals.
    cases = [
        ('HTTPS remains accepted', {'HTTPS':'on','HTTP_HOST':'inventory.example.test','REMOTE_ADDR':'203.0.113.10'}, {}, True),
        ('public HTTP defaults to denied', {'HTTP_HOST':'inventory.example.test','REMOTE_ADDR':'203.0.113.10'}, {}, False),
        ('exact configured HTTP origin accepted', {'HTTP_HOST':'Inventory.Example.Test:80','REMOTE_ADDR':'203.0.113.10'}, {'MFA_ALLOW_HTTP':'1','MFA_HTTP_ORIGIN':'http://inventory.example.test'}, True),
        ('origin alone does not enable HTTP', {'HTTP_HOST':'inventory.example.test'}, {'MFA_HTTP_ORIGIN':'http://inventory.example.test'}, False),
        ('flag alone does not enable HTTP', {'HTTP_HOST':'inventory.example.test'}, {'MFA_ALLOW_HTTP':'1'}, False),
        ('wrong host denied', {'HTTP_HOST':'other.example.test'}, {'MFA_ALLOW_HTTP':'1','MFA_HTTP_ORIGIN':'http://inventory.example.test'}, False),
        ('wrong port denied', {'HTTP_HOST':'inventory.example.test:8080'}, {'MFA_ALLOW_HTTP':'1','MFA_HTTP_ORIGIN':'http://inventory.example.test'}, False),
        ('subdomain suffix denied', {'HTTP_HOST':'inventory.example.test.attacker.test'}, {'MFA_ALLOW_HTTP':'1','MFA_HTTP_ORIGIN':'http://inventory.example.test'}, False),
        ('wildcard origin denied', {'HTTP_HOST':'inventory.example.test'}, {'MFA_ALLOW_HTTP':'1','MFA_HTTP_ORIGIN':'http://*.example.test'}, False),
        ('configured path denied', {'HTTP_HOST':'inventory.example.test'}, {'MFA_ALLOW_HTTP':'1','MFA_HTTP_ORIGIN':'http://inventory.example.test/app'}, False),
        ('configured HTTPS origin is not an HTTP exception', {'HTTP_HOST':'inventory.example.test'}, {'MFA_ALLOW_HTTP':'1','MFA_HTTP_ORIGIN':'https://inventory.example.test'}, False),
        ('forwarded headers do not bypass policy', {'HTTP_HOST':'other.example.test','HTTP_X_FORWARDED_PROTO':'https','HTTP_X_FORWARDED_HOST':'inventory.example.test'}, {'MFA_ALLOW_HTTP':'1','MFA_HTTP_ORIGIN':'http://inventory.example.test'}, False),
        ('loopback option does not allow remote peers', {'HTTP_HOST':'localhost','REMOTE_ADDR':'203.0.113.10'}, {'MFA_ALLOW_LOCAL_HTTP':'1'}, False),
        ('legacy loopback development still works', {'HTTP_HOST':'localhost','REMOTE_ADDR':'127.0.0.1'}, {'MFA_ALLOW_LOCAL_HTTP':'1'}, True),
    ]
    for label, server, overrides, expected in cases:
        env={**ctx['environment'],'MFA_ALLOW_LOCAL_HTTP':'0','MFA_ALLOW_HTTP':'0','MFA_HTTP_ORIGIN':'',**overrides}
        program="require 'php/api/env.php'; require 'php/api/security.php'; require 'php/api/mfa_service.php'; $_SERVER=json_decode("+json.dumps(json.dumps(server))+",true); echo mfa_transport_allowed()?'yes':'no';"
        result=subprocess.run([str(ctx['php']),'-r',program],cwd=root,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        check('HTTP compatibility '+label,result.returncode==0 and result.stdout.decode().strip()==('yes' if expected else 'no'))
    def launch(enabled):
        port=ctx['available_port']()
        env={**ctx['environment'],'MFA_ALLOW_LOCAL_HTTP':'0','MFA_ALLOW_HTTP':str(enabled),'MFA_HTTP_ORIGIN':'http://inventory.example.test'}
        process=subprocess.Popen([str(ctx['php']),'-d','session.save_path='+str(ctx['sessions']),'-d','display_errors=0','-S','127.0.0.1:'+str(port),'-t',str(ctx['web'])],env=env,stdout=ctx['php_log'],stderr=subprocess.STDOUT,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        ctx['processes'].append(process)
        endpoint='http://127.0.0.1:'+str(port)
        for attempt in range(50):
            try: Client(endpoint).call('auth.php?transport=1',headers={'Host':'inventory.example.test'}); break
            except Exception:
                if attempt==49: raise
                time.sleep(.1)
        return endpoint
    class HttpClient(Client):
        def call(self,path,*args,**kwargs):
            kwargs['headers']={'Host':'inventory.example.test',**kwargs.get('headers',{})}
            return super().call(path,*args,**kwargs)
    enabled=launch(1); disabled=launch(0)
    guest=HttpClient(enabled)
    policy=guest.call('auth.php?transport=1')
    check('HTTP compatibility UI readiness shares enabled server policy',policy[0]==200 and policy[1]=={'transport_allowed':True} and policy[2].get('Cache-Control')=='no-store')
    check('HTTP compatibility disabled flag rejects readiness',HttpClient(disabled).call('auth.php?transport=1')[0]==403)
    check('HTTP compatibility disabled flag rejects direct login',HttpClient(disabled).call('auth.php','POST',{'username':'admin','password':'admin123'})[0]==403)
    check('HTTP compatibility wrong address rejects readiness and direct login',guest.call('auth.php?transport=1',headers={'Host':'wrong.example.test'})[0]==403 and guest.call('auth.php','POST',{'username':'admin','password':'admin123'},headers={'Host':'wrong.example.test'})[0]==403)
    check('HTTP compatibility unverified visitor cannot access inventory',guest.call('inventory.php')[0]==401)
    row=create('http_staff');token=grant_for(row['id'])
    setup=guest.call('auth.php','POST',{'action':'enroll_begin','username':'http_staff','password':'MfaPassword123','grant':token})
    assert setup[0]==200,setup[:2]
    guest.csrf=setup[1]['csrf_token']
    check('HTTP compatibility enrollment remains pending',guest.call('inventory.php')[0]==401)
    done=guest.call('auth.php','POST',{'action':'verify','code':otp(setup[1]['secret'])})
    check('HTTP compatibility enrollment provides recovery codes without logging in',done[0]==200 and len(done[1]['recovery_codes'])==10 and guest.call('inventory.php')[0]==401)
    pending=HttpClient(enabled);start=pending.call('auth.php','POST',{'username':'http_staff','password':'MfaPassword123'})
    pending.csrf=start[1].get('csrf_token')
    check('HTTP compatibility password alone cannot authenticate enrolled user',start[0]==200 and start[1]['status']=='challenge' and pending.call('inventory.php')[0]==401)
    check('HTTP compatibility verification retains CSRF protection',pending.call('auth.php','POST',{'action':'verify','recovery_code':done[1]['recovery_codes'][0]},csrf=False)[0]==403)
    verified=pending.call('auth.php','POST',{'action':'verify','recovery_code':done[1]['recovery_codes'][0]})
    check('HTTP compatibility full recovery login preserves Staff permissions',verified[0]==200 and pending.call('inventory.php')[0]==200 and pending.call('users.php')[0]==403)
    # Same browser session on a server with the exception disabled must stop working.
    locked=HttpClient(disabled);locked.opener=pending.opener;locked.csrf=pending.csrf
    check('HTTP compatibility disabling exception rejects existing authenticated session',locked.call('inventory.php')[0]==403)
    replay=HttpClient(enabled);start=replay.call('auth.php','POST',{'username':'http_staff','password':'MfaPassword123'});replay.csrf=start[1]['csrf_token']
    check('HTTP compatibility recovery remains single use',replay.call('auth.php','POST',{'action':'verify','recovery_code':done[1]['recovery_codes'][0]})[0]==401)
    pending.csrf=pending.call('auth.php')[1]['csrf_token'];pending.call('auth.php','DELETE')
    check('HTTP compatibility logout removes protected access',pending.call('inventory.php')[0]==401)
