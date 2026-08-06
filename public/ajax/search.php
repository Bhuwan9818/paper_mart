<?php
// public/ajax/search.php
// Powers the live typeahead in the site header. Uses the shared advanced
// search engine (includes/search_engine.php) — matches product name,
// description, tags, category/industry/type, vendor, and ANY product
// attribute (GSM, BF, thickness, colour, etc.), with a typo-tolerant
// "did you mean" fallback when there are zero direct matches.

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/search_engine.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode(['products' => [], 'vendors' => []]); exit; }

$result = runAdvancedSearch($pdo, $q, '', [], 6, 0, 5);
echo json_encode($result);
