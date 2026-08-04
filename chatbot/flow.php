<?php
// ============================================================
// chatbot/flow.php — Multi-Step Conversation Flow Handler
//
// HOW MULTI-STEP FLOWS WORK:
// ──────────────────────────
// When a user wants human support, the bot collects info
// over multiple turns rather than showing a form.
//
// Flow state is saved in cb_sessions:
//   current_intent = 'collect_ticket'
//   current_step   = 1, 2, 3 ...
//   context_data   = {"name":"...", "email":"..."}
//
// Each AJAX call checks if a flow is active and continues it
// instead of doing intent detection.
// ============================================================

// ─────────────────────────────────────────────────────────────
//  isFlowActive()
//  Returns true if the session is mid-way through a multi-step
// ─────────────────────────────────────────────────────────────
function isFlowActive(array $session): bool {
    return !empty($session['current_intent'])
        && (int)$session['current_step'] > 0;
}

// ─────────────────────────────────────────────────────────────
//  handleFlow()
//  Processes the current step of an active conversation flow.
//  Returns the next bot message.
// ─────────────────────────────────────────────────────────────
function handleFlow(PDO $pdo, array $session, string $userInput): array {
    $intent   = $session['current_intent'];
    $step     = (int)$session['current_step'];
    $ctx      = json_decode($session['context_data'] ?? '{}', true) ?: [];
    $sid      = (int)$session['id'];
    $input    = trim($userInput);

    // ── TICKET COLLECTION FLOW ────────────────────────────────
    if ($intent === 'collect_ticket') {
        return handleTicketFlow($pdo, $sid, $step, $ctx, $input);
    }

    // ── ENQUIRY COLLECTION FLOW ───────────────────────────────
    if ($intent === 'collect_enquiry') {
        return handleEnquiryFlow($pdo, $sid, $step, $ctx, $input);
    }

    // Unknown flow — cancel it
    updateSessionContext($pdo, $sid, ['current_intent'=>null,'current_step'=>0]);
    return [
        'reply'         => "Let me start fresh! What can I help you with?",
        'quick_replies' => ['Vendor Registration','Send Enquiry','Subscription Plans','Support'],
    ];
}

