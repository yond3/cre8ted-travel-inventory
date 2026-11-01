"""Monthly accounting against the already verified disposable database only."""
from concurrent.futures import ThreadPoolExecutor
import json
import subprocess
import threading
import time
import uuid


def run_close_issue_races(*, close_client, issue_client, db, check,
                         month, repetitions=1, stage='legacy', diagnostic=None):
    """Fresh-item races; never retry a failed operation or relax its assertion."""
    assert db('SELECT DATABASE();') == 'phase1_security_test'
    has_lines = db("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='stock_request_items';") == '1'
    assert has_lines == (stage == 'current'), 'Unexpected disposable schema stage'
    description = 'issuance and historical closing both succeed without resetting stock'
    for iteration in range(1, repetitions + 1):
        created = close_client.call('inventory.php', 'POST', {
            'label': 'Monthly race fixture ' + uuid.uuid4().hex[:12],
            'unit': 'pcs', 'current_qty': 75, 'item_type': 'consumable',
        })
        assert created[0] == 200, created[:2]
        key = created[1]['item_key']
        initial = db(f"SELECT current_qty,stock_version FROM items WHERE item_key='{key}';").split('\t')
        assert initial[0] == '75.00', 'Fresh race item has an unexpected opening balance'
        snapshot = close_client.call('close_month.php?item=' + key + '&month=' + month)
        assert snapshot[0] == 200, snapshot[:2]
        historical = {
            'month': month, 'opening_qty': 30, 'received_qty': 0, 'closing_qty': 20,
            'usage_version': snapshot[1]['usage_version'],
            'context_version': snapshot[1]['context_version'],
        }
        operations = [uuid.uuid4().hex, uuid.uuid4().hex]
        barrier = threading.Barrier(2)

        def invoke(client, path, body, operation):
            barrier.wait(timeout=10)
            started = time.monotonic()
            response = client.call(path, 'POST', body,
                                   headers={'X-Idempotency-Key': operation})
            return response, round(time.monotonic() - started, 4)

        # New clients are unnecessary: these independent sessions already use
        # different PHP processes, as in the original Phase 3 regression.
        with ThreadPoolExecutor(max_workers=2) as pool:
            closing = pool.submit(invoke, close_client, 'close_month.php?item=' + key,
                                  historical, operations[0])
            issuance = pool.submit(invoke, issue_client, 'stock_issues.php', {
                'item_key': key, 'qty': 2, 'department': 'Tour Operations',
                'purpose': 'Disposable historical-close and issuance diagnostic',
            }, operations[1])
            outcomes = [closing.result(), issuance.result()]

        values = db(f"SELECT current_qty,stock_version FROM items WHERE item_key='{key}'; "
                    f"SELECT COALESCE(SUM(quantity_delta),0),COUNT(*) FROM stock_movements WHERE item_key='{key}' AND balance_scope='storage'; "
                    f"SELECT usage_qty,usage_version FROM usage_log WHERE item_key='{key}' AND month='{month}'; "
                    f"SELECT usage_qty,closing_qty FROM month_closes WHERE item_key='{key}' AND month='{month}'; "
                    f"SELECT COUNT(*),COALESCE(SUM(qty),0) FROM stock_issues WHERE item_key='{key}' AND status='Active';")
        rows = [line.split('\t') for line in values.splitlines()]
        expected = [['73.00', str(int(initial[1]) + 1)], ['73.00', '2'], ['10.00', '1'],
                    ['10.00', '20.00'], ['1', '2.00']]
        # current_qty was the original assertion. Also prove exactly one stock
        # change and consistent monthly/ledger/issue records for this fresh item.
        succeeded = all(result[0][0] == 200 for result in outcomes) and rows == expected
        event = {
            'event': 'close_issue_race', 'stage': stage, 'iteration': iteration,
            'item_key': key, 'month': month, 'succeeded': succeeded,
            'operations': [
                {'kind': kind, 'operation_key': operation, 'status': result[0][0],
                 'error': result[0][1].get('error') if isinstance(result[0][1], dict) else None,
                 'elapsed_seconds': result[1]}
                for kind, operation, result in zip(['close', 'issue'], operations, outcomes)
            ],
            'actual_rows': rows, 'expected_rows': expected,
        }
        if not succeeded:
            # Read server diagnostics immediately, before later tests overwrite
            # the latest deadlock and before the fixture is removed.
            try:
                event['innodb_status'] = db('SHOW ENGINE INNODB STATUS;')
            except Exception as error:
                event['innodb_capture_error'] = type(error).__name__
        if diagnostic:
            diagnostic(event)
        if not succeeded:
            print('MONTHLY RACE FAILURE ' + json.dumps({k: v for k, v in event.items() if k != 'innodb_status'}), flush=True)
        label = description if stage == 'legacy' and repetitions == 1 else (
            f'{description} [{stage} {iteration}/{repetitions}]')
        check(label, succeeded)


