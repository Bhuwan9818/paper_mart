<?php
// ============================================================
// includes/chatbot-widget.php
//
// CORRECT USAGE — add this ONE line just before </body>
// inside: /dashv10_Fixed/public/includes/footer.php
//
//   <?php include dirname(__DIR__, 2) . '/includes/chatbot-widget.php'; 
//
// dirname(__DIR__, 2) goes up TWO levels from footer.php:
//   footer.php  →  public/includes/
//   level 1 up  →  public/
//   level 2 up  →  dashv10_Fixed/   ← correct root
// ============================================================

// Skip in admin/vendor areas
$_cbUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($_cbUri, '/admin/') !== false || strpos($_cbUri, '/vendor/') !== false) {
    return;
}

// BASE_URL must already be defined (loaded via header.php → config.php)
// This guard prevents a blank page if something goes wrong
if (!defined('BASE_URL')) {
    return;
}
?>
<!-- ═══ PaperMart Chatbot Widget ═══════════════════════════ -->
<script>window.PM_BASE_URL = '<?= rtrim(BASE_URL, '/') ?>';</script>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/chatbot.css">
<script src="<?= BASE_URL ?>/assets/chatbot.js" defer></script>
<!-- ═══════════════════════════════════════════════════════ -->
