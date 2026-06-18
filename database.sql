-- ============================================================
-- Product Enquiry Dashboard - Full Database Schema
-- Run this in phpMyAdmin or MySQL CLI
-- ============================================================

CREATE DATABASE IF NOT EXISTS product_enquiry CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE product_enquiry;

-- -------------------------------------------------------
-- Users (admin / vendor / customer)
-- -------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','vendor','customer') NOT NULL DEFAULT 'customer',
    status ENUM('active','inactive','pending') DEFAULT 'active',
    phone VARCHAR(20),
    company VARCHAR(150),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    country VARCHAR(100) DEFAULT 'India',
    pincode VARCHAR(20),
    gst_number VARCHAR(50),
    avatar VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- -------------------------------------------------------
-- Industries
-- -------------------------------------------------------
CREATE TABLE industries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- -------------------------------------------------------
-- Categories (belong to industry)
-- -------------------------------------------------------
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    industry_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (industry_id) REFERENCES industries(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- Product Types (belong to category)
-- -------------------------------------------------------
CREATE TABLE product_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- Attribute Definitions (template per product type)
-- -------------------------------------------------------
CREATE TABLE attribute_definitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_type_id INT NOT NULL,
    attribute_name VARCHAR(100) NOT NULL,
    attribute_unit VARCHAR(50),
    attribute_type ENUM('text','number','select') DEFAULT 'number',
    options_list TEXT COMMENT 'Comma-separated options for select type',
    is_required TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (product_type_id) REFERENCES product_types(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- Products
-- -------------------------------------------------------
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    industry_id INT NOT NULL,
    category_id INT NOT NULL,
    product_type_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    price_range VARCHAR(100),
    min_order_qty VARCHAR(100),
    images TEXT COMMENT 'Comma-separated filenames',
    status ENUM('active','inactive','pending') DEFAULT 'pending',
    views INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (industry_id) REFERENCES industries(id),
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (product_type_id) REFERENCES product_types(id)
);

-- -------------------------------------------------------
-- Product Attributes (actual values per product)
-- -------------------------------------------------------
CREATE TABLE product_attributes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    attribute_name VARCHAR(100) NOT NULL,
    attribute_value VARCHAR(255),
    attribute_unit VARCHAR(50),
    sort_order INT DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- Enquiries
-- -------------------------------------------------------
CREATE TABLE enquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT,
    customer_id INT NOT NULL,
    vendor_id INT NOT NULL,
    subject VARCHAR(200),
    status ENUM('open','in_progress','closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- Enquiry Messages (conversation thread)
-- -------------------------------------------------------
CREATE TABLE enquiry_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enquiry_id INT NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- Notifications
-- -------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    link VARCHAR(300),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Admin user  (password: admin123)
INSERT INTO users (name, email, password, role, status, company) VALUES
('Admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active', 'Admin');

-- Sample vendor  (password: vendor123)
INSERT INTO users (name, email, password, role, status, company, phone) VALUES
('Ramesh Gupta', 'vendor@example.com', '$2y$10$TKh8H1.PfunBLW1FqzQJiu5K9b.qyOVxM5Sk.fEOIpfuqwXKhj8U2', 'vendor', 'active', 'Gupta Paper Mills', '9876543210');

-- Sample customer  (password: customer123)
INSERT INTO users (name, email, password, role, status, company, phone) VALUES
('Priya Sharma', 'customer@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active', 'Sharma Enterprises', '9123456789');

-- Industries
INSERT INTO industries (name, sort_order) VALUES
('Paper & Packaging', 1),
('Textile', 2),
('Chemical', 3),
('Food & Beverage', 4),
('Pharmaceutical', 5),
('Plastic', 6);

-- Categories
INSERT INTO categories (industry_id, name) VALUES
(1, 'Corrugated Boxes'),
(1, 'Kraft Paper'),
(1, 'Duplex Board'),
(1, 'Mono Carton'),
(2, 'Woven Fabric'),
(2, 'Non-Woven Fabric'),
(3, 'Industrial Adhesives'),
(3, 'Surface Coatings');

-- Product Types
INSERT INTO product_types (category_id, name) VALUES
(1, 'Single Wall Box'),
(1, 'Double Wall Box'),
(1, 'Triple Wall Box'),
(2, 'Brown Kraft Paper'),
(2, 'White Kraft Paper'),
(3, 'Grey Duplex Board'),
(3, 'White Duplex Board'),
(4, 'Printed Mono Carton'),
(4, 'Plain Mono Carton');

-- Attribute Definitions for Single Wall Box
INSERT INTO attribute_definitions (product_type_id, attribute_name, attribute_unit, attribute_type, is_required, sort_order) VALUES
(1, 'BF (Bursting Factor)', '', 'number', 1, 1),
(1, 'GSM', 'g/m²', 'number', 1, 2),
(1, 'Moisture', '%', 'number', 0, 3),
(1, 'Cobb Top', 'g/m²', 'number', 0, 4),
(1, 'Cobb Bottom', 'g/m²', 'number', 0, 5),
(1, 'Flute Type', '', 'select', 1, 6),
(1, 'Length', 'mm', 'number', 0, 7),
(1, 'Width', 'mm', 'number', 0, 8),
(1, 'Height', 'mm', 'number', 0, 9);

-- Attribute Definitions for Double Wall Box
INSERT INTO attribute_definitions (product_type_id, attribute_name, attribute_unit, attribute_type, is_required, sort_order) VALUES
(2, 'BF (Bursting Factor)', '', 'number', 1, 1),
(2, 'GSM', 'g/m²', 'number', 1, 2),
(2, 'Moisture', '%', 'number', 0, 3),
(2, 'Cobb Top', 'g/m²', 'number', 0, 4),
(2, 'Cobb Bottom', 'g/m²', 'number', 0, 5),
(2, 'Flute Type', '', 'select', 1, 6),
(2, 'Length', 'mm', 'number', 0, 7),
(2, 'Width', 'mm', 'number', 0, 8),
(2, 'Height', 'mm', 'number', 0, 9),
(2, 'ECT (Edge Crush Test)', 'N/m', 'number', 0, 10);

-- Attribute Definitions for Kraft Paper
INSERT INTO attribute_definitions (product_type_id, attribute_name, attribute_unit, attribute_type, is_required, sort_order) VALUES
(4, 'GSM', 'g/m²', 'number', 1, 1),
(4, 'Moisture', '%', 'number', 0, 2),
(4, 'Brightness', '%', 'number', 0, 3),
(4, 'Cobb', 'g/m²', 'number', 0, 4),
(4, 'Width', 'mm', 'number', 0, 5),
(4, 'Tensile Strength', 'N/m', 'number', 0, 6);

-- Attribute Definitions for Duplex Board
INSERT INTO attribute_definitions (product_type_id, attribute_name, attribute_unit, attribute_type, is_required, sort_order) VALUES
(6, 'GSM', 'g/m²', 'number', 1, 1),
(6, 'Caliper', 'micron', 'number', 0, 2),
(6, 'Brightness', '%', 'number', 0, 3),
(6, 'Moisture', '%', 'number', 0, 4),
(6, 'Smoothness', 'PPS ml/min', 'number', 0, 5);

-- Update flute type options
UPDATE attribute_definitions SET options_list = 'A Flute,B Flute,C Flute,E Flute,F Flute,BC Flute,EB Flute' WHERE attribute_name = 'Flute Type';
