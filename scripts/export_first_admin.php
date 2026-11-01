<?php
/** Offline only: generates a private first-admin handover and guarded SQL import. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$args=getopt('',['username:','operator:','reason:','output-dir:']);
foreach(['username','operator','reason','output-dir']as $name)if(empty($args[$name]))throw new RuntimeException('Required option: '.$name);
$username=(string)$args['username'];$operator=trim((string)$args['operator']);$reason=trim((string)$args['reason']);
if(!preg_match('/^[a-z][a-z0-9_]{2,29}$/D',$username)||strlen($operator)<1||strlen($operator)>100||strlen($reason)<5||strlen($reason)>1000)throw new RuntimeException('Invalid username, operator or reason');
$destination=$args['output-dir'];$parent=realpath(dirname($destination));$public=realpath(__DIR__.'/../php');
if(!$parent||!$public||file_exists($destination)||str_starts_with(strtolower($parent.DIRECTORY_SEPARATOR),strtolower($public.DIRECTORY_SEPARATOR)))throw new RuntimeException('Choose a new private directory outside the public PHP root');
$token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$routine='phase6_first_admin_'.bin2hex(random_bytes(8));
function sql_text(string $value): string{return 'CONVERT(0x'.bin2hex($value).' USING utf8mb4)';}
$u=sql_text($username);$o=sql_text($operator);$r=sql_text($reason);$h=sql_text($hash);
$sql=<<<SQL
-- Select the intended existing database before importing. Apply Phase 6 migrations first.
-- Requires CREATE ROUTINE, ALTER ROUTINE, EXECUTE and normal table privileges.
-- No raw authorization is in this SQL. Import starts its 15-minute validity.
DELIMITER //
CREATE PROCEDURE `$routine`()
BEGIN
 DECLARE target_id INT DEFAULT NULL;
 DECLARE target_version BIGINT UNSIGNED;
 DECLARE policy_value INT DEFAULT NULL;
 DECLARE new_grant INT;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 -- Same directory lock order as application account/enrollment changes.
 SELECT id FROM users ORDER BY id FOR UPDATE;
 SELECT enforce_mfa INTO policy_value FROM auth_policy WHERE id=1 FOR UPDATE;
 IF policy_value IS NULL OR policy_value<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='First-admin import requires preparation policy'; END IF;
 IF EXISTS(SELECT 1 FROM users u JOIN user_authenticators a ON a.user_id=u.id WHERE u.active=1 AND u.role='super_admin' AND a.revoked_at IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='An enrolled administrator exists; use authenticated administration'; END IF;
 SELECT id,security_version INTO target_id,target_version FROM users WHERE username=$u AND active=1 AND role='super_admin' AND account_kind='named';
 IF target_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='An existing active named Super Admin is required'; END IF;
 IF EXISTS(SELECT 1 FROM auth_enrollment_grants WHERE token_hash=$h) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='This handover was already provisioned; generate a fresh bundle if expired'; END IF;
 UPDATE auth_enrollment_grants SET revoked_at=NOW() WHERE user_id=target_id AND consumed_at IS NULL AND revoked_at IS NULL;
 UPDATE auth_challenges SET consumed_at=NOW() WHERE user_id=target_id AND purpose='enroll' AND consumed_at IS NULL;
 INSERT INTO auth_enrollment_grants(user_id,token_hash,security_version,purpose,issued_by,reason,expires_at) VALUES(target_id,$h,target_version,'initial','operator_bootstrap',$r,NOW()+INTERVAL 15 MINUTE);
 SET new_grant=LAST_INSERT_ID();
 INSERT INTO audit_log(actor_username,actor_name,actor_role,action,entity_type,entity_id,after_json,ip_address) VALUES('operator_bootstrap',$o,'system','mfa.grant','user',CAST(target_id AS CHAR),JSON_OBJECT('purpose','initial','reason',$r,'previous_enrollment_id',NULL,'grant_id',new_grant,'provisioning','operator_sql_import'),NULL);
 COMMIT;
END//
CALL `$routine`()//
DROP PROCEDURE `$routine`//
DELIMITER ;
SQL;
if(!mkdir($destination,0700))throw new RuntimeException('Cannot create private output directory');
$files=[];
try{
 foreach(['bootstrap.sql'=>$sql,'handover.json'=>json_encode(['username'=>$username,'grant'=>$token,'expires_in_after_import'=>900],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),'README.txt'=>"Private operator bundle. Do not upload to the website. Import bootstrap.sql through the database control panel into the correct migrated database. Confirm success and a required mfa.grant audit event before using handover.json at the existing login setup page. This creates no account/session and changes no password/role. Authorization expires 15 minutes after import. Re-import is rejected. After an enrolled admin exists, use their authenticated account security controls. If an import errors, its database transaction rolls back; a generated routine may remain and the hosting operator can remove only that exact routine. Keep normal database/key backups separately. Delete this private bundle after successful enrollment. No all-admin emergency recovery is provided by this tool.\n"] as $name=>$content){
  $file=$destination.DIRECTORY_SEPARATOR.$name;$handle=fopen($file,'x');if(!$handle)throw new RuntimeException('Cannot create private output file');$files[]=$file;
  try{if(fwrite($handle,$content)!==strlen($content))throw new RuntimeException('Incomplete private output');}finally{fclose($handle);}
 }
}catch(Throwable $e){foreach($files as $file)unlink($file);rmdir($destination);throw $e;}
echo "Private first-admin SQL import and handover generated. No database connection was made. No account or session was created.\n";
