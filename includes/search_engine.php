<?php
// ============================================================
// includes/search_engine.php — Advanced product search
//
// Used by BOTH the header typeahead (public/ajax/search.php) and the full
// results page (public/products.php), so search behaves identically
// everywhere on the site.
//
// Two layers:
//  1. DIRECT MATCH — searches product name/description/tags, category/
//     industry/product-type names, vendor company/name, AND every product
//     attribute's name+value+unit — generically, for ANY attribute (GSM,
//     BF, thickness, coating, colour, moisture content, whatever a vendor
//     has defined), not hardcoded to one spec.
//  2. FUZZY FALLBACK — if the direct match finds nothing, we don't just
//     give up. We build a vocabulary of every real word that appears
//     anywhere in the catalog (product names, attribute values, category/
//     industry/type names, vendor names), then use PHP's levenshtein()
//     edit-distance to find the closest real word to each mistyped word
//     in the query, and re-run the search with the corrected term(s) —
//     the "did you mean" behaviour big e-commerce sites have.
// ============================================================

function searchNormalize($s) {
    return strtolower(preg_replace('/\s+/', '', $s));
}

// Runs the direct (non-fuzzy) match for a given query string.
// $extraWhere/$extraParams let callers AND-in additional filters (industry,
// category, product type, vendor) so this same engine powers both the
// header typeahead (no extra filters) and the full products listing page
// (combined with whatever sidebar filters are active).
function searchDirectMatch($pdo, $q, $extraWhere = '', $extraParams = [], $limit = 30, $offset = 0) {
    $qNorm = searchNormalize($q);
    $like = "%$q%";

    $sql = "SELECT DISTINCT p.*,
                   u.company, u.name AS vendor_name,
                   c.name AS category_name, pt.name AS type_name
            FROM products p
            JOIN users u ON u.id = p.vendor_id
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN industries i ON i.id = p.industry_id
            LEFT JOIN product_types pt ON pt.id = p.product_type_id
            WHERE p.status = 'active' AND (
                p.name LIKE ? OR p.description LIKE ? OR p.tags LIKE ?
                OR c.name LIKE ? OR i.name LIKE ? OR pt.name LIKE ?
                OR u.company LIKE ? OR u.name LIKE ?
                OR EXISTS (
                    SELECT 1 FROM product_attributes pa WHERE pa.product_id = p.id AND (
                        pa.attribute_name LIKE ? OR pa.attribute_value LIKE ?
                        OR REPLACE(LOWER(CONCAT(pa.attribute_value, pa.attribute_unit)), ' ', '') LIKE ?
                    )
                )
            )";
    $params = [$like,$like,$like, $like,$like,$like, $like,$like, $like,$like, "%$qNorm%"];

    if ($extraWhere) { $sql .= " AND $extraWhere"; $params = array_merge($params, $extraParams); }

    $sql .= " ORDER BY p.is_featured DESC, p.views DESC LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Total matching count (for pagination), mirrors searchDirectMatch's WHERE.
function searchDirectMatchCount($pdo, $q, $extraWhere = '', $extraParams = []) {
    $qNorm = searchNormalize($q);
    $like = "%$q%";

    $sql = "SELECT COUNT(DISTINCT p.id) FROM products p
            JOIN users u ON u.id = p.vendor_id
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN industries i ON i.id = p.industry_id
            LEFT JOIN product_types pt ON pt.id = p.product_type_id
            WHERE p.status = 'active' AND (
                p.name LIKE ? OR p.description LIKE ? OR p.tags LIKE ?
                OR c.name LIKE ? OR i.name LIKE ? OR pt.name LIKE ?
                OR u.company LIKE ? OR u.name LIKE ?
                OR EXISTS (
                    SELECT 1 FROM product_attributes pa WHERE pa.product_id = p.id AND (
                        pa.attribute_name LIKE ? OR pa.attribute_value LIKE ?
                        OR REPLACE(LOWER(CONCAT(pa.attribute_value, pa.attribute_unit)), ' ', '') LIKE ?
                    )
                )
            )";
    $params = [$like,$like,$like, $like,$like,$like, $like,$like, $like,$like, "%$qNorm%"];
    if ($extraWhere) { $sql .= " AND $extraWhere"; $params = array_merge($params, $extraParams); }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function searchDirectVendorMatch($pdo, $q, $limit = 5) {
    $like = "%$q%";
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.id, u.company, u.name,
                (SELECT COUNT(*) FROM products p2 WHERE p2.vendor_id = u.id AND p2.status = 'active') AS product_count
         FROM users u
         JOIN products p ON p.vendor_id = u.id AND p.status = 'active'
         WHERE u.role = 'vendor' AND u.status = 'active' AND (u.company LIKE ? OR u.name LIKE ?)
         ORDER BY u.company ASC LIMIT $limit"
    );
    $stmt->execute([$like, $like]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Builds the "real word" vocabulary from everything in the live catalog.
// Cached in a static var per-request since it can be reused across a few calls.
function searchBuildVocabulary($pdo) {
    static $vocab = null;
    if ($vocab !== null) return $vocab;

    $phrases = [];
    foreach ($pdo->query("SELECT name FROM products WHERE status='active'") as $r) $phrases[] = $r['name'];
    foreach ($pdo->query("SELECT DISTINCT attribute_name FROM product_attributes") as $r) $phrases[] = $r['attribute_name'];
    foreach ($pdo->query("SELECT DISTINCT attribute_value FROM product_attributes WHERE attribute_value IS NOT NULL") as $r) $phrases[] = $r['attribute_value'];
    foreach ($pdo->query("SELECT name FROM categories WHERE status=1") as $r) $phrases[] = $r['name'];
    foreach ($pdo->query("SELECT name FROM industries WHERE status=1") as $r) $phrases[] = $r['name'];
    foreach ($pdo->query("SELECT name FROM product_types WHERE status=1") as $r) $phrases[] = $r['name'];
    foreach ($pdo->query("SELECT DISTINCT company FROM users WHERE role='vendor' AND status='active' AND company IS NOT NULL AND company <> ''") as $r) $phrases[] = $r['company'];

    $words = [];
    foreach ($phrases as $phrase) {
        foreach (preg_split('/[^a-zA-Z0-9]+/', $phrase) as $w) {
            $w = strtolower(trim($w));
            if (strlen($w) >= 3) $words[$w] = true; // dedupe via keys
        }
    }
    $vocab = array_keys($words);
    return $vocab;
}

// Finds the closest real vocabulary word to a (possibly mistyped) word.
// Returns null if nothing close enough is found (so we don't "correct" a
// word that just genuinely isn't in the catalog at all).
function searchClosestWord($word, $vocabulary) {
    $word = strtolower($word);
    $best = null; $bestDist = PHP_INT_MAX;
    // Distance threshold scales with word length: short words need an
    // almost-exact match, longer words tolerate a couple more typos.
    $maxDist = $word && strlen($word) <= 4 ? 1 : (strlen($word) <= 7 ? 2 : 3);

    foreach ($vocabulary as $candidate) {
        // Quick length pre-filter avoids computing levenshtein() on wildly
        // different-length words — keeps this fast even with thousands of
        // vocabulary entries.
        if (abs(strlen($candidate) - strlen($word)) > $maxDist) continue;
        $dist = levenshtein($word, $candidate);
        if ($dist < $bestDist) { $bestDist = $dist; $best = $candidate; }
        if ($dist === 0) break; // exact match, can't do better
    }
    return ($best !== null && $bestDist <= $maxDist && $bestDist > 0) ? $best : null;
}

// Attempts to spell-correct the query word-by-word against the catalog
// vocabulary. Returns the corrected string, or null if no correction helped.
function searchSuggestCorrection($pdo, $q) {
    $vocabulary = searchBuildVocabulary($pdo);
    if (!$vocabulary) return null;

    $words = preg_split('/\s+/', trim($q));
    $corrected = [];
    $changed = false;

    foreach ($words as $w) {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $w);
        if (strlen($clean) < 3) { $corrected[] = $w; continue; }
        if (ctype_digit($clean)) { $corrected[] = $w; continue; } // don't "correct" numbers to other numbers
        if (in_array(strtolower($clean), $vocabulary, true)) { $corrected[] = $w; continue; } // already a real word
        $fix = searchClosestWord($clean, $vocabulary);
        if ($fix) { $corrected[] = $fix; $changed = true; }
        else { $corrected[] = $w; }
    }

    return $changed ? implode(' ', $corrected) : null;
}

// Main entry point. Returns:
//   ['products'=>[], 'vendors'=>[], 'total'=>int, 'query_used'=>string, 'corrected_from'=>string|null]
// $extraWhere/$extraParams let callers combine this with other active
// filters (industry/category/type/vendor) on the full listing page.
function runAdvancedSearch($pdo, $q, $extraWhere = '', $extraParams = [], $limit = 30, $offset = 0, $vendorLimit = 5) {
    $q = trim($q);
    $result = ['products' => [], 'vendors' => [], 'total' => 0, 'query_used' => $q, 'corrected_from' => null];
    if ($q === '') return $result;

    $products = searchDirectMatch($pdo, $q, $extraWhere, $extraParams, $limit, $offset);
    $total    = searchDirectMatchCount($pdo, $q, $extraWhere, $extraParams);
    $vendors  = searchDirectVendorMatch($pdo, $q, $vendorLimit);

    if (!$products && !$vendors) {
        $suggestion = searchSuggestCorrection($pdo, $q);
        if ($suggestion && strtolower($suggestion) !== strtolower($q)) {
            $products2 = searchDirectMatch($pdo, $suggestion, $extraWhere, $extraParams, $limit, $offset);
            $total2    = searchDirectMatchCount($pdo, $suggestion, $extraWhere, $extraParams);
            $vendors2  = searchDirectVendorMatch($pdo, $suggestion, $vendorLimit);
            if ($products2 || $vendors2) {
                $result['products'] = $products2;
                $result['vendors']  = $vendors2;
                $result['total']    = $total2;
                $result['query_used'] = $suggestion;
                $result['corrected_from'] = $q;
                return $result;
            }
        }
    }

    $result['products'] = $products;
    $result['vendors']  = $vendors;
    $result['total']    = $total;
    return $result;
}
