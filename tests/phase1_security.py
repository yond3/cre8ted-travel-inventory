"""Exercise the real PHP API against a disposable MariaDB instance.

Requires existing PHP/MariaDB binaries only. Never loads application credentials
into a database client; every client uses a fresh loopback-only test server.
"""

import argparse
from contextlib import contextmanager
import http.cookiejar
import json
import os
from pathlib import Path
import socket
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
import uuid


ROOT = Path(__file__).resolve().parents[1]
NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0)


def available_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


@contextmanager
def isolated_test_directory(parent):
    # Inherit the selected directory's Windows ACLs. Python 3.13+ private
    # TemporaryDirectory ACLs can exclude a sandbox's restricted process token.
    parent = Path(parent).resolve()
    directory = parent / ("inventory-phase1-" + uuid.uuid4().hex)
    directory.mkdir()
    try:
        yield directory
    finally:
        assert directory.resolve().parent == parent and directory.name.startswith("inventory-phase1-")
        shutil.rmtree(directory)


class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
            urllib.request.ProxyHandler({}),
        )
        self.csrf = None

    def call(self, path, method="GET", body=None, raw=None, headers=None, csrf=True):
        request_headers = dict(headers or {})
        if method not in ('GET', 'OPTIONS') and self.csrf:
            request_headers.setdefault('X-Idempotency-Key', uuid.uuid4().hex)
        if body is not None and self.csrf and 'stock_version' not in body and (
            (path.startswith('inventory.php?') and method == 'PUT' and 'current_qty' in body)
            or (path.startswith('stock.php?') and 'current' in body)
        ):
            from urllib.parse import parse_qs, urlsplit
            key = parse_qs(urlsplit(path).query).get('item', [''])[0]
            status, snapshot, _ = self.call('inventory.php?item=' + urllib.parse.quote(key))
            if status == 200:
                body = {**body, 'stock_version': snapshot['stock_version']}
        # Match bundled clients' new contracts; raw requests below test omissions.
        if body is not None and self.csrf and method in ('POST', 'PUT'):
            body = dict(body)
            if (path.startswith('inventory.php?') and method == 'PUT') or path.startswith('stock.php?'):
                body.setdefault('reason', 'Disposable regression fixture adjustment')
            if path.startswith(('usage.php?', 'close_month.php?')):
                from urllib.parse import parse_qs, urlsplit, quote
                key = parse_qs(urlsplit(path).query).get('item', [''])[0]
                month = body.get('month')
                if isinstance(month, str):
                    if path.startswith('close_month.php?'):
                        status, snapshot, _ = self.call('close_month.php?item=' + quote(key) + '&month=' + quote(month))
                        if status == 200:
                            body.setdefault('usage_version', snapshot['usage_version'])
                            body.setdefault('context_version', snapshot['context_version'])
                    else:
                        status, rows, _ = self.call('usage.php?item=' + quote(key))
                        if status == 200:
                            body.setdefault('usage_version', next((row['usage_version'] for row in rows if row['month'] == month), '0'))
                body.setdefault('reason', 'Disposable regression fixture correction')
        if body is not None:
            raw = json.dumps(body).encode()
        if raw is not None:
            request_headers.setdefault("Content-Type", "application/json")
        if csrf and self.csrf:
            request_headers["X-CSRF-Token"] = self.csrf
        request = urllib.request.Request(
            self.base + "/api/" + path, data=raw, method=method, headers=request_headers
        )
        try:
            response = self.opener.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            text = response.read().decode()
            if response.status == 204:
                data = None
            elif response.headers.get("Content-Type", "").startswith("text/html"):
                data = text
            else:
                try:
                    data = json.loads(text)
                except json.JSONDecodeError:
                    raise AssertionError(f"Non-JSON response from {path}: {text[:180]}")
            return response.status, data, response.headers

    def login(self, username, password):
        status, data, _ = self.call("auth.php", "POST", {"username": username, "password": password})
        assert status == 200, (username, status, data)
        self.csrf = data["csrf_token"]
        return data


