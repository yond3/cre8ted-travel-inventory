<?php
require __DIR__.'/config.php';
header('Cache-Control: no-store');header('Pragma: no-cache');
$pdo=get_pdo();if(!mfa_ready($pdo))json_error('MFA migration required',503);
mfa_secure_transport();
$actor=require_auth();
if($_SERVER['REQUEST_METHOD']==='GET'){
    $q=$pdo->prepare('SELECT enrollment_id,confirmed_at,revoked_at FROM user_authenticators WHERE user_id=?');$q->execute([$actor['id']]);$own=$q->fetch();
    $out=['enrolled'=>$own&&$own['revoked_at']===null,'method'=>'totp'];
    if($actor['role']==='super_admin')$out['users']=$pdo->query('SELECT u.id,u.username,u.name,u.role,u.account_kind,u.active,u.mfa_required,a.confirmed_at,a.revoked_at FROM users u LEFT JOIN user_authenticators a ON a.user_id=u.id ORDER BY u.id')->fetchAll();
    echo json_encode($out);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST')json_error('Method not allowed',405);
$body=read_json_body();account_change_begin($pdo);account_assert_current($pdo,$actor);
$self=mfa_user($pdo,(int)$actor['id']);mfa_rate($pdo,$actor['username'],'reauth');
if(!password_verify((string)($body['password']??''),$self['password_hash'])){
    mfa_attempt($pdo,$actor['username'],'reauth',false);$pdo->commit();json_error('Current password is incorrect',403);
}
$proof=$_SESSION['mfa_proof']??[];
if(!mfa_session_valid($pdo,$self)||empty($proof['verified_at'])||time()-(int)$proof['verified_at']>300){
    $q=$pdo->prepare('SELECT * FROM user_authenticators WHERE user_id=? AND revoked_at IS NULL');$q->execute([$actor['id']]);$auth=$q->fetch();
    $step=$auth?mfa_step(mfa_decrypt((int)$actor['id'],$auth['secret_blob']),(string)($body['code']??''),$auth['last_step']===null?null:(int)$auth['last_step']):null;
    if($step===null){mfa_attempt($pdo,$actor['username'],'reauth',false);$pdo->commit();json_error('Recent MFA verification required; use a fresh authenticator code',403);}
    $pdo->prepare('UPDATE user_authenticators SET last_step=? WHERE user_id=?')->execute([$step,$actor['id']]);
    $_SESSION['mfa_proof']=['security_version'=>(string)$self['security_version'],'enrollment_id'=>$auth['enrollment_id'],'verified_at'=>time(),'method'=>'totp'];
}
$action=$body['action']??'';
if($action==='grant'){
    require_super_admin();$target=mfa_user($pdo,(int)($body['user_id']??0));
    if((int)$target['id']===(int)$actor['id']&&($body['purpose']??'initial')==='reset')json_error('Use authenticated replacement or another administrator for your own recovery',409);
    $token=mfa_issue_grant($pdo,$actor,$target,(string)($body['purpose']??'initial'),(string)($body['reason']??''));
    $pdo->commit();echo json_encode(['grant'=>$token,'expires_in'=>900]);exit;
}
if($action==='replace'){
    $token=mfa_issue_grant($pdo,$actor,$self,'replace',(string)($body['reason']??'Requested authenticator replacement'));
    $pdo->commit();logout_user();echo json_encode(['grant'=>$token,'expires_in'=>900,'requires_login'=>true]);exit;
}
if($action==='recovery_codes'){
    $q=$pdo->prepare('SELECT enrollment_id FROM user_authenticators WHERE user_id=? AND revoked_at IS NULL');$q->execute([$actor['id']]);$id=$q->fetchColumn();
    if(!$id)json_error('Active authenticator required',409);
    $codes=mfa_recovery($pdo,$self,$id);
    // Revoke all sessions after changing recovery material; fresh authentication is required.
    $pdo->prepare('UPDATE users SET security_version=security_version+1 WHERE id=?')->execute([$self['id']]);
    mfa_audit($pdo,$actor,'mfa.recovery_regenerate',(int)$self['id']);$pdo->commit();logout_user();
    echo json_encode(['recovery_codes'=>$codes,'requires_login'=>true]);exit;
}
json_error('Unknown account security action');
