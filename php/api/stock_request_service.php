<?php
/** Phase 5 request orchestration. Physical writes still use stock_service.php. */
function request_schema_available(PDO $pdo): bool {
    static $available;
    if ($available === null) {
        $count=(int)$pdo->query("SELECT
          (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('stock_request_items','inventory_flow_lock'))+
          (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND
          ((table_name='items' AND column_name='protected_qty') OR (table_name='stock_issues' AND column_name='request_item_id')
          OR (table_name='stock_requests' AND column_name='request_revision')))")->fetchColumn();
        if($count!==0&&$count!==5)json_error('Incomplete stock request migration. Keep writes paused and review the rollout.',503);
        $available=$count===5;
    }
    return $available;
}
function request_require_schema(PDO $pdo): void {
    if (!request_schema_available($pdo)) json_error('Stock request upgrade required before using this workflow.',503);
}
function request_actor_id(PDO $pdo, array $actor): int {
    $q=$pdo->prepare('SELECT id FROM users WHERE username=? AND active=1');
    $q->execute([$actor['username']]); $id=$q->fetchColumn();
    if (!$id) json_error('Active account required',403);
    return (int)$id;
}
function request_revision(array $body,array $row): void {
    $v=$body['request_revision']??null;
    if ((!is_int($v)&&!is_string($v))||!ctype_digit((string)$v)) json_error('request_revision is required; refresh the request',428);
    if ((string)$v!==(string)$row['request_revision']) json_error('Request changed. Refresh and review before retrying.',409);
}
function request_reason($value,string $label='Reason'): string {
    return parse_required_text($value??'',1000,$label);
}
function request_collection_date($value): ?string {
    if($value===null||$value==='')return null;
    if(!is_string($value))json_error('Invalid collection date');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$date||$date->format('Y-m-d')!==$value)json_error('Collection date must be YYYY-MM-DD');
    return $value;
}
function request_remaining(array $line): int {
    return max(0,stock_cents($line['approved_qty']??0)-stock_cents($line['issued_qty'])-stock_cents($line['released_qty']));
}
function request_lines(PDO $pdo,int $id): array {
    $q=$pdo->prepare("SELECT ri.*,i.current_qty,i.protected_qty,i.item_type,i.active,i.min_qty,i.max_qty,
      COALESCE((SELECT SUM(si.qty) FROM stock_issues si WHERE si.request_item_id=ri.id AND si.status='Active'),0) issued_qty
      FROM stock_request_items ri LEFT JOIN items i ON i.item_key=ri.item_key
      WHERE ri.request_id=? ORDER BY ri.line_no");
    $q->execute([$id]);return $q->fetchAll();
}
function request_reserved(PDO $pdo,string $key): int {
    $q=$pdo->prepare("SELECT COALESCE(SUM(GREATEST(0,COALESCE(ri.approved_qty,0)-ri.released_qty-
       COALESCE((SELECT SUM(si.qty) FROM stock_issues si WHERE si.request_item_id=ri.id AND si.status='Active'),0))),0)
       FROM stock_request_items ri JOIN stock_requests sr ON sr.id=ri.request_id
       WHERE ri.item_key=? AND sr.status IN ('Approved','Partially Issued')");
    $q->execute([$key]);return stock_cents($q->fetchColumn());
}
function request_availability(PDO $pdo,array $item): array {
    $reserved=request_reserved($pdo,$item['item_key']);$physical=stock_cents($item['current_qty']);
    $protected=stock_cents($item['protected_qty']??0);
    return ['physical_qty'=>$physical/100,'reserved_qty'=>$reserved/100,'protected_qty'=>$item['protected_qty']===null?null:$protected/100,
      'allocatable_qty'=>max(0,$physical-$reserved-$protected)/100,'uncommitted_qty'=>max(0,$physical-$reserved)/100,
      'commitment_shortfall'=>max(0,$reserved-$physical)/100];
}
function request_snapshot(PDO $pdo,int $id): array {
    $q=$pdo->prepare('SELECT * FROM stock_requests WHERE id=?');$q->execute([$id]);$r=$q->fetch();
    if (!$r) json_error('Unknown stock request',404);
    assert_stock_request_access(require_auth(),$r);
    $r['id']=(int)$r['id'];$r['self_approved']=$r['requested_by_id']!==null&&$r['reviewed_by_id']!==null&&(int)$r['requested_by_id']===(int)$r['reviewed_by_id'];
    $actor=require_auth();$r['can_edit_or_close']=in_array($actor['role'],['manager','super_admin'],true)
        ||($r['requested_by_id']!==null&&(int)$r['requested_by_id']===request_actor_id($pdo,$actor));
    $r['can_review']=in_array($actor['role'],['manager','super_admin'],true)&&$r['status']==='Pending';
    $r['can_issue']=in_array($actor['role'],['staff','manager','super_admin'],true)&&in_array($r['status'],['Approved','Partially Issued'],true);
    $r['items']=array_map(function($line)use($pdo){
        $line['id']=(int)$line['id'];$line['remaining_qty']=request_remaining($line)/100;
        foreach(['requested_qty','approved_qty','issued_qty','released_qty','current_qty'] as $field)
            $line[$field]=$line[$field]===null?null:(float)$line[$field];
        if ($line['item_key']) $line=array_merge($line,category_metadata_for_key($pdo,$line['item_key']),request_availability($pdo,$line));
        return $line;
    },request_lines($pdo,$id));
    $r['partial_approval']=false;
    foreach($r['items'] as $line) if($line['approved_qty']!==null&&$line['approved_qty']<$line['requested_qty'])$r['partial_approval']=true;
    $r['history_warning']=null;
    foreach($r['items']as &$line){
        if($line['approval_source']==='legacy'&&$r['legacy_status']==='Fulfilled'&&$line['issued_qty']+(float)$line['released_qty']<(float)$line['approved_qty'])
            $r['history_warning']='Historical fulfillment has a reversed issue. Original status preserved pending reviewed correction; no stock is reserved.';
        if(!in_array($r['status'],['Approved','Partially Issued'],true))$line['remaining_qty']=0;
    }unset($line);
    $r['item_label']=implode(', ',array_column($r['items'],'item_label'));
    $r['reservation_age_days']=$r['reviewed_at']===null?null:(int)$pdo->query('SELECT DATEDIFF(CURRENT_DATE(),'.$pdo->quote($r['reviewed_at']).')')->fetchColumn();
    if(in_array($actor['role'],['manager','super_admin'],true)){
        $r['review_context']=[];
        foreach($r['items']as $line){
            if(!$line['item_key'])continue;
            $q=$pdo->prepare("SELECT department,SUM(qty) issued_qty FROM stock_issues WHERE item_key=? AND status='Active'
                AND created_at>=DATE_SUB(DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01'),INTERVAL 6 MONTH)
                AND created_at<DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01') GROUP BY department");
            $q->execute([$line['item_key']]);$history=$q->fetchAll();
            $q=$pdo->prepare("SELECT sr.id,sr.request_code,sr.department FROM stock_requests sr JOIN stock_request_items ri ON ri.request_id=sr.id
                WHERE ri.item_key=? AND sr.id<>? AND sr.status IN('Approved','Partially Issued') ORDER BY sr.created_at,sr.id");
            $q->execute([$line['item_key'],$r['id']]);
            $r['review_context'][]=['item_key'=>$line['item_key'],'department_issuance_last_six_completed_months'=>$history,'other_approved_requests'=>$q->fetchAll()];
        }
    }
    return $r;
}
function request_lock(PDO $pdo,int $id): array {
    $q=$pdo->prepare('SELECT * FROM stock_requests WHERE id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();
    if (!$r) json_error('Unknown stock request',404);assert_stock_request_access(require_auth(),$r);return $r;
}
function request_bump(PDO $pdo,int $id): void {
    $pdo->prepare('UPDATE stock_requests SET request_revision=request_revision+1 WHERE id=?')->execute([$id]);
}
function request_recompute(PDO $pdo,int $id): void {
    $r=request_lock($pdo,$id);
    if (!in_array($r['status'],['Approved','Partially Issued','Fulfilled'],true))return;
    $remaining=0;$issued=0;$released=0;foreach(request_lines($pdo,$id)as $line){$remaining+=request_remaining($line);$issued+=stock_cents($line['issued_qty']);$released+=stock_cents($line['released_qty']);}
    $status=$remaining===0?($released>0?($issued>0?'Closed':'Cancelled'):'Fulfilled'):($issued>0?'Partially Issued':'Approved');
    $pdo->prepare('UPDATE stock_requests SET status=? WHERE id=?')->execute([$status,$id]);
}
function request_validate_lines(PDO $pdo,$input): array {
    if (!is_array($input)||!array_is_list($input)||count($input)<1||count($input)>100)json_error('items must contain between 1 and 100 item lines');
    $out=[];$seen=[];
    foreach($input as $line){
        if(!is_array($line))json_error('Invalid item line');
        $key=parse_required_text($line['item_key']??'',50,'Inventory item');
        if(isset($seen[$key]))json_error('Each item can appear only once; combine its quantities');
        $seen[$key]=true;$item=get_item_or_404($key);
        if(!(int)$item['active']||!in_array($item['item_type'],['equipment','consumable'],true))json_error('Item is not requestable',409);
        if($item['item_type']==='equipment'&&(!empty($item['assigned_department'])||empty($item['location_id'])))json_error('Choose equipment with a valid storage location',409);
        $qty=parse_nonnegative_quantity($line['requested_qty']??$line['qty']??null,'Requested quantity',$item['item_type']==='equipment');
        if($qty<=0)json_error('Requested quantity must be greater than zero');
        $out[]=['item'=>$item,'qty'=>$qty,'notes'=>parse_optional_note($line['notes']??null)];
    }return $out;
}
function request_insert_lines(PDO $pdo,int $id,array $lines): void {
    $q=$pdo->prepare('INSERT INTO stock_request_items(request_id,line_no,item_key,item_label,unit,requested_qty,line_notes) VALUES(?,?,?,?,?,?,?)');
    foreach($lines as $n=>$line)$q->execute([$id,$n+1,$line['item']['item_key'],equipment_item_display_label($line['item']),$line['item']['unit'],$line['qty'],$line['notes']]);
}
function request_create(PDO $pdo,array $body,array $actor): int {
    $dept=is_department_user($actor)?$actor['department']:parse_required_text($body['department']??'',100,'Department');
    if(!is_valid_department($dept))json_error('Choose an official department');
    if(is_department_user($actor)&&isset($body['department'])&&$body['department']!==$dept)json_error('Cannot request for another department',403);
    $purpose=request_reason($body['purpose']??null,'Purpose');
    $lines=request_validate_lines($pdo,$body['items']??(isset($body['item_key'])?[['item_key'=>$body['item_key'],'qty'=>$body['qty']??null]]:null));
    $code=next_code('SR','stock_requests','request_code');
    // Legacy single-item columns are compatibility evidence, never used for quantities in this workflow.
    $first=$lines[0];
    $q=$pdo->prepare("INSERT INTO stock_requests(request_code,department,item_key,qty,requested_by,requested_by_id,notes,purpose,status) VALUES(?,?,?,?,?,?,?,?,'Pending')");
    $q->execute([$code,$dept,$first['item']['item_key'],$first['qty'],$actor['name'],request_actor_id($pdo,$actor),parse_optional_note($body['notes']??null),$purpose]);
    $id=(int)$pdo->lastInsertId();request_insert_lines($pdo,$id,$lines);
    $pdo->prepare('UPDATE stock_requests SET expected_collection=? WHERE id=?')->execute([request_collection_date($body['expected_collection']??null),$id]);
    record_audit('stock_request.submit','stock_request',(string)$id,null,['purpose'=>$purpose,'items'=>$body['items']??$lines],true);
    return $id;
}
function request_approve(PDO $pdo,array $r,array $body,array $actor): void {
    require_manager_or_above();if($r['status']!=='Pending')json_error('Only Pending requests can be reviewed',409);
    request_reason($r['purpose'],'Purpose');
    $input=$body['items']??null;if(!is_array($input)||!array_is_list($input))json_error('Item decisions are required');
    $byId=[];foreach($input as $v){if(!is_array($v)||!isset($v['id'])||isset($byId[(string)$v['id']]))json_error('Invalid or duplicate decision line');$byId[(string)$v['id']]=$v;}
    $lines=request_lines($pdo,(int)$r['id']);if(count($byId)!==count($lines))json_error('Review every item');
    $approved=0;
    foreach($lines as $line){
        $decision=$byId[(string)$line['id']]??null;if(!$decision)json_error('Decision references another request');
        if(!$line['item_key'])json_error('Map the legacy described item before approval',409);
        $item=get_item_or_404($line['item_key']);
        if($line['unit']!==$item['unit'])json_error('Item unit changed. Edit and review the request before approval.',409);
        if(!(int)$item['active'])json_error('Inactive item cannot be approved',409);
        if($item['item_type']==='equipment'&&(!empty($item['assigned_department'])||empty($item['location_id'])))json_error('Equipment needs a valid storage placement before approval',409);
        $qty=parse_nonnegative_quantity($decision['approved_qty']??null,'Approved quantity',$item['item_type']==='equipment');
        if(stock_cents($qty)>stock_cents($line['requested_qty']))json_error('Approval cannot exceed the requested quantity');
        $reason=$qty<(float)$line['requested_qty']?request_reason($decision['reason']??null,'Reduction/rejection reason'):parse_optional_note($decision['reason']??null);
        $availability=request_availability($pdo,$item);
        if($qty>$availability['uncommitted_qty'])json_error('Not enough uncommitted physical stock. Reduce the approval.',409);
        $override=$qty>$availability['allocatable_qty']?request_reason($decision['override_reason']??null,'Protected reserve override reason'):null;
        $pdo->prepare("UPDATE stock_request_items SET approved_qty=?,decision_reason=?,approval_source='manager',protected_override_reason=? WHERE id=?")->execute([$qty,$reason,$override,$line['id']]);$approved+=stock_cents($qty);
    }
    $pdo->prepare('UPDATE stock_requests SET status=?,reviewed_by_id=?,reviewed_by_name=?,reviewed_role=?,reviewed_at=NOW(),review_reason=? WHERE id=?')
        ->execute([$approved>0?'Approved':'Rejected',request_actor_id($pdo,$actor),$actor['name'],$actor['role'],parse_optional_note($body['reason']??null),$r['id']]);
    record_audit('stock_request.review','stock_request',(string)$r['id'],null,['decisions'=>$input,'self_approved'=>(int)$r['requested_by_id']===request_actor_id($pdo,$actor)],true);
}
function request_issue(PDO $pdo,array $r,array $body,array $actor): void {
    require_staff_or_above();
    if(!in_array($r['status'],['Approved','Partially Issued'],true))json_error('An approved request is required before issuance',409);
    $input=$body['items']??null;if(!is_array($input)||!array_is_list($input)||!$input)json_error('Choose item quantities to issue');
    $byId=[];foreach($input as $v){if(!is_array($v)||!isset($v['id'])||isset($byId[(string)$v['id']]))json_error('Invalid or duplicate issue line');$byId[(string)$v['id']]=$v;}
    $lines=[];foreach(request_lines($pdo,(int)$r['id'])as $line)$lines[(string)$line['id']]=$line;
    $ordered=[];foreach($byId as $id=>$v){if(!isset($lines[$id]))json_error('Issue line belongs to another request');$ordered[]=$lines[$id];}
    usort($ordered,fn($a,$b)=>strcmp($a['item_key'],$b['item_key']));
    foreach($ordered as $line){
        $item=stock_lock_item($pdo,$line['item_key']);if(!(int)$item['active'])json_error('Inactive item cannot be issued',409);
        $qty=parse_nonnegative_quantity($byId[(string)$line['id']]['qty']??null,'Issued quantity',$item['item_type']==='equipment');
        if($qty<=0||stock_cents($qty)>request_remaining($line))json_error('Issue a positive quantity within the remaining approval',409);
        if(stock_cents($qty)>stock_cents($item['current_qty']))json_error('Physical stock changed. Review commitments.',409);
        $code=next_code('ISS','stock_issues','issue_code');
        $pdo->prepare("INSERT INTO stock_issues(issue_code,item_key,qty,department,issued_to,notes,recorded_by,status,request_item_id) VALUES(?,?,?,?,?,?,?,'Active',?)")
          ->execute([$code,$line['item_key'],$qty,$r['department'],parse_optional_person_name($body['issued_to']??null),parse_optional_note($body['notes']??null),$actor['name'],$line['id']]);
        $issueId=(int)$pdo->lastInsertId();
        stock_reference_item($line['item_key'],'stock_issue',$issueId,$code);
        $GLOBALS['request_issuing_line']=(int)$line['id'];
        stock_change_storage($pdo,$line['item_key'],-$qty);
        unset($GLOBALS['request_issuing_line']);
        apply_equipment_checkout($pdo,$line['item_key'],$r['department'],$qty);
        if($item['item_type']==='equipment')log_equipment_movement($pdo,$line['item_key'],$qty,'issue_from_storage',$actor['name'],$r['department'],null,$body['issued_to']??null,$body['notes']??null,'stock_issue',$issueId,$code);
    }
    request_recompute($pdo,(int)$r['id']);
    record_audit('stock_request.issue','stock_request',(string)$r['id'],null,['items'=>$input],true);
}
function request_close(PDO $pdo,array $r,array $body,array $actor): void {
    $owner=$r['requested_by_id']!==null&&(int)$r['requested_by_id']===request_actor_id($pdo,$actor);
    if(!$owner)require_manager_or_above();
    if(!in_array($r['status'],['Pending','Approved','Partially Issued'],true))json_error('Request cannot be closed in its current state',409);
    $reason=request_reason($body['reason']??null,'Closure reason');$issued=0;
    foreach(request_lines($pdo,(int)$r['id'])as $line){
        $issued+=stock_cents($line['issued_qty']);
        $pdo->prepare('UPDATE stock_request_items SET released_qty=released_qty+? WHERE id=?')->execute([request_remaining($line)/100,$line['id']]);
    }
    $pdo->prepare('UPDATE stock_requests SET status=?,closed_reason=?,cancelled_at=NOW() WHERE id=?')->execute([$issued>0?'Closed':'Cancelled',$reason,$r['id']]);
    record_audit('stock_request.close','stock_request',(string)$r['id'],null,['reason'=>$reason],true);
}

/** Explicit releases paired with truthful stock corrections; all use the same transaction gate. */
function request_reconcile(PDO $pdo,array $body): void {
    if(!request_schema_available($pdo)||!isset($body['reservation_releases']))return;
    require_manager_or_above();$entries=$body['reservation_releases'];
    if(!is_array($entries)||!array_is_list($entries))json_error('Invalid reservation releases');
    $seen=[];
    foreach($entries as $entry){
        if(!is_array($entry)||!isset($entry['id'])||isset($seen[(string)$entry['id']]))json_error('Invalid or duplicate reservation release');
        $seen[(string)$entry['id']]=true;
        $q=$pdo->prepare('SELECT request_id FROM stock_request_items WHERE id=?');$q->execute([$entry['id']]);$id=$q->fetchColumn();
        if(!$id)json_error('Unknown reservation line',404);
        $r=request_lock($pdo,(int)$id);request_revision($entry,$r);
        if(!in_array($r['status'],['Approved','Partially Issued'],true))json_error('Reservation is no longer open',409);
        $line=null;foreach(request_lines($pdo,(int)$id)as $v)if((int)$v['id']===(int)$entry['id'])$line=$v;
        $qty=parse_nonnegative_quantity($entry['qty']??null,'Released quantity',$line['item_type']==='equipment');
        if($qty<=0||stock_cents($qty)>request_remaining($line))json_error('Release exceeds the outstanding commitment');
        $reason=request_reason($entry['reason']??null);
        $pdo->prepare('UPDATE stock_request_items SET released_qty=released_qty+?,decision_reason=? WHERE id=?')->execute([$qty,$reason,$line['id']]);
        request_recompute($pdo,(int)$id);
        record_audit('stock_request.release','stock_request',(string)$id,null,['line_id'=>$line['id'],'qty'=>$qty,'reason'=>$reason],true);
    }
    $ids=[];foreach($entries as $entry){
        $q=$pdo->prepare('SELECT request_id FROM stock_request_items WHERE id=?');$q->execute([$entry['id']]);$ids[(int)$q->fetchColumn()]=true;
    }
    foreach(array_keys($ids)as $id)request_bump($pdo,$id);
}
function request_edit(PDO $pdo,array $r,array $body,array $actor): void {
    if($r['status']!=='Pending')json_error('Only Pending requests may be edited',409);
    if((int)$r['requested_by_id']!==request_actor_id($pdo,$actor))require_manager_or_above();
    $purpose=request_reason($body['purpose']??null,'Purpose');
    $lines=request_validate_lines($pdo,$body['items']??null);
    $q=$pdo->prepare('SELECT COUNT(*) FROM stock_issues si JOIN stock_request_items ri ON ri.id=si.request_item_id WHERE ri.request_id=?');
    $q->execute([$r['id']]);if($q->fetchColumn())json_error('Existing issue history prevents replacing these lines',409);
    record_audit('stock_request.edit','stock_request',(string)$r['id'],['items'=>request_lines($pdo,(int)$r['id']),'purpose'=>$r['purpose']],['purpose'=>$purpose,'items'=>$body['items']],true);
    $pdo->prepare('DELETE FROM stock_request_items WHERE request_id=?')->execute([$r['id']]);
    request_insert_lines($pdo,(int)$r['id'],$lines);
    $pdo->prepare('UPDATE stock_requests SET purpose=?,expected_collection=? WHERE id=?')->execute([$purpose,request_collection_date($body['expected_collection']??null),$r['id']]);
}
