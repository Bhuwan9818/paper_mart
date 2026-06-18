-- ============================================================
-- MIGRATION: Website Features
-- Run AFTER database.sql and migration_subscription.sql
-- ============================================================
USE product_enquiry;

-- Add slug and extra fields to products
ALTER TABLE products
  ADD COLUMN slug VARCHAR(220) DEFAULT '' AFTER name,
  ADD COLUMN short_desc VARCHAR(300) DEFAULT '' AFTER description,
  ADD COLUMN tags VARCHAR(500) DEFAULT '' AFTER short_desc,
  ADD COLUMN is_featured TINYINT(1) DEFAULT 0 AFTER tags,
  ADD COLUMN compare_count INT DEFAULT 0 AFTER views;

-- Add slug to categories and industries
ALTER TABLE categories ADD COLUMN slug VARCHAR(120) DEFAULT '' AFTER name;
ALTER TABLE industries ADD COLUMN slug VARCHAR(120) DEFAULT '' AFTER name;
ALTER TABLE product_types ADD COLUMN slug VARCHAR(120) DEFAULT '' AFTER name;

-- Website enquiries (from public, no login required)
CREATE TABLE IF NOT EXISTS web_enquiries (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT,
    vendor_id   INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) NOT NULL,
    phone       VARCHAR(20),
    company     VARCHAR(150),
    city        VARCHAR(100),
    message     TEXT,
    qty_needed  VARCHAR(100),
    status      ENUM('new','contacted','closed') DEFAULT 'new',
    source      VARCHAR(50) DEFAULT 'website',
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (vendor_id)  REFERENCES users(id)    ON DELETE CASCADE
);

-- Compare session (temporary, cleared periodically)
CREATE TABLE IF NOT EXISTS compare_sessions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    session_key VARCHAR(64) NOT NULL,
    product_id  INT NOT NULL,
    added_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uk_sess_prod (session_key, product_id)
);

-- Vendor profiles (extended public info)
CREATE TABLE IF NOT EXISTS vendor_profiles (
    vendor_id       INT PRIMARY KEY,
    tagline         VARCHAR(200) DEFAULT '',
    established_yr  INT,
    employees       VARCHAR(50),
    annual_turnover VARCHAR(100),
    certifications  TEXT,
    logo            VARCHAR(255),
    banner          VARCHAR(255),
    website         VARCHAR(255),
    linkedin        VARCHAR(255),
    facebook        VARCHAR(255),
    is_verified     TINYINT(1) DEFAULT 0,
    rating          DECIMAL(3,1) DEFAULT 0,
    total_reviews   INT DEFAULT 0,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Product reviews (B2B ratings)
CREATE TABLE IF NOT EXISTS product_reviews (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT NOT NULL,
    reviewer_id INT NOT NULL,
    rating      TINYINT NOT NULL CHECK(rating BETWEEN 1 AND 5),
    review      TEXT,
    is_verified TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id)      ON DELETE CASCADE,
    UNIQUE KEY uk_prod_reviewer (product_id, reviewer_id)
);

-- Search logs (for analytics)
CREATE TABLE IF NOT EXISTS search_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    query       VARCHAR(255),
    results     INT DEFAULT 0,
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Update slugs for existing data
UPDATE industries SET slug = LOWER(REPLACE(REPLACE(name,' ','-'),'&','and')) WHERE slug='';
UPDATE categories  SET slug = LOWER(REPLACE(REPLACE(name,' ','-'),'&','and')) WHERE slug='';
UPDATE product_types SET slug = LOWER(REPLACE(REPLACE(name,' ','-'),'&','and')) WHERE slug='';

-- Seed more sample products
INSERT INTO products (vendor_id,industry_id,category_id,product_type_id,name,slug,description,short_desc,price_range,min_order_qty,status,is_featured) VALUES
(2,1,2,4,'Premium Brown Kraft Paper 80 GSM','premium-brown-kraft-80gsm','High-quality brown kraft paper ideal for industrial packaging and wrapping applications. Excellent tensile strength and moisture resistance.','Industrial grade kraft paper with superior strength','₹40-₹55/kg','500 kg','active',1),
(2,1,2,4,'White Kraft Paper 90 GSM','white-kraft-90gsm','Premium white kraft paper suitable for food-grade packaging, luxury bags, and retail packaging.','Food-grade white kraft paper for premium packaging','₹55-₹70/kg','250 kg','active',1),
(2,1,1,1,'Single Wall Corrugated Box 3 Ply','single-wall-3ply','Standard single wall corrugated boxes for e-commerce, FMCG, and light industrial packaging. Available in custom sizes.','Lightweight e-commerce ready corrugated boxes','₹8-₹15/piece','1000 pieces','active',0),
(2,1,1,2,'Heavy Duty Double Wall Box 5 Ply','heavy-double-wall-5ply','Heavy duty double wall corrugated boxes for industrial and export packaging. High compression strength.','Export-grade heavy duty corrugated boxes','₹25-₹45/piece','500 pieces','active',1);

-- Sample attributes for seeded products
INSERT INTO product_attributes (product_id,attribute_name,attribute_value,attribute_unit,sort_order)
SELECT p.id,'GSM','80','g/m²',1 FROM products p WHERE p.slug='premium-brown-kraft-80gsm';
INSERT INTO product_attributes (product_id,attribute_name,attribute_value,attribute_unit,sort_order)
SELECT p.id,'Moisture','8','%',2 FROM products p WHERE p.slug='premium-brown-kraft-80gsm';
INSERT INTO product_attributes (product_id,attribute_name,attribute_value,attribute_unit,sort_order)
SELECT p.id,'Cobb','28','g/m²',3 FROM products p WHERE p.slug='premium-brown-kraft-80gsm';
INSERT INTO product_attributes (product_id,attribute_name,attribute_value,attribute_unit,sort_order)
SELECT p.id,'Width','100','cm',4 FROM products p WHERE p.slug='premium-brown-kraft-80gsm';

-- Update product slugs
UPDATE products SET slug = CONCAT(id,'-',LOWER(REPLACE(REPLACE(REPLACE(name,' ','-'),'/',''),'.',''))) WHERE slug='';

-- Insert sample vendor profile
INSERT IGNORE INTO vendor_profiles (vendor_id,tagline,established_yr,employees,certifications,is_verified)
SELECT id,'Leading paper manufacturer in India',2005,'50-100','ISO 9001:2015, FSC Certified',1 FROM users WHERE email='vendor@example.com';
