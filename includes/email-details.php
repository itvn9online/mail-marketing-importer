<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hiển thị chi tiết 1 bản ghi dưới dạng row trong bảng
 */

// 
$email_id = isset($_GET['details']) ? intval($_GET['details']) : 0;
// echo '<h2>Chi tiết email ID: ' . $email_id . '</h2>';
if ($email_id > 0) {
    global $wpdb;

    // Lấy thông tin email từ database
    $email = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . $wpdb->prefix . "mail_marketing WHERE id = %d",
        $email_id
    ));

    // Hiển thị thông tin chi tiết
    if ($email) {
        echo '<pre>';
        echo json_encode($email, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo '</pre>';
    }
}
