<?php
// Internal dispatcher included by the existing endpoint, never a second API.
if (basename($_SERVER['SCRIPT_NAME'])==='stock_requests_v5.php'){http_response_code(404);exit;}
request_require_schema($pdo);
$actor=require_auth();
if($method==='GET'){
    if(!empty($_GET['requestable'])){
        $rows=$pdo->query("SELECT * FROM items WHERE active=1 ORDER BY label,item_key")->fetchAll();$out=[];
        foreach($rows as $item){
            if($item['item_type']==='equipment'&&(!empty($item['assigned_department'])||empty($item['location_id'])))continue;
            $type=$_GET['type']??'';if($type!==''&&!in_array($type,['equipment','consumable'],true))json_error('Invalid type');
            if($type!==''&&$item['item_type']!==$type)continue;
            $out[]=array_merge(['item_key'=>$item['item_key'],'label'=>$item['label'],'display_label'=>equipment_item_display_label($item),'unit'=>$item['unit'],'item_type'=>$item['item_type'],
                'max_qty'=>99999999.99],category_metadata($pdo,$item),request_availability($pdo,$item));
        }echo json_encode(category_filter_rows($pdo,$out));exit;
    }
    if(!empty($_GET['departments'])){echo json_encode(is_department_user($actor)?[$actor['department']]:DEPARTMENTS);exit;}
    if(isset($_GET['id'])){echo json_encode(request_snapshot($pdo,(int)$_GET['id']));exit;}
    $where=[];$params=[];apply_stock_request_list_scope($actor,$where,$params);
    if(!empty($_GET['status'])){$where[]='sr.status=?';$params[]=$_GET['status'];}
    if(!empty($_GET['outstanding']))$where[]="sr.status IN ('Approved','Partially Issued')";
    $q=$pdo->prepare('SELECT sr.id FROM stock_requests sr'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY sr.created_at DESC,sr.id DESC');
    $q->execute($params);echo json_encode(array_map(fn($id)=>request_snapshot($pdo,(int)$id),$q->fetchAll(PDO::FETCH_COLUMN)));exit;
}
if(!in_array($method,['POST','PUT'],true))json_error('Method not allowed',405);
$body=read_json_body();stock_begin($pdo,'stock_request');stock_operation($pdo,$body);
if($method==='POST')$id=request_create($pdo,$body,$actor);
else{
    $id=(int)($_GET['id']??0);$r=request_lock($pdo,$id);request_revision($body,$r);
    switch($body['action']??''){
        case 'approve': request_approve($pdo,$r,$body,$actor);break;
        case 'issue': case 'fulfill':request_issue($pdo,$r,$body,$actor);break;
        case 'cancel':case 'close':request_close($pdo,$r,$body,$actor);break;
        case 'edit':request_edit($pdo,$r,$body,$actor);break;
        case 'release':
            require_manager_or_above();
            foreach($body['reservation_releases']??[] as $entry){
                $q=$pdo->prepare('SELECT request_id FROM stock_request_items WHERE id=?');$q->execute([$entry['id']??0]);
                if((int)$q->fetchColumn()!==$id)json_error('Release line belongs to another request');
            }
            request_reconcile($pdo,['reservation_releases'=>$body['reservation_releases']??null]);
            stock_reply($pdo,request_snapshot($pdo,$id));exit;
        default:json_error('Unknown request action');
    }request_bump($pdo,$id);
}
stock_reply($pdo,request_snapshot($pdo,$id));exit;
