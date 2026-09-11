-- ============================================================
-- Migration: Popular Comparisons & Compare Page Enhancements
-- ============================================================

CREATE TABLE IF NOT EXISTS popular_comparisons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    subtitle VARCHAR(300) NULL,
    category_id INT NULL,
    product_ids VARCHAR(255) NOT NULL COMMENT 'Comma separated list of 2-4 product IDs',
    is_sponsored TINYINT(1) NOT NULL DEFAULT 0,
    sponsor_name VARCHAR(150) NULL,
    sponsor_link VARCHAR(300) NULL,
    badge_text VARCHAR(60) NULL DEFAULT 'Popular',
    views_count INT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
