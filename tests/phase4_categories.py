"""Phase 4 checks: only called inside the disposable regression runner."""
import json
import uuid
import subprocess
import time
import threading
from concurrent.futures import ThreadPoolExecutor


def run_phase4(*, Client, base, db, sql, check, root, available_port, php, web, sessions, environment, processes, php_log, instance_token):
    manager, admin, staff, anonymous = [Client(base) for _ in range(4)]
    manager.login('maria', 'manager123')
    admin.login('admin', 'admin123')
    staff.login('juan', 'staff123')

    def good(response):
        assert response[0] == 200, response[:2]
        return response[1]

    def create(name, parent=None):
        return good(manager.call('inventory_categories.php', 'POST', {'name': name, 'parent_id': parent}))

    def update(row, **fields):
        return manager.call('inventory_categories.php?id=' + str(row['id']), 'PUT', {'revision': row['revision'], **fields})

    secondary = Client(f'http://127.0.0.1:{available_port()}')
    process = subprocess.Popen([str(php), '-d', f'session.save_path={sessions}', '-d', 'display_errors=0', '-S', secondary.base.removeprefix('http://'), '-t', str(web)], stdout=php_log, stderr=subprocess.STDOUT, env=environment, creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    processes.append(process)
    deadline = time.monotonic() + 10
    while True:
        try:
            assert secondary.call('phase1-instance.json')[1] == {'test_instance':instance_token}
            break
        except Exception:
            if process.poll() is not None or time.monotonic() > deadline: raise
            time.sleep(.1)
    secondary.login('admin','admin123')
    def race(*calls):
        barrier = threading.Barrier(len(calls))
        def invoke(call):
            barrier.wait(timeout=10)
            return call()
        with ThreadPoolExecutor(max_workers=len(calls)) as pool:
            return list(pool.map(invoke,calls))

    token = uuid.uuid4().hex[:8]
    roots = good(staff.call('inventory_categories.php'))
    check('category seed is readable by internal staff', len(roots) == 15)
    check('anonymous cannot read private taxonomy', anonymous.call('inventory_categories.php')[0] == 401)
    check('staff cannot manage taxonomy', staff.call('inventory_categories.php', 'POST', {'name': 'Forbidden'})[0] == 403)
    check('blank category rejected', manager.call('inventory_categories.php', 'POST', {'name': ' '})[0] == 400)
    check('Unicode whitespace-only category rejected', manager.call('inventory_categories.php', 'POST', {'name': '\u00a0\u2003'})[0] == 400)
    check('malformed category name rejected', manager.call('inventory_categories.php', 'POST', {'name': ['bad']})[0] == 400)
    parent = create('Review ' + token)
    child = create('Child ' + token, parent['id'])
    check('root and child categories created', child['parent_id'] == parent['id'])
    check('duplicate roots rejected including casing and whitespace', manager.call('inventory_categories.php', 'POST', {'name': '  REVIEW   ' + token})[0] == 409)
    check('duplicate siblings rejected', manager.call('inventory_categories.php', 'POST', {'name': child['name'], 'parent_id': parent['id']})[0] == 409)
    check('third level rejected', manager.call('inventory_categories.php', 'POST', {'name': 'Too deep', 'parent_id': child['id']})[0] == 400)
    check('missing parent rejected', manager.call('inventory_categories.php', 'POST', {'name': 'Orphan', 'parent_id': 2147483647})[0] == 400)
    check('self parent rejected', update(parent, parent_id=parent['id'])[0] == 400)
    check('cycle rejected', update(parent, parent_id=child['id'])[0] == 400)
    check('root with active children cannot archive', update(parent, active=False)[0] == 409)
    check('missing category revision rejected', manager.call('inventory_categories.php?id=' + str(parent['id']), 'PUT', {'name': parent['name']})[0] == 428)
    child = good(update(child, name='Renamed ' + token))
    check('stale category edit rejected', update({**child, 'revision': 0}, name='Lost update')[0] == 409)
    check('invalid active input rejected', update(child, active='false')[0] == 400)

    key = good(manager.call('inventory.php', 'POST', {'label': 'Category Sample ' + token, 'unit': 'pcs', 'current_qty': 12, 'category_id': child['id']}))['item_key']
    row = good(manager.call('inventory.php?item=' + key))
    check('new item uses existing inventory endpoint with category', row['category_id'] == child['id'] and row['category_parent_id'] == parent['id'])
    before = db(f"SELECT current_qty FROM items WHERE item_key='{key}'; SELECT COUNT(*) FROM stock_movements WHERE item_key='{key}'; SELECT COUNT(*) FROM usage_log WHERE item_key='{key}';")
    check('parent category includes child items', key in [r['item_key'] for r in good(staff.call('inventory.php?category=' + str(parent['id'])))])
    check('uncategorized filter excludes assigned items', key not in [r['item_key'] for r in good(staff.call('inventory.php?category=uncategorized'))])
    check('invalid category filter rejected', staff.call('inventory.php?category=bad')[0] == 400)
    check('requestable catalog shares item category', key in [r['item_key'] for r in good(staff.call('stock_requests.php?requestable=1&category=' + str(parent['id'])))])
    public = good(anonymous.call('vendor_applications.php?quotable=1&category=' + str(parent['id'])))
    check('vendor quotable catalog shares classification only', any(r['item_key'] == key for r in public) and all('current_qty' not in r and 'stock_version' not in r for r in public))
    check('forecast selector retains category metadata', any(r['item_key'] == key and r['category_id'] == child['id'] for r in good(staff.call('items.php'))))
    check('category assignment requires revision', manager.call('inventory.php?item=' + key, 'PUT', {'category_id': None})[0] == 428)
    changed = good(manager.call('inventory.php?item=' + key, 'PUT', {'category_id': None, 'category_version': row['category_version']}))
    check('explicit null clears assignment', changed['category_id'] is None)
    check('stale item classification rejected', manager.call('inventory.php?item=' + key, 'PUT', {'category_id': child['id'], 'category_version': row['category_version']})[0] == 409)
    changed = good(manager.call('inventory.php?item=' + key, 'PUT', {'category_id': child['id'], 'category_version': changed['category_version']}))
    good(manager.call('inventory.php?item=' + key, 'PUT', {'unit': 'packs'}))
    check('old edit payload preserves assignment', good(manager.call('inventory.php?item=' + key))['category_id'] == child['id'])
    after = db(f"SELECT current_qty FROM items WHERE item_key='{key}'; SELECT COUNT(*) FROM stock_movements WHERE item_key='{key}'; SELECT COUNT(*) FROM usage_log WHERE item_key='{key}';")
    check('classification changes no balance, movements or usage', before == after)
    request = good(staff.call('stock_requests.php','POST',{'item_key':key,'qty':2,'department':'Tour Operations'}))
    good(staff.call('stock_requests.php?id='+str(request['id']),'PUT',{'action':'fulfill'}))
    purchase = good(staff.call('purchase_requests.php','POST',{'item_key':key,'qty':3,'request_type':'consumable'}))
    good(manager.call('purchase_requests.php?id='+str(purchase['id']),'PUT',{'action':'approve'}))
    good(manager.call('purchase_orders.php','POST',{'request_id':purchase['id'],'amount':30}))
    good(manager.call('inventory_retirements.php','POST',{'item_key':key,'qty':1,'reason':'other','notes':'Disposable category history check'}))
    good(admin.call('inventory.php?item='+key,'PUT',{'active':False}))
    check('staff active catalog still excludes inactive items',key not in [r['item_key'] for r in good(staff.call('inventory.php'))])
    for endpoint in ('stock_requests.php','purchase_requests.php','purchase_orders.php','stock_issues.php','inventory_retirements.php'):
        history = [r for r in good(staff.call(endpoint)) if r.get('item_key') == key]
        check('inactive item retains authorized category history: '+endpoint,bool(history) and all(r['category_id']==child['id'] and r['category_parent_id']==parent['id'] for r in history))
    good(admin.call('inventory.php?item='+key,'PUT',{'active':True}))
    equipment = good(manager.call('inventory.php','POST',{'label':'Review variant '+token,'equipment_group':'Category review '+token,'unit':'pcs','item_type':'equipment','current_qty':2,'location_id':int(db("SELECT id FROM locations WHERE location_type='storage' AND active=1 LIMIT 1;")),'category_id':child['id']}))
    good(staff.call('stock_issues.php','POST',{'item_key':equipment['item_key'],'qty':1,'department':'Tour Operations'}))
    good(admin.call('inventory.php?item='+equipment['item_key'],'PUT',{'active':False}))
    movements = good(staff.call('equipment_movements.php?item='+equipment['item_key']))
    check('inactive equipment movements retain current classification',bool(movements) and all(r['category_id']==child['id'] and r['item_type']=='equipment' for r in movements))
    child = good(update(child, active=False))
    check('archived categories remain readable', any(r['id'] == child['id'] for r in good(staff.call('inventory_categories.php'))))
    check('assigned archived items remain requestable', key in [r['item_key'] for r in good(staff.call('stock_requests.php?requestable=1'))])
    good(manager.call('inventory.php?item=' + key, 'PUT', {'category_id': child['id'], 'category_version': changed['category_version']}))
    other = good(manager.call('inventory.php', 'POST', {'label': 'Unclassified Example ' + token, 'unit': 'pcs', 'current_qty': 1}))
    check('old create payload stays usable and uncategorized', other['category_id'] is None)
    check('new assignment to archived category rejected', manager.call('inventory.php?item=' + other['item_key'], 'PUT', {'category_id': child['id'], 'category_version': 0})[0] == 400)
    check('unknown category assignment rejected', manager.call('inventory.php?item=' + other['item_key'], 'PUT', {'category_id': 2147483647, 'category_version': 0})[0] == 400)
    check('staff cannot change classification', staff.call('inventory.php?item=' + key, 'PUT', {'category_id': None, 'category_version': 0})[0] == 403)
    check('archive parent after children archived', update(parent, active=False)[0] == 200)
    check('reactivate child under archived parent rejected', update(child, active=True)[0] == 400)
    report = manager.call('reports.php?type=inventory&format=html&category=' + str(parent['id']))
    check('inventory category report remains available', report[0] == 200)
    parallel = create('Concurrent ' + token)
    results = race(lambda: update(parallel, name='Manager ' + token), lambda: secondary.call('inventory_categories.php?id=' + str(parallel['id']), 'PUT', {'revision':0, 'name':'Admin ' + token}))
    check('concurrent category edits prevent lost updates', sorted(r[0] for r in results) == [200,409])
    results = race(lambda: manager.call('inventory_categories.php','POST',{'name':'Duplicate race ' + token}),lambda: secondary.call('inventory_categories.php','POST',{'name':'Duplicate race ' + token}))
    check('concurrent root creation preserves database uniqueness', sorted(r[0] for r in results) == [200,409])
    current = good(manager.call('inventory.php?item=' + key))
    results = race(lambda: manager.call('inventory.php?item=' + key, 'PUT', {'category_id':None,'category_version':current['category_version']}),lambda: secondary.call('inventory.php?item=' + key, 'PUT', {'category_id':5,'category_version':current['category_version']}))
    check('concurrent assignments prevent lost classification', sorted(r[0] for r in results) == [200,409])
    current = good(manager.call('inventory.php?item=' + key))
    # Restore archived classification only inside the disposable database for the rollback case.
    db(f"UPDATE items SET category_id={child['id']} WHERE item_key='{key}';")
    # Audit storage failure must abort both taxonomy and item edits.
    db("CREATE TRIGGER phase4_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Disposable audit failure';")
    response = manager.call('inventory_categories.php', 'POST', {'name': 'Rollback ' + token})
    check('audit failure rolls back category creation', response[0] >= 500 and db(f"SELECT COUNT(*) FROM inventory_categories WHERE name='Rollback {token}';") == '0')
    current = good(manager.call('inventory.php?item=' + key))
    response = manager.call('inventory.php?item=' + key, 'PUT', {'category_id': None, 'category_version': current['category_version']})
    check('audit failure rolls back item assignment', response[0] >= 500 and good(manager.call('inventory.php?item=' + key))['category_id'] == child['id'])
    db('DROP TRIGGER phase4_audit_failure;')
    # Unapplied schema compatibility: rename only inside this disposable server.
    db('ALTER TABLE items CHANGE COLUMN category_id saved_category_id INT NULL, CHANGE COLUMN category_version saved_category_version BIGINT UNSIGNED NOT NULL DEFAULT 0;')
    check('unmigrated application still loads existing inventory', staff.call('inventory.php')[0] == 200)
    check('unmigrated taxonomy read is empty without breaking login', good(staff.call('inventory_categories.php')) == [])
    check('unmigrated category write fails clearly', manager.call('inventory_categories.php','POST',{'name':'Not ready'})[0] == 503)
    check('unmigrated assignment fails without stock changes', manager.call('inventory.php?item=' + key,'PUT',{'category_id':5,'category_version':0})[0] == 503)
    db('ALTER TABLE items CHANGE COLUMN saved_category_id category_id INT NULL, CHANGE COLUMN saved_category_version category_version BIGINT UNSIGNED NOT NULL DEFAULT 0;')
    # Upgrade separately from fresh install, on the same already verified disposable server.
    schema = (root / 'sql/schema.sql').read_text(encoding='utf-8-sig').split('-- Phase 4 additive upgrade.')[0]
    schema = '\n'.join(line for line in schema.splitlines() if not line.startswith(('DROP TABLE','CREATE DATABASE','USE ')))
    sql('CREATE DATABASE phase4_upgrade_test CHARACTER SET utf8mb4; USE phase4_upgrade_test;\n' + schema)
    original = sql('USE phase4_upgrade_test; SELECT COUNT(*),SUM(current_qty) FROM items; SELECT COUNT(*) FROM usage_log; SELECT COUNT(*) FROM stock_requests; SELECT COUNT(*) FROM purchase_orders;')
    sql('USE phase4_upgrade_test;\n' + (root / 'sql/migration_inventory_categories.sql').read_text())
    upgraded = sql('USE phase4_upgrade_test; SELECT COUNT(*),SUM(current_qty) FROM items; SELECT COUNT(*) FROM usage_log; SELECT COUNT(*) FROM stock_requests; SELECT COUNT(*) FROM purchase_orders;')
    check('category upgrade preserves existing counts and stock', original == upgraded)
    check('upgrade leaves all original items unclassified', sql('USE phase4_upgrade_test; SELECT COUNT(*) FROM items WHERE category_id IS NOT NULL OR category_version<>0;') == '0')
