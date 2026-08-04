<?php
// ============================================================
// ajax/chatbot.php — Main Chatbot AJAX Endpoint (No OpenAI!)
// Place in: /dashv10_Fixed/ajax/chatbot.php
// ============================================================
define('IN_APP', true);
// require_once __DIR__ . '/../config.php';
// require_once __DIR__ . '/../includes/functions.php';
// require_once __DIR__ . '/../chatbot/engine.php';
// require_once __DIR__ . '/../chatbot/flow.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cbJsonOut(['error'=>'Method not allowed'],405); }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($body['action'] ?? 'message');
$token  = trim($body['token']  ?? '');
$ip     = cbGetClientIp();

// ── Rate limit ─────────────────────────────────────────────
if (cbIsRateLimited($pdo, $ip)) {
    cbJsonOut(['error'=>true,'reply'=>'You\'ve sent too many messages today. Please try again tomorrow or email admin@papermart.in.','type'=>'rate_limit'], 429);
}

// ── Get/create session ─────────────────────────────────────
$session      = getOrCreateCBSession($pdo, $token, ['ip'=>$ip]);
$sessionId    = (int)$session['id'];
$sessionToken = $session['session_token'];
$msgCount     = (int)$session['msg_count'];
$context      = ['last_intent' => $session['last_intent'] ?? null];

