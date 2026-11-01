"""Real API tests on the disposable Phase 5 -> Phase 6A transition."""
def run_phase6a(*,Client,base,db,check,root,**ctx):
    admin=Client(base);admin.login('admin','admin123')
    evidence="SELECT SHA2(GROUP_CONCAT(CONCAT_WS('|',id,username,password_hash,name,role,IFNULL(department,''),active,created_at,updated_at,IFNULL(created_by,'')) ORDER BY id SEPARATOR '\\n'),256) FROM users"
    before=db(evidence)
    db((root/'sql/migration_account_security.sql').read_text())
    check('Phase6A migration preserves original user records',db(evidence)==before)
    check('Phase6A pre-upgrade sessions cannot bypass security version',admin.call('inventory.php')[0]==401)
    admin=Client(base);admin.login('admin','admin123')
    check('Phase6A existing accounts remain unclassified',all(u['account_kind']=='unclassified' for u in admin.call('users.php')[1]))
    def create(username,role='staff',department=None):
        r=admin.call('users.php','POST',dict(username=username,password='AccountTest123',name='Test Employee',role=role,department=department))
        assert r[0]==200,r
        return r[1]
    row=create('named_person');other=Client(base);other.login('named_person','AccountTest123')
    person=Client(base);person.login('named_person','AccountTest123')
    check('Phase6A new account is named and has existing Staff permissions',row['account_kind']=='named' and person.call('inventory.php')[0]==200)
    check('Phase6A missing CSRF blocks password change',person.call('users.php?id=me','PUT',dict(action='change_password',current_password='AccountTest123',new_password='ChangedTest123'),csrf=False)[0]==403)
    check('Phase6A wrong current password is rejected',person.call('users.php?id=me','PUT',dict(action='change_password',current_password='wrong',new_password='ChangedTest123'))[0]==403)
    check('Phase6A own password change succeeds',person.call('users.php?id=me','PUT',dict(action='change_password',current_password='AccountTest123',new_password='ChangedTest123'))[0]==200)
    check('Phase6A all sessions revoked after password change',person.call('inventory.php')[0]==401 and other.call('inventory.php')[0]==401)
    person=Client(base);person.login('named_person','ChangedTest123')
    check('Phase6A new password authenticates',person.call('inventory.php')[0]==200)
    dept='Fleet & Transportation management';department_row=create('named_department','department',dept)
    department=Client(base);department.login('named_department','AccountTest123')
    check('Phase6A named Department retains department boundaries',department.call('inventory.php')[0]==403 and department.call('users.php')[0]==403 and department.call('stock_requests.php')[0]==200)
    check('Phase6A Department cannot edit another user',department.call('users.php?id='+str(row['id']),'PUT',{'name':'Other Person'})[0]==403)
    check('Phase6A Department can change own password',department.call('users.php?id=me','PUT',dict(action='change_password',current_password='AccountTest123',new_password='DepartmentNew123'))[0]==200)
    check('Phase6A Department password change revokes session',department.call('stock_requests.php')[0]==401)
    check('Phase6A admin password reset succeeds',admin.call('users.php?id='+str(row['id']),'PUT',{'action':'set_password','password':'ResetPassword123'})[0]==200)
    check('Phase6A admin password reset revokes target session',person.call('inventory.php')[0]==401)
    person=Client(base);person.login('named_person','ResetPassword123')
    check('Phase6A deactivation succeeds',admin.call('users.php?id='+str(row['id']),'PUT',{'active':False})[0]==200)
    check('Phase6A deactivated account loses access',person.call('inventory.php')[0]==401)
    admin.call('users.php?id='+str(row['id']),'PUT',{'active':True})
    check('Phase6A reactivation cannot revive old sessions',person.call('inventory.php')[0]==401)
    admin_row=next(r for r in admin.call('users.php')[1] if r['username']=='admin')
    check('Phase6A last administrator cannot be disabled',admin.call('users.php?id='+str(admin_row['id']),'PUT',{'active':False})[0]==409)
    check('Phase6A invalid active flag is rejected',admin.call('users.php?id='+str(row['id']),'PUT',{'active':'garbage'})[0]==400)
    check('Phase6A bcrypt truncation cannot silently accept oversized passwords',admin.call('users.php?id='+str(row['id']),'PUT',{'action':'set_password','password':'x'*73})[0]==400)
    db("CREATE TRIGGER phase6a_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected account audit failure';")
    failed=admin.call('users.php?id='+str(row['id']),'PUT',{'active':False})
    failed_create=admin.call('users.php','POST',dict(username='must_not_exist',password='AccountTest123',name='Rollback Person',role='staff'))
    db('DROP TRIGGER phase6a_audit_failure;')
    check('Phase6A required audit failure rejects updates and creation',failed[0]==503 and failed_create[0]==503)
    users=admin.call('users.php')[1]
    check('Phase6A audit failure preserves account and rolls back creation',next(r for r in users if r['id']==row['id'])['active'] and not any(r['username']=='must_not_exist' for r in users))
    person=Client(base);person.login('named_person','ResetPassword123')
    check('Phase6A role update succeeds',admin.call('users.php?id='+str(row['id']),'PUT',{'role':'manager'})[0]==200)
    check('Phase6A role change requires reauthentication',person.call('inventory.php')[0]==401)
    person=Client(base);person.login('named_person','ResetPassword123')
    item=admin.call('inventory.php','POST',{'label':'Phase Six Paper','item_type':'consumable','unit':'pcs','current_qty':10})[1]
    request=person.call('stock_requests.php','POST',{'department':dept,'purpose':'Account regression verification','items':[{'item_key':item['item_key'],'requested_qty':2}]})[1]
    approved=person.call('stock_requests.php?id='+str(request['id']),'PUT',{'action':'approve','request_revision':request['request_revision'],'items':[{'id':request['items'][0]['id'],'approved_qty':2}]})
    assert approved[0]==200,approved
    issued=person.call('stock_requests.php?id='+str(request['id']),'PUT',{'action':'issue','request_revision':approved[1]['request_revision'],'items':[{'id':request['items'][0]['id'],'qty':2}]})
    check('Phase6A existing approved issuance still works',issued[0]==200 and issued[1]['status']=='Fulfilled')
    check('Phase6A protected reports and procurement still load',person.call('reports.php?type=stock_requests&format=html')[0]==200 and person.call('purchase_orders.php')[0]==200)
    db("INSERT INTO login_attempts(username,ip_address,success,created_at) VALUES "+','.join("('rate_fixture','203.0.113.15',0,NOW()-INTERVAL 11 MINUTE)" for _ in range(5)))
    check('Phase6A 15-minute lockout does not disappear at minute 10',Client(base).call('auth.php','POST',{'username':'rate_fixture','password':'not-valid'})[0]==429)
    db("INSERT INTO login_attempts(username,ip_address,success,created_at) VALUES "+','.join("('rate_spread','203.0.113.16',0,NOW()-INTERVAL 20 MINUTE)" for _ in range(4))+",('rate_spread','203.0.113.16',0,NOW()-INTERVAL 11 MINUTE)")
    check('Phase6A spread-out failures preserve the full lockout after threshold crossing',Client(base).call('auth.php','POST',{'username':'rate_spread','password':'not-valid'})[0]==429)
    db("ALTER TABLE users CHANGE COLUMN account_kind hidden_kind ENUM('unclassified','named','shared') NOT NULL DEFAULT 'unclassified'")
    check('Phase6A partial migration fails closed',person.call('inventory.php')[0]==503)
    db("ALTER TABLE users CHANGE COLUMN hidden_kind account_kind ENUM('unclassified','named','shared') NOT NULL DEFAULT 'unclassified'")
    check('Phase6A schema recovery restores valid session checks',person.call('inventory.php')[0]==200)
    from concurrent.futures import ThreadPoolExecutor
    import subprocess,time
    arow=create('concurrent_admin_a','super_admin');brow=create('concurrent_admin_b','super_admin')
    a=Client(base);a.login('concurrent_admin_a','AccountTest123')
    port=ctx['available_port']();other_base='http://127.0.0.1:'+str(port)
    server=subprocess.Popen([str(ctx['php']),'-d','session.save_path='+str(ctx['sessions']),'-d','display_errors=0','-S','127.0.0.1:'+str(port),'-t',str(ctx['web'])],stdout=ctx['php_log'],stderr=subprocess.STDOUT,env=ctx['environment'],creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    ctx['processes'].append(server)
    b=Client(other_base)
    for attempt in range(50):
        try:
            b.login('concurrent_admin_b','AccountTest123');break
        except Exception:
            if attempt==49:raise
            time.sleep(.1)
    assert a.call('users.php?id='+str(admin_row['id']),'PUT',{'active':False})[0]==200
    with ThreadPoolExecutor(max_workers=2)as pool:
        fa=pool.submit(a.call,'users.php?id='+str(brow['id']),'PUT',{'active':False})
        fb=pool.submit(b.call,'users.php?id='+str(arow['id']),'PUT',{'active':False})
        ra,rb=fa.result(),fb.result()
    check('Phase6A concurrent offboarding cannot remove every administrator',sorted([ra[0],rb[0]])==[200,401])
    survivor=a if ra[0]==200 else b
    assert survivor.call('users.php?id='+str(admin_row['id']),'PUT',{'active':True,'account_kind':'named'})[0]==200
    check('Phase6A administrator access remains recoverable after concurrent changes',Client(base).login('admin','admin123')['user']['role']=='super_admin')
