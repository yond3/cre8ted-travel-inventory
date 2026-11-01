"""Apply an approved Phase 2, 3 or 4 upgrade after verifying a rehearsed local backup.

This is an explicit application-database operation, not a test runner. Requires
--apply. It never drops/rebuilds existing tables and never changes credentials.
"""
import argparse
from datetime import datetime
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tests'))
from phase2_migration_rehearsal import Database, MANILA, NO_WINDOW, digest, ident, literal, receipt_manifest
from phase1_security import available_port


def run(args):
    if getattr(args, 'phase5', False):
        from phase5_rollout import run as run_phase5
        return run_phase5(args)
    assert args.apply, 'Application migration requires the explicit --apply option'
    phase3 = getattr(args, 'phase3', False)
    phase4 = getattr(args, 'phase4', False)
    assert not (phase3 and phase4), 'Select only one phase'
    phase = 4 if phase4 else 3 if phase3 else 2
    migration_name = 'migration_inventory_categories.sql' if phase4 else 'migration_usage_consistency.sql' if phase3 else 'migration_stock_integrity.sql'
    backup = Path(args.backup_directory).resolve()
    assert backup.parent == (ROOT / 'backups').resolve(), 'Select a verified backup inside this project'
    manifest = json.loads((backup / 'manifest.json').read_text(encoding='utf-8'))
    rehearsal = json.loads((backup / 'rehearsal_result.json').read_text(encoding='utf-8'))
    assert rehearsal['status'] == 'PASSED', 'Backup must have a successful restore/migration rehearsal'
    assert digest(backup / 'database.sql') == manifest['dump_sha256'], 'Database backup checksum mismatch'
    assert receipt_manifest(backup / 'receipts') == manifest['receipts'], 'Receipt backup checksum mismatch'
    php, binaries = Path(args.php), Path(args.mysql_bin)
    program = "require 'php/api/env.php'; echo json_encode([env('DB_HOST','127.0.0.1'),env('DB_PORT','3306'),env('DB_NAME','cre8ted_inventory'),env('DB_USER','root'),env('DB_PASSWORD','')]);"
    profile = subprocess.run([str(php), '-r', program], cwd=ROOT, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, check=True, creationflags=NO_WINDOW)
    host, port, name, user, password = json.loads(profile.stdout)
    assert name == manifest['database'], 'Configured database differs from the rehearsed backup'
    source = Database(binaries / 'mysql.exe', host, port, name, user, password, source=True)
    server_identity = source.execute(['SELECT @@datadir,@@port,VERSION()'])
    assert server_identity.split('\t')[-1] == manifest['server_version'], 'Database server version changed since rehearsal'
    migration_file = ROOT / 'sql' / migration_name
    migration_hash = digest(migration_file)
    if phase3 or phase4:
        assert rehearsal.get('phase') == phase, 'Select a matching phase rehearsed backup'
        assert rehearsal.get('migration_sha256') == migration_hash, 'Migration changed after rehearsal'
    migration = migration_file.read_text(encoding='utf-8')
    marker = ROOT / 'php' / 'api' / '.inventory-maintenance'
    owner = args.maintenance_token or uuid.uuid4().hex
    if args.maintenance_token:
        assert marker.is_file() and marker.read_text(encoding='ascii') == owner, 'Existing maintenance operation does not match the supplied owner'
    else:
        assert not marker.exists(), 'A maintenance operation is already in progress'
        with marker.open('x', encoding='ascii') as stream:
            stream.write(owner)
    began_ddl = False
    successful = False
    process = None
    checks = []
    started = datetime.now(MANILA).isoformat()
    error = None
    temporary = Path(tempfile.gettempdir()).resolve() / ('inventory-rollout-' + owner)
    temporary.mkdir()
    sessions = temporary / 'sessions'
    sessions.mkdir()
    base = 'http://127.0.0.1:' + str(available_port())
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def request(path):
        try:
            response = opener.open(base + '/' + path, timeout=5)
        except urllib.error.HTTPError as http_error:
            response = http_error
        with response:
            return response.status, response.read()

    try:
        with (temporary / 'php.log').open('w+') as log:
            environment = os.environ.copy()
            environment.update({'DB_HOST': host, 'DB_PORT': str(port), 'DB_NAME': name,
                                'DB_USER': user, 'DB_PASSWORD': password})
            process = subprocess.Popen([str(php), '-d', 'session.save_path=' + str(sessions), '-d', 'display_errors=0',
                '-S', base.removeprefix('http://'), '-t', str(ROOT / 'php')], cwd=ROOT,
                stdout=log, stderr=subprocess.STDOUT, env=environment, creationflags=NO_WINDOW)
            deadline = time.monotonic() + 10
            while True:
                try:
                    status, body = request('api/health.php')
                    assert status == 503 and 'maintenance' in json.loads(body)['error'].lower()
                    break
                except Exception:
                    if process.poll() is not None or time.monotonic() > deadline:
                        raise RuntimeError('Maintenance gate could not be verified')
                    time.sleep(0.1)
            checks.append('API maintenance gate returned 503 before database/session work.')
            # The marker stops new application requests; wait for existing writers.
            deadline = time.monotonic() + 20
            while True:
                activity = source.execute([
                    "SELECT COUNT(*) FROM information_schema.processlist WHERE DB=" + literal(name) + " AND ID<>CONNECTION_ID() AND COMMAND<>'Sleep'",
                    "SELECT COUNT(*) FROM information_schema.innodb_trx t JOIN information_schema.processlist p ON p.ID=t.trx_mysql_thread_id WHERE p.DB=" + literal(name) + ' AND p.ID<>CONNECTION_ID()',
                    "SELECT COUNT(*) FROM information_schema.events WHERE EVENT_SCHEMA=" + literal(name) + " AND STATUS='ENABLED'"])
                if all(int(value) == 0 for value in activity.splitlines()):
                    break
                if time.monotonic() > deadline:
                    raise RuntimeError('Application database is not quiet; no migration was attempted')
                time.sleep(0.5)
            checks.append('No active application queries, open transactions or enabled application database events.')
            columns = source.columns()
            assert columns == manifest['source_columns'], 'Application schema changed or upgrade was already/partially applied'
            if phase4:
                assert {'usage_log', 'month_closes', 'audit_log', 'stock_movements', 'inventory_operations', 'code_counters'} <= columns.keys(), 'Missing Phase 4 prerequisites'
                assert 'stock_version' in columns['items'] and {'usage_version', 'usage_source'} <= set(columns['usage_log']), 'Phases 2 and 3 must be installed first'
                assert 'inventory_categories' not in columns and not {'category_id', 'category_version'} & set(columns['items']), 'Phase 4 was already/partially applied'
            if phase3:
                assert {'usage_log', 'month_closes', 'audit_log', 'stock_movements', 'inventory_operations', 'code_counters'} <= columns.keys(), 'Missing Phase 3 prerequisites'
                assert 'stock_version' in columns['items'], 'Phase 2 must be installed first'
                assert not {'usage_version','usage_source'} & set(columns['usage_log']), 'Phase 3 was already/partially applied'
            before = source.fingerprint(columns)
            assert before == manifest['source_tables'], 'Application data changed: refresh backup/rehearsal before applying'
            assert receipt_manifest(ROOT / 'php' / 'uploads' / 'receipts') == manifest['receipts'], 'Receipt files changed since backup'
            invalid = source.execute(["SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=" + literal(name) + " AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'",
                "SELECT COUNT(*) FROM items WHERE current_qty<0 OR (item_type='equipment' AND MOD(current_qty,1)<>0)",
                'SELECT COUNT(*) FROM equipment_deployments WHERE qty<0 OR MOD(qty,1)<>0'])
            assert all(int(value) == 0 for value in invalid.splitlines()), 'Migration preflight failed'
            if phase4:
                balances = source.execute([
                    "SELECT COUNT(*) FROM items i WHERE i.current_qty<>COALESCE((SELECT SUM(quantity_delta) FROM stock_movements sm WHERE sm.item_key=i.item_key AND sm.balance_scope='storage'),0)",
                    "SELECT COUNT(*) FROM equipment_deployments ed WHERE ed.qty<>COALESCE((SELECT SUM(quantity_delta) FROM stock_movements sm WHERE sm.item_key=ed.item_key AND sm.department=ed.department AND sm.balance_scope='department'),0)"])
                assert all(int(value) == 0 for value in balances.splitlines()), 'Existing stock ledger mismatch; no category migration attempted'
            checks.append('Current records, original schema and all ' + str(len(manifest['receipts'])) + ' receipt files still match the verified backup.')
            assert source.execute(['SELECT @@datadir,@@port,VERSION()']) == server_identity
            assert digest(migration_file) == migration_hash and marker.read_text(encoding='ascii') == owner
            print('Backup, source identity, quiet state and maintenance gate verified. Applying the approved migration.', flush=True)
            began_ddl = True
            # Explicitly authorized application write. The read-only Database helper
            # deliberately remains unchanged and cannot execute this operation.
            result = subprocess.run(source.command, input=('USE ' + ident(name) + ';\n' + migration).encode(),
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=source.environment, timeout=90, creationflags=NO_WINDOW)
            if result.returncode:
                raise RuntimeError('Migration stopped at its first error: ' + result.stderr.decode(errors='replace')[-1500:])
            assert source.fingerprint(columns) == before, 'Original application records changed during migration'
            checks.append('Every original row and column is unchanged after migration (SHA-256 comparison).')
            after_columns = source.columns()
            if phase4:
                assert set(after_columns) == set(columns) | {'inventory_categories'}, 'Unexpected table changes'
                assert after_columns['items'] == columns['items'] + ['category_id', 'category_version'], 'Unexpected item column changes'
                assert all(after_columns[table] == names for table, names in columns.items() if table != 'items'), 'Unexpected column changes'
                verification = source.execute([
                    'SELECT COUNT(*) FROM items WHERE category_id IS NOT NULL OR category_version<>0',
                    'SELECT COUNT(*)<>15 FROM inventory_categories',
                    "SELECT COUNT(*)<>2 FROM information_schema.referential_constraints WHERE CONSTRAINT_SCHEMA=" + literal(name) + " AND CONSTRAINT_NAME IN ('fk_category_parent','fk_item_category') AND DELETE_RULE='RESTRICT'",
                    "SELECT COUNT(*)<>1 FROM information_schema.statistics WHERE TABLE_SCHEMA=" + literal(name) + " AND TABLE_NAME='items' AND INDEX_NAME='item_category' AND COLUMN_NAME='category_id'",
                    "SELECT COUNT(*)<>2 FROM information_schema.statistics WHERE TABLE_SCHEMA=" + literal(name) + " AND TABLE_NAME='inventory_categories' AND INDEX_NAME='category_sibling_name' AND NON_UNIQUE=0",
                    "SELECT COUNT(*) FROM items i WHERE i.current_qty<>COALESCE((SELECT SUM(quantity_delta) FROM stock_movements sm WHERE sm.item_key=i.item_key AND sm.balance_scope='storage'),0)",
                    "SELECT COUNT(*) FROM equipment_deployments ed WHERE ed.qty<>COALESCE((SELECT SUM(quantity_delta) FROM stock_movements sm WHERE sm.item_key=ed.item_key AND sm.department=ed.department AND sm.balance_scope='department'),0)"])
                assert all(int(value) == 0 for value in verification.splitlines()), 'Category schema/stock verification failed'
                checks.append('Existing items remain Uncategorized at revision zero; 15 categories, restrictive foreign keys and category indexes verified; storage and department stock ledgers reconcile.')
            elif phase3:
                assert set(after_columns) == set(columns), 'Unexpected table changes'
                assert after_columns['usage_log'] == columns['usage_log'] + ['usage_version','usage_source']
                assert all(after_columns[table] == names for table,names in columns.items() if table != 'usage_log'), 'Unexpected column changes'
                verification = source.execute([
                    "SELECT COUNT(*) FROM usage_log WHERE usage_version<>0 OR usage_source<>'legacy'",
                    "SELECT COUNT(*)<>1 FROM information_schema.columns WHERE TABLE_SCHEMA=" + literal(name) + " AND TABLE_NAME='audit_log' AND COLUMN_NAME='entity_id' AND CHARACTER_MAXIMUM_LENGTH=80",
                    "SELECT COUNT(*) FROM items i WHERE i.current_qty<>COALESCE((SELECT SUM(quantity_delta) FROM stock_movements sm WHERE sm.item_key=i.item_key AND sm.balance_scope='storage'),0)"])
                assert all(int(value) == 0 for value in verification.splitlines()), 'Monthly schema/stock verification failed'
                checks.append('Monthly revisions/provenance initialized; audit references support full item/month identifiers; stock ledger still reconciles.')
            else:
                assert set(after_columns) == set(columns) | {'stock_movements', 'inventory_operations', 'code_counters'}
                assert after_columns['items'] == columns['items'] + ['stock_version']
                verification = source.execute([
                    "SELECT COUNT(*) FROM items i LEFT JOIN stock_movements sm ON sm.item_key=i.item_key AND sm.balance_scope='storage' WHERE sm.id IS NULL OR sm.quantity_before<>0 OR sm.quantity_after<>i.current_qty OR sm.quantity_delta<>i.current_qty",
                    "SELECT COUNT(*) FROM equipment_deployments ed LEFT JOIN stock_movements sm ON sm.item_key=ed.item_key AND sm.department=ed.department AND sm.balance_scope='department' WHERE sm.id IS NULL OR sm.quantity_before<>0 OR sm.quantity_after<>ed.qty OR sm.quantity_delta<>ed.qty",
                    'SELECT COUNT(*) FROM items WHERE stock_version<>0',
                    'SELECT (SELECT COUNT(*) FROM stock_movements)<>((SELECT COUNT(*) FROM items)+(SELECT COUNT(*) FROM equipment_deployments))',
                    'SELECT COUNT(*) FROM inventory_operations',
                    'SELECT COUNT(*)<>8 FROM code_counters WHERE code_year=YEAR(CURRENT_DATE)'])
                assert all(int(value) == 0 for value in verification.splitlines()), 'Post-migration balance/schema checks failed'
            assert receipt_manifest(ROOT / 'php' / 'uploads' / 'receipts') == manifest['receipts']
            checks.append('Receipt files unchanged.' if phase3 or phase4 else 'All storage/deployment opening balances, initial revisions and eight code counters verified; receipt files unchanged.')
            assert marker.read_text(encoding='ascii') == owner
            marker.unlink()
            health_status, health_body = request('api/health.php')
            assert health_status == 200 and json.loads(health_body)['database'] == 'connected'
            assert request('login.html')[0] == 200 and request('assets/logo-icon.png')[0] == 200
            assert request('api/auth.php')[0] == 200
            assert request('api/inventory.php')[0] == 401
            if phase4:
                assert request('api/inventory_categories.php')[0] == 401
            assert source.fingerprint(columns) == before, 'Read-only rollout checks unexpectedly changed original records'
            checks.append('Maintenance lifted: database health, login page, logo and anonymous auth respond normally; inventory remains protected (401).')
            successful = True
            print('Migration applied and verified. Normal API access restored.', flush=True)
    except Exception as failure:
        error = str(failure)
        # After any DDL has begun, keep the app paused until the state is reviewed.
        if began_ddl and not marker.exists():
            marker.write_text(owner, encoding='ascii')
        raise
    finally:
        if process and process.poll() is None:
            process.terminate()
            process.wait(timeout=10)
        if not began_ddl and marker.exists() and marker.read_text(encoding='ascii') == owner:
            marker.unlink()
        status = 'APPLIED AND VERIFIED' if successful else ('REVIEW REQUIRED - MAINTENANCE RETAINED' if began_ddl else 'NOT APPLIED')
        record = {'phase': phase, 'status': status, 'started_at': started, 'finished_at': datetime.now(MANILA).isoformat(),
            'migration_sha256': migration_hash, 'backup': str(backup.relative_to(ROOT)), 'checks': checks, 'error': error,
            'maintenance_active': marker.exists()}
        (backup / 'application_rollout.json').write_text(json.dumps(record, indent=2), encoding='utf-8')
        report = ['# Phase ' + str(phase) + ' application database rollout', '', '**' + status + '**', '',
            'Approved by the user before application. No commit or push performed.', '',
            '- Started (Asia/Manila): ' + started,
            '- Finished (Asia/Manila): ' + record['finished_at'],
            '- Migration: `sql/' + migration_name + '`',
            '- Migration SHA-256: `' + migration_hash + '`',
            '- Verified backup: `' + record['backup'] + '`',
            '- Maintenance active: ' + str(marker.exists()), '', '## Verification', '', *['- ' + check for check in checks], '',
            '## Boundaries', '', '- No test users, inventory items, requests or POs were inserted into the application database.',
            '- Existing original records and receipt contents were verified without creating production stock transactions.',
            '- Authenticated workflows were already exercised on the isolated restore; final normal-browser review uses your existing account.',
            ('- Categories classify existing items only; no stock, request, PO or usage workflow was replaced.' if phase4 else '- Historical month closing is now separate from live stock; no existing historical values were automatically repaired.' if phase3 else '- Stock balance history begins at this cutover. Historical month-close accounting remains Phase 3.'), '']
        if error:
            report += ['## Error', '', error, '']
        (ROOT / ('PHASE' + str(phase) + '_APPLICATION_ROLLOUT.md')).write_text('\n'.join(report), encoding='utf-8')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--phase5', action='store_true', help='Coordinated approved Phase 5 code/database rollout')
    parser.add_argument('--target-root', help='Normal project receiving the isolated Phase 5 release')
    parser.add_argument('--phase3', action='store_true', help='Apply only the approved Phase 3 monthly migration after a matching rehearsal')
    parser.add_argument('--phase4', action='store_true', help='Apply only the approved Phase 4 category migration after a matching rehearsal')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--backup-directory', required=True)
    parser.add_argument('--maintenance-token', help='Continue this same operator-owned maintenance window after refreshing the backup')
    parser.add_argument('--php', default='C:/xampp/php/php.exe')
    parser.add_argument('--mysql-bin', default=str(Path(shutil.which('mysql.exe')).parent) if shutil.which('mysql.exe') else 'C:/tools/mysql/current/bin')
    run(parser.parse_args())