// ─────────────────────────────────────────────────────────────
//  handleTicketFlow()
//  Collects name → email → phone → subject → description
//  Then creates the ticket.
// ─────────────────────────────────────────────────────────────
function handleTicketFlow(PDO $pdo, int $sid, int $step, array $ctx, string $input): array {

    // Allow user to cancel
    if (in_array(strtolower($input), ['cancel','stop','exit','no','no thanks','abort'])) {
        updateSessionContext($pdo, $sid, ['current_intent'=>null,'current_step'=>0]);
        return [
            'reply'         => "No problem! Ticket creation cancelled. Is there anything else I can help you with?",
            'quick_replies' => ['Vendor Registration','Send Enquiry','Subscription Plans'],
        ];
    }

    switch ($step) {
        // Step 1: Ask for name
        case 1:
            return [
                'reply'      => "Sure! I'll create a support ticket for you. 🎫\n\n<strong>Step 1/4:</strong> What is your <strong>full name</strong>?",
                'next_step'  => 2,
                'save_ctx'   => [],
                'flow_continues' => true,
            ];

        // Step 2: Got name, ask for email
        case 2:
            if (strlen($input) < 2) {
                return ['reply'=>"Please enter your name.", 'next_step'=>2, 'flow_continues'=>true];
            }
            return [
                'reply'      => "Thanks, <strong>" . htmlspecialchars($input) . "</strong>! 👋\n\n<strong>Step 2/4:</strong> What is your <strong>email address</strong>?",
                'next_step'  => 3,
                'save_ctx'   => ['name' => $input],
                'flow_continues' => true,
            ];

        // Step 3: Got email, ask for phone
        case 3:
            if (!filter_var($input, FILTER_VALIDATE_EMAIL)) {
                return ['reply'=>"❌ That doesn't look like a valid email. Please enter a valid email address.", 'next_step'=>3, 'flow_continues'=>true, 'save_ctx'=>[]];
            }
            return [
                'reply'      => "Got it! ✅\n\n<strong>Step 3/4:</strong> Your <strong>phone number</strong>? (Type 'skip' to skip)",
                'next_step'  => 4,
                'save_ctx'   => ['email' => $input],
                'flow_continues' => true,
            ];

        // Step 4: Got phone, ask for issue description
        case 4:
            $phone = strtolower($input) === 'skip' ? '' : preg_replace('/[^0-9+\-\s]/','',$input);
            return [
                'reply'      => "<strong>Step 4/4:</strong> Please briefly describe your issue or question:",
                'next_step'  => 5,
                'save_ctx'   => ['phone' => $phone],
                'flow_continues' => true,
            ];

        // Step 5: Got description, create ticket
        case 5:
            if (strlen($input) < 5) {
                return ['reply'=>"Please describe your issue in a few words.", 'next_step'=>5, 'flow_continues'=>true, 'save_ctx'=>[]];
            }

            $name    = $ctx['name']   ?? 'Guest';
            $email   = $ctx['email']  ?? '';
            $phone   = $ctx['phone']  ?? '';
            $desc    = $input;
            $subject = 'Support Request from ' . $name;

            if (!$email) {
                updateSessionContext($pdo, $sid, ['current_intent'=>null,'current_step'=>0]);
                return ['reply'=>"Something went wrong — missing email. Please try again.", 'quick_replies'=>['Talk to Support']];
            }

            // Create ticket
            $ticketRef = cbGenerateTicketRef($pdo);
            try {
                $pdo->prepare("INSERT INTO cb_tickets (session_id,ticket_ref,user_name,user_email,user_phone,subject,description) VALUES(?,?,?,?,?,?,?)")
                    ->execute([$sid,$ticketRef,$name,$email,$phone,$subject,$desc]);

                // Update session
                updateSessionContext($pdo, $sid, [
                    'current_intent' => null,
                    'current_step'   => 0,
                    'user_name'      => $name,
                    'user_email'     => $email,
                    'status'         => 'escalated',
                    'context_data'   => [],
                ]);

                // Notify admin
                cbNotifyAdmin($pdo, "New Support Ticket: $ticketRef", "From: $name ($email)\n$desc", BASE_URL.'/admin/chatbot-tickets.php');

            } catch(Exception $e) {
                updateSessionContext($pdo, $sid, ['current_intent'=>null,'current_step'=>0]);
                return ['reply'=>"Sorry, could not create ticket. Please email <a href='mailto:admin@papermart.in'>admin@papermart.in</a> directly."];
            }

            return [
                'reply' => "✅ <strong>Ticket Created Successfully!</strong><br><br>
                    🎫 Ticket Ref: <strong>$ticketRef</strong><br>
                    👤 Name: $name<br>
                    📧 Email: $email<br><br>
                    Our support team will contact you at <strong>$email</strong> within <strong>24 hours</strong>.<br>
                    Keep your ticket reference safe!",
                'quick_replies' => ['Back to Main Menu','Ask Another Question'],
                'flow_continues' => false,
            ];

        default:
            updateSessionContext($pdo, $sid, ['current_intent'=>null,'current_step'=>0]);
            return ['reply'=>"Let me restart. What can I help you with?", 'quick_replies'=>['Vendor Registration','Send Enquiry','Support']];
    }
}

