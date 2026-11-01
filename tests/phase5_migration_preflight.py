"""Read-only migration exception report. Does not apply SQL or change source data."""
from pathlib import Path
import argparse,json,subprocess,sys
from phase2_migration_rehearsal import Database,NO_WINDOW

def exceptions(database):
    queries={
      'fulfilled_without_issue':"SELECT sr.id FROM stock_requests sr LEFT JOIN stock_issues si ON si.id=sr.fulfilled_issue_id WHERE sr.status='Fulfilled' AND si.id IS NULL",
      'fulfilled_with_voided_issue':"SELECT sr.id FROM stock_requests sr JOIN stock_issues si ON si.id=sr.fulfilled_issue_id WHERE sr.status='Fulfilled' AND si.status<>'Active'",
      'quantity_differs_from_issue':"SELECT sr.id FROM stock_requests sr JOIN stock_issues si ON si.id=sr.fulfilled_issue_id WHERE sr.qty<>si.qty",
      'item_or_department_mismatch':"SELECT sr.id FROM stock_requests sr JOIN stock_issues si ON si.id=sr.fulfilled_issue_id WHERE NOT(sr.item_key<=>si.item_key) OR sr.department<>si.department",
      'duplicate_issue_reference':"SELECT fulfilled_issue_id FROM stock_requests WHERE fulfilled_issue_id IS NOT NULL GROUP BY fulfilled_issue_id HAVING COUNT(*)>1",
      'invalid_requested_quantity':"SELECT id FROM stock_requests WHERE qty<=0",
      'unmapped_pending_description':"SELECT id FROM stock_requests WHERE status='Pending' AND item_key IS NULL",
    }
    return {name:[int(v)for v in database.execute([sql]).splitlines()if v]for name,sql in queries.items()}

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source-root',required=True)
    p.add_argument('--mysql-bin',default='C:/tools/mysql/current/bin')
    args=p.parse_args();source=Path(args.source_root).resolve()
    program="require 'php/api/env.php'; echo json_encode([env('DB_HOST','127.0.0.1'),env('DB_PORT','3306'),env('DB_NAME','cre8ted_inventory'),env('DB_USER','root'),env('DB_PASSWORD','')]);"
    profile=subprocess.run(['C:/xampp/php/php.exe','-r',program],cwd=source,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True,creationflags=NO_WINDOW)
    host,port,name,user,password=json.loads(profile.stdout)
    database=Database(Path(args.mysql_bin)/'mysql.exe',host,port,name,user,password,source=True)
    report=exceptions(database)
    output=Path(__file__).resolve().parents[1]/'PHASE5_MIGRATION_EXCEPTIONS.json'
    output.write_text(json.dumps({'read_only':True,'request_ids':report,'normal_database_changed':False},indent=2),encoding='utf-8')
    print(json.dumps({k:len(v)for k,v in report.items()}))
    print('Read-only exception report: '+str(output))
