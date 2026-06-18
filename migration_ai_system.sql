-- ============================================================
-- AI Chatbot + Enquiry Assistant — Database Migration
-- PaperMart (product_enquiry database)
-- Run AFTER your existing database.sql
-- ============================================================

USE product_enquiry;

-- -------------------------------------------------------
-- AI Chat Sessions
-- Stores each visitor's conversation session
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_chat_sessions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    session_token VARCHAR(64) NOT NULL UNIQUE,          -- random token stored in localStorage
    user_id       INT NULL,                              -- if logged-in user
    user_name     VARCHAR(100) DEFAULT 'Guest',
    user_email    VARCHAR(150) DEFAULT NULL,
    language      ENUM('en','hi') DEFAULT 'en',
    status        ENUM('active','escalated','closed') DEFAULT 'active',
    page_url      VARCHAR(500) DEFAULT NULL,            -- page where chat was opened
    ip_address    VARCHAR(45) DEFAULT NULL,
    user_agent    TEXT DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_token    (session_token),
    INDEX idx_status   (status),
    INDEX idx_created  (created_at)
);

-- -------------------------------------------------------
-- AI Chat Messages
-- Every message in every session (both user and assistant)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_chat_messages (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    session_id    INT NOT NULL,
    role          ENUM('user','assistant','system') NOT NULL,
    content       TEXT NOT NULL,
    tokens_used   SMALLINT UNSIGNED DEFAULT 0,          -- track token cost per message
    model_used    VARCHAR(50) DEFAULT NULL,             -- e.g. gpt-4o-mini
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES ai_chat_sessions(id) ON DELETE CASCADE,
    INDEX idx_session  (session_id),
    INDEX idx_created  (created_at)
);

-- -------------------------------------------------------
-- AI Support Tickets
-- Created when chatbot escalates to human support
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_support_tickets (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    session_id    INT NOT NULL,
    ticket_ref    VARCHAR(20) NOT NULL UNIQUE,          -- e.g. TKT-20240115-0042
    user_name     VARCHAR(100) NOT NULL,
    user_email    VARCHAR(150) NOT NULL,
    user_phone    VARCHAR(20) DEFAULT NULL,
    subject       VARCHAR(255) NOT NULL,
    description   TEXT NOT NULL,
    priority      ENUM('low','medium','high','urgent') DEFAULT 'medium',
    status        ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    assigned_to   INT NULL,                             -- admin user ID
    resolution    TEXT DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at   TIMESTAMP NULL,
    FOREIGN KEY (session_id) REFERENCES ai_chat_sessions(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status   (status),
    INDEX idx_email    (user_email),
    INDEX idx_ref      (ticket_ref)
);

-- -------------------------------------------------------
-- AI Enquiry Leads
-- Structured data extracted by the AI Enquiry Assistant
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_enquiry_leads (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    session_id       INT NULL,                          -- if came from chatbot
    web_enquiry_id   INT NULL,                          -- linked to web_enquiries if converted
    raw_text         TEXT NOT NULL,                     -- original user input
    -- Extracted entities
    product_name     VARCHAR(200) DEFAULT NULL,
    product_category VARCHAR(150) DEFAULT NULL,
    quantity         VARCHAR(100) DEFAULT NULL,
    quantity_unit    VARCHAR(50) DEFAULT NULL,          -- kg, pieces, tons, etc.
    city             VARCHAR(100) DEFAULT NULL,
    state            VARCHAR(100) DEFAULT NULL,
    delivery_location VARCHAR(200) DEFAULT NULL,
    budget_range     VARCHAR(100) DEFAULT NULL,
    timeline         VARCHAR(100) DEFAULT NULL,
    specifications   TEXT DEFAULT NULL,                 -- JSON string of extra specs
    intent           ENUM('buy','quote','sample','compare','info') DEFAULT 'buy',
    confidence       TINYINT UNSIGNED DEFAULT 0,        -- 0-100 AI confidence score
    -- Contact info if provided
    user_name        VARCHAR(100) DEFAULT NULL,
    user_email       VARCHAR(150) DEFAULT NULL,
    user_phone       VARCHAR(20) DEFAULT NULL,
    user_company     VARCHAR(150) DEFAULT NULL,
    -- Meta
    matched_vendors  TEXT DEFAULT NULL,                 -- JSON array of matched vendor IDs
    status           ENUM('new','contacted','converted','junk') DEFAULT 'new',
    source           ENUM('chatbot','widget','api','manual') DEFAULT 'chatbot',
    ip_address       VARCHAR(45) DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id)     REFERENCES ai_chat_sessions(id) ON DELETE SET NULL,
    FOREIGN KEY (web_enquiry_id) REFERENCES web_enquiries(id)    ON DELETE SET NULL,
    INDEX idx_product  (product_name),
    INDEX idx_city     (city),
    INDEX idx_status   (status),
    INDEX idx_created  (created_at)
);

-- -------------------------------------------------------
-- AI Rate Limiting
-- Prevents abuse — one record per IP per day
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_rate_limits (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45) NOT NULL,
    endpoint     VARCHAR(50) NOT NULL,                  -- 'chat' or 'enquiry'
    request_count SMALLINT UNSIGNED DEFAULT 1,
    window_date  DATE NOT NULL,                         -- rate limit resets daily
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY  uq_ip_endpoint_date (ip_address, endpoint, window_date),
    INDEX idx_date (window_date)
);

-- -------------------------------------------------------
-- AI API Usage Log
-- Track costs and monitor usage
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_usage_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    session_id    INT NULL,
    endpoint      VARCHAR(50) NOT NULL,
    model         VARCHAR(50) NOT NULL,
    prompt_tokens INT UNSIGNED DEFAULT 0,
    completion_tokens INT UNSIGNED DEFAULT 0,
    total_tokens  INT UNSIGNED DEFAULT 0,
    estimated_cost_usd DECIMAL(8,6) DEFAULT 0.000000,
    response_ms   SMALLINT UNSIGNED DEFAULT 0,          -- latency in milliseconds
    success       TINYINT(1) DEFAULT 1,
    error_msg     VARCHAR(500) DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_date    (created_at)
);

-- -------------------------------------------------------
-- Admin Notifications for AI events
-- Inserts into existing notifications table
-- (No new table — uses your existing notifications system)
-- -------------------------------------------------------

-- -------------------------------------------------------
-- Cleanup event — purge old rate limit records
-- -------------------------------------------------------
CREATE EVENT IF NOT EXISTS cleanup_ai_rate_limits
    ON SCHEDULE EVERY 1 DAY
    STARTS CURRENT_TIMESTAMP
    DO DELETE FROM ai_rate_limits WHERE window_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY);

-- Done!
SELECT 'AI System migration complete.' AS status;
