<?php

/**
 * Plugin Name: Mail Marketing Importer
 * Plugin URI: https://echbay.com
 * Description: Import email marketing data from Excel files (.xlsx, .xls, .csv)
 * Version: 1.2.5
 * Author: Dao Quoc Dai
 * License: GPL v2 or later
 * Text Domain: mail-marketing-importer
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('MMI_GITHUB_USER', 'itvn9online');
define('MMI_GITHUB_REPO', 'mail-marketing-importer');
define('MMI_GITHUB_BRANCH', 'main');

/**
 * Read plugin version from version.txt / VERSION, fallback to plugin header.
 */
function mmi_get_plugin_version()
{
    foreach (['version.txt', 'VERSION'] as $file) {
        $path = __DIR__ . '/' . $file;
        if (is_readable($path)) {
            $version = trim((string) file_get_contents($path));
            if ($version !== '') {
                return $version;
            }
        }
    }

    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $data = get_plugin_data(__FILE__, false, false);

    return $data['Version'] ?? '0.0.0';
}

$mmi_plugin_version = mmi_get_plugin_version();

// Define plugin constants
define('MMI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MMI_PLUGIN_PATH', __DIR__ . '/');
define('MMI_PLUGIN_VERSION', $mmi_plugin_version);

// Create domain-specific config key for multi-domain support
function mmi_get_domain_config_key()
{
    // Get current domain safely
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Remove port number if present (e.g., localhost:8080 -> localhost)
    $domain = str_replace([
        '.',
        '-',
    ], '_', strtolower(explode(':', $host)[0]));

    return $domain;
}

// Create domain-specific config key for multi-domain support
define('MMI_DOMAIN_PREFIX', mmi_get_domain_config_key());
define('MMI_ZOHO_CONFIG', 'mmi_zoho_config_' . MMI_DOMAIN_PREFIX);
// echo 'MMI Domain Config Key: ' . MMI_ZOHO_CONFIG . '<br>';
define('MMI_GOOGLE_CONFIG', 'mmi_google_config_' . MMI_DOMAIN_PREFIX);
// echo 'MMI Domain Config Key: ' . MMI_GOOGLE_CONFIG . '<br>';

// Số giờ tối thiểu giữa 2 lần chạy Auto Unsubscribe (dùng để throttle, tránh bị rate limit từ Gmail API)
define('GG_AUTO_UNSUB_NEXT_RUN', 3);
// Số lượng email tối đa để fetch từ Gmail API trong 1 lần (dùng cho Auto Unsubscribe)
define('GG_AUTO_UNSUB_MAXRESULTS', GG_AUTO_UNSUB_NEXT_RUN * 50);

// Include required files
require_once MMI_PLUGIN_PATH . 'includes/class-mail-marketing-importer.php';
require_once MMI_PLUGIN_PATH . 'includes/class-excel-reader.php';
require_once MMI_PLUGIN_PATH . 'includes/class-enhanced-excel-reader.php';
require_once MMI_PLUGIN_PATH . 'includes/class-auto-unsubscribe.php';
require_once MMI_PLUGIN_PATH . 'includes/class-plugin-updater.php';

if (is_admin()) {
    new MMI_Plugin_Updater(__FILE__, MMI_GITHUB_USER, MMI_GITHUB_REPO, MMI_GITHUB_BRANCH);
}

// Initialize the plugin
function mail_marketing_importer_init()
{
    new Mail_Marketing_Importer();
}
add_action('init', 'mail_marketing_importer_init');

