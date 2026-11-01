"""Phase 2 checks, run by phase1_security.py --phase2 in its disposable database.

Multiple independent PHP processes and login sessions are essential on Windows:
two HTTP calls to one built-in server would only test sequential behavior.
"""
from concurrent.futures import ThreadPoolExecutor
import json
import subprocess
import threading
import time
import uuid


def run_phase2(*, Client, available_port, php, web, sessions, uploads, environment,
               processes, php_log, instance_token, db, sql, check, root):
    clients = []
    for username, password in (("maria", "manager123"), ("maria", "manager123"), ("admin", "admin123")):
        port = available_port()
        process = subprocess.Popen([
            str(php), "-d", f"session.save_path={sessions}", "-d", "display_errors=0",
            "-d", f"upload_tmp_dir={uploads}", "-d", "log_errors=1", "-S", f"127.0.0.1:{port}", "-t", str(web),
        ], stdout=php_log, stderr=subprocess.STDOUT, env=environment,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        processes.append(process)
        client = Client(f"http://127.0.0.1:{port}")
        deadline = time.monotonic() + 10
        while True:
            try:
                assert process.poll() is None
                assert client.call("phase1-instance.json")[1] == {"test_instance": instance_token}
                break
            except Exception:
                if process.poll() is not None or time.monotonic() > deadline:
                    raise
                time.sleep(0.1)
        client.login(username, password)
        clients.append(client)
    a, b, admin = clients

    def race(*calls):
        barrier = threading.Barrier(len(calls))
        def invoke(call):
            barrier.wait(timeout=10)
            return call()
        with ThreadPoolExecutor(max_workers=len(calls)) as pool:
            return list(pool.map(invoke, calls))

    def good(result):
        assert result[0] == 200, result[:2]
        return result[1]

    def item(quantity=10, equipment=False):
        body = {"label": "Fixture " + uuid.uuid4().hex[:10], "unit": "pcs",
                "current_qty": quantity, "item_type": "equipment" if equipment else "consumable"}
        if equipment:
            body.update(equipment_group="Phase Two", location_id=int(db("SELECT id FROM locations WHERE location_type='storage' AND active=1 LIMIT 1;")))
        return good(a.call("inventory.php", "POST", body))["item_key"]

    def balance(key):
        return float(db(f"SELECT current_qty FROM items WHERE item_key='{key}';"))

    def snapshot(key):
        return good(a.call("inventory.php?item=" + key))

    department = "Tour Operations"
    def issue(client, key, qty=1, op=None):
        return client.call("stock_issues.php", "POST", {"item_key": key, "qty": qty, "department": department},
                           headers={"X-Idempotency-Key": op or uuid.uuid4().hex})

    def stock_request(key, qty=1):
        return good(a.call("stock_requests.php", "POST", {"item_key": key, "qty": qty, "department": department}))["id"]

    def purchase(key, qty=3, proof=True):
        pr = good(a.call("purchase_requests.php", "POST", {"item_key": key, "qty": qty, "request_type": "consumable"}))["id"]
        good(a.call(f"purchase_requests.php?id={pr}", "PUT", {"action": "approve"}))
        po = good(a.call("purchase_orders.php", "POST", {"request_id": pr, "amount": 30}))["id"]
        if proof:
            good(a.call(f"purchase_orders.php?id={po}", "PUT", {"action": "declare_receipt_lost", "actual_amount": 30, "note": "Disposable concurrency fixture receipt"}))
        return pr, po

    def outcome(results):
        return sorted(result[0] for result in results)

    def upload(client, po):
        boundary = 'phase2-' + uuid.uuid4().hex
        content = (f'--{boundary}\r\nContent-Disposition: form-data; name="amount"\r\n\r\n30\r\n'
                   f'--{boundary}\r\nContent-Disposition: form-data; name="receipt"; filename="fixture.pdf"\r\n'
                   'Content-Type: application/pdf\r\n\r\n%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n'
                   f'\r\n--{boundary}--\r\n').encode()
        return client.call(f'receipts.php?po_id={po}', 'POST', raw=content,
                           headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})

    # Verify the safe upgrade separately from a fresh schema installation.
    full_schema = (root / "sql/schema.sql").read_text(encoding="utf-8-sig")
    old_schema = full_schema.split("-- Stock integrity structures and cutover balances")[0]
    old_schema = "\n".join(line for line in old_schema.splitlines()
                           if not line.startswith(("DROP TABLE", "CREATE DATABASE", "USE ")))
    sql("CREATE DATABASE phase2_upgrade_test CHARACTER SET utf8mb4; USE phase2_upgrade_test;\n" + old_schema)
    before = sql("USE phase2_upgrade_test; SELECT COUNT(*), SUM(current_qty) FROM items; SELECT COUNT(*) FROM stock_issues; SELECT COUNT(*) FROM purchase_orders;")
    migration = (root / "sql/migration_stock_integrity.sql").read_text(encoding="utf-8")
    sql("USE phase2_upgrade_test;\n" + migration)
    after = sql("USE phase2_upgrade_test; SELECT COUNT(*), SUM(current_qty) FROM items; SELECT COUNT(*) FROM stock_issues; SELECT COUNT(*) FROM purchase_orders;")
    check("phase2 migration preserves existing records and stock", before == after)
    check("phase2 migration baselines match stored inventory", sql("USE phase2_upgrade_test; SELECT COUNT(*) FROM items i JOIN stock_movements sm ON sm.item_key=i.item_key AND sm.balance_scope='storage' WHERE sm.quantity_after <> i.current_qty;") == "0")

    # Delay writes in this disposable database to make overlap repeatable.
    db("CREATE TRIGGER phase2_overlap BEFORE UPDATE ON items FOR EACH ROW DO SLEEP(0.12);")
    key = item(5)
    results = race(lambda: issue(a, key, 4), lambda: issue(b, key, 4))
    check("concurrent issuances cannot overdraw remaining stock", outcome(results) == [200, 409] and balance(key) == 1)

    key = item(10)
    op = uuid.uuid4().hex
    results = race(lambda: issue(a, key, 3, op), lambda: issue(b, key, 3, op))
    check("same operation key returns one issue to simultaneous retries", outcome(results) == [200, 200] and results[0][1]["id"] == results[1][1]["id"] and balance(key) == 7)
    check("operation key cannot be reused for changed payload", issue(a, key, 2, op)[0] == 409 and balance(key) == 7)
    check("completed issue retry after response loss is replayed", good(issue(a, key, 3, op))["id"] == results[0][1]["id"] and balance(key) == 7)
    payload = {"item_key": key, "qty": 1, "department": department}
    check("unbound stock writes require an operation identifier", a.call("stock_issues.php", "POST", payload, headers={"X-Idempotency-Key": ""})[0] == 400)

    key = item(10)
    rid = stock_request(key, 3)
    path = f"stock_requests.php?id={rid}"
    results = race(lambda: a.call(path, "PUT", {"action": "fulfill"}), lambda: b.call(path, "PUT", {"action": "fulfill"}))
    check("concurrent fulfillment produces exactly one deduction", outcome(results) == [200, 409] and balance(key) == 7 and db(f"SELECT COUNT(*) FROM stock_issues WHERE item_key='{key}';") == "1")
    issue_id = next(result[1]["fulfilled_issue_id"] for result in results if result[0] == 200)
    path = f"stock_issues.php?id={issue_id}"
    results = race(lambda: a.call(path, "PUT", {"action": "void"}), lambda: b.call(path, "PUT", {"action": "void"}))
    check("concurrent issue reversal restores quantity once", outcome(results) == [200, 409] and balance(key) == 10)

    key = item(10)
    rid = stock_request(key, 3)
    path = f"stock_requests.php?id={rid}"
    results = race(lambda: a.call(path, "PUT", {"action": "fulfill"}), lambda: b.call(path, "PUT", {"action": "cancel"}))
    status = db(f"SELECT status FROM stock_requests WHERE id={rid};")
    check("fulfillment and cancellation cannot both succeed", outcome(results) == [200, 409] and ((status == "Fulfilled" and balance(key) == 7) or (status == "Cancelled" and balance(key) == 10)))

    key = item(10)
    version = snapshot(key)["stock_version"]
    good(issue(b, key, 2))
    check("stale inventory form cannot overwrite newer issuance", a.call(f"inventory.php?item={key}", "PUT", {"current_qty": 10, "stock_version": version})[0] == 409 and balance(key) == 8)
    check("stock endpoint rejects the same stale revision", a.call(f"stock.php?item={key}", "POST", {"current": 10, "stock_version": version})[0] == 409)
    completed = db("SELECT DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH), '%Y-%m-01');")
    check("historical close cannot overwrite issuance even with an old stock revision", a.call(f"close_month.php?item={key}", "POST", {"month": completed, "opening_qty": 10, "received_qty": 0, "closing_qty": 10, "stock_version": version})[0] == 200 and balance(key) == 8)
    current = snapshot(key)["stock_version"]
    results = race(lambda: a.call(f"stock.php?item={key}", "POST", {"current": 7, "stock_version": current}), lambda: b.call(f"stock.php?item={key}", "POST", {"current": 6, "stock_version": current}))
    check("simultaneous absolute edits require a fresh revision", outcome(results) == [200, 409])

    key = item(5)
    pr, po = purchase(key, 3)
    path = f"purchase_orders.php?id={po}"
    results = race(lambda: a.call(path, "PUT", {"action": "receive"}), lambda: b.call(path, "PUT", {"action": "receive"}))
    check("concurrent PO receiving credits inventory once", outcome(results) == [200, 409] and balance(key) == 8)
    check("receiving keeps PO and purchase request statuses consistent", db(f"SELECT status FROM purchase_orders WHERE id={po};") == "Received" and db(f"SELECT status FROM purchase_requests WHERE id={pr};") == "Completed")
    key = item(5)
    pr, po = purchase(key, 3)
    path = f"purchase_orders.php?id={po}"
    results = race(lambda: a.call(path, "PUT", {"action": "receive"}), lambda: admin.call(path, "PUT", {"action": "cancel"}))
    state = db(f"SELECT status FROM purchase_orders WHERE id={po};")
    check("receiving and cancellation cannot both succeed", outcome(results) == [200, 409] and ((state == "Received" and balance(key) == 8) or (state == "Cancelled" and balance(key) == 5)))

    key = item(5)
    pr = good(a.call("purchase_requests.php", "POST", {"item_key": key, "qty": 2, "request_type": "consumable"}))["id"]
    good(a.call(f"purchase_requests.php?id={pr}", "PUT", {"action": "approve"}))
    results = race(lambda: a.call("purchase_orders.php", "POST", {"request_id": pr}), lambda: b.call("purchase_orders.php", "POST", {"request_id": pr}))
    check("simultaneous PO creation leaves one active PO", outcome(results) == [200, 409] and db(f"SELECT COUNT(*) FROM purchase_orders WHERE request_id={pr} AND status='Placed';") == "1")

    # Equipment storage and department balances use the same item lock.
    key = item(6, equipment=True)
    good(issue(a, key, 4))
    body = {"item_key": key, "qty": 3, "department": department, "condition": "good"}
    results = race(lambda: a.call("equipment_returns.php", "POST", body), lambda: b.call("equipment_returns.php", "POST", body))
    check("concurrent returns cannot exceed deployed equipment", outcome(results) == [200, 409] and balance(key) == 5 and db(f"SELECT qty FROM equipment_deployments WHERE item_key='{key}' AND department='{department}';") == "1.00")
    body = {"item_key": key, "qty": 1, "department": department, "source": "department", "reason": "broken"}
    results = race(lambda: a.call("inventory_retirements.php", "POST", body), lambda: b.call("inventory_retirements.php", "POST", body))
    check("concurrent retirement cannot remove department equipment twice", outcome(results) == [200, 409] and db(f"SELECT COUNT(*) FROM equipment_deployments WHERE item_key='{key}';") == "0")
    check("fractional equipment issuance is rejected", issue(a, key, 0.5)[0] == 400)

    # Required history failure must roll back the business row and replay key too.
    key = item(5)
    op = uuid.uuid4().hex
    db("CREATE TRIGGER phase2_history_failure BEFORE INSERT ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected test failure';")
    result = issue(a, key, 2, op)
    check("history failure rolls back deduction and issue record", result[0] == 500 and balance(key) == 5 and db(f"SELECT COUNT(*) FROM stock_issues WHERE item_key='{key}';") == "0")
    check("failed operation does not consume its retry key", db(f"SELECT COUNT(*) FROM inventory_operations WHERE operation_key='{op}';") == "0")
    db("DROP TRIGGER phase2_history_failure;")
    good(issue(a, key, 2, op))
    check("same operation can succeed after a rolled-back failure", balance(key) == 3)

    key = item(0)
    pr, po = purchase(key, 3)
    db("CREATE TRIGGER phase2_history_failure BEFORE INSERT ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected test failure';")
    result = a.call(f"purchase_orders.php?id={po}", "PUT", {"action": "receive"})
    check("receiving failure rolls back stock and both statuses", result[0] == 500 and balance(key) == 0 and db(f"SELECT status FROM purchase_orders WHERE id={po};") == "Placed" and db(f"SELECT status FROM purchase_requests WHERE id={pr};") == "Ordered")
    db("DROP TRIGGER phase2_history_failure;")
    good(a.call(f"purchase_orders.php?id={po}", "PUT", {"action": "receive"}))

    # Receipt approval state is rechecked under the same PO lock as receiving.
    key = item(5)
    pr, po = purchase(key, 3, proof=False)
    good(upload(a, po))
    path = f"purchase_orders.php?id={po}"
    results = race(lambda: a.call(path, "PUT", {"action": "receive"}),
                   lambda: b.call(path, "PUT", {"action": "reject_receipt", "note": "Disposable fixture receipt rejected"}))
    state = db(f"SELECT status FROM purchase_orders WHERE id={po};")
    rejected = db(f"SELECT receipt_rejected FROM purchase_orders WHERE id={po};")
    check("receipt rejection cannot race past PO receiving", outcome(results) == [200, 409] and
          ((state == 'Received' and rejected == '0' and balance(key) == 8) or
           (state == 'Placed' and rejected == '1' and balance(key) == 5)))

    key = item(5)
    pr, po = purchase(key, 3, proof=False)
    good(upload(a, po))
    good(a.call(f"purchase_orders.php?id={po}", "PUT", {"action": "reject_receipt", "note": "Replace disposable fixture receipt"}))
    results = race(lambda: upload(a, po), lambda: upload(b, po))
    check("concurrent receipt replacement keeps one current file", outcome(results) == [200, 409] and
          len(list((web / 'uploads' / 'receipts').glob(f'po{po}-*'))) == 1)
    check("replacement clears rejection and still permits receiving", db(f"SELECT receipt_rejected FROM purchase_orders WHERE id={po};") == '0' and
          a.call(f"purchase_orders.php?id={po}", "PUT", {"action": "receive"})[0] == 200)

    # Concurrent creation on different items exercises the shared counter.
    key, other = item(5), item(5)
    db("UPDATE code_counters SET counter_value=999 WHERE prefix='ISS' AND code_year=YEAR(CURRENT_DATE);")
    results = race(lambda: issue(a, key), lambda: issue(b, other))
    check("concurrent code allocation is unique beyond three digits", outcome(results) == [200, 200] and
          {int(result[1]['issue_code'].split('-')[-1]) for result in results} == {1000, 1001})

    # Retry identities tolerate JSON property ordering but never changed values.
    op = uuid.uuid4().hex
    first = good(issue(a, key, 1, op))
    reordered = {'department': department, 'qty': 1, 'item_key': key}
    check("retry fingerprint ignores JSON property ordering", good(a.call('stock_issues.php', 'POST', reordered,
          headers={'X-Idempotency-Key': op}))['id'] == first['id'])
    check("absolute stock edit requires a revision", a.call(f'stock.php?item={key}', 'POST', raw=json.dumps({'current': 1}).encode())[0] == 428)

    key = item(10)
    version = snapshot(key)['stock_version']
    context = good(a.call(f'close_month.php?item={key}&month={completed}'))
    body = {'month': completed, 'opening_qty': 10, 'received_qty': 0, 'closing_qty': 8,
            'usage_version': context['usage_version'], 'context_version': context['context_version']}
    results = race(lambda: a.call(f'close_month.php?item={key}', 'POST', body), lambda: b.call(f'close_month.php?item={key}', 'POST', body))
    assert outcome(results) == [200, 409], results
    check("concurrent month close saves only one monthly revision without changing stock", balance(key) == 10)

    key = item(99999999)
    pr, po = purchase(key, 3)
    check("receiving cannot overflow the storage quantity", a.call(f'purchase_orders.php?id={po}', 'PUT', {'action': 'receive'})[0] == 409 and
          balance(key) == 99999999 and db(f'SELECT status FROM purchase_orders WHERE id={po};') == 'Placed')

    # Both existing equipment receiving destinations remain supported.
    for deploy in (False, True):
        key = item(0, equipment=True)
        request = good(a.call('purchase_requests.php', 'POST', {'item_key': key, 'qty': 2, 'request_type': 'equipment',
            'department': department, 'reason': 'new_need' if deploy else 'stock_up'}))
        good(a.call(f"purchase_requests.php?id={request['id']}", 'PUT', {'action': 'approve'}))
        order = good(a.call('purchase_orders.php', 'POST', {'request_id': request['id'], 'amount': 30}))
        good(a.call(f"purchase_orders.php?id={order['id']}", 'PUT', {'action': 'declare_receipt_lost', 'actual_amount': 30, 'note': 'Disposable equipment receiving fixture'}))
        result = good(a.call(f"purchase_orders.php?id={order['id']}", 'PUT', {'action': 'receive', 'deploy_to_department': deploy}))
        check(f"equipment receiving retains {'department' if deploy else 'storage'} destination", result['received_to'] == ('department' if deploy else 'storage') and balance(key) == (0 if deploy else 2))
        if deploy:
            good(a.call('equipment_returns.php', 'POST', {'item_key': key, 'qty': 1, 'department': department, 'condition': 'damaged'}))
            good(a.call('equipment_returns.php', 'POST', {'item_key': key, 'qty': 1, 'department': department, 'condition': 'broken'}))
            check("damaged return restores storage; broken return writes off equipment", balance(key) == 1 and db(f"SELECT COUNT(*) FROM equipment_deployments WHERE item_key='{key}';") == '0' and
                  db(f"SELECT COUNT(*) FROM inventory_retirements WHERE item_key='{key}' AND reason='broken';") == '1')

    # Authorization still precedes replay access, including a changed department scope.
    scoped = Client(a.base)
    scoped.login('fleet_dept', 'staff123')
    key = item(10)
    op = uuid.uuid4().hex
    body = {'item_key': key, 'qty': 1}
    good(scoped.call('stock_requests.php', 'POST', body, headers={'X-Idempotency-Key': op}))
    original_department = db("SELECT department FROM users WHERE username='fleet_dept';")
    db("UPDATE users SET department='Tour Operations' WHERE username='fleet_dept';")
    check("replay key cannot expose records after a department reassignment", scoped.call('stock_requests.php', 'POST', body, headers={'X-Idempotency-Key': op})[0] == 409)
    db(f"UPDATE users SET department='{original_department}' WHERE username='fleet_dept';")

    # Ledger totals are a check against the one current stock/deployment projection.
    check("storage movement totals reconcile to every item balance", db("SELECT COUNT(*) FROM items i WHERE i.current_qty <> COALESCE((SELECT SUM(quantity_delta) FROM stock_movements sm WHERE sm.item_key=i.item_key AND sm.balance_scope='storage'),0);") == "0")
    check("department movement totals reconcile to deployments", db("SELECT COUNT(*) FROM (SELECT item_key, department, SUM(quantity_delta) AS qty FROM stock_movements WHERE balance_scope='department' GROUP BY item_key, department) sm LEFT JOIN equipment_deployments ed ON ed.item_key=sm.item_key AND ed.department=sm.department WHERE sm.qty <> COALESCE(ed.qty,0);") == "0")
    check("all recorded movements preserve before/delta/after arithmetic", db("SELECT COUNT(*) FROM stock_movements WHERE quantity_after<>quantity_before+quantity_delta OR quantity_after<0;") == "0")
    check("phase2 stock writes preserve reports", a.call("reports.php?type=stock_issues&format=html")[0] == 200 and a.call("reports.php?type=equipment_movements&format=html")[0] == 200)
    db("DROP TRIGGER phase2_overlap;")
