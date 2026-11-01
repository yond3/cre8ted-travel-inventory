"""Coordinated Phase 5 deployment, dispatched by the existing rollout operator."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import urllib.request
import urllib.error
from datetime import datetime

from phase2_migration_rehearsal import Database, MANILA, NO_WINDOW, digest, ident, literal, receipt_manifest

FILES = [
 'php/api/config.php','php/api/stock_service.php','php/api/stock_requests.php',
 'php/api/stock_issues.php','php/api/inventory.php','php/api/stock.php','php/api/reports.php',
 'php/api/stock_request_service.php','php/api/stock_requests_v5.php',
 'php/stock-request-ui.js','php/index.html','php/department-api.html',
 'sql/schema.sql','sql/migration_stock_request_items.sql','sql/migration_close_reversed_requests.sql',
 'sql/apply_stock_integrity.py','sql/phase5_rollout.py','API.md',
 'tests/phase1_security.py','tests/phase2_migration_rehearsal.py',
 'tests/phase5_requests.py','tests/phase5_chrome.cjs','tests/phase5_csv.cjs',
 'tests/phase5_ui_syntax.cjs','tests/phase5_migration_preflight.py','tests/phase5_historical_correction.py',
 'tests/phase5_operator.py',
 'scripts/start_local_system.cmd',
]


def snapshot(db):
    columns = db.columns()
    # Original request status is the only original business field intentionally changed.
    evidence = {t: [c for c in cs if not (t == 'stock_requests' and c == 'status')]
                for t, cs in columns.items() if t != 'audit_log'}
    audit_max = db.execute(['SELECT COALESCE(MAX(id),0) FROM audit_log'])
    audit_cols = ','.join(ident(c) for c in columns['audit_log'])
    return dict(columns=columns, evidence=evidence, hashes=db.fingerprint(evidence),
        statuses=db.execute(['SELECT id,status FROM stock_requests ORDER BY id']),
        audit_max=audit_max, audit_cols=audit_cols,
        audits=db.execute(['SELECT ' + audit_cols + ' FROM audit_log WHERE id<=' + audit_max + ' ORDER BY id']))


def verify_corrected(db, before):
    assert db.fingerprint(before['evidence']) == before['hashes'], 'Original non-status evidence changed'
    expected = [row.split('\t')[0] + '\tClosed' if row.split('\t')[0] in ('18','26','27','31') else row
                for row in before['statuses'].splitlines()]
    assert db.execute(['SELECT id,status FROM stock_requests ORDER BY id']).splitlines() == expected, 'Unexpected request statuses'
    assert db.execute(['SELECT ' + before['audit_cols'] + ' FROM audit_log WHERE id<=' + before['audit_max'] + ' ORDER BY id']) == before['audits']
    assert db.execute(['SELECT COUNT(*) FROM audit_log WHERE id>' + before['audit_max']]) == '4', 'Unexpected new audit count'
    assert db.execute(["SELECT COUNT(*) FROM audit_log WHERE id>" + before['audit_max'] + " AND action='stock_request.migration_close' AND entity_type='stock_request' AND entity_id IN ('18','26','27','31')"]) == '4'
    assert db.execute(["SELECT COUNT(*) FROM stock_requests sr JOIN stock_request_items ri ON ri.request_id=sr.id JOIN stock_issues si ON si.id=sr.fulfilled_issue_id AND si.request_item_id=ri.id WHERE sr.id IN (18,26,27,31) AND sr.status='Closed' AND sr.legacy_status='Fulfilled' AND sr.request_revision=1 AND si.status='Voided' AND ri.released_qty=ri.approved_qty"]) == '4'
    assert db.execute(['SELECT COUNT(*) FROM stock_requests']) == db.execute(['SELECT COUNT(*) FROM stock_request_items'])
    assert db.execute(['SELECT COUNT(*) FROM items WHERE protected_qty IS NOT NULL']) == '0'
    assert db.execute(['SELECT COUNT(*) FROM inventory_flow_lock WHERE id=1']) == '1'
    for query in (
        "SELECT COUNT(*) FROM items i WHERE i.current_qty<>COALESCE((SELECT SUM(quantity_delta) FROM stock_movements m WHERE m.item_key=i.item_key AND m.balance_scope='storage'),0)",
        "SELECT COUNT(*) FROM equipment_deployments d WHERE d.qty<>COALESCE((SELECT SUM(quantity_delta) FROM stock_movements m WHERE m.item_key=d.item_key AND m.department=d.department AND m.balance_scope='department'),0)",
        "SELECT COUNT(*) FROM stock_issues si JOIN stock_requests sr ON sr.fulfilled_issue_id=si.id JOIN stock_request_items ri ON ri.request_id=sr.id WHERE si.request_item_id<>ri.id OR si.request_item_id IS NULL"):
        assert db.execute([query]) == '0', 'Request linkage/stock verification failed'


def publish_files(release, target, backup, manifest, *, fail_after=None):
    """Back up exact existing files before replacing anything; never touch env/uploads."""
    originals = {}
    for name, expected in manifest.items():
        src, dest = release / name, target / name
        assert digest(src) == expected, 'Release changed: ' + name
        if dest.exists():
            assert dest.is_file() and not dest.is_symlink()
            saved = backup / 'application' / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(dest, saved)
            originals[name] = digest(saved)
        else:
            originals[name] = None
    (backup / 'application_manifest.json').write_text(json.dumps(originals, indent=2), encoding='utf-8')
    for count, (name, expected) in enumerate(manifest.items(), 1):
        dest = target / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        staging = dest.with_name(dest.name + '.phase5-stage')
        assert not staging.exists(), 'Unexpected deployment staging file'
        shutil.copyfile(release / name, staging)
        assert digest(staging) == expected
        os.replace(staging, dest)
        if fail_after == count:
            raise RuntimeError('Injected publication failure')
    assert all(digest(target / name) == expected for name, expected in manifest.items())
    return originals


def run(args):
    assert args.apply, 'Explicit --apply required'
    assert not args.phase3 and not args.phase4, 'Select only one phase'
    release = Path(__file__).resolve().parents[1]
    target = Path(args.target_root).resolve()
    assert target != release and release.is_relative_to(target), 'Use the reviewed isolated release under its normal project'
    baseline = json.loads((release / 'PHASE5_BASELINE.json').read_text(encoding='utf-8'))
    assert Path(baseline['source']).resolve() == target
    assert all((target / f).is_file() and digest(target / f) == h for f, h in baseline['files'].items()), 'Normal source changed since isolation'
    for f in FILES:
        assert f in baseline['files'] or not (target / f).exists(), 'Unexpected new-file collision: ' + f
    hashes = {f: digest(release / f) for f in FILES}
    backup = Path(args.backup_directory).resolve()
    assert backup.parent == release / 'backups'
    m = json.loads((backup / 'manifest.json').read_text(encoding='utf-8'))
    rehearsal = json.loads((backup / 'rehearsal_result.json').read_text(encoding='utf-8'))
    assert rehearsal['status'] == 'PASSED' and rehearsal['phase'] == 5
    assert rehearsal['migration_sha256'] == hashes['sql/migration_stock_request_items.sql']
    assert rehearsal['historical_correction_sha256'] == hashes['sql/migration_close_reversed_requests.sql']
    assert rehearsal.get('operator_rehearsal_sha256') == hashes['sql/phase5_rollout.py'], 'Operator needs matching rehearsal'
    assert digest(backup / 'database.sql') == m['dump_sha256']
    assert receipt_manifest(backup / 'receipts') == m['receipts']
    marker = target / 'php/api/.inventory-maintenance'
    assert args.maintenance_token and marker.read_text(encoding='ascii') == args.maintenance_token, 'Verified operator-owned maintenance window required'
    php, binaries = Path(args.php), Path(args.mysql_bin)
    program = "require 'php/api/env.php'; echo json_encode([env('DB_HOST','127.0.0.1'),env('DB_PORT','3306'),env('DB_NAME','cre8ted_inventory'),env('DB_USER','root'),env('DB_PASSWORD','')]);"
    config = subprocess.run([str(php),'-r',program], cwd=target, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True, creationflags=NO_WINDOW)
    host, port, name, user, password = json.loads(config.stdout)
    assert name == m['database']
    db = Database(binaries / 'mysql.exe',host,port,name,user,password,source=True)
    identity = db.execute(['SELECT @@datadir,@@port,VERSION()'])
    assert identity.split('\t')[-1] == m['server_version'], 'Database server changed since rehearsal'
    started = datetime.now(MANILA).isoformat()
    checks, error, began, success = [], None, False, False
    def write_sql(sql):
        assert marker.read_text(encoding='ascii') == args.maintenance_token
        result = subprocess.run(db.command, input=('USE '+ident(name)+';\n'+sql).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=db.environment, timeout=90, creationflags=NO_WINDOW)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors='replace')[-1500:])
    try:
        assert db.columns() == m['source_columns'] and db.fingerprint(m['source_columns']) == m['source_tables'], 'Refresh backup/rehearsal: database changed'
        assert receipt_manifest(target / 'php/uploads/receipts') == m['receipts']
        activity = db.execute(["SELECT COUNT(*) FROM information_schema.processlist WHERE DB="+literal(name)+" AND ID<>CONNECTION_ID() AND COMMAND<>'Sleep'", "SELECT COUNT(*) FROM information_schema.innodb_trx t JOIN information_schema.processlist p ON p.ID=t.trx_mysql_thread_id WHERE p.DB="+literal(name)+" AND p.ID<>CONNECTION_ID()", "SELECT COUNT(*) FROM information_schema.events WHERE EVENT_SCHEMA="+literal(name)+" AND STATUS='ENABLED'"])
        assert all(v=='0' for v in activity.splitlines()), 'Database writers/events must be quiet'
        before = snapshot(db)
        assert 'stock_request_items' not in before['columns'] and 'inventory_categories' in before['columns']
        assert {'usage_version','usage_source'} <= set(before['columns']['usage_log'])
        from phase5_migration_preflight import exceptions
        found = exceptions(db)
        assert sorted(found.pop('fulfilled_with_voided_issue')) == [18,26,27,31], 'Historical correction scope changed'
        assert all(not ids for ids in found.values()), 'Unexpected migration exceptions'
        checks.append('Original files, database, receipt hashes, quiet writers and both rehearsed migrations verified.')
        # Back up application files before schema DDL. Publication occurs later under maintenance.
        original_files = {}
        for f in FILES:
            if (target / f).is_file():
                saved = backup / 'predeployment' / f
                saved.parent.mkdir(parents=True,exist_ok=True)
                shutil.copyfile(target / f,saved)
                original_files[f] = digest(saved)
            else:
                original_files[f] = None
        (backup / 'predeployment_manifest.json').write_text(json.dumps(original_files,indent=2),encoding='utf-8')
        assert db.execute(['SELECT @@datadir,@@port,VERSION()']) == identity
        began = True
        write_sql((release / 'sql/migration_stock_request_items.sql').read_text(encoding='utf-8'))
        assert db.fingerprint(m['source_columns']) == m['source_tables'], 'Structural migration changed original evidence'
        write_sql((release / 'sql/migration_close_reversed_requests.sql').read_text(encoding='utf-8'))
        verify_corrected(db,before)
        checks.append('Schema/line linkage verified; only four approved request statuses changed, four audits added, original evidence and stock preserved.')
        publish_files(release,target,backup,hashes)
        assert receipt_manifest(target / 'php/uploads/receipts') == m['receipts']
        verify_corrected(db,before)
        checks.append('Matching code/frontend files deployed with exact predeployment backups and verified release hashes.')
        assert marker.read_text(encoding='ascii') == args.maintenance_token
        # The orchestrator performs HTTP checks before explicitly reopening the window.
        success = True
    except Exception as exc:
        error = str(exc)
        raise
    finally:
        record = dict(phase=5,status='DEPLOYED - FINAL HEALTH CHECKS PENDING' if success else 'REVIEW REQUIRED' if began else 'NOT APPLIED', started_at=started,finished_at=datetime.now(MANILA).isoformat(),checks=checks,error=error,maintenance_active=marker.exists(),release_files=hashes,backup=str(backup),migration_sha256=hashes['sql/migration_stock_request_items.sql'],historical_correction_sha256=hashes['sql/migration_close_reversed_requests.sql'])
        (backup / 'application_rollout.json').write_text(json.dumps(record,indent=2),encoding='utf-8')
        (target / 'PHASE5_APPLICATION_ROLLOUT.md').write_text('# Phase 5 application rollout\n\n'+record['status']+'\n\n'+'\n'.join('- '+c for c in checks)+'\n\nMaintenance retained until final health checks. No commit or push.\n',encoding='utf-8')
    print('Phase 5 code/database deployed. Maintenance retained for final health verification.',flush=True)
