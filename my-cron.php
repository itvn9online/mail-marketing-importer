<?php

/**
 * MMI Auto Unsubscribe — Standalone Cron Script
 *
 * Gọi trực tiếp từ run-cron.sh qua curl, không phụ thuộc WP-Cron.
 * Tự throttle số giờ bằng cách kiểm tra bản ghi run_summary mới nhất
 * trong bảng mmi_api_log (phân loại theo cột domain = MMI_DOMAIN_PREFIX).
 *
 * Usage (trong run-cron.sh):
 *   fetch_url "https://${domain}/wp-content/plugins/mail-marketing-importer/my-cron.php"
 */

// Load WordPress — plugin nằm tại {WP_ROOT}/wp-content/plugins/mail-marketing-importer/
$wp_load = dirname(__DIR__, 3) . '/wp-load.php';

if (!file_exists($wp_load)) {
    http_response_code(500);
    echo '[MMI-CRON] ERROR: wp-load.php not found at ' . $wp_load;
    exit(1);
}

// Nạp WordPress để có access đến database và các function của plugin
require_once $wp_load;

// Sau khi WP load, plugin đã được khởi tạo → MMI_DOMAIN_PREFIX và class sẵn sàng
if (!defined('MMI_DOMAIN_PREFIX') || !class_exists('MMI_Auto_Unsubscribe')) {
    http_response_code(500);
    echo '[MMI-CRON] ERROR: MMI plugin not loaded (check plugin is activated)';
    exit(1);
}

// Chạy — class tự throttle số giờ dựa vào fetch_list trong mmi_api_log
$runner = new MMI_Auto_Unsubscribe();
$result = $runner->run('cron');

echo '[MMI-CRON] DONE domain=' . MMI_DOMAIN_PREFIX;
foreach ($result as $key => $value) {
    echo " | $key=" . (is_array($value) ? json_encode($value) : $value);
}
