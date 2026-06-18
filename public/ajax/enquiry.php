<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__,2).'/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD']!=='POST'){ echo json_encode(['ok'=>false,'msg'=>'Invalid request']); exit; }

$productId = (int)($_POST['product_id']??0);
$vendorId  = (int)($_POST['vendor_id']??0);
$name      = trim($_POST['name']??'');
$email     = trim($_POST['email']??'');
$phone     = trim($_POST['phone']??'');
$company   = trim($_POST['company']??'');
$city      = trim($_POST['city']??'');
$message   = trim($_POST['message']??'');
$qty       = trim($_POST['qty_needed']??'');

if (!$name||!$email||!$vendorId){ echo json_encode(['ok'=>false,'msg'=>'Please fill in required fields.']); exit; }
if (!filter_var($email,FILTER_VALIDATE_EMAIL)){ echo json_encode(['ok'=>false,'msg'=>'Invalid email address.']); exit; }

try {
    $pdo->prepare("INSERT INTO web_enquiries (product_id,vendor_id,name,email,phone,company,city,message,qty_needed,ip_address) VALUES(?,?,?,?,?,?,?,?,?,?)")
        ->execute([$productId?:null,$vendorId,$name,$email,$phone,$company,$city,$message,$qty,$_SERVER['REMOTE_ADDR']??'']);
    // Notify vendor
    try{ $pdo->prepare("INSERT INTO notifications (user_id,title,message,link) VALUES(?,?,?,?)")->execute([$vendorId,"New enquiry from $name","Product enquiry received from $company $city. Email: $email",'/dashv10_Fixed/vendor/enquiries.php']); }catch(Exception $e){}
    echo json_encode(['ok'=>true,'msg'=>'Your enquiry has been sent! The vendor will contact you within 24 hours.']);
} catch(Exception $e){
    echo json_encode(['ok'=>false,'msg'=>'Error submitting enquiry. Please try again.']);
}
