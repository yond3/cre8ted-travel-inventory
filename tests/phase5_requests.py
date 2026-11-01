"""Runs only after the existing phases in the disposable database."""
import uuid
import subprocess,time
from concurrent.futures import ThreadPoolExecutor

def run_phase5(*,Client,base,db,sql,check,root,**context):
    migration=(root/'sql/migration_stock_request_items.sql').read_text()
    db(migration)
    manager,staff,admin,department=[Client(base)for _ in range(4)]
    manager.login('maria','manager123');staff.login('juan','staff123');admin.login('admin','admin123');department.login('fleet_dept','staff123')
    def good(response):
        assert response[0]==200,response[:2]
        return response[1]
    tag=uuid.uuid4().hex[:8]
    def item(name,qty=20):
        return good(manager.call('inventory.php','POST',{'label':name+' '+tag,'unit':'pcs','item_type':'consumable','current_qty':qty,'min_qty':1,'max_qty':100}))
    a,b=item('Phase5 paper'),item('Phase5 pens')
    dept='Fleet & Transportation management'
    def create(client=staff,items=None,**extra):
        return client.call('stock_requests.php','POST',{'department':dept,'purpose':'Replace damaged office supplies','items':items or [{'item_key':a['item_key'],'requested_qty':5},{'item_key':b['item_key'],'requested_qty':3}],**extra})
    def action(client,r,act,**extra):
        return client.call('stock_requests.php?id='+str(r['id']),'PUT',{'action':act,'request_revision':r['request_revision'],**extra})
    check('Phase5 purpose mandatory',create(purpose='')[0]==400)
    check('Phase5 duplicate line rejected',create(items=[{'item_key':a['item_key'],'qty':1}]*2)[0]==400)
    r=good(create())
    check('Phase5 one request contains two lines',len(r['items'])==2 and r['status']=='Pending')
    decisions=[{'id':x['id'],'approved_qty':x['requested_qty']}for x in r['items']]
    check('Phase5 staff cannot approve',action(staff,r,'approve',items=decisions)[0]==403)
    r=good(action(manager,r,'approve',items=decisions))
    check('Phase5 approval reserves without deduction',r['items'][0]['reserved_qty']==5 and r['items'][0]['physical_qty']==20)
    check('Phase5 stale review rejected',action(manager,{**r,'request_revision':0},'approve',items=decisions)[0]==409)
    check('Phase5 staff standalone issuance forbidden',staff.call('stock_issues.php','POST',{'item_key':a['item_key'],'qty':1,'department':dept})[0]==403)
    check('Phase5 correction cannot consume commitment',manager.call('stock.php?item='+a['item_key'],'POST',{'current':2})[0]==409)
    first=r['items'][0]
    r=good(action(staff,r,'issue',items=[{'id':first['id'],'qty':2}]))
    check('Phase5 partial issuance updates progress',r['status']=='Partially Issued' and r['items'][0]['issued_qty']==2 and r['items'][0]['remaining_qty']==3)
    r=good(action(staff,r,'issue',items=[{'id':x['id'],'qty':x['remaining_qty']}for x in r['items']if x['remaining_qty']>0]))
    check('Phase5 full completion releases reservations',r['status']=='Fulfilled' and all(x['remaining_qty']==0 for x in r['items']))
    check('Phase5 repeat completion rejected',action(staff,r,'issue',items=[{'id':first['id'],'qty':1}])[0]==409)
    own=good(create(client=manager,items=[{'item_key':a['item_key'],'qty':1}]))
    own=good(action(manager,own,'approve',items=[{'id':own['items'][0]['id'],'approved_qty':1}]))
    check('Phase5 self approval uses account identity',own['self_approved']is True and r['self_approved']is False)
    own=good(action(manager,own,'cancel',reason='No longer needed'))
    check('Phase5 explicit cancellation releases commitment',own['status']=='Cancelled' and own['items'][0]['remaining_qty']==0)
    big=good(create(items=[{'item_key':a['item_key'],'qty':90}]))
    check('Phase5 legitimate large request may be submitted',big['items'][0]['requested_qty']==90)
    check('Phase5 approval cannot promise absent stock',action(manager,big,'approve',items=[{'id':big['items'][0]['id'],'approved_qty':90}])[0]==409)
    # Failed multi-line issuance must roll back every line.
    r2=good(create(items=[{'item_key':a['item_key'],'qty':2},{'item_key':b['item_key'],'qty':2}]))
    r2=good(action(manager,r2,'approve',items=[{'id':x['id'],'approved_qty':2}for x in r2['items']]))
    failed=action(staff,r2,'issue',items=[{'id':r2['items'][0]['id'],'qty':1},{'id':r2['items'][1]['id'],'qty':3}]); print('Atomic failure response:',failed[:2],flush=True)
    check('Phase5 mixed invalid issuance is atomic',failed[0]==409)
    after=good(staff.call('stock_requests.php?id='+str(r2['id'])))
    check('Phase5 rollback preserves both lines',all(x['issued_qty']==0 for x in after['items']))
    check('Phase5 department cannot approve',action(department,r2,'approve',items=[])[0]==403)
    check('Phase5 outstanding view contains approved request',any(x['id']==r2['id']for x in good(manager.call('stock_requests.php?outstanding=1'))))
    current=good(manager.call('inventory.php?item='+a['item_key']))
    policy=good(manager.call('inventory.php?item='+a['item_key'],'PUT',{'protected_qty':12,'policy_reason':'Protect operations reserve','stock_version':current['stock_version']}))
    protected=good(create(items=[{'item_key':a['item_key'],'qty':3}]))
    line=protected['items'][0]
    check('Phase5 protected stock requires override reason',action(manager,protected,'approve',items=[{'id':line['id'],'approved_qty':3}])[0]==400)
    protected=good(action(manager,protected,'approve',items=[{'id':line['id'],'approved_qty':3,'override_reason':'Urgent approved maintenance'}]))
    check('Phase5 protected override is preserved',protected['items'][0]['protected_override_reason']=='Urgent approved maintenance')
    current=good(manager.call('inventory.php?item='+a['item_key']))
    good(manager.call('inventory.php?item='+a['item_key'],'PUT',{'protected_qty':15,'policy_reason':'New higher reserve','stock_version':current['stock_version']}))
    protected=good(action(staff,protected,'issue',items=[{'id':line['id'],'qty':1}]))
    check('Phase5 policy changes honor prior commitments',protected['items'][0]['issued_qty']==1)
    closed=good(action(staff,protected,'close',reason='Only one unit needed'))
    issues=good(staff.call('stock_issues.php?item='+a['item_key']))
    issued=next(x for x in issues if x['request_item_id']==line['id'])
    check('Phase5 closed request reversal cannot reissue',manager.call('stock_issues.php?id='+str(issued['id']),'PUT',{'action':'void','reason':'Entry error','request_revision':closed['request_revision'],'reservation_disposition':'reissue'})[0]==409)
    good(manager.call('stock_issues.php?id='+str(issued['id']),'PUT',{'action':'void','reason':'Entry error','request_revision':closed['request_revision'],'reservation_disposition':'release'}))
    check('Phase5 reversal preserves closure',good(manager.call('stock_requests.php?id='+str(closed['id'])))['status']=='Closed')
    # Genuine write-off/correction can reconcile specifically chosen commitments.
    r2=good(manager.call('stock_requests.php?id='+str(r2['id'])))
    releases=[{'id':x['id'],'qty':x['remaining_qty'],'request_revision':r2['request_revision'],'reason':'Physical stock correction'}for x in r2['items']]
    current=good(manager.call('inventory.php?item='+a['item_key']))
    good(manager.call('stock.php?item='+a['item_key'],'POST',{'current':1,'reason':'Physical damage confirmed','stock_version':current['stock_version'],'reservation_releases':releases}))
    check('Phase5 correction explicitly reconciles commitments',good(manager.call('stock_requests.php?id='+str(r2['id'])))['status']=='Cancelled')
    # Parallel approvals use distinct PHP processes and sessions.
    contested=item('Phase5 scarce',2)
    x=good(create(items=[{'item_key':contested['item_key'],'qty':2}]))
    y=good(create(items=[{'item_key':contested['item_key'],'qty':2}]))
    secondary=Client('http://127.0.0.1:'+str(context['available_port']()))
    process=subprocess.Popen([str(context['php']),'-d','session.save_path='+str(context['sessions']),'-d','display_errors=0','-S',secondary.base.removeprefix('http://'),'-t',str(context['web'])],stdout=context['php_log'],stderr=subprocess.STDOUT,env=context['environment'],creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    context['processes'].append(process)
    deadline=time.monotonic()+10
    while True:
        try:
            assert secondary.call('phase1-instance.json')[1]=={'test_instance':context['instance_token']}
            break
        except Exception:
            if process.poll()is not None or time.monotonic()>deadline:raise
            time.sleep(.1)
    secondary.login('maria','manager123')
    def approve(client,row):return action(client,row,'approve',items=[{'id':row['items'][0]['id'],'approved_qty':2}])
    with ThreadPoolExecutor(2)as pool:
        left=pool.submit(approve,manager,x);right=pool.submit(approve,secondary,y);outcomes=[left.result(),right.result()]
    check('Phase5 concurrent managers cannot reserve same stock',sorted(o[0]for o in outcomes)==[200,409])
    winner=next(o[1]for o in outcomes if o[0]==200)
    body={'action':'issue','request_revision':winner['request_revision'],'items':[{'id':winner['items'][0]['id'],'qty':1}]}
    key=uuid.uuid4().hex
    one=staff.call('stock_requests.php?id='+str(winner['id']),'PUT',body,headers={'X-Idempotency-Key':key})
    two=staff.call('stock_requests.php?id='+str(winner['id']),'PUT',body,headers={'X-Idempotency-Key':key})
    check('Phase5 retried partial issuance executes once',one[0]==two[0]==200 and one[1]==two[1] and one[1]['items'][0]['issued_qty']==1)
    check('Phase5 ledger issue references match their items',int(db("SELECT COUNT(*) FROM stock_movements sm JOIN stock_issues si ON sm.reference_type='stock_issue' AND sm.reference_id=si.id WHERE sm.item_key<>si.item_key;"))==0)
    check('Phase5 stock balances reconcile',int(db("SELECT COUNT(*) FROM items i WHERE i.current_qty<>COALESCE((SELECT SUM(quantity_delta) FROM stock_movements sm WHERE sm.item_key=i.item_key AND sm.balance_scope='storage'),0);"))==0)
    check('Phase5 request report renders new workflow',manager.call('reports.php?type=stock_requests&format=html')[0]==200)
    check('Phase5 staff cannot export manager report',staff.call('reports.php?type=stock_requests&format=html')[0]==403)
    good(create(purpose='=1+1',items=[{'item_key':b['item_key'],'qty':1}]))
    import csv,io
    with manager.opener.open(base+'/api/reports.php?type=stock_requests&format=csv')as response:
        exported=list(csv.reader(io.StringIO(response.read().decode('utf-8-sig'))))
    check('Phase5 CSV purpose cannot execute a spreadsheet formula',any(len(row)>4 and row[4]=="'=1+1" for row in exported))
    audit_request=good(create(items=[{'item_key':b['item_key'],'qty':1}]))
    db('RENAME TABLE audit_log TO phase5_audit_saved;')
    try:
        response=action(manager,audit_request,'approve',items=[{'id':audit_request['items'][0]['id'],'approved_qty':1}])
        check('Phase5 audit failure rejects approval',response[0]==500)
    finally:db('RENAME TABLE phase5_audit_saved TO audit_log;')
    unchanged=good(manager.call('stock_requests.php?id='+str(audit_request['id'])))
    check('Phase5 audit failure leaves no approval or reservation',unchanged['status']=='Pending' and unchanged['items'][0]['approved_qty']is None)
    storage=next(x for x in good(staff.call('locations.php'))if x['location_type']=='storage' and x['active'])
    equipment=good(manager.call('inventory.php','POST',{'label':'Test variant '+tag,'equipment_group':'Phase5 equipment '+tag,'unit':'pcs','item_type':'equipment','current_qty':4,'location_id':storage['id']}))
    er=good(create(items=[{'item_key':equipment['item_key'],'qty':2}]))
    er=good(action(manager,er,'approve',items=[{'id':er['items'][0]['id'],'approved_qty':2}]))
    er=good(action(staff,er,'issue',items=[{'id':er['items'][0]['id'],'qty':2}]))
    good(staff.call('equipment_returns.php','POST',{'item_key':equipment['item_key'],'department':dept,'qty':1,'condition':'good','returned_by':'Test employee'}))
    ei=next(x for x in good(staff.call('stock_issues.php?item='+equipment['item_key']))if x['request_item_id']==er['items'][0]['id'])
    check('Phase5 equipment cannot double-credit a returned issue',manager.call('stock_issues.php?id='+str(ei['id']),'PUT',{'action':'void','reason':'Review ambiguous reversal','request_revision':er['request_revision'],'reservation_disposition':'release'})[0]==409)
    check('Phase5 equipment return preserves original fulfillment',good(staff.call('stock_requests.php?id='+str(er['id'])))['status']=='Fulfilled')
    # Full fresh install uses the identical migration, not a different schema definition.
    fresh=(root/'sql/schema.sql').read_text(encoding='utf-8-sig')
    fresh='\n'.join(line for line in fresh.splitlines()if not line.startswith(('DROP TABLE','CREATE DATABASE','USE ')))
    sql('CREATE DATABASE phase5_fresh_test CHARACTER SET utf8mb4; USE phase5_fresh_test;\n'+fresh)
    check('Phase5 fresh install has request lines and no invented reserve',sql('USE phase5_fresh_test; SELECT COUNT(*) FROM stock_request_items; SELECT COUNT(*) FROM items WHERE protected_qty IS NOT NULL;').splitlines()==['0','0'])
    db('RENAME TABLE inventory_flow_lock TO phase5_saved_gate;')
    try:check('Phase5 partial migration fails closed',staff.call('stock_requests.php')[0]==503)
    finally:db('RENAME TABLE phase5_saved_gate TO inventory_flow_lock;')
    db('DELETE FROM inventory_flow_lock;')
    try:check('Phase5 missing singleton prevents all inventory writes',create()[0]==503)
    finally:db('INSERT INTO inventory_flow_lock VALUES(1);')
