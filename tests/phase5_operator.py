"""Rehearse exact code publication and its failure behavior in temporary folders."""
import json
from phase5_rollout import FILES, digest, publish_files


def check_publication(release, temporary, checks):
    hashes = {f: digest(release / f) for f in FILES}
    for failure in (True,False):
        target = temporary / ('publication-failure' if failure else 'publication-success')
        backup = temporary / ('publication-failure-backup' if failure else 'publication-success-backup')
        backup.mkdir()
        marker = target / 'php/api/.inventory-maintenance'
        marker.parent.mkdir(parents=True)
        marker.write_text('test-owner',encoding='ascii')
        old = target / FILES[0]
        old.write_text('Existing uncommitted application content',encoding='utf-8')
        old_hash = digest(old)
        secret = target / '.env'
        secret.write_text('disposable-test-configuration',encoding='utf-8')
        try:
            publish_files(release,target,backup,hashes,fail_after=1 if failure else None)
        except RuntimeError as error:
            assert failure and 'Injected publication failure' in str(error)
        else:
            assert not failure, 'Publication failure injection did not fire'
        assert marker.read_text(encoding='ascii') == 'test-owner', 'Publication lifted maintenance'
        assert secret.read_text(encoding='utf-8') == 'disposable-test-configuration'
        originals = json.loads((backup / 'application_manifest.json').read_text(encoding='utf-8'))
        assert originals[FILES[0]] == old_hash and digest(backup / 'application' / FILES[0]) == old_hash
        assert all(originals[f] is None for f in FILES[1:])
        if not failure:
            assert all(digest(target / f) == h for f,h in hashes.items())
    checks.append('Operator publication rehearsed: existing uncommitted bytes backed up, new-file absence recorded, release hashes verified and environment untouched. Injected mid-publication failure retained maintenance and recovery evidence.')
