/**
 * PaperMart Free Chatbot Widget
 * File: /assets/chatbot.js
 * No dependencies — pure vanilla JavaScript
 * 
 * Features:
 * - Intent-based chat via PHP engine (no OpenAI)
 * - Quick reply chips
 * - FAQ suggestion pills
 * - Multi-step conversation flows
 * - Typing animation
 * - Session persistence (localStorage)
 * - Auto-scroll, timestamps, char counter
 * - Mobile responsive
 */
(function () {
  'use strict';

  // ── Config ─────────────────────────────────────────────────
  const AJAX_URL    = (window.PM_BASE_URL || '') + '/ajax/chatbot.php';
  const STORAGE_KEY = 'pmbot_session';

  // ── FAQ suggestions shown in the scrollable bar ────────────
  const FAQ_SUGGESTIONS = [
    'Vendor registration',
    'Subscription plans',
    'How to send enquiry?',
    'Login / Password reset',
    'About PaperMart',
    'Payment methods',
    'Shipping & delivery',
    'Cancel subscription',
  ];

  // ── Greeting message ───────────────────────────────────────
  const GREETING = `<p>👋 <strong>Hello! Welcome to PaperMart.</strong></p>
<p>I'm your virtual assistant — powered by smart intent matching, not an AI API!</p>
<p>I can help you with vendor registration, enquiries, subscriptions, and more.</p>`;
  const GREETING_CHIPS = ['How to become a vendor?','Send an enquiry','Subscription plans','Login help'];

  // ── State ──────────────────────────────────────────────────
  const state = {
    open:      false,
    loading:   false,
    inFlow:    false,
    token:     localStorage.getItem(STORAGE_KEY) || '',
    msgCount:  0,
    booted:    false,
  };

  // ── Build DOM ──────────────────────────────────────────────
  function buildWidget() {
    const el = document.createElement('div');
    el.id = 'pmbot';
    el.innerHTML = `
      <!-- Trigger button -->
      <button id="pmbot-btn" aria-label="Open PaperMart Chat Assistant" title="Chat with us">
        <svg class="ic-chat" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
        </svg>
        <svg class="ic-close" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
        <span id="pmbot-notif" aria-hidden="true">1</span>
      </button>

      <!-- Chat window -->
      <div id="pmbot-window" role="dialog" aria-label="PaperMart Chat Assistant">

        <!-- Header -->
        <div id="pmbot-header">
          <div class="pmbot-avatar">
            🤖<span class="pmbot-online-dot"></span>
          </div>
          <div class="pmbot-hd-info">
            <div class="pmbot-hd-name">PaperMart Assistant</div>
            <div class="pmbot-hd-sub">Online · Replies instantly</div>
          </div>
          <div class="pmbot-hd-btns">
            <button class="pmbot-hd-btn" id="pmbot-clear-btn" title="Clear chat">🗑</button>
            <button class="pmbot-hd-btn" id="pmbot-close-btn" title="Close">✕</button>
          </div>
        </div>

        <!-- Status bar (shows during multi-step flows) -->
        <div id="pmbot-status-bar">
          💬 Collecting your details — type <em>cancel</em> to stop.
        </div>

        <!-- Messages -->
        <div id="pmbot-msgs" role="log" aria-live="polite"></div>

        <!-- Typing indicator -->
        <div id="pmbot-typing">
          <div class="pmbot-row-avatar">🤖</div>
          <div class="pmbot-typing-dots">
            <div class="pmbot-dot"></div>
            <div class="pmbot-dot"></div>
            <div class="pmbot-dot"></div>
          </div>
        </div>

        <!-- FAQ suggestion chips -->
        <div id="pmbot-faq" aria-label="Frequently asked questions"></div>

        <!-- Input footer -->
        <div id="pmbot-footer">
          <div class="pmbot-input-row">
            <textarea id="pmbot-input" rows="1" placeholder="Type your message…" maxlength="700" aria-label="Message input"></textarea>
            <button id="pmbot-send" aria-label="Send">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
              </svg>
            </button>
          </div>
          <div class="pmbot-char" id="pmbot-char">0 / 700</div>
        </div>
      </div>
    `;
    return el;
  }

  // ── Helpers ────────────────────────────────────────────────
  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function fmtTime() {
    return new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
  }
  function scrollDown() {
    const m = document.getElementById('pmbot-msgs');
    if (m) requestAnimationFrame(() => { m.scrollTop = m.scrollHeight; });
  }
  function showTyping() {
    const t = document.getElementById('pmbot-typing');
    if (t) { t.style.display = 'flex'; scrollDown(); }
  }
  function hideTyping() {
    const t = document.getElementById('pmbot-typing');
    if (t) t.style.display = 'none';
  }
  function setSending(sending) {
    state.loading = sending;
    const btn = document.getElementById('pmbot-send');
    if (btn) btn.disabled = sending;
    const inp = document.getElementById('pmbot-input');
    if (inp) inp.disabled = sending;
  }
  function autoResize() {
    const ta = document.getElementById('pmbot-input');
    if (!ta) return;
    ta.style.height = 'auto';
    // ta.style.height = Math.min(ta.scrollHeight, 90) + 'px';
  }
  function updateCharCount() {
    const ta = document.getElementById('pmbot-input');
    const el = document.getElementById('pmbot-char');
    if (ta && el) el.textContent = ta.value.length + ' / 700';
  }

  // ── Append a message bubble ────────────────────────────────
  function appendMsg(role, html, showChips = []) {
    const msgs = document.getElementById('pmbot-msgs');
    if (!msgs) return;

    // Remove chips area from previous bot message
    const prevChips = msgs.querySelector('.pmbot-chips:last-child');
    if (prevChips) prevChips.remove();

    const row = document.createElement('div');
    row.className = 'pmbot-row ' + role;

    const avatarEl = document.createElement('div');
    avatarEl.className = 'pmbot-row-avatar';
    avatarEl.textContent = role === 'bot' ? '🤖' : '👤';

    const wrap = document.createElement('div');
    const bubble = document.createElement('div');
    bubble.className = 'pmbot-bubble';
    // For bot: allow HTML (our own templates). For user: escape.
    if (role === 'bot') {
      bubble.innerHTML = html;
    } else {
      bubble.textContent = html;
    }
    const time = document.createElement('div');
    time.className = 'pmbot-time';
    time.textContent = fmtTime();

    wrap.appendChild(bubble);
    wrap.appendChild(time);
    row.appendChild(avatarEl);
    row.appendChild(wrap);
    msgs.appendChild(row);

    // Quick-reply chips
    if (showChips.length > 0) {
      appendChips(showChips);
    }

    scrollDown();
    state.msgCount++;
  }

  function appendChips(chips) {
    const msgs = document.getElementById('pmbot-msgs');
    if (!msgs) return;
    const el = document.createElement('div');
    el.className = 'pmbot-chips';
    chips.forEach(chip => {
      const btn = document.createElement('button');
      btn.className = 'pmbot-chip';
      btn.textContent = chip;
      btn.onclick = () => {
        el.remove();
        sendMessage(chip);
      };
      el.appendChild(btn);
    });
    msgs.appendChild(el);
    scrollDown();
  }

  // ── FAQ Bar ────────────────────────────────────────────────
  function buildFAQBar() {
    const faq = document.getElementById('pmbot-faq');
    if (!faq) return;
    faq.innerHTML = '';
    FAQ_SUGGESTIONS.forEach(q => {
      const btn = document.createElement('button');
      btn.className = 'pmbot-faq-chip';
      btn.textContent = q;
      btn.onclick = () => sendMessage(q);
      faq.appendChild(btn);
    });
  }

  // ── Show/hide flow status bar ──────────────────────────────
  function setFlowMode(inFlow) {
    state.inFlow = inFlow;
    const bar = document.getElementById('pmbot-status-bar');
    const faq = document.getElementById('pmbot-faq');
    if (bar) bar.classList.toggle('show', inFlow);
    if (faq) faq.style.display = inFlow ? 'none' : 'flex';
    const inp = document.getElementById('pmbot-input');
    if (inp) inp.placeholder = inFlow ? 'Type your answer…' : 'Type your message…';
  }

  // ── Toggle widget open/close ───────────────────────────────
  function toggleWidget(force) {
    state.open = (force !== undefined) ? force : !state.open;
    const btn = document.getElementById('pmbot-btn');
    const win = document.getElementById('pmbot-window');
    if (!btn || !win) return;

    btn.classList.toggle('open', state.open);
    btn.classList.remove('pulse');
    win.classList.toggle('open', state.open);

    if (state.open) {
      // Hide notification badge
      document.getElementById('pmbot-notif')?.classList.remove('show');
      // Focus input
      setTimeout(() => document.getElementById('pmbot-input')?.focus(), 280);
      // Boot greeting
      if (!state.booted) {
        state.booted = true;
        // Small delay for smoother animation
        setTimeout(bootGreeting, 200);
      }
    }
  }

  // ── First-load greeting ────────────────────────────────────
  function bootGreeting() {
    // Check if there's an existing session with history
    if (state.token) {
      fetch(AJAX_URL, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action:'get_history', token:state.token}),
      })
      .then(r => r.json())
      .then(data => {
        if (data.token) {
          state.token = data.token;
          localStorage.setItem(STORAGE_KEY, data.token);
        }
        if (data.history && data.history.length > 0) {
          // Restore last few messages
          data.history.slice(-6).forEach(m => {
            appendMsg(m.role === 'user' ? 'user' : 'bot', m.role === 'user' ? m.message : m.message);
          });
          if (data.in_flow) setFlowMode(true);
        } else {
          showGreeting();
        }
      })
      .catch(() => showGreeting());
    } else {
      showGreeting();
    }
  }

  function showGreeting() {
    // Typing → greeting
    showTyping();
    setTimeout(() => {
      hideTyping();
      appendMsg('bot', GREETING, GREETING_CHIPS);
    }, 600);
  }

  // ── Send a message ─────────────────────────────────────────
  async function sendMessage(text) {
    if (!text || state.loading) return;
    text = text.trim();
    if (!text) return;

    // Remove chips
    document.querySelectorAll('.pmbot-chips').forEach(el => el.remove());

    // Show user message
    appendMsg('user', text);

    const input = document.getElementById('pmbot-input');
    if (input) { input.value = ''; autoResize(); updateCharCount(); }

    setSending(true);
    showTyping();

    // Simulate slight typing delay (more natural)
    const delay = 600 + Math.min(text.length * 12, 1000);

    try {
      const res = await fetch(AJAX_URL, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action:'message', message:text, token:state.token}),
      });
      const data = await res.json();

      await new Promise(r => setTimeout(r, delay));
      hideTyping();

      if (data.token) {
        state.token = data.token;
        localStorage.setItem(STORAGE_KEY, data.token);
      }

      const reply      = data.reply || "Sorry, I couldn't process that. Please try again.";
      const chips      = Array.isArray(data.quick_replies) ? data.quick_replies : [];
      const inFlow     = !!data.in_flow;

      appendMsg('bot', reply, chips);
      setFlowMode(inFlow);

      // If error type rate limit
      if (data.type === 'rate_limit') {
        appendMsg('bot', '⏳ You\'ve reached today\'s message limit. Come back tomorrow!');
      }

    } catch (err) {
      await new Promise(r => setTimeout(r, delay));
      hideTyping();
      appendMsg('bot', '⚠️ Connection error. Please check your internet and try again.', ['Try again','Contact Support']);
    } finally {
      setSending(false);
    }
  }

  // ── Clear chat ─────────────────────────────────────────────
  function clearChat() {
    if (!confirm('Clear this conversation?')) return;
    const msgs = document.getElementById('pmbot-msgs');
    if (msgs) msgs.innerHTML = '';
    localStorage.removeItem(STORAGE_KEY);
    state.token   = '';
    state.booted  = false;
    state.msgCount= 0;
    setFlowMode(false);
    showGreeting();
  }

  // ── Attention badge (shows after 5s if not opened) ────────
  function scheduleAttention() {
    setTimeout(() => {
      if (!state.open) {
        const notif = document.getElementById('pmbot-notif');
        const btn   = document.getElementById('pmbot-btn');
        notif?.classList.add('show');
        btn?.classList.add('pulse');
      }
    }, 5000);
  }

  // ── Init ───────────────────────────────────────────────────
  function init() {
    // Inject CSS
    const link = document.createElement('link');
    link.rel  = 'stylesheet';
    link.href = (window.PM_BASE_URL || '') + '/assets/chatbot.css';
    document.head.appendChild(link);

    // Inject widget HTML
    document.body.appendChild(buildWidget());

    // Build FAQ bar
    buildFAQBar();

    // Events
    document.getElementById('pmbot-btn')?.addEventListener('click', () => toggleWidget());
    document.getElementById('pmbot-close-btn')?.addEventListener('click', () => toggleWidget(false));
    document.getElementById('pmbot-clear-btn')?.addEventListener('click', clearChat);

    // Input: Enter to send, Shift+Enter for newline
    const inp = document.getElementById('pmbot-input');
    if (inp) {
      inp.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          const val = inp.value.trim();
          if (val) sendMessage(val);
        }
      });
      inp.addEventListener('input', () => { autoResize(); updateCharCount(); });
    }

    // Send button
    document.getElementById('pmbot-send')?.addEventListener('click', () => {
      const val = inp?.value.trim();
      if (val) sendMessage(val);
    });

    // Close on Escape
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && state.open) toggleWidget(false);
    });

    // Show attention badge after delay
    scheduleAttention();
  }

  // Run after DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