def run(args):
    diagnostics = None
    diagnostic_path = getattr(args, 'diagnostic_dir', None)
    if diagnostic_path:
        diagnostics = Path(diagnostic_path).resolve()
        assert not diagnostics.is_relative_to((ROOT / 'php').resolve()), 'Diagnostics must stay outside the public root'
        diagnostics.mkdir()  # New private directory only; never overwrite evidence.

    def diagnostic(event):
        if diagnostics:
            with (diagnostics / 'events.jsonl').open('a', encoding='utf-8') as output:
                output.write(json.dumps(event) + '\n')

    # The suite needs loopback HTTP only. Origins below are header test values,
    # never destinations; reject outbound DNS as well as connections.
    network_events = {'loopback_connections': 0, 'blocked': 0}
    network_log = getattr(args, 'network_log', None)
    if network_log:
        network_log = Path(network_log).resolve()
        assert network_log.parent.is_dir() and not network_log.exists(), 'Use a new private network metadata log'
        network_log.write_text('', encoding='utf-8')
    def network_metadata(kind, **metadata):
        if network_log:
            with network_log.open('a', encoding='utf-8') as output:
                output.write(json.dumps({'event': kind, **metadata}) + '\n')
    allowed_programs = {Path(args.php).resolve(), *[(Path(args.mysql_bin)/p).resolve()
        for p in ('mysql.exe','mysqld.exe','mysql_install_db.exe')]}
    def loopback_only(event, arguments):
        if network_log and event == 'subprocess.Popen':
            command = arguments[0]
            if not command:
                if isinstance(arguments[1], str):
                    import re
                    first = re.match(r'^\s*(?:"([^"]+)"|(\S+))', arguments[1])
                    assert first, 'Cannot identify test executable'
                    command = first.group(1) or first.group(2)
                else:
                    command = arguments[1][0]
            executable = Path(command).resolve()
            if executable not in allowed_programs:
                network_metadata('process_denied', executable=executable.name)
                raise RuntimeError('Diagnostic regression allows only installed PHP and MariaDB tools')
            network_metadata('process_allowed', executable=executable.name)

        if event in ('socket.connect', 'socket.getaddrinfo'):
            address = arguments[1] if event == 'socket.connect' else arguments[0]
            host = address[0] if isinstance(address, tuple) else address
            network_metadata(event, host=str(host), allowed=host in ('127.0.0.1', '::1', 'localhost'))
            if host not in ('127.0.0.1', '::1', 'localhost'):
                network_events['blocked'] += 1
                raise RuntimeError('Tests may connect only to loopback hosts')
            if event == 'socket.connect':
                network_events['loopback_connections'] += 1
    sys.addaudithook(loopback_only)
    php = Path(args.php).resolve()
    mysql_bin = Path(args.mysql_bin).resolve()
    for binary in (php, mysql_bin / "mysql.exe", mysql_bin / "mysqld.exe", mysql_bin / "mysql_install_db.exe"):
        if not binary.is_file():
            raise SystemExit(f"Required installed binary not found: {binary}")

    # Only a newly generated directory beneath this explicit parent is removed.
    temp_parent = Path(args.temp_parent or tempfile.gettempdir()).resolve()
    with isolated_test_directory(temp_parent) as temporary:
        temp = Path(temporary).resolve()
        assert temp.parent == temp_parent and temp.name.startswith("inventory-phase1-")
        db_dir = temp / "database"
        sessions = temp / "sessions"
        sessions.mkdir()
        uploads = temp / "upload-temp"
        uploads.mkdir()
        web = temp / "web"
        (web / "api").mkdir(parents=True)
        for endpoint in (ROOT / "php" / "api").glob("*.php"):
            shutil.copyfile(endpoint, web / "api" / endpoint.name)
        for page in (ROOT / "php").glob("*.html"):
            shutil.copyfile(page, web / page.name)
        for script in (ROOT/'php').glob('*.js'):
            shutil.copyfile(script,web/script.name)
        if (ROOT/'vendor').is_dir():
            for source in (ROOT/'vendor').rglob('*'):
                if source.is_file():
                    target=temp/'vendor'/source.relative_to(ROOT/'vendor');target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,target)
        if getattr(args, 'preview', False):
            # Only the disposable copy is labeled; normal application pages stay unchanged.
            (web / 'api' / 'forecast.php').write_text("<?php require __DIR__ . '/config.php'; block_department_user(); require_auth(); json_error('The forecasting service is disabled in this isolated test preview. Normal forecasting configuration is unchanged.', 503);", encoding='utf-8')
            for page in web.glob('*.html'):
                html = page.read_text(encoding='utf-8-sig')
                html = html.replace('<title>', '<title>[ISOLATED TEST] ', 1)
                badge = '<div style="position:fixed;bottom:8px;left:8px;z-index:99999;padding:8px 12px;background:#fff4cc;color:#503800;border:1px solid #b98b00;border-radius:8px;font:13px Arial;pointer-events:none">ISOLATED TEST PREVIEW â€” sample data; normal database unchanged</div>'
                html = html.replace('</body>', badge + '</body>', 1)
                page.write_text(html, encoding='utf-8')
        # Copy bytes into freshly inherited temporary ACLs; copytree/copy2 can
        # carry read-only OneDrive directory metadata and block cleanup on Windows.
        assets = ROOT / "php" / "assets"
        for source in assets.rglob("*"):
            target = web / "assets" / source.relative_to(assets)
            if source.is_dir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, target)
        instance_token = uuid.uuid4().hex
        (web / "api" / "phase1-instance.json").write_text(json.dumps({"test_instance": instance_token}), encoding="utf-8")
        http_port = getattr(args, 'preview_port', None) or available_port()
        if getattr(args, 'preview_port', None):
            if not getattr(args, 'preview', False) or not 1024 <= http_port <= 65535:
                raise SystemExit('--preview-port requires --preview or --preview-hold and a port from 1024 to 65535')
            with socket.socket() as port_probe:
                try:
                    port_probe.bind(('127.0.0.1', http_port))
                except OSError:
                    raise SystemExit(f'Preview port {http_port} is already in use. Close the previous preview window before restarting; no existing process was stopped.')
        db_port = available_port()
        while db_port == http_port:
            db_port = available_port()
        base = f"http://127.0.0.1:{http_port}"
        environment = os.environ.copy()
        runtime_temp=temp/'runtime-temp'
        runtime_temp.mkdir()
        # AppContainer-provided TEMP may be inaccessible to native child tools.
        # Keep temporary files inside this verified disposable test directory.
        environment.update({'TEMP':str(runtime_temp),'TMP':str(runtime_temp),'TMPDIR':str(runtime_temp)})
        environment.update({
            "DB_HOST": "127.0.0.1", "DB_PORT": str(db_port),
            "DB_NAME": "phase1_security_test", "DB_USER": "root", "DB_PASSWORD": "",
            "APP_BASE_URL": "https://app.example.test/inventory",
            "CORS_ALLOWED_ORIGINS": "https://department.example.test,*,https://*.example.test",
            "FINANCE_MODE": "stub", "FINANCE_WEBHOOK_SECRET": "phase1-test-secret",
            "FORECAST_SERVICE_URL": "http://127.0.0.1:1", "OCR_SERVICE_URL": "http://127.0.0.1:1",
            "MYSQL_PWD": "",
        })
        import base64
        environment['MFA_ENCRYPTION_KEY']=base64.b64encode(os.urandom(32)).decode()
        environment['MFA_SCHEMA_REQUIRED']='0'  # Baseline migrations are staged before MFA readiness.
        environment['MFA_ALLOW_HTTP']='0'
        environment['MFA_HTTP_ORIGIN']=''
        environment['MFA_ALLOW_LOCAL_HTTP']='1'  # Disposable loopback servers only.
        environment['MFA_ISSUER']='Cre8ted Inventory (TEST)'
        # PHP is a separate process, so Python's socket hook cannot protect it.
        # Use a private ini for EVERY test PHP process (including concurrency
        # workers). Do not edit the user's installed ini or application config.
        ini_dir = temp / 'php-config'
        ini_dir.mkdir()
        installed_ini = php.parent / 'php.ini'
        assert installed_ini.is_file(), 'Installed PHP configuration not found'
        disabled_functions = ('curl_init,curl_exec,curl_multi_init,curl_multi_exec,curl_multi_socket_action,fsockopen,pfsockopen,'
            'stream_socket_client,stream_socket_sendto,socket_connect,socket_sendto,'
            'mail,exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec')
        (ini_dir / 'php.ini').write_text(installed_ini.read_text(encoding='utf-8-sig')
            + '\n[PHP]\nallow_url_fopen=0\nallow_url_include=0\n'
            + 'auto_prepend_file=\nauto_append_file=\ndisable_functions='
            + disabled_functions + '\n', encoding='utf-8')
        environment['PHPRC'] = str(ini_dir)
        environment['PHP_INI_SCAN_DIR'] = str(ini_dir / 'no-additional-ini')
        # Keep host-managed proxy/security variables intact. urllib's local-only
        # client already disables proxy use, and PHP network functions are off.
        probe = subprocess.run([str(php), '-r',
            "echo json_encode([php_ini_loaded_file(),ini_get('allow_url_fopen'),"
            "ini_get('disable_functions')]);"], stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, check=True, env=environment,
            creationflags=NO_WINDOW)
        loaded_ini, url_streams, disabled = json.loads(probe.stdout)
        assert Path(loaded_ini).resolve() == (ini_dir / 'php.ini').resolve()
        assert url_streams == '0' and set(disabled_functions.split(',')) <= set(disabled.split(','))
        network_metadata('php_policy_verified', outbound_functions_disabled=True, url_streams_disabled=True)
        print('Private PHP runtime verified: outbound HTTP, URL streams, direct sockets, mail and process launches disabled.', flush=True)
        processes = []
        with (temp / "database.log").open("w+") as db_log, (temp / "php.log").open("w+") as php_log:
            try:
                print("Initializing isolated test database...", flush=True)
                initialized = subprocess.run([
                    str(mysql_bin / "mysql_install_db.exe"), f"--datadir={db_dir}",
                    f"--port={db_port}", "--silent",
                ], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=60,
                    creationflags=NO_WINDOW, env=environment)
                assert initialized.returncode == 0, initialized.stdout.decode(errors="replace")
                database = subprocess.Popen([
                    str(mysql_bin / "mysqld.exe"), "--no-defaults",
                    f"--basedir={mysql_bin.parent}", f"--datadir={db_dir}",
                    f"--port={db_port}", "--bind-address=127.0.0.1", "--skip-name-resolve",
                    "--innodb-buffer-pool-size=32M",
                ], stdout=db_log, stderr=subprocess.STDOUT, creationflags=NO_WINDOW, env=environment)
                processes.append(database)
                mysql = [str(mysql_bin / "mysql.exe"), "--no-defaults", "--protocol=tcp",
                         "--host=127.0.0.1", f"--port={db_port}", "--user=root",
                         "--connect-timeout=2", "--batch", "--raw", "--skip-column-names"]

                def sql(statement):
                    result = subprocess.run(mysql, input=statement.encode(), stdout=subprocess.PIPE,
                                            stderr=subprocess.PIPE, timeout=15,
                                            creationflags=NO_WINDOW, env=environment)
                    assert result.returncode == 0, result.stderr.decode(errors="replace")
                    return result.stdout.decode().strip()

                deadline = time.monotonic() + 20
                while True:
                    try:
                        sql("SELECT 1;")
                        break
                    except AssertionError:
                        if database.poll() is not None or time.monotonic() >= deadline:
                            db_log.seek(0)
                            raise AssertionError(db_log.read()[-3000:])
                        time.sleep(0.1)

                # A port could be claimed between selection and server startup.
                # Verify server identity before issuing any creation/write SQL.
                assert database.poll() is None, "The isolated database process exited"
                assert Path(sql("SELECT @@datadir;")).resolve() == db_dir.resolve(), "Refusing to write to a database outside the test directory"

                schema = (ROOT / "sql" / "schema.sql").read_text(encoding="utf-8-sig")
                schema=schema.split("-- PHASE 5 REQUEST-LINE UPGRADE")[0]  # Baseline regressions run before the Phase 5 cutover.
                # Import creation/seed statements only into our brand-new server.
                schema = "\n".join(line for line in schema.splitlines()
                                   if not line.startswith(("DROP TABLE", "CREATE DATABASE", "USE ")))
                sql("CREATE DATABASE phase1_security_test CHARACTER SET utf8mb4;\n"
                    "USE phase1_security_test;\n" + schema)

                def db(statement):
                    return sql("USE phase1_security_test;\n" + statement)

                def count(statement):
                    return int(db(statement))

                php_server = subprocess.Popen([
                    str(php), "-d", f"session.save_path={sessions}", "-d", "display_errors=0",
                    "-d", f"upload_tmp_dir={uploads}", "-d", "log_errors=1",
                    "-S", f"127.0.0.1:{http_port}", "-t", str(web),
                ], stdout=php_log, stderr=subprocess.STDOUT, creationflags=NO_WINDOW, env=environment)
                processes.append(php_server)
                anonymous = Client(base)
                deadline = time.monotonic() + 10
                while True:
                    try:
                        assert php_server.poll() is None
                        assert anonymous.call("phase1-instance.json")[1] == {"test_instance": instance_token}
                        assert anonymous.call("health.php")[1]["database"] == "connected"
                        break
                    except (urllib.error.URLError, AssertionError):
                        if php_server.poll() is not None or time.monotonic() >= deadline:
                            php_log.seek(0)
                            raise AssertionError(php_log.read()[-3000:])
                        time.sleep(0.1)

                passed = 0
                last_check = None

                def check(description, condition):
                    nonlocal passed, last_check
                    last_check = description
                    assert condition, description
                    passed += 1
                    print(f"PASS {description}", flush=True)

                for path, method in (("stock.php?item=ballpointpens", "POST"),
                                     ("usage.php?item=ballpointpens", "POST"),
                                     ("usage.php?item=ballpointpens", "GET"),
                                     ("inventory.php", "GET")):
                    status, _, _ = anonymous.call(path, method, {"current": 1} if method == "POST" else None)
                    check(f"anonymous access denied: {method} {path}", status == 401)
                check("anonymous usage DELETE rejected", anonymous.call("usage.php?item=ballpointpens", "DELETE")[0] == 405)

                for origin in ("https://untrusted.example.test", "null", "https://department.example.test.evil.test"):
                    status, _, headers = anonymous.call("auth.php", headers={"Origin": origin})
                    check(f"untrusted browser origin rejected: {origin}", status == 403 and "Access-Control-Allow-Origin" not in headers)
                for origin in (base, "https://department.example.test", "https://app.example.test"):
                    status, _, headers = anonymous.call("auth.php", "OPTIONS", headers={
                        "Origin": origin, "Access-Control-Request-Method": "POST",
                        "Access-Control-Request-Headers": "Content-Type,X-CSRF-Token",
                    })
                    check(f"trusted preflight allowed: {origin}", status == 204 and headers["Access-Control-Allow-Origin"] == origin)
                status, _, headers = anonymous.call("auth.php")
                check("no wildcard CORS without Origin", status == 200 and "Access-Control-Allow-Origin" not in headers)

                check("invalid login remains rejected", anonymous.call("auth.php", "POST", {"username": "juan", "password": "incorrect"})[0] == 401)
                for raw in (b'{"username":', b'[]', b'null', b'"text"'):
                    check(f"invalid JSON/object rejected: {raw!r}", anonymous.call("auth.php", "POST", raw=raw)[0] == 400)

                staff, manager, admin, department = (Client(base) for _ in range(4))
                for client, username, password, role in (
                    (staff, "juan", "staff123", "staff"),
                    (manager, "maria", "manager123", "manager"),
                    (admin, "admin", "admin123", "super_admin"),
                    (department, "fleet_dept", "staff123", "department"),
                ):
                    check(f"normal {role} login", client.login(username, password)["user"]["role"] == role)
                    check(f"session persists for {role}", client.call("auth.php")[1]["authenticated"])
                for client, role in ((staff, "staff"), (department, "department")):
                    check(f"{role} cannot directly edit stock", client.call("stock.php?item=ballpointpens", "POST", {"current": 10})[0] == 403)
                    check(f"{role} cannot write usage", client.call("usage.php?item=ballpointpens", "POST", {"month": "2026-01-01", "usage": 10})[0] == 403)
                check("staff can read usage", staff.call("usage.php?item=ballpointpens")[0] == 200)
                check("department cannot read full usage", department.call("usage.php?item=ballpointpens")[0] == 403)
                check("CSRF remains required", manager.call("stock.php?item=ballpointpens", "POST", {"current": 10}, csrf=False)[0] == 403)
                check("trusted origin still requires CSRF", manager.call("stock.php?item=ballpointpens", "POST", {"current": 10}, csrf=False, headers={"Origin": "https://department.example.test"})[0] == 403)
                check("untrusted origin cannot mutate an authenticated session", manager.call("stock.php?item=ballpointpens", "POST", {"current": 10}, headers={"Origin": "https://untrusted.example.test"})[0] == 403)
                check("allowed origin does not bypass authorization", anonymous.call("stock.php?item=ballpointpens", "POST", {"current": 10}, headers={"Origin": "https://department.example.test"})[0] == 401)

                check("manager can edit stock with valid input", manager.call("stock.php?item=ballpointpens", "POST", {"current": 80, "min": 5})[0] == 200)
                before = db("SELECT current_qty FROM items WHERE item_key='ballpointpens';")
                invalid_numbers = (-1, "not-a-number", None, True, [], {}, 0.001, 0.00000001, 100000000, "1e309")
                for value in invalid_numbers:
                    status, _, _ = manager.call("stock.php?item=ballpointpens", "POST", {"current": value})
                    check(f"invalid stock quantity rejected: {value!r}", status == 400)
                check("invalid stock edits leave balance intact", db("SELECT current_qty FROM items WHERE item_key='ballpointpens';") == before)
                status, _, _ = manager.call("stock.php?item=ballpointpens", "POST", {"current": 50, "min": "invalid"})
                check("multi-field stock validation prevents partial edit", status == 400 and db("SELECT current_qty FROM items WHERE item_key='ballpointpens';") == before)
                check("unknown stock edit fields rejected", manager.call("stock.php?item=ballpointpens", "POST", {"quantity": 2})[0] == 400)
                check("super admin can edit stock", admin.call("stock.php?item=ballpointpens", "POST", {"current": "80.25"})[0] == 200)
                check("stock adjustment audited", count("SELECT COUNT(*) FROM audit_log WHERE action='stock.adjust' AND actor_username='admin';") == 1)

                for value in invalid_numbers:
                    check(f"invalid usage quantity rejected: {value!r}", manager.call("usage.php?item=ballpointpens", "POST", {"month": "2026-01-01", "usage": value})[0] == 400)
                for month in ("2026-02-31", "2026-13-01", "2026-01-15", "2026-01", [], None):
                    check(f"invalid usage month rejected: {month!r}", manager.call("usage.php?item=ballpointpens", "POST", {"month": month, "usage": 1})[0] == 400)
                check("valid monthly usage accepted", manager.call("usage.php?item=ballpointpens", "POST", {"month": "2026-01-01", "usage": 12.5})[0] == 200)
                check("usage upsert retained", manager.call("usage.php?item=ballpointpens", "POST", {"month": "2026-01-01", "usage": 0})[0] == 200 and count("SELECT COUNT(*) FROM usage_log WHERE item_key='ballpointpens' AND month='2026-01-01';") == 1)
                check("usage DELETE cannot write", manager.call("usage.php?item=ballpointpens", "DELETE", {"month": "2026-01-01", "usage": 99})[0] == 405 and float(db("SELECT usage_qty FROM usage_log WHERE item_key='ballpointpens' AND month='2026-01-01';")) == 0)

                for payload in ({"current_qty": -1}, {"min_qty": "invalid"}, {"max_qty": -1}, {"min_qty": 20, "max_qty": 5}, {"item_type": "invalid"}):
                    check(f"inventory edit validates {payload!r}", manager.call("inventory.php?item=ballpointpens", "PUT", payload)[0] == 400)
                for payload in ({"current_qty": -1}, {"current_qty": None}, {"min_qty": -1}, {"max_qty": "invalid"}):
                    body = {"label": "Invalid Quantity Fixture", "unit": "pcs", "item_type": "consumable", **payload}
                    check(f"inventory creation validates {payload!r}", manager.call("inventory.php", "POST", body)[0] == 400)
                status, item, _ = manager.call("inventory.php", "POST", {"label": "Security Test Paper", "unit": "reams", "item_type": "consumable", "current_qty": 30.5, "min_qty": 2, "max_qty": 50})
                check("existing consumable creation works", status == 200 and item["current_qty"] == 30.5)
                key = item["item_key"]
                check("valid inventory edit works", manager.call(f"inventory.php?item={key}", "PUT", {"current_qty": 35.5, "min_qty": None, "max_qty": None})[0] == 200)
                check("staff inventory editing remains forbidden", staff.call(f"inventory.php?item={key}", "PUT", {"current_qty": 1})[0] == 403)
                equipment = db("SELECT item_key FROM items WHERE item_type='equipment' AND assigned_department IS NULL LIMIT 1;")
                check("equipment fixture exists", bool(equipment))
                check("equipment whole-unit validation applies", manager.call(f"stock.php?item={equipment}", "POST", {"current": 1.5})[0] == 400)
                check("inventory equipment edits require whole units", manager.call(f"inventory.php?item={equipment}", "PUT", {"current_qty": 1.5})[0] == 400)

                for client, role in ((staff, "staff"), (manager, "manager"), (admin, "super_admin")):
                    for path in ("inventory.php", "items.php", "stock_issues.php", "purchase_requests.php", "purchase_orders.php", "suppliers.php", "demand_insights.php"):
                        check(f"existing {role} read: {path}", client.call(path)[0] == 200)
                check("existing report export works", manager.call("reports.php?type=inventory&format=html")[0] == 200)

                status, request, _ = department.call("stock_requests.php", "POST", {"item_key": key, "qty": 2, "notes": "Regression request"})
                check("department request creation retained", status == 200)
                request_id = request["id"]
                status, request, _ = staff.call(f"stock_requests.php?id={request_id}", "PUT", {"action": "fulfill"})
                check("existing staff fulfillment retained", status == 200 and request["status"] == "Fulfilled")
                issue_id = request["fulfilled_issue_id"]
                check("fulfillment deducts correct stock", float(db(f"SELECT current_qty FROM items WHERE item_key='{key}';")) == 33.5)
                check("existing manager issue void retained", manager.call(f"stock_issues.php?id={issue_id}", "PUT", {"action": "void", "reason": "Regression reversal"})[0] == 200)
                check("void restores stock", float(db(f"SELECT current_qty FROM items WHERE item_key='{key}';")) == 35.5)
                status, request, _ = department.call("stock_requests.php", "POST", {"item_key": key, "qty": 1})
                check("existing department cancellation retained", status == 200 and department.call(f"stock_requests.php?id={request['id']}", "PUT", {"action": "cancel"})[0] == 200)

                status, purchase, _ = staff.call("purchase_requests.php", "POST", {"item_key": key, "qty": 3, "request_type": "consumable", "notes": "Procurement regression"})
                check("existing purchase request creation retained", status == 200 and purchase["status"] == "Pending")
                pr_id = purchase["id"]
                check("staff cannot approve purchase requests", staff.call(f"purchase_requests.php?id={pr_id}", "PUT", {"action": "approve"})[0] == 403)
                check("manager purchase approval retained", manager.call(f"purchase_requests.php?id={pr_id}", "PUT", {"action": "approve"})[0] == 200)
                supplier_id = count("SELECT id FROM suppliers WHERE active=1 LIMIT 1;")
                status, order, _ = manager.call("purchase_orders.php", "POST", {"request_id": pr_id, "supplier_id": supplier_id, "procurement_method": "walk_in", "assigned_to": "Juan Dela Cruz", "amount": 30})
                check("existing PO creation and Finance stub retained", status == 200 and order["status"] == "Placed" and order["finance_status"] == "funded")
                po_id = order["id"]
                check("staff cannot mark POs received", staff.call(f"purchase_orders.php?id={po_id}", "PUT", {"action": "receive"})[0] == 403)

                boundary = "phase1-security-" + uuid.uuid4().hex
                multipart = b""
                for name, value in (("amount", "30"), ("receipt_number", "PHASE1-TEST-RECEIPT")):
                    multipart += (f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n').encode()
                multipart += (f'--{boundary}\r\nContent-Disposition: form-data; name="receipt"; filename="test.pdf"\r\nContent-Type: application/pdf\r\n\r\n').encode()
                multipart += b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
                multipart += f"\r\n--{boundary}--\r\n".encode()
                status, receipt, _ = staff.call(f"receipts.php?po_id={po_id}", "POST", raw=multipart, headers={"Content-Type": f"multipart/form-data; boundary={boundary}"})
                check(f"existing receipt upload retained ({status})", status == 200)
                check("uploaded receipt stays inside test workspace", len(list((web / "uploads" / "receipts").glob("*.pdf"))) == 1)
                check("receipt download remains protected", anonymous.call(f"receipts.php?po_id={po_id}")[0] == 401)
                status, received, _ = manager.call(f"purchase_orders.php?id={po_id}", "PUT", {"action": "receive"})
                check("existing full PO receiving retained", status == 200 and received["new_status"] == "Received")
                check("receiving adds full purchase quantity", float(db(f"SELECT current_qty FROM items WHERE item_key='{key}';")) == 38.5)
                check("sequential duplicate receive remains rejected", manager.call(f"purchase_orders.php?id={po_id}", "PUT", {"action": "receive"})[0] == 409)

                completed_month = db("SELECT DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH), '%Y-%m-01');")
                status, closed, _ = manager.call(f"close_month.php?item={key}", "POST", {"month": completed_month, "opening_qty": 35.5, "received_qty": 3, "closing_qty": 36.5})
                check("existing month close retained", status == 200 and closed["computed_usage"] == 2)
                check("monthly usage reaches existing readers", any(row["month"] == completed_month and row["usage"] == 2 for row in staff.call(f"usage.php?item={key}")[1]))
                check("monthly report remains available", manager.call("reports.php?type=month_closes&format=html")[0] == 200)

                # No session is required for the established public vendor picker.
                check("public vendor picker retained", anonymous.call("vendor_applications.php?quotable=1")[0] == 200)
                check("Finance webhook still uses its secret", anonymous.call("finance_integration.php", "POST", {"event": "expense.recorded", "po_code": "UNKNOWN"})[0] == 401)
                check("Finance callback can reach validation without an internal login", anonymous.call("finance_integration.php", "POST", {"event": "expense.recorded", "po_code": "UNKNOWN"}, headers={"X-Finance-Secret": "phase1-test-secret"})[0] == 404)

                if getattr(args, "phase2", False):
                    from phase2_concurrency import run_phase2
                    run_phase2(Client=Client, available_port=available_port, php=php, web=web,
                               sessions=sessions, uploads=uploads, environment=environment,
                               processes=processes, php_log=php_log, instance_token=instance_token,
                               db=db, sql=sql, check=check, root=ROOT)

                if getattr(args, "phase3", False):
                    from phase3_usage import run_phase3
                    environment["PHASE3_PRIMARY_BASE"] = base
                    monthly_clients = run_phase3(Client=Client, available_port=available_port, php=php, web=web,
                               sessions=sessions, uploads=uploads, environment=environment,
                               processes=processes, php_log=php_log, instance_token=instance_token,
                               db=db, sql=sql, check=check, root=ROOT,
                               race_repetitions=args.month_close_race_repetitions if args.month_close_race_stage in ('legacy', 'both') else 1,
                               diagnostic=diagnostic)

                if getattr(args, 'phase4', False):
                    from phase4_categories import run_phase4
                    run_phase4(Client=Client, base=base, db=db, sql=sql, check=check, root=ROOT, available_port=available_port, php=php, web=web, sessions=sessions, environment=environment, processes=processes, php_log=php_log, instance_token=instance_token)

                if getattr(args,'phase5',False):
                    from phase5_requests import run_phase5
                    run_phase5(Client=Client,base=base,db=db,sql=sql,check=check,root=ROOT,available_port=available_port,php=php,web=web,sessions=sessions,environment=environment,processes=processes,php_log=php_log,instance_token=instance_token)
                    if args.month_close_race_stage in ('current', 'both'):
                        from phase3_usage import run_close_issue_races
                        db('CREATE TRIGGER phase3_current_overlap BEFORE INSERT ON usage_log FOR EACH ROW DO SLEEP(0.15);')
                        try:
                            run_close_issue_races(close_client=monthly_clients[0], issue_client=monthly_clients[1],
                                db=db, check=check,
                                month=db("SELECT DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 2 MONTH), '%Y-%m-01');"),
                                repetitions=args.month_close_race_repetitions, stage='current', diagnostic=diagnostic)
                        finally:
                            db('DROP TRIGGER phase3_current_overlap;')

                check("logout succeeds", staff.call("auth.php", "DELETE")[0] == 200)
                check("logout removes protected access", staff.call("inventory.php")[0] == 401)
                db("UPDATE users SET active=0 WHERE username='maria';")
                check("deactivated account loses existing session", manager.call("auth.php")[1]["authenticated"] is False)
                # Only this disposable DB is changed. A missing account table must
                # fail closed even for an already authenticated admin session.
                db("RENAME TABLE users TO unavailable_test_users;")
                check("account verification failure denies stale session", admin.call("inventory.php")[0] == 503)
                db("RENAME TABLE unavailable_test_users TO users;")
                check("session resumes after account verification recovers", admin.call("inventory.php")[0] == 200)
                if getattr(args,'phase6a',False):
                    from phase6a_accounts import run_phase6a
                    run_phase6a(Client=Client,base=base,db=db,check=check,root=ROOT,available_port=available_port,php=php,web=web,sessions=sessions,environment=environment,processes=processes,php_log=php_log)
                if getattr(args,'phase6b',False):
                    from phase6b_mfa import run_phase6b
                    run_phase6b(Client=Client,base=base,db=db,check=check,root=ROOT,available_port=available_port,php=php,web=web,sessions=sessions,environment=environment,processes=processes,php_log=php_log,preview=getattr(args,'preview',False))
                assert network_events['blocked'] == 0, 'A non-loopback Python connection was attempted'
                print(f"\n{passed} checks passed. Application database and environment were not used.", flush=True)
                print('Network verification: ' + str(network_events['loopback_connections'])
                    + ' loopback Python connections; no non-loopback attempts. Private PHP outbound restrictions remained in effect.', flush=True)
                if getattr(args, "preview", False):
                    # Disposable fixtures only; enable the existing demo manager for manual review.
                    db("UPDATE users SET active=1 WHERE username='maria';")
                    print(f"MANUAL TEST URL: {base}/login.html", flush=True)
                    print("Isolated DEMO database. Manager: maria / manager123; staff: juan / staff123; admin: admin / admin123.", flush=True)
                    print("Keep this terminal open. Ctrl+C stops the preview and removes its disposable database." if getattr(args, "preview_hold", False) else "Keep this terminal open. Enter or Ctrl+C stops the preview and removes its disposable database.", flush=True)
                    try:
                        if getattr(args,'preview_hold',False):
                            control=temp/'preview-running'
                            control.write_text('Disposable preview only. Remove this file to stop gracefully.',encoding='utf-8')
                            print('PREVIEW CONTROL FILE: '+str(control),flush=True)
                            while control.is_file():
                                time.sleep(1)
                        else:
                            input()
                    except (KeyboardInterrupt, EOFError):
                        pass
            except BaseException as error:
                if diagnostics:
                    # Save only relevant error lines, never sessions, credentials,
                    # private handovers, request bodies or the whole fixture.
                    php_log.flush()
                    db_log.flush()
                    inventory_errors = [line for line in Path(php_log.name).read_text(encoding='utf-8', errors='replace').splitlines()
                                        if 'Inventory operation failed:' in line]
                    database_errors = [line for line in Path(db_log.name).read_text(encoding='utf-8', errors='replace').splitlines()
                                       if any(marker in line.lower() for marker in ('deadlock', 'lock wait timeout', '[error]'))]
                    (diagnostics / 'inventory-errors.log').write_text('\n'.join(inventory_errors), encoding='utf-8')
                    (diagnostics / 'database-errors.log').write_text('\n'.join(database_errors), encoding='utf-8')
                    diagnostic({'event': 'run_failure', 'exception_type': type(error).__name__,
                                'last_check': locals().get('last_check'), 'passed_checks': locals().get('passed', 0)})
                raise
            finally:
                for process in reversed(processes):
                    if process.poll() is None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="C:/xampp/php/php.exe")
    parser.add_argument("--mysql-bin", default="C:/xampp/mysql/bin")
    parser.add_argument("--temp-parent", help="Writable parent for the disposable test files")
    parser.add_argument("--phase2", action="store_true", help="Also test concurrent inventory operations and safe upgrade")
    parser.add_argument("--phase4", action="store_true", help="Category tests plus Phases 1-3 regressions")
    parser.add_argument("--phase3", action="store_true", help="Also test monthly accounting; includes Phase 2 regressions")
    parser.add_argument("--preview", action="store_true", help="Keep the isolated app running for manual browser tests after checks pass")
    parser.add_argument("--preview-hold",action="store_true",help="Keep the disposable preview alive without stdin; remove its printed control file to stop")
    parser.add_argument("--preview-port",type=int,help="Optional fixed loopback HTTP port for a disposable preview")
    parser.add_argument("--network-log", help="New private metadata-only network/process audit log; restricts test subprocesses to installed PHP/MariaDB")
    parser.add_argument('--diagnostic-dir', help='New private directory for race evidence and relevant failure logs')
    parser.add_argument('--month-close-race-repetitions', type=int, default=1, help='Fresh-item close/issue races per selected schema stage (1-100)')
    parser.add_argument('--month-close-race-stage', choices=('legacy', 'current', 'both'), default='both', help='Repeat legacy, current request-line, or both transaction paths')
    parser.add_argument("--phase6a",action="store_true")
    parser.add_argument("--phase6b",action="store_true")
    parser.add_argument("--phase5",action="store_true")
    args = parser.parse_args()
    if not 1 <= args.month_close_race_repetitions <= 100:
        parser.error('--month-close-race-repetitions must be between 1 and 100')
    if args.preview_hold: args.preview=True
    if args.phase6b: args.phase6a = True
    if args.phase6a: args.phase5 = True
    if args.phase5: args.phase4 = True
    if args.phase4: args.phase3 = True
    if args.phase3: args.phase2 = True
    run(args)
