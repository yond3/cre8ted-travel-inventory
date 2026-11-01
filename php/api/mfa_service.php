<?php
/** One authentication workflow: pending login/enrollment are not authenticated users. */
function mfa_ready(PDO $pdo): bool {
    static $ready=null;if($ready!==null)return $ready;
    $n=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('auth_policy','user_authenticators','auth_enrollment_grants','auth_challenges','user_recovery_codes')")->fetchColumn();
    $c=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='users' AND column_name='mfa_required') OR (table_name='login_attempts' AND column_name='stage'))")->fetchColumn();
    if($n===0&&$c===0&&env('MFA_SCHEMA_REQUIRED','0')!=='1')return $ready=false;
    if($n!==5||$c!==2||!account_security_ready($pdo))json_error('MFA migration is incomplete',503);
    if((int)$pdo->query('SELECT COUNT(*) FROM auth_policy WHERE id=1')->fetchColumn()!==1)json_error('MFA policy is unavailable',503);
    mfa_key();return $ready=true;
}
function mfa_key(): string {
    $key=base64_decode((string)env('MFA_ENCRYPTION_KEY',''),true);
    if($key===false||strlen($key)!==32||!in_array('aes-256-gcm',openssl_get_cipher_methods(),true))json_error('MFA encryption is unavailable',503);
    return $key;
}
/** Shared by login UI readiness and every existing MFA transport guard. */
function mfa_transport_allowed(): bool {
    if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')return true;
    $authority=$_SERVER['HTTP_HOST']??'';
    $host=parse_url('http://'.$authority,PHP_URL_HOST);
    if(env('MFA_ALLOW_LOCAL_HTTP','0')==='1'&&in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)&&in_array($host,['127.0.0.1','localhost','[::1]'],true))return true;
    if(env('MFA_ALLOW_HTTP','0')!=='1')return false;
    $configured=normalize_http_origin((string)env('MFA_HTTP_ORIGIN',''));
    $requested=normalize_http_origin('http://'.$authority);
    return $configured!==null&&str_starts_with($configured,'http://')&&$requested===$configured;
}
function mfa_secure_transport(): void {
    if(PHP_SAPI==='cli'||mfa_transport_allowed())return;
    json_error('Secure HTTPS is required for authentication; HTTP access must be explicitly configured for this address',403);
}
function mfa_encrypt(int $id,string $secret): string {
    $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($secret,'aes-256-gcm',mfa_key(),OPENSSL_RAW_DATA,$nonce,$tag,'cre8ted-totp-v1:'.$id,16);
    if($cipher===false)throw new RuntimeException('MFA encryption failed');
    return base64_encode($nonce.$tag.$cipher);
}
function mfa_decrypt(int $id,string $blob): string {
    $bytes=base64_decode($blob,true);
    if($bytes===false||strlen($bytes)<29)throw new RuntimeException('Invalid MFA ciphertext');
    $secret=openssl_decrypt(substr($bytes,28),'aes-256-gcm',mfa_key(),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),'cre8ted-totp-v1:'.$id);
    if($secret===false)throw new RuntimeException('MFA ciphertext authentication failed');return $secret;
}
function mfa_totp(string $secret): \OTPHP\TOTP {
    static $loaded=false;if(!$loaded){$path=__DIR__.'/../../vendor/autoload.php';if(!is_file($path))json_error('Authenticator dependency unavailable',503);require_once $path;$loaded=true;}
    return \OTPHP\TOTP::create($secret,30,'sha1',6);
}
function mfa_step(string $secret,string $code,?int $last): ?int {
    if(!preg_match('/^[0-9]{6}$/D',$code))return null;
    $totp=mfa_totp($secret);$now=intdiv(time(),30);
    foreach([0,-1,1]as $delta){$step=$now+$delta;if(($last===null||$step>$last)&&hash_equals($totp->at($step*30),$code))return $step;}
    return null;
}
function mfa_required(PDO $pdo,array $row): bool {
    return (int)($row['mfa_required']??0)===1||(int)$pdo->query('SELECT enforce_mfa FROM auth_policy WHERE id=1')->fetchColumn()===1;
}
function mfa_session_valid(PDO $pdo,array $row): bool {
    if(!mfa_ready($pdo))return true;
    mfa_secure_transport();
    if(!mfa_required($pdo,$row))return true;
    $proof=$_SESSION['mfa_proof']??[];
    if($row['account_kind']!=='named'||(string)($proof['security_version']??'')!==(string)$row['security_version'])return false;
    $q=$pdo->prepare('SELECT enrollment_id FROM user_authenticators WHERE user_id=? AND revoked_at IS NULL');$q->execute([$row['id']]);$id=$q->fetchColumn();
    return is_string($id)&&hash_equals($id,(string)($proof['enrollment_id']??''));
}
function mfa_audit(PDO $pdo,array $actor,string $action,int $target,array $data=[]): void {
    $pdo->prepare('INSERT INTO audit_log(actor_username,actor_name,actor_role,action,entity_type,entity_id,after_json,ip_address) VALUES(?,?,?,?,?,?,?,?)')->execute([
        $actor['username'],$actor['name'],$actor['role'],$action,'user',(string)$target,json_encode($data,JSON_THROW_ON_ERROR),get_client_ip()]);
}
function mfa_user(PDO $pdo,int $id): array {
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$row=$q->fetch();if(!$row)json_error('Account unavailable',401);return $row;
}
function mfa_revoke(PDO $pdo,int $id): void {
    $pdo->prepare('UPDATE user_authenticators SET revoked_at=COALESCE(revoked_at,NOW()) WHERE user_id=?')->execute([$id]);
    $pdo->prepare('UPDATE user_recovery_codes SET revoked_at=COALESCE(revoked_at,NOW()) WHERE user_id=?')->execute([$id]);
    $pdo->prepare('UPDATE auth_enrollment_grants SET revoked_at=COALESCE(revoked_at,NOW()) WHERE user_id=? AND consumed_at IS NULL')->execute([$id]);
    $pdo->prepare('UPDATE auth_challenges SET consumed_at=COALESCE(consumed_at,NOW()) WHERE user_id=?')->execute([$id]);
}
function mfa_attempt(PDO $pdo,string $username,string $stage,bool $success): void {
    $pdo->prepare('INSERT INTO login_attempts(username,ip_address,success,stage) VALUES(?,?,?,?)')->execute([$username,get_client_ip(),$success?1:0,$stage]);
}
function mfa_rate(PDO $pdo,string $username,string $stage): void {
    $q=$pdo->prepare("SELECT SUM(username=?) AS per_user, SUM(ip_address=?) AS per_ip FROM login_attempts WHERE stage=? AND success=0 AND created_at>=NOW()-INTERVAL 15 MINUTE AND (username=? OR ip_address=?)");
    $q->execute([$username,get_client_ip(),$stage,$username,get_client_ip()]);$n=$q->fetch();
    if((int)$n['per_user']>=5||(int)$n['per_ip']>=30)json_error('Too many verification attempts; wait 15 minutes before retrying',429);
}
function mfa_challenge_rate(PDO $pdo,string $username): void {
    $perUser=(int)env('MFA_CHALLENGES_PER_USER','10');$perIp=(int)env('MFA_CHALLENGES_PER_IP','60');
    if($perUser<2||$perIp<2||$perUser>10000||$perIp>10000)json_error('MFA rate-limit configuration is invalid',503);
    $q=$pdo->prepare("SELECT SUM(username=?) AS per_user,SUM(ip_address=?) AS per_ip FROM login_attempts WHERE stage='password' AND success=1 AND created_at>=NOW()-INTERVAL 5 MINUTE AND (username=? OR ip_address=?)");
    $q->execute([$username,get_client_ip(),$username,get_client_ip()]);$n=$q->fetch();
    if((int)$n['per_user']>=$perUser||(int)$n['per_ip']>=$perIp)json_error('Too many login starts; wait five minutes before trying again',429);
}
function mfa_preauth_csrf(): void {
    $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(!is_string($token)||empty($_SESSION['preauth_csrf'])||!hash_equals($_SESSION['preauth_csrf'],$token))json_error('Refresh the login page and try again',403);
}
function mfa_pending_response(PDO $pdo): array {
    $pending=$_SESSION['mfa_pending']??null;if(!is_array($pending))return ['authenticated'=>false];
    $q=$pdo->prepare('SELECT purpose,expires_at FROM auth_challenges WHERE id=? AND consumed_at IS NULL AND expires_at>NOW()');$q->execute([$pending['id']]);$row=$q->fetch();
    if(!$row){unset($_SESSION['mfa_pending']);return ['authenticated'=>false];}
    return ['authenticated'=>false,'challenge'=>$row,'csrf_token'=>$_SESSION['preauth_csrf']];
}
function mfa_new_pending(PDO $pdo,array $user,string $purpose,?int $grantId=null,?string $blob=null): array {
    // Called with directory locks held and a current account row.
    if(!session_regenerate_id(true)){logout_user();json_error('Unable to create a secure challenge; start again',503);}
    unset($_SESSION['user'],$_SESSION['mfa_proof']);
    $id=bin2hex(random_bytes(32));$binding=bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_challenges(id,user_id,purpose,security_version,binding_hash,grant_id,secret_blob,expires_at) VALUES(?,?,?,?,?,?,?,NOW()+INTERVAL 5 MINUTE)")
        ->execute([$id,$user['id'],$purpose,$user['security_version'],hash('sha256',$binding),$grantId,$blob]);
    $_SESSION['mfa_pending']=['id'=>$id,'binding'=>$binding];$_SESSION['preauth_csrf']=bin2hex(random_bytes(32));
    return ['status'=>'challenge','challenge'=>['purpose'=>$purpose],'csrf_token'=>$_SESSION['preauth_csrf']];
}
function mfa_issue_grant(PDO $pdo,array $actor,array $user,string $purpose,string $reason): string {
    if((int)$user['active']!==1||$user['account_kind']!=='named')json_error('Enrollment requires an active named account',409);
    if(!in_array($purpose,['initial','reset','replace'],true)||strlen(trim($reason))<5||strlen($reason)>1000)json_error('A valid enrollment purpose and reason are required');
    $q=$pdo->prepare('SELECT enrollment_id FROM user_authenticators WHERE user_id=? AND revoked_at IS NULL');$q->execute([$user['id']]);$existing=$q->fetchColumn();
    if($purpose==='initial'&&$existing)json_error('Account is already enrolled; use explicit recovery or replacement',409);
    if($purpose==='reset'){
        mfa_revoke($pdo,(int)$user['id']);
        $pdo->prepare('UPDATE users SET security_version=security_version+1,mfa_required=1 WHERE id=?')->execute([$user['id']]);
        $user=mfa_user($pdo,(int)$user['id']);
    }
    $pdo->prepare('UPDATE auth_enrollment_grants SET revoked_at=NOW() WHERE user_id=? AND consumed_at IS NULL AND revoked_at IS NULL')->execute([$user['id']]);
    $pdo->prepare("UPDATE auth_challenges SET consumed_at=NOW() WHERE user_id=? AND purpose='enroll' AND consumed_at IS NULL")->execute([$user['id']]);
    $token=bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO auth_enrollment_grants(user_id,token_hash,security_version,purpose,issued_by,reason,expires_at) VALUES(?,?,?,?,?,?,NOW()+INTERVAL 15 MINUTE)')->execute([
        $user['id'],hash('sha256',$token),$user['security_version'],$purpose,$actor['username'],trim($reason)]);
    mfa_audit($pdo,$actor,'mfa.grant',(int)$user['id'],['purpose'=>$purpose,'reason'=>trim($reason),'previous_enrollment_id'=>$existing?:null,'grant_id'=>(int)$pdo->lastInsertId()]);
    return $token;
}
function mfa_recovery(PDO $pdo,array $user,string $enrollment): array {
    $pdo->prepare('UPDATE user_recovery_codes SET revoked_at=COALESCE(revoked_at,NOW()) WHERE user_id=?')->execute([$user['id']]);
    $codes=[];$q=$pdo->prepare('INSERT INTO user_recovery_codes(user_id,enrollment_id,code_hash) VALUES(?,?,?)');
    for($i=0;$i<10;$i++){$raw=bin2hex(random_bytes(16));$codes[]=implode('-',str_split($raw,8));$q->execute([$user['id'],$enrollment,hash('sha256',$raw)]);}return $codes;
}
function mfa_fail(PDO $pdo,array $user,string $id,string $method='totp'): never {
    $pdo->prepare('UPDATE auth_challenges SET attempts=attempts+1 WHERE id=?')->execute([$id]);
    mfa_attempt($pdo,$user['username'],'mfa',false);
    mfa_audit($pdo,$user,'mfa.verify_failed',(int)$user['id']);$pdo->commit();
    json_error($method==='recovery'?'Recovery code invalid, already used or replaced. Enter one complete unused recovery code from your latest saved set.':'Code invalid, already used or outside the allowed time window. Use the next code and check automatic device time.',401);
}
function mfa_verify_pending(PDO $pdo,array $body): array {
    mfa_preauth_csrf();$pending=$_SESSION['mfa_pending']??null;
    if(!is_array($pending))json_error('Login challenge expired; start again',401);
    account_change_begin($pdo);
    $q=$pdo->prepare('SELECT * FROM auth_challenges WHERE id=? AND consumed_at IS NULL AND expires_at>NOW() FOR UPDATE');$q->execute([$pending['id']]);$c=$q->fetch();
    if(!$c||!hash_equals($c['binding_hash'],hash('sha256',(string)$pending['binding'])))json_error('Login challenge expired; start again',401);
    $user=mfa_user($pdo,(int)$c['user_id']);
    if((int)$user['active']!==1||$user['account_kind']!=='named'||(string)$user['security_version']!==(string)$c['security_version'])json_error('Account or enrollment changed; start again',401);
    mfa_rate($pdo,$user['username'],'mfa');if((int)$c['attempts']>=5)json_error('Verification limit reached; start again after the cooldown',429);
    if($c['purpose']==='enroll'){
        $g=$pdo->prepare('SELECT * FROM auth_enrollment_grants WHERE id=? AND user_id=? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>NOW() FOR UPDATE');$g->execute([$c['grant_id'],$user['id']]);$grant=$g->fetch();
        if(!$grant||(string)$grant['security_version']!==(string)$user['security_version'])json_error('Enrollment authorization expired or replaced',401);
        $step=mfa_step(mfa_decrypt((int)$user['id'],$c['secret_blob']),(string)($body['code']??''),null);
        if($step===null)mfa_fail($pdo,$user,$c['id']);
        $enrollment=bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO user_authenticators(user_id,enrollment_id,secret_blob,confirmed_at,last_step) VALUES(?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE enrollment_id=VALUES(enrollment_id),secret_blob=VALUES(secret_blob),confirmed_at=NOW(),revoked_at=NULL,last_step=VALUES(last_step)')->execute([$user['id'],$enrollment,$c['secret_blob'],$step]);
        $codes=mfa_recovery($pdo,$user,$enrollment);
        $pdo->prepare('UPDATE users SET mfa_required=1,security_version=security_version+1 WHERE id=?')->execute([$user['id']]);
        $pdo->prepare('UPDATE auth_enrollment_grants SET consumed_at=NOW() WHERE id=?')->execute([$grant['id']]);
        $pdo->prepare('UPDATE auth_challenges SET consumed_at=COALESCE(consumed_at,NOW()) WHERE user_id=?')->execute([$user['id']]);
        mfa_audit($pdo,$user,'mfa.enroll',(int)$user['id'],['enrollment_id'=>$enrollment,'purpose'=>$grant['purpose']]);
        $pdo->commit();logout_user();return ['status'=>'enrolled','recovery_codes'=>$codes,'requires_login'=>true];
    }
    $q=$pdo->prepare('SELECT * FROM user_authenticators WHERE user_id=? AND revoked_at IS NULL');$q->execute([$user['id']]);$auth=$q->fetch();
    if(!$auth)json_error('Authenticator unavailable; request authorized re-enrollment',409);
    $method='totp';
    if(!empty($body['recovery_code'])){
        $raw=strtolower(str_replace('-','',trim((string)$body['recovery_code'])));
        $q=$pdo->prepare('SELECT id FROM user_recovery_codes WHERE user_id=? AND enrollment_id=? AND code_hash=? AND consumed_at IS NULL AND revoked_at IS NULL FOR UPDATE');$q->execute([$user['id'],$auth['enrollment_id'],hash('sha256',$raw)]);$recovery=$q->fetchColumn();
        if(!$recovery)mfa_fail($pdo,$user,$c['id'],'recovery');
        $pdo->prepare('UPDATE user_recovery_codes SET consumed_at=NOW() WHERE id=?')->execute([$recovery]);$method='recovery';
    }else{
        $step=mfa_step(mfa_decrypt((int)$user['id'],$auth['secret_blob']),(string)($body['code']??''),$auth['last_step']===null?null:(int)$auth['last_step']);
        if($step===null)mfa_fail($pdo,$user,$c['id']);
        $pdo->prepare('UPDATE user_authenticators SET last_step=? WHERE user_id=?')->execute([$step,$user['id']]);
    }
    $pdo->prepare('UPDATE auth_challenges SET consumed_at=NOW() WHERE id=?')->execute([$c['id']]);
    mfa_attempt($pdo,$user['username'],'mfa',true);mfa_audit($pdo,$user,'auth.login',(int)$user['id'],['method'=>$method]);$pdo->commit();
    login_user($user);$_SESSION['mfa_proof']=['security_version'=>(string)$user['security_version'],'enrollment_id'=>$auth['enrollment_id'],'verified_at'=>time(),'method'=>$method];
    return ['status'=>'ok','user'=>public_user($user),'csrf_token'=>get_csrf_token()];
}
function mfa_auth_post(PDO $pdo,array $body): array {
    mfa_secure_transport();
    header('Cache-Control: no-store');
    $action=$body['action']??'login';
    if($action==='verify')return mfa_verify_pending($pdo,$body);
    if($action==='cancel'){
        mfa_preauth_csrf();$pending=$_SESSION['mfa_pending']??null;
        if(is_array($pending)){
            account_change_begin($pdo);
            $pdo->prepare('UPDATE auth_challenges SET consumed_at=COALESCE(consumed_at,NOW()) WHERE id=? AND binding_hash=?')->execute([$pending['id'],hash('sha256',(string)$pending['binding'])]);
            $pdo->commit();
        }
        logout_user();return ['status'=>'cancelled'];
    }
    if(!in_array($action,['login','enroll_begin'],true))json_error('Unknown authentication action');
    $username=trim((string)($body['username']??''));$password=(string)($body['password']??'');
    if($username===''||$password==='')json_error('username and password are required');
    if(login_lockout_seconds_remaining(strtolower($username),get_client_ip())>0)json_error('Too many failed login attempts; try again later',429);
    $actor=authenticate_user($username,$password);record_login_attempt(strtolower($username),get_client_ip(),$actor!==null);
    if(!$actor)json_error('Invalid username or password',401);
    account_change_begin($pdo);account_assert_current($pdo,$actor);$user=mfa_user($pdo,(int)$actor['id']);
    if($action==='enroll_begin'||mfa_required($pdo,$user))mfa_challenge_rate($pdo,$user['username']);
    if($action==='enroll_begin'){
        mfa_rate($pdo,$user['username'],'mfa');
        if($user['account_kind']!=='named')json_error('A named account and verified enrollment authorization are required',409);
        $q=$pdo->prepare('SELECT * FROM auth_enrollment_grants WHERE user_id=? AND token_hash=? AND security_version=? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>NOW() FOR UPDATE');$q->execute([$user['id'],hash('sha256',(string)($body['grant']??'')),$user['security_version']]);$grant=$q->fetch();
        if(!$grant){mfa_attempt($pdo,$user['username'],'mfa',false);$pdo->commit();json_error('Enrollment authorization invalid or expired',401);}
        $pdo->prepare("UPDATE auth_challenges SET consumed_at=NOW() WHERE user_id=? AND purpose='enroll' AND consumed_at IS NULL")->execute([$user['id']]);
        mfa_totp('JBSWY3DPEHPK3PXP');$totp=\OTPHP\TOTP::create();$totp->setLabel($user['username']);$totp->setIssuer(env('MFA_ISSUER','Cre8ted Inventory'));
        $result=mfa_new_pending($pdo,$user,'enroll',(int)$grant['id'],mfa_encrypt((int)$user['id'],$totp->getSecret()));
        mfa_audit($pdo,$user,'mfa.enroll_start',(int)$user['id']);$pdo->commit();
        return $result+['secret'=>$totp->getSecret(),'uri'=>$totp->getProvisioningUri()];
    }
    if(mfa_required($pdo,$user)){
        $result=mfa_new_pending($pdo,$user,'login');$pdo->commit();return $result;
    }
    // Explicit preparation stage only. Existing users are not enrolled by guessing identity.
    mfa_audit($pdo,$user,'auth.login',(int)$user['id'],['method'=>'password_preparation']);$pdo->commit();login_user($user);
    return ['status'=>'ok','user'=>public_user($user),'csrf_token'=>get_csrf_token()];
}
