<?php
// ============================================================
// includes/functions.php — Shared helper functions
// ============================================================

// ---- Notification helpers ----
function addNotification($pdo, $userId, $title, $message = '', $link = '') {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $title, $message, $link]);
}

function getUnreadCount($pdo, $userId) {
    return $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0")
               ->execute([$userId]) ? 
               $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId AND is_read = 0")->fetchColumn() : 0;
}

function getNotifications($pdo, $userId, $limit = 10) {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

// ---- Data fetch helpers ----
function getAllIndustries($pdo, $activeOnly = true) {
    $sql = "SELECT * FROM industries" . ($activeOnly ? " WHERE status=1" : "") . " ORDER BY sort_order, name";
    return $pdo->query($sql)->fetchAll();
}

function getCategoriesByIndustry($pdo, $industryId) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE industry_id = ? AND status = 1 ORDER BY sort_order, name");
    $stmt->execute([$industryId]);
    return $stmt->fetchAll();
}

function getProductTypesByCategory($pdo, $categoryId) {
    $stmt = $pdo->prepare("SELECT * FROM product_types WHERE category_id = ? AND status = 1 ORDER BY sort_order, name");
    $stmt->execute([$categoryId]);
    return $stmt->fetchAll();
}

function getAttributesByProductType($pdo, $productTypeId) {
    $stmt = $pdo->prepare("SELECT * FROM attribute_definitions WHERE product_type_id = ? ORDER BY sort_order");
    $stmt->execute([$productTypeId]);
    return $stmt->fetchAll();
}

// ---- Image upload ----
function uploadImage($file, $prefix = 'img') {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    // Ensure upload directory exists and is writable
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    // Validate mime from file content, not user-supplied header
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($mime, $allowed)) return null;
    // Sanitize extension
    $ext  = preg_replace('/[^a-z0-9]/i', '', pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $name = $prefix . '_' . uniqid() . '.' . $ext;
    $dest = UPLOAD_DIR . $name;
    if (move_uploaded_file($file['tmp_name'], $dest)) return $name;
    return null;
}

// ---- Formatting ----
function statusBadge($status) {
    $map = [
        'active'      => 'badge-success',
        'inactive'    => 'badge-secondary',
        'pending'     => 'badge-warning',
        'open'        => 'badge-info',
        'in_progress' => 'badge-warning',
        'closed'      => 'badge-secondary',
    ];
    $cls = $map[$status] ?? 'badge-secondary';
    return "<span class='badge $cls'>" . ucfirst(str_replace('_',' ',$status)) . "</span>";
}

function timeAgo($datetime) {
    $now  = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);
    if ($diff->days === 0) {
        if ($diff->h === 0) return $diff->i . ' min ago';
        return $diff->h . ' hr ago';
    }
    if ($diff->days < 7) return $diff->days . 'd ago';
    return date('d M Y', strtotime($datetime));
}

function formatDate($date) {
    return date('d M Y, h:i A', strtotime($date));
}

function avatarLetter($name) {
    return strtoupper(substr(trim($name), 0, 1));
}

function sanitize($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

// ---- Pagination helper ----
function paginate($total, $perPage, $current, $url) {
    $pages = ceil($total / $perPage);
    if ($pages <= 1) return '';
    // strpos returns 0 (falsy) when '?' is the first character — use
    // !== false so a URL like '?search=foo' correctly gets '&' separator.
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    $html = '<div class="pagination">';
    if ($current > 1)
        $html .= "<a href='{$url}{$sep}page=" . ($current-1) . "'>&#8249;</a>";
    for ($i = 1; $i <= $pages; $i++) {
        if ($i == $current)
            $html .= "<span class='active'>$i</span>";
        else
            $html .= "<a href='{$url}{$sep}page=$i'>$i</a>";
    }
    if ($current < $pages)
        $html .= "<a href='{$url}{$sep}page=" . ($current+1) . "'>&#8250;</a>";
    $html .= '</div>';
    return $html;
}

// ---- Flash display ----
function showFlash() {
    $flash = getFlash();
    $html  = '';
    if (!empty($flash['success'])) $html .= "<div class='alert alert-success'>" . sanitize($flash['success']) . "</div>";
    if (!empty($flash['error']))   $html .= "<div class='alert alert-error'>"   . sanitize($flash['error'])   . "</div>";
    return $html;
}
