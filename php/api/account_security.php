<?php
/** Phase 6A account/session foundation. No OTP enrollment or bypass lives here. */
function account_security_ready(PDO $pdo): bool {
    static $ready=null;
    if($ready!==null)return $ready;
    $count=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name IN ('security_version','account_kind')")->fetchColumn();
    if($count!==0&&$count!==2)json_error('Account security migration is incomplete',503);
    return $ready=$count===2;
}
function account_change_begin(PDO $pdo): void {
    if(!account_security_ready($pdo))json_error('Account security upgrade required',503);
    $pdo->beginTransaction();
    // One consistent order for this small internal user directory. Independent of stock locks.
    $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
    set_exception_handler(function(Throwable $error)use($pdo){
        $rolledBack=$pdo->inTransaction();if($rolledBack)$pdo->rollBack();
        error_log('Account security update failed: '.$error->getMessage());
        json_error($rolledBack?'Account update unavailable; no changes were saved':'Account operation could not finish; check its current status before retrying',503);
    });
}
function account_assert_current(PDO $pdo,array $actor): void {
    $q=$pdo->prepare('SELECT active,security_version,role FROM users WHERE username=?');
    $q->execute([$actor['username']]);$row=$q->fetch();
    if(!$row||(int)$row['active']!==1||(string)$row['security_version']!==(string)($actor['security_version']??'')||$row['role']!==$actor['role']){
        $pdo->rollBack();logout_user();json_error('Account changed; sign in again',401);
    }
}
