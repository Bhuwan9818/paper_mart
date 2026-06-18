<?php
// ============================================================
// chatbot/engine.php — The Brain of the Free Chatbot
//
// HOW INTENT SCORING WORKS:
// ─────────────────────────
// 1. Normalize user input (lowercase, remove punctuation)
// 2. For every active intent, scan its keywords
// 3. Each keyword match adds its weight to the intent score
// 4. Partial/fuzzy matches add a fractional score
// 5. Intent with highest total score wins
// 6. If winner score >= MIN_CONFIDENCE → return response
// 7. If below threshold → fallback response + escalation offer
//
// HOW FUZZY MATCHING WORKS:
// ─────────────────────────
// similar_text("cant login","cannot login") → high similarity %
// levenshtein("registr","register") → edit distance = 2 (close)
// We combine both for a robust fuzzy score
//
// ============================================================

define('CB_MIN_CONFIDENCE', 20);   // minimum score to trigger a response
define('CB_FUZZY_THRESHOLD', 70);  // % similarity for fuzzy match to count
define('CB_RATE_LIMIT',      80);  // max messages per IP per day

// ─────────────────────────────────────────────────────────────
//  normalizeText()
//  Cleans user input for matching
// ─────────────────────────────────────────────────────────────
function normalizeText(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    // Remove punctuation except apostrophes (don't → dont)
    $text = preg_replace("/[^\w\s']/u", ' ', $text);
    // Collapse whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    // Common contractions
    $contractions = [
        "can't"  => 'cannot',  "won't"  => 'will not',
        "don't"  => 'do not',  "i'm"    => 'i am',
        "i've"   => 'i have',  "i'll"   => 'i will',
        "it's"   => 'it is',   "that's" => 'that is',
        "what's" => 'what is', "how's"  => 'how is',
        "didn't" => 'did not', "isn't"  => 'is not',
        "wasn't" => 'was not', "aren't" => 'are not',
    ];
    foreach ($contractions as $short => $long) {
        $text = str_replace($short, $long, $text);
    }
    return trim($text);
}

// ─────────────────────────────────────────────────────────────
//  fuzzyScore()
//  Returns 0-100 similarity between two strings.
//  Combines similar_text() and levenshtein() for best results.
// ─────────────────────────────────────────────────────────────
function fuzzyScore(string $a, string $b): float {
    if ($a === $b) return 100.0;
    if (empty($a) || empty($b)) return 0.0;

    // similar_text: percentage of common chars
    similar_text($a, $b, $simPct);

    // levenshtein: edit distance converted to similarity %
    $maxLen = max(strlen($a), strlen($b));
    if ($maxLen === 0) return 100.0;
    $lev    = levenshtein($a, $b);
    $levPct = (1 - ($lev / $maxLen)) * 100;

    // Weighted average: similar_text is more meaningful for phrases
    return ($simPct * 0.6) + ($levPct * 0.4);
}

// ─────────────────────────────────────────────────────────────
//  scoreIntent()
//  Given user input and all keywords for one intent,
//  returns a numeric confidence score.
//
//  Scoring breakdown:
//  • Exact phrase match in input  → keyword.weight * 10
//  • Word-level exact match       → keyword.weight * 6
//  • Fuzzy match ≥ threshold      → keyword.weight * (score/100) * 4
//  • Substring match              → keyword.weight * 3
// ─────────────────────────────────────────────────────────────
function scoreIntent(string $userText, array $keywords): int {
    $score     = 0;
    $userWords = explode(' ', $userText);

    foreach ($keywords as $kw) {
        $kword = $kw['keyword'];   // already lowercase in DB
        $w     = (int)$kw['weight'];

        // 1. Exact phrase match (highest value)
        if (strpos($userText, $kword) !== false) {
            $score += $w * 10;
            continue; // no need to check further for this keyword
        }

        // 2. Word-level exact matches
        // e.g. keyword "register" matches word "register" in "i want to register"
        $kWords   = explode(' ', $kword);
        $matched  = 0;
        foreach ($kWords as $kw_part) {
            if (in_array($kw_part, $userWords, true)) {
                $matched++;
            }
        }
        if ($matched === count($kWords) && $matched > 0) {
            $score += $w * 6;
            continue;
        }
        if ($matched > 0) {
            $score += $w * $matched * 2;
        }

        // 3. Fuzzy match on the full keyword vs full user text
        $fScore = fuzzyScore($userText, $kword);
        if ($fScore >= CB_FUZZY_THRESHOLD) {
            $score += (int)($w * ($fScore / 100) * 4);
            continue;
        }

        // 4. Fuzzy match each keyword word vs each user word
        foreach ($kWords as $kw_part) {
            if (strlen($kw_part) < 3) continue; // skip short words
            foreach ($userWords as $uw) {
                if (strlen($uw) < 3) continue;
                $fs = fuzzyScore($uw, $kw_part);
                if ($fs >= CB_FUZZY_THRESHOLD) {
                    $score += (int)($w * ($fs / 100) * 2);
                }
            }
        }

        // 5. Substring match (keyword word inside user text)
        foreach ($kWords as $kw_part) {
            if (strlen($kw_part) >= 4 && strpos($userText, $kw_part) !== false) {
                $score += $w * 3;
            }
        }
    }

    return $score;
}

