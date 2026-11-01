"""Verify the specifically approved Option A correction on the isolated restore only."""
from pathlib import Path


def check_correction(db, checks):
    db.verify_target()  # The source/read-only connection cannot execute this helper.
    sql = (Path(__file__).resolve().parents[1] / 'sql' / 'migration_close_reversed_requests.sql').read_text(encoding='utf-8')
    columns = db.columns()
    before = db.fingerprint(columns)
    preserved = {t: c for t, c in columns.items() if t not in ('stock_requests', 'stock_request_items', 'audit_log')}
    request_evidence = {'stock_requests': [c for c in columns['stock_requests'] if c not in ('status', 'closed_reason', 'request_revision')]}
    line_evidence = {'stock_request_items': [c for c in columns['stock_request_items'] if c != 'released_qty']}
    evidence_before = db.fingerprint(request_evidence | line_evidence)
    audit_columns = ','.join('`' + c + '`' for c in columns['audit_log'])
    old_audit = db.execute(['SELECT ' + audit_columns + ' FROM audit_log ORDER BY id'])
    # Audit insertion failure must roll back both request state and line releases.
    db.execute(["CREATE TRIGGER phase5_correction_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected correction audit failure'"], write=True)
    try:
        try:
            db.execute([sql], write=True)
        except RuntimeError as error:
            assert 'Injected correction audit failure' in str(error), str(error)
        else:
            raise AssertionError('Correction unexpectedly bypassed audit failure')
        assert db.fingerprint(columns) == before, 'Failed correction changed data'
    finally:
        db.execute(['DROP TRIGGER phase5_correction_audit_failure'], write=True)
    checks.append('Option A audit failure rolls back the complete historical correction.')
    db.execute([sql], write=True)
    assert db.fingerprint(preserved) == {t: before[t] for t in preserved}, 'Correction changed stock, issues or unrelated tables'
    assert db.fingerprint(request_evidence | line_evidence) == evidence_before, 'Historical evidence changed'
    assert db.execute(['SELECT ' + audit_columns + " FROM audit_log WHERE action<>'stock_request.migration_close' ORDER BY id"]) == old_audit
    result = db.execute(["SELECT sr.id,sr.status,sr.legacy_status,sr.request_revision,ri.released_qty=ri.approved_qty,si.status FROM stock_requests sr JOIN stock_request_items ri ON ri.request_id=sr.id JOIN stock_issues si ON si.id=sr.fulfilled_issue_id WHERE sr.id IN (18,26,27,31) ORDER BY sr.id"])
    assert result.splitlines() == [f'{i}\tClosed\tFulfilled\t1\t1\tVoided' for i in (18,26,27,31)], result
    assert db.execute(["SELECT COUNT(*) FROM audit_log WHERE action='stock_request.migration_close'"]) == '4'
    # Every other request's original status must still agree with its saved legacy value.
    assert db.execute(["SELECT COUNT(*) FROM stock_requests WHERE id NOT IN (18,26,27,31) AND status<>legacy_status"]) == '0'
    corrected = db.fingerprint(columns)
    db.execute([sql], write=True)
    assert db.fingerprint(columns) == corrected, 'Retry duplicated audit or changed data'
    checks.append('Option A closes only requests 18, 26, 27 and 31, preserves legacy status/voided issues and historical evidence, releases their line remainder, and records four migration audits without changing stock.')
    checks.append('Option A retry is idempotent; no duplicate correction audits or revision increments.')
    # A stale/mixed state must be rejected before writing any correction.
    db.execute(['START TRANSACTION', 'UPDATE stock_requests SET request_revision=2 WHERE id=18', 'COMMIT'], write=True)
    stale = db.fingerprint(columns)
    try:
        db.execute([sql], write=True)
    except RuntimeError as error:
        assert 'Reviewed historical requests changed' in str(error), str(error)
    else:
        raise AssertionError('Changed historical state was accepted')
    assert db.fingerprint(columns) == stale
    db.execute(['UPDATE stock_requests SET request_revision=1 WHERE id=18'], write=True)
    checks.append('Option A rejects changed or partially corrected records without additional data changes.')
