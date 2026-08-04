<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__,2).'/config.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$key    = session_id();

function getItems($pdo,$key){
    try{
        $s=$pdo->prepare("SELECT cs.product_id AS id, p.name, p.images FROM compare_sessions cs JOIN products p ON p.id=cs.product_id WHERE cs.session_key=? AND p.status='active'");
        $s->execute([$key]); return $s->fetchAll(PDO::FETCH_ASSOC);
    }catch(Exception $e){return [];}
}

if ($action==='list') {
    echo json_encode(['ok'=>true,'items'=>getItems($pdo,$key)]);
} elseif ($action==='add') {
    $pid=(int)($_POST['product_id']??0);
    if (!$pid){ echo json_encode(['ok'=>false,'msg'=>'Invalid product']); exit; }
    try{
        $cnt=$pdo->prepare("SELECT COUNT(*) FROM compare_sessions WHERE session_key=?");
        $cnt->execute([$key]);
        if($cnt->fetchColumn()>=4){ echo json_encode(['ok'=>false,'msg'=>'Max 4 products can be compared.']); exit; }
        $pdo->prepare("INSERT IGNORE INTO compare_sessions (session_key,product_id) VALUES(?,?)")->execute([$key,$pid]);
        echo json_encode(['ok'=>true,'items'=>getItems($pdo,$key)]);
    }catch(Exception $e){ echo json_encode(['ok'=>false,'msg'=>'Error: '.$e->getMessage()]); }
} elseif ($action==='remove') {
    $pid=(int)($_POST['product_id']??0);
    try{ $pdo->prepare("DELETE FROM compare_sessions WHERE session_key=? AND product_id=?")->execute([$key,$pid]); }catch(Exception $e){}
    echo json_encode(['ok'=>true,'items'=>getItems($pdo,$key)]);
} elseif ($action==='clear') {
    try{ $pdo->prepare("DELETE FROM compare_sessions WHERE session_key=?")->execute([$key]); }catch(Exception $e){}
    echo json_encode(['ok'=>true,'items'=>[]]);
} else {
    echo json_encode(['ok'=>false]);
}
