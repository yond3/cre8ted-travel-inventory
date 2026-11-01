<?php
// Operator-only first administrator enrollment; never web accessible.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$_SERVER['REQUEST_METHOD']='CLI';
require __DIR__.'/../php/api/config.php';
$args=getopt('',['username:','operator:','reason:','output:']);
foreach(['username','operator','reason','output']as $name)if(empty($args[$name]))throw new RuntimeException('Required option: '.$name);
$output=$args['output'];$directory=realpath(dirname($output));$web=realpath(__DIR__.'/../php');
if(!$directory||str_starts_with(strtolower($directory.DIRECTORY_SEPARATOR),strtolower($web.DIRECTORY_SEPARATOR))||file_exists($output))throw new RuntimeException('Choose a new private output file outside the web root');
$pdo=get_pdo();if(!mfa_ready($pdo))throw new RuntimeException('MFA migration required');
account_change_begin($pdo);
if((int)$pdo->query('SELECT enforce_mfa FROM auth_policy WHERE id=1')->fetchColumn()!==0)throw new RuntimeException('Bootstrap is closed after enforcement');
if((int)$pdo->query("SELECT COUNT(*) FROM users u JOIN user_authenticators a ON a.user_id=u.id WHERE u.active=1 AND u.role='super_admin' AND a.revoked_at IS NULL")->fetchColumn()!==0)throw new RuntimeException('An enrolled administrator exists; use authenticated administration');
$q=$pdo->prepare("SELECT * FROM users WHERE username=? AND active=1 AND role='super_admin' AND account_kind='named'");$q->execute([$args['username']]);$user=$q->fetch();
if(!$user)throw new RuntimeException('Bootstrap requires an existing active named Super Admin');
$actor=['username'=>'operator_bootstrap','name'=>(string)$args['operator'],'role'=>'system'];
$token=mfa_issue_grant($pdo,$actor,$user,'initial',(string)$args['reason']);
$handle=fopen($output,'x');if(!$handle)throw new RuntimeException('Cannot create the private authorization handover file');
fwrite($handle,json_encode(['username'=>$user['username'],'grant'=>$token,'expires_in'=>900],JSON_THROW_ON_ERROR));fclose($handle);
try{$pdo->commit();}catch(Throwable $e){unlink($output);throw $e;}
echo "First-administrator authorization written to the private output file. No user session was created.\n";