// ──────────────────────────────────────────────────────────────
//  ACTION: message — Main chat processing
// ──────────────────────────────────────────────────────────────
if ($action === 'message') {
    $rawInput = cbSanitize($body['message'] ?? '');

    if (empty($rawInput)) { cbJsonOut(['error'=>'Empty message'],400); }

    // Save user message
    saveCBMessage($pdo, $sessionId, 'user', $rawInput);

    // ── Special quick-reply triggers that start flows ────────
    $lowerInput = strtolower($rawInput);

    // "Raise Support Ticket" or "Raise a Ticket" → start flow
    if (preg_match('/raise.*ticket|create.*ticket|support ticket/i', $rawInput)) {
        $flowResult = startFlow($pdo, $sessionId, 'collect_ticket');
        saveCBMessage($pdo, $sessionId, 'bot', $flowResult['reply'], 'collect_ticket', 100);
        cbJsonOut([
            'reply'         => $flowResult['reply'],
            'quick_replies' => $flowResult['quick_replies'] ?? ['Cancel'],
            'token'         => $sessionToken,
            'in_flow'       => true,
        ]);
    }

    // "Send Enquiry" quick reply → start enquiry flow
    if (preg_match('/^(send enquiry|submit enquiry|quick enquiry|place enquiry)$/i', trim($rawInput))) {
        $flowResult = startFlow($pdo, $sessionId, 'collect_enquiry');
        saveCBMessage($pdo, $sessionId, 'bot', $flowResult['reply'], 'collect_enquiry', 100);
        cbJsonOut([
            'reply'         => $flowResult['reply'],
            'quick_replies' => $flowResult['quick_replies'] ?? ['Cancel'],
            'token'         => $sessionToken,
            'in_flow'       => true,
        ]);
    }

    // "Back to Main Menu" → reset
    if (in_array($lowerInput, ['back to main menu','main menu','restart','start over'])) {
        updateSessionContext($pdo, $sessionId, ['current_intent'=>null,'current_step'=>0]);
        $reply = "👋 Back to the main menu! What can I help you with?";
        saveCBMessage($pdo, $sessionId, 'bot', $reply);
        cbJsonOut(['reply'=>$reply,'quick_replies'=>['Vendor Registration','Send Enquiry','Subscription Plans','Contact Support'],'token'=>$sessionToken]);
    }

    // ── Check if a multi-step flow is active ─────────────────
    if (isFlowActive($session)) {
        $step     = (int)$session['current_step'];
        $ctx      = json_decode($session['context_data'] ?? '{}', true) ?: [];
        $flowResp = handleFlow($pdo, $session, $rawInput);

        // Advance to next step if flow continues
        if (!empty($flowResp['flow_continues'])) {
            $newStep = $flowResp['next_step'] ?? ($step + 1);
            $newCtx  = array_merge($ctx, $flowResp['save_ctx'] ?? []);
            updateSessionContext($pdo, $sessionId, [
                'current_step' => $newStep,
                'context_data' => $newCtx,
            ]);
        }

        saveCBMessage($pdo, $sessionId, 'bot', $flowResp['reply'], $session['current_intent'], 100);
        cbJsonOut([
            'reply'         => $flowResp['reply'],
            'quick_replies' => $flowResp['quick_replies'] ?? [],
            'token'         => $sessionToken,
            'in_flow'       => !empty($flowResp['flow_continues']),
        ]);
    }

    // ── Intent detection (the main matching engine) ───────────
    $match = detectIntent($pdo, $rawInput, $context);

    if ($match) {
        $intentName   = $match['intent']['name'];
        $responseRow  = $match['response'];
        $botReply     = $responseRow['response'];
        $quickReplies = [];

        if (!empty($responseRow['quick_replies'])) {
            $qr = $responseRow['quick_replies'];
            if (is_string($qr)) $qr = json_decode($qr, true);
            $quickReplies = is_array($qr) ? $qr : [];
        }

        // Special case: if "contact_support" matched, offer ticket flow
        if ($intentName === 'contact_support') {
            $quickReplies = ['Raise Support Ticket', 'No thanks — just asking'];
        }

        // Save bot reply
        saveCBMessage($pdo, $sessionId, 'bot', $botReply, $intentName, $match['confidence']);

        // Update session context
        updateSessionContext($pdo, $sessionId, [
            'last_intent'    => $intentName,
            'current_intent' => null,
            'current_step'   => 0,
        ]);

        // Log fallback if confidence is low
        if ($match['confidence'] < 40) {
            try {
                $pdo->prepare("INSERT INTO cb_fallback_log (session_id,message) VALUES(?,?)")->execute([$sessionId,$rawInput]);
            } catch(Exception $e){}
        }

        cbJsonOut([
            'reply'         => $botReply,
            'quick_replies' => $quickReplies,
            'intent'        => $intentName,
            'confidence'    => $match['confidence'],
            'token'         => $sessionToken,
            'in_flow'       => false,
        ]);
    }

    // ── No match — fallback ────────────────────────────────────
    $fallback = getFallbackResponse($msgCount);

    // Log unmatched message
    try {
        $pdo->prepare("INSERT INTO cb_fallback_log (session_id,message) VALUES(?,?)")->execute([$sessionId,$rawInput]);
    } catch(Exception $e){}

    saveCBMessage($pdo, $sessionId, 'bot', $fallback['reply'], 'fallback', 0);
    updateSessionContext($pdo, $sessionId, ['last_intent'=>null,'current_intent'=>null,'current_step'=>0]);

    cbJsonOut([
        'reply'         => $fallback['reply'],
        'quick_replies' => $fallback['quick_replies'],
        'token'         => $sessionToken,
        'in_flow'       => false,
        'is_fallback'   => true,
        'escalate'      => $fallback['escalate'],
    ]);
}

// ──────────────────────────────────────────────────────────────
//  ACTION: get_history — Load last N messages for returning users
// ──────────────────────────────────────────────────────────────
elseif ($action === 'get_history') {
    try {
        $msgs = $pdo->prepare("SELECT role, message, created_at FROM cb_messages WHERE session_id=? AND role IN('user','bot') ORDER BY created_at DESC LIMIT 20");
        $msgs->execute([$sessionId]);
        $history = array_reverse($msgs->fetchAll());
    } catch(Exception $e){ $history = []; }

    cbJsonOut(['history'=>$history,'token'=>$sessionToken,'in_flow'=>isFlowActive($session)]);
}

// ──────────────────────────────────────────────────────────────
//  ACTION: close — Close the session
// ──────────────────────────────────────────────────────────────
elseif ($action === 'close') {
    updateSessionContext($pdo, $sessionId, ['status'=>'closed','current_intent'=>null,'current_step'=>0]);
    cbJsonOut(['success'=>true,'token'=>$sessionToken]);
}

else {
    cbJsonOut(['error'=>'Unknown action'], 400);
}
