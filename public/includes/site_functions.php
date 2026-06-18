<?php
// public/includes/site_functions.php — Shared helpers for the public website

function getProductUrl($product) {
    $slug = $product['slug'] ?: $product['id'];
    return BASE_URL . '/public/product.php?slug=' . urlencode($slug);
}
function getCategoryUrl($cat) {
    return BASE_URL . '/public/products.php?category=' . $cat['id'];
}
function getIndustryUrl($ind) {
    return BASE_URL . '/public/products.php?industry=' . $ind['id'];
}
function getVendorUrl($vendorId) {
    return BASE_URL . '/public/vendor-profile.php?id=' . $vendorId;
}

function getProductImage($product, $index=0) {
    $imgs = array_filter(array_map('trim', explode(',', $product['images'] ?? '')));
    $imgs = array_values($imgs);
    if (!empty($imgs[$index])) return UPLOAD_URL . $imgs[$index];
    return BASE_URL . '/public/assets/img/no-product.svg';
}

function getProductAttributes($pdo, $productId) {
    $stmt = $pdo->prepare("SELECT * FROM product_attributes WHERE product_id=? ORDER BY sort_order");
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

function getVendorsByProduct($pdo, $productTypeId, $excludeId=null) {
    $sql = "SELECT p.*, u.name AS vendor_name, u.company, u.phone, u.city, u.state,
                   vp.is_verified, vp.logo, vp.tagline, vp.established_yr
            FROM products p
            JOIN users u ON u.id=p.vendor_id
            LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id
            WHERE p.product_type_id=? AND p.status='active'";
    $params = [$productTypeId];
    if ($excludeId) { $sql .= " AND p.id!=?"; $params[] = $excludeId; }
    $sql .= " ORDER BY vp.is_verified DESC, p.views DESC LIMIT 6";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getCompareList($pdo) {
    $key = session_id();
    try {
        $stmt = $pdo->prepare("SELECT cs.product_id,p.name,p.images,p.price_range FROM compare_sessions cs JOIN products p ON p.id=cs.product_id WHERE cs.session_key=? AND p.status='active'");
        $stmt->execute([$key]);
        return $stmt->fetchAll();
    } catch(Exception $e) { return []; }
}

function addToCompare($pdo, $productId) {
    $key = session_id();
    try {
        $count = $pdo->prepare("SELECT COUNT(*) FROM compare_sessions WHERE session_key=?");
        $count->execute([$key]);
        if ($count->fetchColumn() >= 4) return ['ok'=>false,'msg'=>'Max 4 products can be compared.'];
        $pdo->prepare("INSERT IGNORE INTO compare_sessions (session_key,product_id) VALUES(?,?)")->execute([$key,$productId]);
        return ['ok'=>true];
    } catch(Exception $e) { return ['ok'=>false,'msg'=>'Error']; }
}

function starRating($rating, $max=5) {
    $html='';
    for ($i=1;$i<=$max;$i++) {
        $html.= $i<=$rating ? '<span style="color:#f59e0b">★</span>' : '<span style="color:#d1d5db">★</span>';
    }
    return $html;
}

function sH($str) { return htmlspecialchars($str??'',ENT_QUOTES,'UTF-8'); }
