<?php
// ============================================================
// chatbot/test_engine.php — Quick engine diagnostic
// Run once from CLI or browser (protected by basic auth).
// DELETE this file after testing!
//
//   CLI: php chatbot/test_engine.php
//   Web: https://yourdomain.com/chatbot/test_engine.php
//        (protected with password below)
// ============================================================

// ── Web protection ────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    if (empty($_SERVER['PHP_AUTH_PW']) || $_SERVER['PHP_AUTH_PW'] !== 'chatbottest2024') {
        header('WWW-Authenticate: Basic realm="Chatbot Test"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Protected. Delete this file after testing.'; exit;
    }
    echo '<pre style="font-size:14px;font-family:monospace;padding:20px;line-height:1.8">';
}

define('IN_APP', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/engine.php';

$OK   = '✅';
$FAIL = '❌';
$WARN = '⚠️ ';

echo "PaperMart Free Chatbot — Engine Test\n";
echo str_repeat('─', 55) . "\n\n";

// ── 1. DB Tables exist ────────────────────────────────────
echo "1. Required chatbot tables:\n";
foreach (['cb_intents','cb_keywords','cb_responses','cb_sessions','cb_messages','cb_tickets','cb_fallback_log','cb_rate_limits'] as $t) {
    $exists = $pdo->query("SHOW TABLES LIKE '$t'")->rowCount() > 0;
    echo "   " . ($exists ? $OK : $FAIL) . " $t\n";
}

// ── 2. Intents seeded ─────────────────────────────────────
$intentCount = (int)$pdo->query("SELECT COUNT(*) FROM cb_intents WHERE is_active=1")->fetchColumn();
echo "\n2. Active intents: " . ($intentCount > 0 ? "$OK $intentCount intents loaded" : "$FAIL No intents! Run chatbot_migration.sql") . "\n";

$kwCount = (int)$pdo->query("SELECT COUNT(*) FROM cb_keywords")->fetchColumn();
echo "3. Keywords: " . ($kwCount > 0 ? "$OK $kwCount keywords" : "$FAIL No keywords") . "\n";

$rCount = (int)$pdo->query("SELECT COUNT(*) FROM cb_responses WHERE is_active=1")->fetchColumn();
echo "4. Responses: " . ($rCount > 0 ? "$OK $rCount responses" : "$FAIL No responses") . "\n";

// ── 3. Intent matching tests ──────────────────────────────
echo "\n" . str_repeat('─', 55) . "\n";
echo "5. Intent Detection Tests:\n\n";

$testCases = [
    // [input, expected_intent, description]
    ['hello there',                'greeting',             'Simple greeting'],
    ['helo',                       'greeting',             'Typo tolerance (helo)'],
    ['i want to register as vendor','vendor_registration', 'Vendor registration intent'],
    ['how do i become a seller',   'vendor_registration',  'Synonym (seller=vendor)'],
    ['supplier registration',      'vendor_registration',  'Keyword: supplier registration'],
    ['what are the subscription plans', 'subscription_pricing', 'Subscription intent'],
    ['how much does it cost',      'subscription_pricing', 'Cost synonym'],
    ['i forgot my password',       'login_help',           'Password reset'],
    ['cant login to my account',   'login_help',           'Login problem'],
    ['i need to talk to someone',  'contact_support',      'Human escalation'],
    ['how do i send a product enquiry', 'enquiry_process', 'Enquiry process'],
    ['tell me about papermart',    'platform_info',        'Platform info'],
    ['shipping and delivery',      'shipping_delivery',    'Shipping intent'],
    ['how to pay for subscription','payment_info',         'Payment info'],
    ['cancel my plan',             'cancellation_refund',  'Cancellation intent'],
    ['bye goodbye',                'goodbye',              'Goodbye intent'],
    ['xyzabc random nonsense 123', null,                   'Should NOT match (fallback)'],
];

$passed = 0; $failed = 0;
foreach ($testCases as [$input, $expected, $desc]) {
    $result  = detectIntent($pdo, $input);
    $matched = $result ? $result['intent']['name'] : null;
    $conf    = $result ? $result['confidence'] : 0;
    $score   = $result ? $result['score'] : 0;

    $ok = ($matched === $expected);
    if ($ok) $passed++; else $failed++;

    $icon   = $ok ? $OK : $FAIL;
    $confStr = $conf ? " (conf:{$conf}%, score:{$score})" : " (no match)";
    $matchStr = $matched ? $matched : '(fallback)';
    $expectStr = $expected ?? '(fallback)';

    printf("   %s %-42s → %s%s\n",
        $icon,
        "\"" . substr($input, 0, 38) . "\"",
        $matchStr === $expectStr ? $matchStr : "$matchStr [expected: $expectStr]",
        $confStr
    );
}

echo "\n" . str_repeat('─', 55) . "\n";
echo "Results: $OK $passed passed | " . ($failed > 0 ? "$FAIL" : "") . " $failed failed\n";

// ── 4. normalizeText tests ────────────────────────────────
echo "\n6. Text normalization:\n";
$normalizeTests = [
    "I Can't Login!"        => "i cannot login",
    "   hello   world   "   => "hello world",
    "don't know what's this"=> "do not know what is this",
    "UPPERCASE TEXT"        => "uppercase text",
];
foreach ($normalizeTests as $in => $expected) {
    $got = normalizeText($in);
    $ok  = (strpos($got, strtolower(explode(' ',$expected)[0])) !== false);
    echo "   " . ($ok?$OK:$WARN) . " \"$in\" → \"$got\"\n";
}

// ── 5. Fuzzy matching tests ───────────────────────────────
echo "\n7. Fuzzy matching scores:\n";
$fuzzyTests = [
    ['register','registr',    'Typo: missing letter'],
    ['vendor',  'vndor',      'Typo: missing vowel'],
    ['login',   'logon',      'Similar word'],
    ['enquiry', 'inquiry',    'British/US spelling'],
    ['pricing', 'price',      'Stem variation'],
    ['hello',   'xyz123',     'No match (should be low)'],
];
foreach ($fuzzyTests as [$a, $b, $label]) {
    $score = fuzzyScore($a, $b);
    printf("   %-15s vs %-15s → %5.1f%% — %s\n", "\"$a\"", "\"$b\"", $score, $label);
}

echo "\n" . str_repeat('─', 55) . "\n";
echo "Test complete. DELETE chatbot/test_engine.php now!\n";
if (php_sapi_name() !== 'cli') echo '</pre>';
