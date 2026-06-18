-- ============================================================
-- MIGRATION: Subscription & Credit System
-- Run in phpMyAdmin AFTER database.sql
-- ============================================================
USE product_enquiry;

CREATE TABLE IF NOT EXISTS subscription_plans (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(50)  NOT NULL UNIQUE,
    price_monthly   DECIMAL(10,2) DEFAULT 0,
    price_yearly    DECIMAL(10,2) DEFAULT 0,
    product_limit   INT DEFAULT 5,
    enquiry_limit   INT DEFAULT 10,
    image_limit     INT DEFAULT 2,
    analytics       TINYINT(1) DEFAULT 0,
    priority_listing TINYINT(1) DEFAULT 0,
    badge           VARCHAR(50)  DEFAULT '',
    color           VARCHAR(20)  DEFAULT '#6366f1',
    features        TEXT,
    is_active       TINYINT(1) DEFAULT 1,
    sort_order      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vendor_subscriptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id       INT NOT NULL,
    plan_id         INT NOT NULL,
    billing_cycle   ENUM('monthly','yearly') DEFAULT 'monthly',
    status          ENUM('active','expired','cancelled','trial') DEFAULT 'trial',
    started_at      DATETIME,
    expires_at      DATETIME,
    trial_ends_at   DATETIME,
    auto_renew      TINYINT(1) DEFAULT 1,
    payment_ref     VARCHAR(200) DEFAULT '',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id)   REFERENCES subscription_plans(id)
);

CREATE TABLE IF NOT EXISTS vendor_usage (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id       INT NOT NULL,
    month_year      VARCHAR(7) NOT NULL,
    products_added  INT DEFAULT 0,
    enquiries_sent  INT DEFAULT 0,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vendor_month (vendor_id, month_year),
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS subscription_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id       INT NOT NULL,
    plan_id         INT NOT NULL,
    amount          DECIMAL(10,2),
    currency        VARCHAR(10) DEFAULT 'INR',
    billing_cycle   ENUM('monthly','yearly'),
    payment_method  VARCHAR(50) DEFAULT 'manual',
    payment_ref     VARCHAR(200),
    status          ENUM('paid','pending','failed','refunded') DEFAULT 'pending',
    paid_at         DATETIME,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id)   REFERENCES subscription_plans(id)
);

CREATE TABLE IF NOT EXISTS vendor_analytics (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id   INT NOT NULL,
    product_id  INT,
    event_type  ENUM('product_view','enquiry_received','profile_view') NOT NULL,
    meta        TEXT,
    event_date  DATE NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Seed plans
INSERT IGNORE INTO subscription_plans (name,slug,price_monthly,price_yearly,product_limit,enquiry_limit,image_limit,analytics,priority_listing,badge,color,features,sort_order) VALUES
('Free','free',0,0,3,5,1,0,0,'','#64748b','["3 product listings","5 enquiries/month","1 image per product","Basic dashboard","Community support"]',1),
('Starter','starter',999,9990,15,50,3,0,0,'','#3b82f6','["15 product listings","50 enquiries/month","3 images per product","Basic analytics","Email support"]',2),
('Professional','professional',2499,24990,50,200,8,1,1,'POPULAR','#8b5cf6','["50 product listings","200 enquiries/month","8 images per product","Full analytics & insights","Performance reports","Priority listing","Dedicated support","Featured vendor badge"]',3),
('Enterprise','enterprise',5999,59990,-1,-1,20,1,1,'BEST VALUE','#f59e0b','["Unlimited products","Unlimited enquiries","20 images per product","Advanced analytics","Top priority listing","API access","Dedicated account manager"]',4);