// Add settings link to plugin list
if (strpos($_SERVER['REQUEST_URI'], '/plugins.php') !== false) {
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'mail_marketing_importer_settings_link');
}
function mail_marketing_importer_settings_link($links)
{
    $settings_link = '<a href="' . admin_url('tools.php?page=mail-marketing-importer') . '">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Activation hook - create tables + schedule cron
register_activation_hook(__FILE__, 'mail_marketing_importer_activate');
function mail_marketing_importer_activate()
{
    mail_marketing_importer_update_database();
}

// Deactivation hook - clear cron
// register_deactivation_hook(__FILE__, 'mail_marketing_importer_deactivate');
function mail_marketing_importer_deactivate() {}

// Database update function (can be called from activation or version check)
function mail_marketing_importer_update_database()
{
    global $wpdb;

    // Create main mail_marketing table
    $sql_query = "
    CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}mail_marketing` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `campaign_id` bigint(20) unsigned DEFAULT NULL,
      `email` varchar(255) NOT NULL,
      `phone` varchar(25) DEFAULT NULL,
      `name` varchar(255) DEFAULT NULL,
      `first_name` varchar(255) DEFAULT NULL,
      `last_name` varchar(255) DEFAULT NULL,
      `address` varchar(255) DEFAULT NULL,
      `city` varchar(100) DEFAULT NULL,
      `state` varchar(50) DEFAULT NULL,
      `zip_code` varchar(50) DEFAULT NULL,
      `status` tinyint(1) NOT NULL DEFAULT '0',
      `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
      `is_unsubscribed` tinyint(1) NOT NULL DEFAULT '0',
      `sended_at` datetime DEFAULT NULL,
      `opened_at` datetime DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `email` (`email`),
      KEY `status` (`status`),
      KEY `is_deleted` (`is_deleted`),
      KEY `is_unsubscribed` (`is_unsubscribed`),
      KEY `campaign_id` (`campaign_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $wpdb->query($sql_query);

    // Create campaigns table
    $campaigns_sql = "
    CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}mail_marketing_campaigns` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `name` varchar(255) NOT NULL,
      `description` text DEFAULT NULL,
      `email_subject` varchar(255) DEFAULT NULL,
      `email_url` varchar(512) DEFAULT NULL,
      `email2_url` varchar(512) DEFAULT NULL,
      `email_content` longtext DEFAULT NULL,
      `email_template` varchar(255) DEFAULT 'default.html',
      `start_date` datetime DEFAULT NULL,
      `status` enum('active','inactive','completed') NOT NULL DEFAULT 'active',
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `status` (`status`),
      KEY `start_date` (`start_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $wpdb->query($campaigns_sql);

    // Add new columns for existing installations (for plugin updates)
    $columns_to_add = array(
        'campaign_id' => "ALTER TABLE {$wpdb->prefix}mail_marketing ADD COLUMN campaign_id bigint(20) unsigned DEFAULT NULL",
        'first_name' => "ALTER TABLE {$wpdb->prefix}mail_marketing ADD COLUMN first_name varchar(255) DEFAULT NULL",
        'last_name' => "ALTER TABLE {$wpdb->prefix}mail_marketing ADD COLUMN last_name varchar(255) DEFAULT NULL",
        'address' => "ALTER TABLE {$wpdb->prefix}mail_marketing ADD COLUMN address varchar(255) DEFAULT NULL",
        'city' => "ALTER TABLE {$wpdb->prefix}mail_marketing ADD COLUMN city varchar(100) DEFAULT NULL",
        'state' => "ALTER TABLE {$wpdb->prefix}mail_marketing ADD COLUMN state varchar(50) DEFAULT NULL",
        'zip_code' => "ALTER TABLE {$wpdb->prefix}mail_marketing ADD COLUMN zip_code varchar(50) DEFAULT NULL",
        'opened_at' => "ALTER TABLE {$wpdb->prefix}mail_marketing ADD COLUMN opened_at datetime DEFAULT NULL",
    );

    foreach ($columns_to_add as $column => $sql) {
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}mail_marketing LIKE '{$column}'");
        if (empty($column_exists)) {
            $wpdb->query($sql);
        }
    }

    // Add new columns to campaigns table for existing installations
    $campaign_columns_to_add = array(
        'email_subject' => "ALTER TABLE {$wpdb->prefix}mail_marketing_campaigns ADD COLUMN email_subject varchar(255) DEFAULT NULL",
        'email_url' => "ALTER TABLE {$wpdb->prefix}mail_marketing_campaigns ADD COLUMN email_url varchar(512) DEFAULT NULL",
        'email2_url' => "ALTER TABLE {$wpdb->prefix}mail_marketing_campaigns ADD COLUMN email2_url varchar(512) DEFAULT NULL",
        'email_content' => "ALTER TABLE {$wpdb->prefix}mail_marketing_campaigns ADD COLUMN email_content longtext DEFAULT NULL",
        'email_template' => "ALTER TABLE {$wpdb->prefix}mail_marketing_campaigns ADD COLUMN email_template varchar(255) DEFAULT 'default.html'",
        'start_date' => "ALTER TABLE {$wpdb->prefix}mail_marketing_campaigns ADD COLUMN start_date datetime DEFAULT NULL",
    );

    foreach ($campaign_columns_to_add as $column => $sql) {
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}mail_marketing_campaigns LIKE '{$column}'");
        if (empty($column_exists)) {
            $wpdb->query($sql);
        }
    }

    // Add index for campaign_id if it doesn't exist
    $index_exists = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}mail_marketing WHERE Key_name = 'campaign_id'");
    if (empty($index_exists)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}mail_marketing ADD KEY `campaign_id` (`campaign_id`)");
    }

    // Add foreign key constraint if it doesn't exist (optional, some shared hosting might not support this)
    try {
        $foreign_key_exists = $wpdb->get_results("
            SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '{$wpdb->prefix}mail_marketing' 
            AND CONSTRAINT_NAME = 'fk_mail_marketing_campaign'
        ");

        if (empty($foreign_key_exists)) {
            // Try to add foreign key constraint, but don't fail if it's not supported
            $wpdb->query("
                ALTER TABLE {$wpdb->prefix}mail_marketing 
                ADD CONSTRAINT fk_mail_marketing_campaign 
                FOREIGN KEY (campaign_id) REFERENCES {$wpdb->prefix}mail_marketing_campaigns(id) 
                ON DELETE SET NULL ON UPDATE CASCADE
            ");
        }
    } catch (Exception $e) {
        // Foreign key constraint failed, but that's okay for some hosting environments
        error_log('MMI: Foreign key constraint could not be added: ' . $e->getMessage());
    }

    // ── Tạo bảng mmi_api_log cho Auto Unsubscribe ──────────────────────────────
    $api_log_sql = "
    CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}mmi_api_log` (
      `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
      `domain`           VARCHAR(100)     NOT NULL COMMENT 'MMI_DOMAIN_PREFIX',
      `domain_host`      VARCHAR(255)     NOT NULL COMMENT '$_SERVER[HTTP_HOST] raw',
      `run_type`         VARCHAR(20)      NOT NULL DEFAULT 'cron' COMMENT 'cron | manual',
      `gmail_message_id` VARCHAR(100)     NULL,
      `bounce_email`     VARCHAR(255)     NULL,
      `unsubscribed`     TINYINT(1)       NOT NULL DEFAULT 0,
      `api_action`       VARCHAR(50)      NOT NULL COMMENT 'fetch_list|fetch_batch|bounce_found|unsubscribe|run_summary',
      `status`           VARCHAR(20)      NOT NULL DEFAULT 'success' COMMENT 'success|error|skipped',
      `response_summary` TEXT             NULL,
      `error_message`    TEXT             NULL,
      `created_at`       DATETIME         NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_domain`         (`domain`),
      KEY `idx_message_domain` (`gmail_message_id`, `domain`),
      KEY `idx_bounce_email`   (`bounce_email`(100)),
      KEY `idx_created_at`     (`created_at`),
      KEY `idx_status`         (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $wpdb->query($api_log_sql);
}

// Manual database initialization function (for debugging or manual setup)
function mail_marketing_importer_force_db_update()
{
    if (current_user_can('manage_options')) {
        mail_marketing_importer_update_database();
        wp_redirect(admin_url('tools.php?page=mail-marketing-importer&db_updated=1'));
        exit;
    }
}
add_action('admin_post_mmi_force_db_update', 'mail_marketing_importer_force_db_update');

// function chuyển nội dung trong email template thành 1 dòng duy nhất
function convert_to_single_line($text)
{
    // Handle different line endings (Windows \r\n, Unix \n, Mac \r)
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Remove HTML comments (<!-- comment -->) including multiline comments
    $text = preg_replace('/<!--.*?-->/s', '', $text);

    // Split by newlines, trim each line, filter empty lines, then join with spaces
    return implode(' ', array_filter(array_map('trim', explode("\n", $text)), function ($line) {
        return $line !== '';
    }));
}

// Function to get email content settings
function get_email_content_settings($campaign_id = 0)
{
    $default_html_content = file_get_contents(MMI_PLUGIN_PATH . 'html-template/default.html');
    $default_subject = 'Welcome to {SITE_NAME}';

    // Get email content from database
    if ($campaign_id > 0) {
        global $wpdb;
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mail_marketing_campaigns WHERE id = %d",
            $campaign_id
        ), ARRAY_A);
        // print_r($data);

        // nếu có bản ghi thì lấy, không thì dùng mặc định
        $email_content = $data['email_content'] ?? '';
        $email_subject = $data['email_subject'] ?? '';

        // Handle empty content - use default if content is empty or just whitespace
        if (empty(trim($email_content))) {
            $email_template = $data['email_template'] ?? '';
            if ($email_template != 'default.html') {
                $template_path = MMI_PLUGIN_PATH . 'html-template/' . $email_template;
                if (is_file($template_path)) {
                    $default_html_content = file_get_contents($template_path);
                }
            }
            $email_content = convert_to_single_line($default_html_content);
        } else {
            $email_content = str_replace([
                'http://{',
                'https://{'
            ], '{', $email_content);
            // Định dạng HTML cho email content
            $email_content = apply_filters('the_content', $email_content);
        }

        // Handle empty subject - use default if subject is empty or just whitespace
        if (empty(trim($email_subject))) {
            $email_subject = $default_subject;
        }
    } else {
        $email_content = convert_to_single_line($default_html_content);
        $email_subject = $default_subject;
    }

    return array(
        'subject' => $email_subject,
        'content' => $email_content,
        'content_type' => 'html',
        // 'tracking_enabled' => get_option(SCM_PLUGIN_PREFIX . 'scm_email_tracking_enabled', '1'),
        'tracking_enabled' => '1',
    );
}
