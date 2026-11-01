"""Compare typed JSON business values when mysqldump normalizes numeric rendering.

Never permits a changed string, boolean, array order, key or numeric value.
Original source fingerprints remain unchanged and are checked again afterward.
"""
import json
from decimal import Decimal,localcontext
from phase2_migration_rehearsal import ident


def canonical(value):
    if isinstance(value,Decimal):
        with localcontext()as context:
            context.prec=max(28,len(value.as_tuple().digits));return ('number',str(value.normalize()))
    if isinstance(value,bool):return ('bool',value)
    if value is None:return ('null',)
    if isinstance(value,str):return ('string',value)
    if isinstance(value,list):return ('array',tuple(canonical(v)for v in value))
    if isinstance(value,dict):return ('object',tuple((k,canonical(v))for k,v in sorted(value.items())))
    raise AssertionError('Unsupported JSON value')


def verify_audit_json(source,isolated,columns,checks):
    names=['before_json','after_json']
    raw_columns={t:cs for t,cs in columns.items()}
    raw_columns['audit_log']=[c for c in columns['audit_log'] if c not in names]
    assert source.fingerprint(raw_columns)==isolated.fingerprint(raw_columns),'Non-JSON data differs after restore'
    changed=[]
    for name in names:
        sql='SELECT id,IF('+ident(name)+" IS NULL,'NULL',HEX("+ident(name)+')) FROM audit_log ORDER BY id'
        def rows(db):
            result={}
            for line in db.execute([sql]).splitlines():
                key,hex_value=line.split('\t');result[key]=hex_value
            return result
        a,b=rows(source),rows(isolated);assert a.keys()==b.keys(),'Audit records missing after restore'
        for key in a:
            if a[key]==b[key]:continue
            assert a[key]!='NULL' and b[key]!='NULL','Audit JSON null changed'
            def decode(value):return canonical(json.loads(bytes.fromhex(value).decode('utf-8'),parse_float=Decimal,parse_int=Decimal))
            assert decode(a[key])==decode(b[key]),'Audit JSON business data changed for record '+key
            changed.append(key+':'+name)
    checks.append('Native restore: all non-JSON original values match exact hashes; audit JSON typed values, keys, strings, booleans and array order match. MySQL dump normalized numeric rendering in '+str(len(changed))+' audit fields; original application JSON was not changed.')
    return changed