// ─────────────────────────────────────────────────────────────
//  detectIntent()
//  Main intent detection function.
//  Returns ['intent' => row, 'score' => int, 'response' => row]
//  or null if nothing matches.
// ─────────────────────────────────────────────────────────────
function detectIntent(PDO $pdo, string $rawInput, array $sessionContext = []): ?array {
    $userText = normalizeText($rawInput);

    if (empty($userText)) return null;

    // Load all active intents + their keywords in one query
    try {
        $rows = $pdo->query("
            SELECT i.id AS intent_id, i.name, i.display_name, i.priority, i.requires_step,
                   k.keyword, k.weight, k.is_exact
            FROM cb_intents i
            JOIN cb_keywords k ON k.intent_id = i.id
            WHERE i.is_active = 1
            ORDER BY i.priority ASC, k.weight DESC
        ")->fetchAll();
    } catch (Exception $e) {
        return null;
    }

    if (empty($rows)) return null;

    // Group keywords by intent
    $intents = [];
    foreach ($rows as $row) {
        $iid = $row['intent_id'];
        if (!isset($intents[$iid])) {
            $intents[$iid] = [
                'id'            => $iid,
                'name'          => $row['name'],
                'display_name'  => $row['display_name'],
                'priority'      => $row['priority'],
                'requires_step' => $row['requires_step'],
                'keywords'      => [],
            ];
        }
        $intents[$iid]['keywords'][] = [
            'keyword'  => $row['keyword'],
            'weight'   => $row['weight'],
            'is_exact' => $row['is_exact'],
        ];
    }

    // Score each intent
    $scores = [];
    foreach ($intents as $iid => $intentData) {
        $score = scoreIntent($userText, $intentData['keywords']);

        // Priority bonus: higher priority intents get a small boost
        // priority 1 = +10, priority 10 = +1
        $priorityBonus = max(0, 11 - $intentData['priority']);
        $score += $priorityBonus;

        // Context bonus: if user's last intent is related, boost follow-up
        if (!empty($sessionContext['last_intent'])
            && $sessionContext['last_intent'] === $intentData['name']) {
            $score = (int)($score * 1.15); // 15% boost for continuity
        }

        $scores[$iid] = $score;
    }

    // Find winner
    arsort($scores);
    $winnerId    = array_key_first($scores);
    $winnerScore = $scores[$winnerId];

    if ($winnerScore < CB_MIN_CONFIDENCE) {
        return null; // no confident match
    }

    $winnerIntent = $intents[$winnerId];

    // Pick a random active response for this intent
    try {
        $rStmt = $pdo->prepare("
            SELECT * FROM cb_responses
            WHERE intent_id = ? AND is_active = 1
            ORDER BY RAND() LIMIT 1
        ");
        $rStmt->execute([$winnerId]);
        $response = $rStmt->fetch();
    } catch (Exception $e) {
        $response = null;
    }

    if (!$response) return null;

    // Increment usage count
    try {
        $pdo->prepare("UPDATE cb_responses SET usage_count = usage_count + 1 WHERE id = ?")
            ->execute([$response['id']]);
    } catch (Exception $e) {}

    // Normalize score to 0-100 for display
    $confidence = min(100, (int)(($winnerScore / 150) * 100));

    return [
        'intent'     => $winnerIntent,
        'score'      => $winnerScore,
        'confidence' => $confidence,
        'response'   => $response,
    ];
}

// ─────────────────────────────────────────────────────────────
//  getOrCreateCBSession()
// ─────────────────────────────────────────────────────────────
function getOrCreateCBSession(PDO $pdo, string $token, array $meta = []): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM cb_sessions WHERE session_token = ?");
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        if (!$session) {
            $pdo->prepare("
                INSERT INTO cb_sessions (session_token, user_id, user_name, ip_address)
                VALUES (?, ?, ?, ?)
            ")->execute([$token, $meta['user_id']??null, $meta['user_name']??'Guest', $meta['ip']??null]);
            $stmt->execute([$token]);
            $session = $stmt->fetch();
        }
        return $session ?: ['id'=>0,'session_token'=>$token,'current_intent'=>null,'current_step'=>0,'context_data'=>null,'last_intent'=>null,'status'=>'active'];
    } catch (Exception $e) {
        return ['id'=>0,'session_token'=>$token,'current_intent'=>null,'current_step'=>0,'context_data'=>null,'last_intent'=>null,'status'=>'active'];
    }
}

// ─────────────────────────────────────────────────────────────
//  saveCBMessage()
// ─────────────────────────────────────────────────────────────
function saveCBMessage(PDO $pdo, int $sessionId, string $role, string $msg, string $intent = '', int $confidence = 0): void {
    if ($sessionId <= 0) return;
    try {
        $pdo->prepare("INSERT INTO cb_messages (session_id,role,message,intent_matched,confidence) VALUES(?,?,?,?,?)")
            ->execute([$sessionId, $role, $msg, $intent?:null, $confidence]);
        $pdo->prepare("UPDATE cb_sessions SET msg_count=msg_count+1, updated_at=NOW() WHERE id=?")->execute([$sessionId]);
    } catch (Exception $e) {}
}

// ─────────────────────────────────────────────────────────────
//  updateSessionContext()
//  Saves current intent + step to session for multi-step flows
// ─────────────────────────────────────────────────────────────
function updateSessionContext(PDO $pdo, int $sessionId, array $updates): void {
    if ($sessionId <= 0) return;
    $sets   = [];
    $params = [];
    foreach (['current_intent','current_step','last_intent','status','user_name','user_email'] as $field) {
        if (array_key_exists($field, $updates)) {
            $sets[]   = "$field = ?";
            $params[] = $updates[$field];
        }
    }
    if (!empty($updates['context_data'])) {
        $sets[]   = "context_data = ?";
        $params[] = json_encode($updates['context_data']);
    }
    if (empty($sets)) return;
    $params[] = $sessionId;
    try {
        $pdo->prepare("UPDATE cb_sessions SET " . implode(', ', $sets) . " WHERE id = ?")
            ->execute($params);
    } catch (Exception $e) {}
}

// ─────────────────────────────────────────────────────────────
//  getFallbackResponse()
//  Returns a randomized fallback message with suggestions
// ─────────────────────────────────────────────────────────────
function getFallbackResponse(int $msgCount = 0): array {
    $fallbacks = [
        "🤔 I'm not sure I understood that. Could you rephrase?",
        "😅 I didn't quite catch that. Try asking something like \"how to register as vendor\" or \"subscription plans\".",
        "🔍 I couldn't find an answer for that. Can you be more specific?",
        "💡 That's outside my knowledge! Try asking about vendor registration, enquiries, subscriptions, or login help.",
    ];
    // If repeated fallbacks, escalate proactively
    $escalate = ($msgCount > 0 && $msgCount % 3 === 0);

    $msg = $fallbacks[array_rand($fallbacks)];
    if ($escalate) {
        $msg .= '<br><br>Would you like me to <strong>connect you with a human agent?</strong>';
    }

    return [
        'reply'        => $msg,
        'quick_replies'=> ['Vendor Registration','Send Enquiry','Subscription Plans','Talk to Support'],
        'escalate'     => $escalate,
        'is_fallback'  => true,
    ];
}

// ─────────────────────────────────────────────────────────────
//  isRateLimited()
// ─────────────────────────────────────────────────────────────
function cbIsRateLimited(PDO $pdo, string $ip): bool {
    $today = date('Y-m-d');
    try {
        $pdo->prepare("INSERT INTO cb_rate_limits (ip_address,request_count,window_date) VALUES(?,1,?) ON DUPLICATE KEY UPDATE request_count=request_count+1")
            ->execute([$ip, $today]);
        $count = (int)$pdo->prepare("SELECT request_count FROM cb_rate_limits WHERE ip_address=? AND window_date=?")
            ->execute([$ip,$today]) ? $pdo->query("SELECT request_count FROM cb_rate_limits WHERE ip_address=".($pdo->quote($ip))." AND window_date=".($pdo->quote($today)))->fetchColumn() : 0;

        // Simpler approach:
        $s = $pdo->prepare("SELECT request_count FROM cb_rate_limits WHERE ip_address=? AND window_date=?");
        $s->execute([$ip,$today]);
        $count = (int)$s->fetchColumn();
        return $count > CB_RATE_LIMIT;
    } catch (Exception $e) {
        return false;
    }
}

function cbGetClientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        $ip = trim(explode(',', $_SERVER[$k]??'')[0]);
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function cbJsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cbSanitize(string $input, int $maxLen = 800): string {
    $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
    return mb_substr(trim($input), 0, $maxLen);
}

function cbGenerateTicketRef(PDO $pdo): string {
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM cb_tickets WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    } catch(Exception $e){ $count = 0; }
    return 'TKT-' . date('Ymd') . '-' . sprintf('%04d', $count+1);
}

function cbNotifyAdmin(PDO $pdo, string $title, string $message, string $link = ''): void {
    try {
        $adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
        if (!$adminId) return;
        if (function_exists('addNotification')) {
            addNotification($pdo, $adminId, $title, $message, $link);
        } else {
            $pdo->prepare("INSERT INTO notifications (user_id,title,message,link) VALUES(?,?,?,?)")
                ->execute([$adminId, $title, $message, $link]);
        }
    } catch(Exception $e){}
}
