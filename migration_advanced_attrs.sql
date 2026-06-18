-- ============================================================
-- MIGRATION: Advanced Product Attributes
-- Run this in phpMyAdmin AFTER the original database.sql
-- ============================================================

USE product_enquiry;

-- Drop old simple attributes table
DROP TABLE IF EXISTS product_attributes;

-- -------------------------------------------------------
-- product_attribute_groups
-- Each row = one "attribute slot" on a product.
-- attr_mode: 'single'  = vendor fills one value
--             'variant' = vendor fills multiple variant rows
-- -------------------------------------------------------
CREATE TABLE product_attribute_groups (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    product_id    INT NOT NULL,
    attr_def_id   INT,                          -- NULL if vendor added custom attr
    attribute_name VARCHAR(100) NOT NULL,
    attribute_unit VARCHAR(50),
    attr_mode     ENUM('single','variant') NOT NULL DEFAULT 'single',
    is_custom     TINYINT(1) DEFAULT 0,         -- 1 = added by vendor, not from template
    sort_order    INT DEFAULT 0,
    FOREIGN KEY (product_id)  REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (attr_def_id) REFERENCES attribute_definitions(id) ON DELETE SET NULL
);

-- -------------------------------------------------------
-- product_attribute_values
-- For 'single'  mode: one row, variant_label = NULL
-- For 'variant' mode: many rows, each with a variant_label
--   e.g.  variant_label='20 BF', sub_attrs stored as JSON
--         { "GSM": "80", "Shade": "Natural", "Cobb Top": "120" }
-- -------------------------------------------------------
CREATE TABLE product_attribute_values (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    group_id      INT NOT NULL,
    variant_label VARCHAR(100),                 -- e.g. '20 BF', '22 BF', NULL for single
    single_value  VARCHAR(500),                 -- used when attr_mode='single'
    sub_attrs     TEXT,                          -- JSON object for variant sub-attributes
    sort_order    INT DEFAULT 0,
    FOREIGN KEY (group_id) REFERENCES product_attribute_groups(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- master_attributes
-- Global attribute library. Admin manages this.
-- Vendors can pull from here when adding extra attributes.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS master_attributes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    attribute_name  VARCHAR(100) NOT NULL,
    attribute_unit  VARCHAR(50),
    attribute_type  ENUM('text','number','select') DEFAULT 'number',
    options_list    TEXT,
    industry_id     INT,                        -- NULL = global / applies to all
    is_active       TINYINT(1) DEFAULT 1,
    sort_order      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (industry_id) REFERENCES industries(id) ON DELETE SET NULL
);

-- Seed master attributes (paper industry)
INSERT INTO master_attributes (attribute_name, attribute_unit, attribute_type, industry_id, sort_order) VALUES
('BF (Bursting Factor)',   '',        'number', 1, 1),
('GSM',                    'g/m²',    'number', 1, 2),
('Moisture',               '%',       'number', 1, 3),
('Cobb Top',               'g/m²',    'number', 1, 4),
('Cobb Bottom',            'g/m²',    'number', 1, 5),
('Brightness',             '%',       'number', 1, 6),
('Tensile Strength (MD)',  'kN/m',    'number', 1, 7),
('Tensile Strength (CD)',  'kN/m',    'number', 1, 8),
('ECT',                    'N/m',     'number', 1, 9),
('Caliper',                'micron',  'number', 1, 10),
('Smoothness',             'ml/min',  'number', 1, 11),
('Width',                  'mm',      'number', 1, 12),
('Length',                 'mm',      'number', 1, 13),
('Height',                 'mm',      'number', 1, 14),
('Shade',                  '',        'text',   1, 15),
('Color',                  '',        'text',   1, 16),
('Ply',                    '',        'number', 1, 17);

-- Global attributes (all industries)
INSERT INTO master_attributes (attribute_name, attribute_unit, attribute_type, industry_id, sort_order) VALUES
('Length',        'mm',   'number', NULL, 1),
('Width',         'mm',   'number', NULL, 2),
('Height',        'mm',   'number', NULL, 3),
('Weight',        'kg',   'number', NULL, 4),
('Color',         '',     'text',   NULL, 5),
('Material',      '',     'text',   NULL, 6),
('Thickness',     'mm',   'number', NULL, 7),
('Grade',         '',     'text',   NULL, 8),
('Certification', '',     'text',   NULL, 9),
('Country of Origin','',  'text',   NULL, 10);
