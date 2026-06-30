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

/**
 * Category-wise other vendors: every other active product in the same
 * category (one card per matching product, vendors can appear more than
 * once if they have multiple listings in that category).
 */
function getVendorsByCategory($pdo, $categoryId, $excludeProductId, $excludeVendorId=null, $limit=8) {
    $sql = "SELECT p.*, u.name AS vendor_name, u.company, u.phone, u.city, u.state,
                   vp.is_verified, vp.logo, vp.tagline, vp.established_yr
            FROM products p
            JOIN users u ON u.id=p.vendor_id
            LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id
            WHERE p.category_id=? AND p.status='active' AND p.id!=?";
    $params = [$categoryId, $excludeProductId];
    if ($excludeVendorId) { $sql .= " AND p.vendor_id!=?"; $params[] = $excludeVendorId; }
    $sql .= " ORDER BY vp.is_verified DESC, p.views DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Product-wise other vendors: products from a DIFFERENT vendor that are
 * an exact match on name + category + product type + every single
 * attribute (name, value, and unit) — i.e. truly the same product
 * listing, just sold by someone else.
 *
 * Matching is done by comparing attribute counts: a candidate product
 * qualifies only if (a) it has the same number of attributes as the
 * current product, and (b) every one of those attributes has an
 * identical name+value+unit row also present on the current product.
 * That two-sided count check rules out partial matches in either
 * direction (extra attributes on either side, or any differing value).
 */
function getVendorsByExactProduct($pdo, $product, $excludeProductId, $excludeVendorId, $limit=8) {
    // Build a normalized signature of the current product's attributes
    $stmt = $pdo->prepare("SELECT attribute_name, attribute_value, attribute_unit FROM product_attributes WHERE product_id=?");
    $stmt->execute([$excludeProductId]);
    $myAttrs = $stmt->fetchAll();
    $myAttrCount = count($myAttrs);

    // Candidates: same name, category, product type, different vendor, active
    $sql = "SELECT p.*, u.name AS vendor_name, u.company, u.phone, u.city, u.state,
                   vp.is_verified, vp.logo, vp.tagline, vp.established_yr
            FROM products p
            JOIN users u ON u.id=p.vendor_id
            LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id
            WHERE p.name=? AND p.category_id=? AND p.product_type_id=?
              AND p.status='active' AND p.id!=? AND p.vendor_id!=?
            ORDER BY vp.is_verified DESC, p.views DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $product['name'], $product['category_id'], $product['product_type_id'],
        $excludeProductId, $excludeVendorId,
    ]);
    $candidates = $stmt->fetchAll();

    if (!$candidates) return [];

    // Pre-build a comparable set of "name|value|unit" strings for the
    // current product, so each candidate's attributes can be checked
    // without re-querying per row.
    $mySignature = [];
    foreach ($myAttrs as $a) {
        $key = strtolower(trim($a['attribute_name'])) . '|' . strtolower(trim((string)$a['attribute_value'])) . '|' . strtolower(trim((string)$a['attribute_unit']));
        $mySignature[$key] = true;
    }

    $exactMatches = [];
    foreach ($candidates as $cand) {
        $cStmt = $pdo->prepare("SELECT attribute_name, attribute_value, attribute_unit FROM product_attributes WHERE product_id=?");
        $cStmt->execute([$cand['id']]);
        $candAttrs = $cStmt->fetchAll();

        // Must have exactly the same number of attributes — rules out a
        // candidate with extra or missing attributes vs. the original.
        if (count($candAttrs) !== $myAttrCount) continue;

        $allMatch = true;
        foreach ($candAttrs as $a) {
            $key = strtolower(trim($a['attribute_name'])) . '|' . strtolower(trim((string)$a['attribute_value'])) . '|' . strtolower(trim((string)$a['attribute_unit']));
            if (!isset($mySignature[$key])) { $allMatch = false; break; }
        }
        if ($allMatch) {
            $exactMatches[] = $cand;
            if (count($exactMatches) >= $limit) break;
        }
    }
    return $exactMatches;
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