// ─────────────────────────────────────────────────────────────
//  handleEnquiryFlow()
//  Quick enquiry collection: product → qty → city → contact
// ─────────────────────────────────────────────────────────────
function handleEnquiryFlow(PDO $pdo, int $sid, int $step, array $ctx, string $input): array {

    if (in_array(strtolower($input), ['cancel','stop','exit','no'])) {
        updateSessionContext($pdo, $sid, ['current_intent'=>null,'current_step'=>0]);
        return ['reply'=>"Enquiry cancelled. What else can I help you with?", 'quick_replies'=>['Vendor Registration','Subscription Plans','Support']];
    }

    switch ($step) {
        case 1:
            return [
                'reply'      => "📋 <strong>Quick Enquiry Form</strong>\n\n<strong>Step 1/4:</strong> What <strong>product</strong> do you need? (e.g. Kraft Paper 80 GSM)",
                'next_step'  => 2, 'save_ctx' => [], 'flow_continues' => true,
            ];
        case 2:
            if (strlen($input) < 2) return ['reply'=>"Please tell me the product name.", 'next_step'=>2, 'flow_continues'=>true];
            return [
                'reply'      => "Great choice! 📦\n\n<strong>Step 2/4:</strong> What <strong>quantity</strong> do you need? (e.g. 500 kg, 10000 pieces)",
                'next_step'  => 3, 'save_ctx' => ['product'=>$input], 'flow_continues' => true,
            ];
        case 3:
            return [
                'reply'      => "<strong>Step 3/4:</strong> Which <strong>city</strong> do you need delivery to?",
                'next_step'  => 4, 'save_ctx' => ['quantity'=>$input], 'flow_continues' => true,
            ];
        case 4:
            return [
                'reply'      => "<strong>Step 4/4:</strong> Your <strong>email address</strong> so vendors can send quotes:",
                'next_step'  => 5, 'save_ctx' => ['city'=>$input], 'flow_continues' => true,
            ];
        case 5:
            if (!filter_var($input, FILTER_VALIDATE_EMAIL)) {
                return ['reply'=>"❌ Please enter a valid email address.", 'next_step'=>5, 'flow_continues'=>true];
            }
            $product  = htmlspecialchars($ctx['product']  ?? '');
            $quantity = htmlspecialchars($ctx['quantity'] ?? '');
            $city     = htmlspecialchars($ctx['city']     ?? '');
            $email    = $input;

            // Save to web_enquiries if table exists
            try {
                $message = "Product: $product\nQty: $quantity\nCity: $city\n[Via Chatbot]";
                $pdo->prepare("INSERT INTO web_enquiries (name,email,city,message,qty_needed,source,ip_address) VALUES(?,?,?,?,?,'chatbot',?)")
                    ->execute(['Chatbot Lead', $email, $city, $message, $quantity, $_SERVER['REMOTE_ADDR']??'']);
            } catch(Exception $e) {} // table may not exist yet

            updateSessionContext($pdo, $sid, ['current_intent'=>null,'current_step'=>0,'user_email'=>$email,'context_data'=>[]]);
            cbNotifyAdmin($pdo, "New Chatbot Enquiry: $product", "Product: $product\nQty: $quantity\nCity: $city\nEmail: $email", BASE_URL.'/admin/web-enquiries.php');

            return [
                'reply'      => "✅ <strong>Enquiry Submitted!</strong><br><br>
                    📦 Product: <strong>$product</strong><br>
                    ⚖️ Quantity: <strong>$quantity</strong><br>
                    📍 City: <strong>$city</strong><br><br>
                    Matched suppliers will contact you at <strong>$email</strong> within 24 hours! 🚀",
                'quick_replies' => ['Ask Another Question','Talk to Support'],
                'flow_continues' => false,
            ];
        default:
            updateSessionContext($pdo, $sid, ['current_intent'=>null,'current_step'=>0]);
            return ['reply'=>"Something went wrong. Let me restart!", 'quick_replies'=>['Send Enquiry','Support']];
    }
}

// ─────────────────────────────────────────────────────────────
//  startFlow()
//  Called when a quick-reply button triggers a multi-step flow
// ─────────────────────────────────────────────────────────────
function startFlow(PDO $pdo, int $sid, string $flowName): array {
    updateSessionContext($pdo, $sid, [
        'current_intent' => $flowName,
        'current_step'   => 1,
        'context_data'   => [],
    ]);
    // Return step 1 message
    if ($flowName === 'collect_ticket') {
        return handleTicketFlow($pdo, $sid, 1, [], '');
    }
    if ($flowName === 'collect_enquiry') {
        return handleEnquiryFlow($pdo, $sid, 1, [], '');
    }
    return ['reply'=>"Let's get started! What can I help you with?"];
}