def run_phase3(*, Client, available_port, php, web, sessions, uploads, environment,
               processes, php_log, instance_token, db, sql, check, root,
               race_repetitions=1, diagnostic=None):
    a = Client(f"http://127.0.0.1:{available_port()}")
    port = a.base.rsplit(':', 1)[1]
    process = subprocess.Popen([str(php), '-d', f'session.save_path={sessions}', '-d', 'display_errors=0',
        '-S', f'127.0.0.1:{port}', '-t', str(web)], stdout=php_log, stderr=subprocess.STDOUT,
        env=environment, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    processes.append(process)
    deadline = time.monotonic() + 10
    while True:
        try:
            assert a.call('phase1-instance.json')[1] == {'test_instance': instance_token}
            break
        except Exception:
            if process.poll() is not None or time.monotonic() > deadline: raise
            time.sleep(.1)
    a.login('maria', 'manager123')
    # Another PHP process and independent session, not just a second tab.
    b = Client(environment['PHASE3_PRIMARY_BASE'])
    b.login('admin', 'admin123')
    staff = Client(b.base)
    staff.login('juan', 'staff123')
    anonymous = Client(b.base)

    def good(result):
        assert result[0] == 200, result[:2]
        return result[1]

    def race(*calls):
        barrier = threading.Barrier(len(calls))
        def invoke(call):
            barrier.wait(timeout=10)
            return call()
        with ThreadPoolExecutor(max_workers=len(calls)) as pool:
            return list(pool.map(invoke, calls))

    def item(qty=75, equipment=False):
        body = {'label': 'Monthly fixture ' + uuid.uuid4().hex[:12], 'unit': 'pcs',
            'current_qty': qty, 'item_type': 'equipment' if equipment else 'consumable'}
        if equipment:
            body.update(equipment_group='Monthly test', location_id=int(db("SELECT id FROM locations WHERE location_type='storage' AND active=1 LIMIT 1;")))
        return good(a.call('inventory.php', 'POST', body))['item_key']

    def context(key, month):
        return good(a.call(f'close_month.php?item={key}&month={month}'))

    def payload(key, month, **changes):
        snap = context(key, month)
        return {'month': month, 'opening_qty': 30, 'received_qty': 0, 'closing_qty': 20,
            'usage_version': snap['usage_version'], 'context_version': snap['context_version'], **changes}

    def post(key, body, client=a, raw=False):
        return client.call('close_month.php?item=' + key, 'POST',
            **({'raw': json.dumps(body).encode()} if raw else {'body': body}))

    month = db("SELECT DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 2 MONTH), '%Y-%m-01');")
    previous = db("SELECT DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 3 MONTH), '%Y-%m-01');")
    following = db("SELECT DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH), '%Y-%m-01');")
    current = db("SELECT DATE_FORMAT(CURRENT_DATE, '%Y-%m-01');")
    future = db("SELECT DATE_FORMAT(DATE_ADD(CURRENT_DATE, INTERVAL 1 MONTH), '%Y-%m-01');")

    # The upgrade database was created from the pre-Phase-2 schema by that suite.
    def legacy_values():
        return sql("USE phase2_upgrade_test; SELECT * FROM month_closes ORDER BY item_key,month; SELECT item_key,month,usage_qty FROM usage_log ORDER BY item_key,month; SELECT * FROM items ORDER BY item_key; SELECT * FROM audit_log ORDER BY id;")
    before = legacy_values()
    sql('USE phase2_upgrade_test;\n' + (root / 'sql/migration_usage_consistency.sql').read_text())
    check('phase3 additive upgrade preserves all existing monthly figures, items and audits', legacy_values() == before)
    check('upgrade marks unknown provenance legacy without inventing usage', sql("USE phase2_upgrade_test; SELECT COUNT(*) FROM usage_log WHERE usage_source<>'legacy' OR usage_version<>0;") == '0')
    check('fresh install contains the exact phase3 upgrade', (root / 'sql/migration_usage_consistency.sql').read_text() in (root / 'sql/schema.sql').read_text(encoding='utf-8-sig'))

    key = item()
    check('first historical opening stays unknown instead of using current stock', context(key, month)['opening_qty'] is None)
    stock_before = db(f"SELECT current_qty,stock_version FROM items WHERE item_key='{key}'; SELECT COUNT(*) FROM stock_movements WHERE item_key='{key}';")
    saved = good(post(key, payload(key, month)))
    check('historical close saves usage and explicitly reports unchanged stock', saved['computed_usage'] == 10 and saved['stock_changed'] is False and saved['current_qty'] == 75)
    check('historical close changes neither stock, stock revision nor movement count', db(f"SELECT current_qty,stock_version FROM items WHERE item_key='{key}'; SELECT COUNT(*) FROM stock_movements WHERE item_key='{key}';") == stock_before)
    check('usage readers retain month/usage and expose source/revision', any(row['month'] == month and row['usage'] == 10 and row['usage_source'] == 'month_close' for row in good(staff.call('usage.php?item=' + key))))
    check('reopened close retains saved quantities', context(key, month)['existing_closing_qty'] == 20)
    correction = payload(key, month, closing_qty=18)
    check('correction requires a reason on the server', post(key, correction, raw=True)[0] == 400)
    correction['reason'] = 'Correct transcribed month-end count'
    changed = good(post(key, correction, raw=True))
    check('correction advances only the monthly revision', changed['usage_version'] == '2' and changed['computed_usage'] == 12 and db(f"SELECT current_qty FROM items WHERE item_key='{key}';") == '75.00')
    check('old monthly form cannot overwrite a correction', post(key, correction, raw=True)[0] == 409)
    check('direct usage cannot diverge from a saved close', a.call('usage.php?item=' + key, 'POST', {'month': month, 'usage': 99})[0] == 409)
    check('month close correction has required before/after audit and reason', db(f"SELECT COUNT(*) FROM audit_log WHERE entity_id='{key}:{month}' AND JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.reason'))='Correct transcribed month-end count' AND before_json IS NOT NULL;") == '1')

    fresh = item()
    body = payload(fresh, month)
    check('missing monthly revision fails closed', post(fresh, {k:v for k,v in body.items() if k != 'usage_version'}, raw=True)[0] == 428)
    check('missing context token fails closed', post(fresh, {k:v for k,v in body.items() if k != 'context_version'}, raw=True)[0] == 428)
    check('invalid closing arithmetic is rejected instead of clamped to zero', post(fresh, {**body, 'closing_qty': 31}, raw=True)[0] == 400)
    for value in ([], {}, True, None, '2026-02-31', '2026-01-15'):
        check(f'invalid close month is a validation error: {value!r}', post(fresh, {**body, 'month': value}, raw=True)[0] == 400)
    for selected in (current, future):
        check('unfinished/future month cannot become final usage: ' + selected, post(fresh, {**body, 'month': selected}, raw=True)[0] == 400)
        check('manual usage also rejects unfinished/future month: ' + selected, a.call('usage.php?item=' + fresh, 'POST', {'month': selected, 'usage': 1})[0] == 400)
    check('anonymous month close remains protected', anonymous.call(f'close_month.php?item={key}&month={month}')[0] == 401)
    check('Staff cannot close a month', post(fresh, body, client=staff)[0] == 403)
    equipment = item(3, equipment=True)
    check('equipment cannot be written into monthly consumable usage', a.call('usage.php?item=' + equipment, 'POST', {'month': month, 'usage': 1})[0] == 400)

    manual = item()
    check('manual usage requires a monthly revision', a.call('usage.php?item=' + manual, 'POST', raw=json.dumps({'month': month, 'usage': 0}).encode())[0] == 428)
    zero = good(a.call('usage.php?item=' + manual, 'POST', {'month': month, 'usage': 0}))
    check('recorded zero usage is preserved without filling missing months', zero['usage_version'] == '1' and len(good(a.call('usage.php?item=' + manual))) == 1)
    check('manual correction requires a reason', a.call('usage.php?item=' + manual, 'POST', raw=json.dumps({'month': month, 'usage': 1, 'usage_version': '1'}).encode())[0] == 400)
    context_before = context(manual, month)
    good(a.call('usage.php?item=' + manual, 'POST', {'month': month, 'usage': 1}))
    check('manual edit invalidates an already open close form', post(manual, {'month': month,'opening_qty':30,'received_qty':0,'closing_qty':20,'usage_version':context_before['usage_version'],'context_version':context_before['context_version'],'reason':'Review manual history'}, raw=True)[0] == 409)
    good(post(manual, payload(manual, month, reason='Replace manual history with documented closing figures')))
    check('manual history transitions into the same close-owned dataset', db(f"SELECT usage_source FROM usage_log WHERE item_key='{manual}' AND month='{month}';") == 'month_close')

    linked = item()
    good(post(linked, payload(linked, previous)))
    next_body = payload(linked, month, opening_qty=20, closing_qty=15)
    good(post(linked, payload(linked, previous, closing_qty=19, reason='Correct previous period')))
    check('previous-close correction invalidates stale suggested opening', post(linked, next_body, raw=True)[0] == 409)
    good(post(linked, payload(linked, month, opening_qty=19, closing_qty=15)))
    good(post(linked, payload(linked, following, opening_qty=15, closing_qty=10)))
    result = good(post(linked, payload(linked, month, opening_qty=19, closing_qty=14, reason='Review later period separately')))
    check('adjacent inconsistency is surfaced without cascading changes', bool(result['warnings']) and context(linked, following)['opening_qty'] == 15)
    check('report exposes monthly disagreement and adjacent-period review columns', 'Review needed' in good(a.call('reports.php?type=month_closes&format=html')) and 'Next month mismatch' in good(a.call('reports.php?type=month_closes&format=html')))

    # Explicit legacy conflicts stay unchanged until reviewed, including a close
    # whose matching usage row is missing. These are disposable fixtures only.
    legacy = item()
    db(f"INSERT INTO month_closes(item_key,month,opening_qty,received_qty,closing_qty,usage_qty) VALUES('{legacy}','{month}',30,0,20,10);")
    check('legacy close with missing usage is visibly inconsistent', context(legacy, month)['usage_conflict'] is True)
    good(post(legacy, payload(legacy, month, reason='Reconcile documented legacy close')))
    db(f"UPDATE usage_log SET usage_qty=99 WHERE item_key='{legacy}' AND month='{month}';")
    check('conflicting legacy figures are shown without silent repair', context(legacy, month)['usage_conflict'] is True and db(f"SELECT usage_qty FROM usage_log WHERE item_key='{legacy}' AND month='{month}';") == '99.00')
    good(post(legacy, payload(legacy, month, reason='Reviewed original close and corrected usage')))
    check('explicit correction reconciles both records', context(legacy, month)['usage_conflict'] is False)

    atomic = item()
    original = payload(atomic, month)
    db("CREATE TRIGGER phase3_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Disposable required audit failure';")
    failed_close = post(atomic, original)
    assert failed_close[0] == 500, failed_close[:2]
    check('audit failure rolls back both monthly datasets', db(f"SELECT COUNT(*) FROM usage_log WHERE item_key='{atomic}'; SELECT COUNT(*) FROM month_closes WHERE item_key='{atomic}';").splitlines() == ['0', '0'])
    check('audit failure also rolls back manual usage', a.call('usage.php?item=' + atomic, 'POST', {'month':month,'usage':1})[0] == 500 and db(f"SELECT COUNT(*) FROM usage_log WHERE item_key='{atomic}';") == '0')
    check('audit failure rolls back stock adjustment and movement', a.call('stock.php?item=' + atomic, 'POST', {'current':50})[0] == 500 and db(f"SELECT current_qty FROM items WHERE item_key='{atomic}';") == '75.00')
    check('audit failure rolls back inventory edit', a.call('inventory.php?item=' + atomic, 'PUT', {'current_qty':50})[0] == 500 and db(f"SELECT current_qty FROM items WHERE item_key='{atomic}';") == '75.00')
    db('DROP TRIGGER phase3_audit_failure;')
    check('failed monthly save can succeed after audit recovery', post(atomic, original)[0] == 200)
    stock_version = good(a.call('inventory.php?item=' + atomic))['stock_version']
    check('quantity change requires a server-side reason', a.call('stock.php?item=' + atomic, 'POST', raw=json.dumps({'current':50,'stock_version':stock_version}).encode())[0] == 400)
    check('inventory quantity change also requires a reason', a.call('inventory.php?item=' + atomic, 'PUT', raw=json.dumps({'current_qty':50,'stock_version':stock_version}).encode())[0] == 400)
    physical_correction = a.call('stock.php?item=' + atomic, 'POST', {'current':50, 'reason':'Current physical count correction'})
    physical_values = db(f"SELECT current_qty,stock_version FROM items WHERE item_key='{atomic}'; "
                         f"SELECT SUM(quantity_delta),COUNT(*) FROM stock_movements WHERE item_key='{atomic}'; "
                         f"SELECT COUNT(*) FROM audit_log WHERE action='stock.adjust' AND entity_id='{atomic}';")
    physical_rows = [line.split('\t') for line in physical_values.splitlines()]
    physical_ok = physical_correction[0] == 200 and physical_rows[0][0] == '50.00' and physical_rows[1][0] == '50.00'
    physical_event = {
        'event': 'physical_correction', 'item_key': atomic, 'succeeded': physical_ok,
        'status': physical_correction[0], 'actual_rows': physical_rows,
        'response': {key: physical_correction[1].get(key) for key in ('status', 'current', 'error')}
                    if isinstance(physical_correction[1], dict) else None,
    }
    if diagnostic:
        diagnostic(physical_event)
    if not physical_ok:
        print('PHYSICAL CORRECTION FAILURE ' + json.dumps(physical_event), flush=True)
    good(physical_correction)
    check('current physical correction still uses the stock ledger', physical_ok)

    simultaneous = item()
    shared = payload(simultaneous, month)
    db('CREATE TRIGGER phase3_overlap BEFORE INSERT ON usage_log FOR EACH ROW DO SLEEP(0.15);')
    outcomes = race(lambda: post(simultaneous, shared), lambda: post(simultaneous, shared, client=b))
    check('concurrent closes allow one monthly revision and reject the other', sorted(r[0] for r in outcomes) == [200,409])
    retry = item()
    shared = payload(retry, month)
    operation = uuid.uuid4().hex
    def replay(client):
        return client.call('close_month.php?item=' + retry, 'POST', shared, headers={'X-Idempotency-Key': operation})
    # Operation keys are actor-scoped, so use a second independently logged-in manager.
    manager2 = Client(b.base)
    manager2.login('maria','manager123')
    outcomes = race(lambda: replay(a), lambda: replay(manager2))
    check('same-actor retry replays one historical close after response loss', all(r[0] == 200 for r in outcomes) and outcomes[0][1] == outcomes[1][1] and db(f"SELECT usage_version FROM usage_log WHERE item_key='{retry}' AND month='{month}';") == '1')
    manual_race = item()
    outcomes = race(lambda: a.call('usage.php?item=' + manual_race,'POST',{'month':month,'usage':1,'usage_version':'0'}), lambda: b.call('usage.php?item=' + manual_race,'POST',{'month':month,'usage':2,'usage_version':'0'}))
    check('concurrent manual entries cannot overwrite the same monthly revision', sorted(r[0] for r in outcomes) == [200,409])
    run_close_issue_races(close_client=a, issue_client=b, db=db,
                         check=check, month=month, repetitions=race_repetitions,
                         diagnostic=diagnostic)
    db('DROP TRIGGER phase3_overlap;')

    insights_key = item()
    old_month = db("SELECT DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH), '%Y-%m-01');")
    db(f"INSERT INTO usage_log(item_key,month,usage_qty) VALUES('{insights_key}','{old_month}',999),('{insights_key}','{month}',0),('{insights_key}','{current}',888);")
    insights = good(a.call('demand_insights.php?limit=20'))
    # Rank the fixture into the response using a second valid recent month.
    db(f"INSERT INTO usage_log(item_key,month,usage_qty) VALUES('{insights_key}','{following}',1000);")
    rows = good(a.call('demand_insights.php?limit=20'))['items']
    row = next(r for r in rows if r['item_key'] == insights_key)
    check('demand averages use completed calendar periods and preserve recorded zero', row['months_of_history'] == 2 and row['avg_monthly_usage'] == 500 and row['missing_months_in_window'] == 4)
    check('all stock balances still reconcile after monthly corrections', db("SELECT COUNT(*) FROM items i WHERE i.current_qty <> COALESCE((SELECT SUM(quantity_delta) FROM stock_movements sm WHERE sm.item_key=i.item_key AND sm.balance_scope='storage'),0);") == '0')
    check('equipment and procurement reports remain available', a.call('reports.php?type=equipment_movements&format=html')[0] == 200 and a.call('reports.php?type=purchase_orders&format=html')[0] == 200)
    return a, b
