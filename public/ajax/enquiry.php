<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__,2).'/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD']!=='POST'){ echo json_encode(['ok'=>false,'msg'=>'Invalid request']); exit; }

$productId = (int)($_POST['product_id']??0);
$vendorId  = (int)($_POST['vendor_id']??0);
$customerId = (isset($_SESSION['role']) && $_SESSION['role']==='customer') ? (int)$_SESSION['user_id'] : null;
$name      = trim($_POST['name']??'');
$email     = trim($_POST['email']??'');
$phone     = trim($_POST['phone']??'');
$company   = trim($_POST['company']??'');
$city      = trim($_POST['city']??'');
$message   = trim($_POST['message']??'');
$qty       = trim($_POST['qty_needed']??'');

if (!$name||!$email||!$vendorId){ echo json_encode(['ok'=>false,'msg'=>'Please fill in required fields.']); exit; }
if (!filter_var($email,FILTER_VALIDATE_EMAIL)){ echo json_encode(['ok'=>false,'msg'=>'Invalid email address.']); exit; }

if ($customerId) {
    require_once dirname(__DIR__,2).'/includes/customer_subscription.php';
    $custSub = getCustomerSubscription($pdo, $customerId);
    if ($custSub) {
        $enqCheck = checkCustomerEnquiryLimit($pdo, $customerId, $custSub);
        if (!$enqCheck['allowed']) {
            echo json_encode(['ok'=>false,'msg'=>"You've used all {$enqCheck['limit']} free enquiries this month. Upgrade to Premium for unlimited enquiries.",'limitReached'=>true]);
            exit;
        }
    }
}

try {
    $pdo->prepare("INSERT INTO web_enquiries (product_id,vendor_id,customer_id,name,email,phone,company,city,message,qty_needed,ip_address) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$productId?:null,$vendorId,$customerId,$name,$email,$phone,$company,$city,$message,$qty,$_SERVER['REMOTE_ADDR']??'']);
    $enquiryId = $pdo->lastInsertId();

    if ($customerId) {
        incrementCustomerUsage($pdo, $customerId, 'enquiries_sent');
    }

    // Notify vendor (in-app)
    try{ $pdo->prepare("INSERT INTO notifications (user_id,title,message,link) VALUES(?,?,?,?)")->execute([$vendorId,"New enquiry from $name","Product enquiry received from $company $city. Email: $email",'/dashv10_Fixed/vendor/enquiries.php']); }catch(Exception $e){}

    // Email the vendor AND admin — never let an email failure break the enquiry submission itself.
    try {
        require_once dirname(__DIR__,2).'/includes/mailer.php';
        notifyEnquiryByEmail($pdo, $enquiryId);
    } catch (Exception $e) {
        error_log('Enquiry email notification failed: ' . $e->getMessage());
    }

    echo json_encode(['ok'=>true,'msg'=>'Your enquiry has been sent! The vendor will contact you within 24 hours.']);
} catch(Exception $e){
    echo json_encode(['ok'=>false,'msg'=>'Error submitting enquiry. Please try again.']);
}
