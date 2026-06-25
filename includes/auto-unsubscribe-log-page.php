<?php

/**
 * Auto Unsubscribe Log Viewer Page
 *
 * Trang admin xem log từ bảng {prefix}mmi_api_log.
 * Filter theo domain, status, api_action, date range.
 * Hỗ trợ pagination, clear old logs, export CSV.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die('Permission denied');
}

global $wpdb;
$table = $wpdb->prefix . 'mmi_api_log';
$per_page = 50;

// ─── Xử lý actions ────────────────────────────────────────────────────────────

$action_msg = '';

// Clear old logs
if (
    isset($_POST['mmi_clear_logs_nonce']) &&
    wp_verify_nonce($_POST['mmi_clear_logs_nonce'], 'mmi_clear_old_logs')
) {
    $days    = max(1, (int) ($_POST['clear_days'] ?? 30));
    $deleted = MMI_Auto_Unsubscribe::cleanup_old_logs($days);
    $action_msg = '<div class="notice notice-success is-dismissible"><p>✅ Đã xóa <strong>' . $deleted . '</strong> bản ghi cũ hơn ' . $days . ' ngày (tất cả domain).</p></div>';
}

// Xóa toàn bộ wp-cron cũ để chuyển sang sử dụng my-cron riêng
if (1 > 2) {
    $all_crons_tmp = _get_cron_array();
    $hooks_to_resched = [];
    if (is_array($all_crons_tmp)) {
        foreach ($all_crons_tmp as $_ts => $_hooks) {
            foreach ($_hooks as $_hook => $_events) {
                echo "Checking cron hook: $_hook <br> \n";
                if (str_starts_with($_hook, 'mmi_auto_unsubscribe_event')) {
                    $hooks_to_resched[$_hook] = true;
                    wp_clear_scheduled_hook($_hook);
                    echo "Cleared cron hook: $_hook <br> \n";
                }
            }
        }
    }
}

// Export CSV
if (
    isset($_POST['mmi_export_csv_nonce']) &&
    wp_verify_nonce($_POST['mmi_export_csv_nonce'], 'mmi_export_logs_csv')
) {
    // Build WHERE clause (dùng lại filter hiện tại)
    $where_parts  = [];
    $where_values = [];

    if (!empty($_POST['filter_domain'])) {
        $where_parts[]  = "domain = %s";
        $where_values[] = sanitize_text_field($_POST['filter_domain']);
    }
    if (!empty($_POST['filter_status'])) {
        $where_parts[]  = "status = %s";
        $where_values[] = sanitize_text_field($_POST['filter_status']);
    }
    if (!empty($_POST['filter_action'])) {
        $where_parts[]  = "api_action = %s";
        $where_values[] = sanitize_text_field($_POST['filter_action']);
    }
    if (!empty($_POST['filter_date_from'])) {
        $where_parts[]  = "created_at >= %s";
        $where_values[] = sanitize_text_field($_POST['filter_date_from']) . ' 00:00:00';
    }
    if (!empty($_POST['filter_date_to'])) {
        $where_parts[]  = "created_at <= %s";
        $where_values[] = sanitize_text_field($_POST['filter_date_to']) . ' 23:59:59';
    }

    $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
    $filename_domain = $where_parts ? sanitize_text_field($_POST['filter_domain'] ?? 'all') : 'all';
    if ($where_values) {
        $sql = $wpdb->prepare("SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT 10000", ...$where_values);
    } else {
        $sql = "SELECT * FROM {$table} ORDER BY id DESC LIMIT 10000";
    }
    $rows = $wpdb->get_results($sql, ARRAY_A);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mmi_api_log_' . $filename_domain . '_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }
    fclose($out);
    exit;
}

// ─── Filter params ─────────────────────────────────────────────────────────────

$filter_domain    = sanitize_text_field($_GET['filter_domain'] ?? '');
$filter_status    = sanitize_text_field($_GET['filter_status'] ?? '');
$filter_action    = sanitize_text_field($_GET['filter_action'] ?? '');
$filter_email     = sanitize_email($_GET['filter_email'] ?? '');
$filter_date_from = sanitize_text_field($_GET['filter_date_from'] ?? '');
$filter_date_to   = sanitize_text_field($_GET['filter_date_to'] ?? '');
$current_page     = max(1, (int) ($_GET['paged'] ?? 1));
if (empty($filter_email) && empty($filter_domain)) {
    $filter_domain = MMI_DOMAIN_PREFIX;
}

// ─── Build WHERE ──────────────────────────────────────────────────────────────

$where_parts  = [];
$where_values = [];

if ($filter_domain !== '') {
    $where_parts[]  = "domain = %s";
    $where_values[] = $filter_domain;
}
if ($filter_status !== '') {
    $where_parts[]  = "status = %s";
    $where_values[] = $filter_status;
}
if ($filter_action !== '') {
    $where_parts[]  = "api_action = %s";
    $where_values[] = $filter_action;
}
if ($filter_email !== '') {
    $where_parts[]  = "bounce_email = %s";
    $where_values[] = $filter_email;
}
if ($filter_date_from !== '') {
    $where_parts[]  = "created_at >= %s";
    $where_values[] = $filter_date_from . ' 00:00:00';
}
if ($filter_date_to !== '') {
    $where_parts[]  = "created_at <= %s";
    $where_values[] = $filter_date_to . ' 23:59:59';
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// ─── Queries ──────────────────────────────────────────────────────────────────

$total_rows = (int) ($where_values
    ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where_sql}", ...$where_values))
    : $wpdb->get_var("SELECT COUNT(*) FROM {$table}")
);
$total_pages = max(1, ceil($total_rows / $per_page));
$offset      = ($current_page - 1) * $per_page;

$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
        ...array_merge($where_values, [$per_page, $offset])
    ),
    ARRAY_A
);

// Summary stats (7 ngày gần nhất, theo domain filter nếu có)
$stats_cutoff = date('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
if ($filter_domain) {
    $stats = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN api_action = 'run_summary' AND status = 'success' THEN 1 ELSE 0 END) as runs_success,
                SUM(CASE WHEN api_action = 'run_summary' AND status = 'error' THEN 1 ELSE 0 END) as runs_error,
                SUM(CASE WHEN api_action = 'unsubscribe' AND unsubscribed = 1 THEN 1 ELSE 0 END) as total_unsubscribed,
                SUM(CASE WHEN api_action = 'bounce_found' AND bounce_email IS NOT NULL THEN 1 ELSE 0 END) as bounces_found
             FROM {$table}
             WHERE domain = %s AND created_at >= %s",
            $filter_domain,
            $stats_cutoff
        ),
        ARRAY_A
    );
} else {
    $stats = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN api_action = 'run_summary' AND status = 'success' THEN 1 ELSE 0 END) as runs_success,
                SUM(CASE WHEN api_action = 'run_summary' AND status = 'error' THEN 1 ELSE 0 END) as runs_error,
                SUM(CASE WHEN api_action = 'unsubscribe' AND unsubscribed = 1 THEN 1 ELSE 0 END) as total_unsubscribed,
                SUM(CASE WHEN api_action = 'bounce_found' AND bounce_email IS NOT NULL THEN 1 ELSE 0 END) as bounces_found
             FROM {$table}
             WHERE created_at >= %s",
            $stats_cutoff
        ),
        ARRAY_A
    );
}

// Distinct domain values cho filter dropdown
$distinct_domains = $wpdb->get_col("SELECT DISTINCT domain FROM {$table} ORDER BY domain");

// Distinct api_action values cho filter dropdown
$distinct_actions = $wpdb->get_col("SELECT DISTINCT api_action FROM {$table} ORDER BY api_action");

// Trạng thái cron hiện tại (theo domain đang filter, fallback về domain hiện tại)
$cron_status = MMI_Auto_Unsubscribe::get_status($filter_domain ?: MMI_DOMAIN_PREFIX);

// ─── Build filter URL helper ──────────────────────────────────────────────────

function mmi_log_filter_url(array $extra = []): string
{
    $base = [
        'page'             => 'mmi-unsubscribe-log',
        'filter_domain'    => $_GET['filter_domain'] ?? '',
        'filter_status'    => $_GET['filter_status'] ?? '',
        'filter_action'    => $_GET['filter_action'] ?? '',
        'filter_email'     => $_GET['filter_email'] ?? '',
        'filter_date_from' => $_GET['filter_date_from'] ?? '',
        'filter_date_to'   => $_GET['filter_date_to'] ?? '',
    ];
    $params = array_merge($base, $extra);
    $params = array_filter($params, fn($v) => $v !== '');
    return admin_url('tools.php?' . http_build_query($params));
}

?>
<div class="wrap">
    <h1 style="display: flex; align-items: center; gap: 10px;">
        📋 Auto Unsubscribe Log
        <span style="font-size: 13px; font-weight: normal; color: #666; background: #f0f0f0; padding: 3px 8px; border-radius: 3px;">
            <?php if ($filter_domain): ?>
                Domain: <strong><?php echo esc_html($filter_domain); ?></strong>
            <?php else: ?>
                🌐 All domains
            <?php endif; ?>
        </span>
    </h1>

    <?php echo $action_msg; ?>
    <?php if ($filter_email !== ''): ?>
        <div class="notice notice-info" style="display:flex;align-items:center;gap:10px;padding:8px 12px;">
            <span>🔍 Đang lọc theo email: <strong><?php echo esc_html($filter_email); ?></strong></span>
            <a href="<?php echo esc_url(mmi_log_filter_url(['filter_email' => '', 'paged' => 1])); ?>" class="button button-small" style="font-size:11px;">✖ Bỏ filter email</a>
        </div>
    <?php endif; ?>
    <?php if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")): ?>
        <div class="notice notice-error">
            <p>❌ Bảng <code><?php echo esc_html($table); ?></code> chưa tồn tại. Hãy deactivate và activate lại plugin để tạo bảng.</p>
        </div>
    <?php return;
    endif; ?>

    <!-- ── Cron Status Card ── -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 20px;">
        <?php
        $s = $cron_status;
        $db_write_error = get_option(MMI_DOMAIN_PREFIX . '_mmi_db_write_error', []);
        $cards = [
            ['🕐 Lần chạy cuối',  $s['last_run'] ?: '—', '#e8f4fd'],
            ['⏭️ Lần chạy tiếp',  $s['next_run'] ?: '—', '#e8fde8'],
            ['🛑 DB Write Error',    !empty($db_write_error['error']) ? esc_html($db_write_error['error']) . ' (' . $db_write_error['time'] . ')' : '— (OK)', !empty($db_write_error['error']) ? '#fde8e8' : '#f9f9f9'],
            ['📧 Unsubscribe (7d)', number_format((int)($stats['total_unsubscribed'] ?? 0)), '#fff3e0'],
            ['⚡ Bounce found (7d)', number_format((int)($stats['bounces_found'] ?? 0)), '#fff3e0'],
            ['✅ Runs OK (7d)',      number_format((int)($stats['runs_success'] ?? 0)), '#e8fde8'],
            ['❌ Runs Error (7d)',   number_format((int)($stats['runs_error'] ?? 0)), (int)($stats['runs_error'] ?? 0) > 0 ? '#fde8e8' : '#f9f9f9'],
        ];
        foreach ($cards as [$label, $value, $bg]):
        ?>
            <div style="background: <?php echo $bg; ?>; border: 1px solid #ddd; border-radius: 4px; padding: 10px 12px; text-align: center;">
                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><?php echo $label; ?></div>
                <div style="font-size: 16px; font-weight: 600; color: #333;"><?php echo $value; ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Filters ── -->
    <form id="mmi-log-filter-form" method="get" action="<?php echo admin_url('tools.php'); ?>" style="background: #f9f9f9; padding: 12px 15px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="page" value="mmi-unsubscribe-log">

        <div>
            <label style="display: block; font-size: 12px; margin-bottom: 3px;">Domain</label>
            <select name="filter_domain" style="font-size: 12px;">
                <option value="">All domains</option>
                <?php foreach ($distinct_domains as $d): ?>
                    <option value="<?php echo esc_attr($d); ?>" <?php selected($filter_domain, $d); ?>><?php echo esc_html($d); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 12px; margin-bottom: 3px;">Status</label>
            <select name="filter_status" style="font-size: 12px;">
                <option value="">All statuses</option>
                <?php foreach (['success', 'error', 'skipped'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php selected($filter_status, $s); ?>><?php echo ucfirst($s); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 12px; margin-bottom: 3px;">Action</label>
            <select name="filter_action" style="font-size: 12px;">
                <option value="">All actions</option>
                <?php foreach ($distinct_actions as $act): ?>
                    <option value="<?php echo esc_attr($act); ?>" <?php selected($filter_action, $act); ?>><?php echo esc_html($act); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 12px; margin-bottom: 3px;">Từ ngày</label>
            <input type="date" name="filter_date_from" value="<?php echo esc_attr($filter_date_from); ?>" style="font-size: 12px;">
        </div>

        <div>
            <label style="display: block; font-size: 12px; margin-bottom: 3px;">Đến ngày</label>
            <input type="date" name="filter_date_to" value="<?php echo esc_attr($filter_date_to); ?>" style="font-size: 12px;">
        </div>

        <div style="display: flex; gap: 5px; align-items: flex-end;">
            <button type="submit" class="button button-primary" style="font-size: 12px;">🔍 Lọc</button>
            <a href="<?php echo admin_url('tools.php?page=mmi-unsubscribe-log'); ?>" class="button" style="font-size: 12px;">✖ Reset</a>
        </div>
    </form>

    <!-- ── Actions toolbar ── -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
        <div style="font-size: 13px; color: #666;">
            Kết quả: <strong><?php echo number_format($total_rows); ?></strong> bản ghi
            (trang <?php echo $current_page; ?>/<?php echo $total_pages; ?>)
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <!-- Run Now -->
            <button type="button" id="mmi-run-now-log-page" class="button button-primary" style="font-size: 12px;">
                ▶ Run Now
            </button>

            <!-- Export CSV -->
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('mmi_export_logs_csv', 'mmi_export_csv_nonce'); ?>
                <input type="hidden" name="filter_domain" value="<?php echo esc_attr($filter_domain); ?>">
                <input type="hidden" name="filter_status" value="<?php echo esc_attr($filter_status); ?>">
                <input type="hidden" name="filter_action" value="<?php echo esc_attr($filter_action); ?>">
                <input type="hidden" name="filter_date_from" value="<?php echo esc_attr($filter_date_from); ?>">
                <input type="hidden" name="filter_date_to" value="<?php echo esc_attr($filter_date_to); ?>">
                <button type="submit" class="button" style="font-size: 12px;">⬇ Export CSV</button>
            </form>

            <!-- Clear Old Logs -->
            <form method="post" style="display: inline;" onsubmit="return confirm('Xóa log cũ hơn số ngày đã chọn của tất cả domain?')">
                <?php wp_nonce_field('mmi_clear_old_logs', 'mmi_clear_logs_nonce'); ?>
                <select name="clear_days" style="font-size: 12px;">
                    <option value="7">7 ngày</option>
                    <option value="14">14 ngày</option>
                    <option value="30" selected>30 ngày</option>
                    <option value="60">60 ngày</option>
                    <option value="90">90 ngày</option>
                </select>
                <button type="submit" class="button button-secondary" style="font-size: 12px; color: #d63638;">🗑 Clear Old Logs</button>
            </form>
        </div>
    </div>

    <div id="mmi-run-now-result" style="margin-bottom: 10px;"></div>

    <!-- ── Data Table ── -->
    <div style="overflow-x: auto;">
        <table class="widefat striped" style="font-size: 12px;">
            <thead>
                <tr>
                    <th style="width: 140px;">Host</th>
                    <th style="width: 60px;">Type</th>
                    <th style="width: 110px;">Action</th>
                    <th style="width: 70px;">Status</th>
                    <th style="width: 190px;">Bounce Email</th>
                    <th style="width: 40px;">Unsub</th>
                    <th>Response Summary</th>
                    <th style="width: 200px;">Error</th>
                    <th style="width: 140px;">Created At</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 20px; color: #666;">Không có dữ liệu</td>
                    </tr>
                    <?php else: foreach ($rows as $row):
                        $status_color = [
                            'success' => '#46b450',
                            'error'   => '#dc3232',
                            'skipped' => '#856404',
                        ][$row['status']] ?? '#333';
                        $action_badge = [
                            'run_summary' => 'background:#0073aa;color:#fff',
                            'fetch_list'  => 'background:#ddd;color:#333',
                            'fetch_batch' => 'background:#ddd;color:#333',
                            'bounce_found' => 'background:#ffc107;color:#333',
                            'unsubscribe' => 'background:#dc3232;color:#fff',
                        ][$row['api_action']] ?? 'background:#eee;color:#333';
                    ?>
                        <tr>
                            <td style="font-size: 11px;"><?php echo esc_html($row['domain_host'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['run_type'] ?? ''); ?></td>
                            <td>
                                <span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 11px; <?php echo $action_badge; ?>">
                                    <?php echo esc_html($row['api_action'] ?? ''); ?>
                                </span>
                            </td>
                            <td style="color: <?php echo $status_color; ?>; font-weight: 600;">
                                <?php echo esc_html($row['status'] ?? ''); ?>
                            </td>
                            <td style="font-size: 11px; white-space: nowrap;">
                                <?php if ($row['bounce_email']): ?>
                                    <a href="<?php echo admin_url('tools.php?page=mmi-unsubscribe-log&filter_email=' . urlencode($row['bounce_email'])); ?>" target="_blank">
                                        <?php echo esc_html($row['bounce_email']); ?>
                                    </a>
                                    <a href="<?php echo admin_url('tools.php?page=email-campaigns&filter=1&search_email=' . urlencode($row['bounce_email'])); ?>" target="_blank">
                                        🔍
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php echo $row['unsubscribed'] ? '<span style="color:#46b450;font-weight:600;">✓</span>' : '<span style="color:#999;">—</span>'; ?>
                            </td>
                            <td style="font-size: 11px; max-width: 280px; word-break: break-word;">
                                <?php
                                $summary = $row['response_summary'] ?? '';
                                // Nếu là JSON của run_summary, hiển thị đẹp hơn
                                if ($row['api_action'] === 'run_summary' && $decoded = json_decode($summary, true)) {
                                    $parts = [];
                                    if (isset($decoded['messages_found']))      $parts[] = 'Found: ' . $decoded['messages_found'];
                                    if (isset($decoded['messages_new']))        $parts[] = 'New: ' . $decoded['messages_new'];
                                    if (isset($decoded['emails_extracted']))    $parts[] = 'Extracted: ' . $decoded['emails_extracted'];
                                    if (isset($decoded['emails_unsubscribed'])) $parts[] = 'Unsub: ' . $decoded['emails_unsubscribed'];
                                    if (!empty($decoded['errors']))             $parts[] = 'Errors: ' . count($decoded['errors']);
                                    echo esc_html(implode(' | ', $parts));
                                } else {
                                    echo esc_html(mb_substr($summary, 0, 200));
                                }
                                ?>
                            </td>
                            <td style="font-size: 11px; color: #dc3232; max-width: 200px; word-break: break-word;">
                                <?php echo esc_html(mb_substr($row['error_message'] ?? '', 0, 150)); ?>
                            </td>
                            <td style="font-size: 11px;"><?php echo esc_html($row['created_at'] ?? ''); ?></td>
                        </tr>
                <?php endforeach;
                endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Pagination ── -->
    <?php if ($total_pages > 1): ?>
        <div style="margin-top: 15px; display: flex; gap: 5px; flex-wrap: wrap; align-items: center;">
            <?php if ($current_page > 1): ?>
                <a href="<?php echo esc_url(mmi_log_filter_url(['paged' => $current_page - 1])); ?>" class="button">← Trang trước</a>
            <?php endif; ?>

            <?php
            $start = max(1, $current_page - 3);
            $end   = min($total_pages, $current_page + 3);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="<?php echo esc_url(mmi_log_filter_url(['paged' => $i])); ?>"
                    class="button <?php echo $i === $current_page ? 'button-primary' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo esc_url(mmi_log_filter_url(['paged' => $current_page + 1])); ?>" class="button">Trang tiếp →</a>
            <?php endif; ?>

            <span style="font-size: 12px; color: #666; margin-left: 10px;">
                Tổng <?php echo number_format($total_rows); ?> bản ghi / <?php echo $total_pages; ?> trang
            </span>
        </div>
    <?php endif; ?>

    <!-- ── Chú giải api_action ── -->
    <div style="margin-top: 25px;">
        <h3 style="font-size: 13px; margin-bottom: 8px; color: #444;">📖 Chú giải cột <code>Action</code></h3>
        <table class="widefat" style="font-size: 12px;">
            <thead>
                <tr>
                    <th style="width: 140px;">Action</th>
                    <th style="width: 90px;">Loại</th>
                    <th>Ý nghĩa</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>fetch_list</code></td>
                    <td>Gmail API</td>
                    <td>Gọi <code>Gmail messages.list</code> với 1 search query (ví dụ: <em>subject:Delivery</em>). Ghi 1 dòng log cho mỗi query trong số 7 queries được cấu hình. Cột <em>Response Summary</em> cho biết query nào và tìm được bao nhiêu message.</td>
                </tr>
                <tr style="background: #f9f9f9;">
                    <td><code>fetch_batch</code></td>
                    <td>Gmail Batch API</td>
                    <td>Gọi <code>Gmail Batch API</code> để lấy chi tiết nhiều message cùng lúc (tối đa 100 message/batch). Giảm số HTTP call từ hàng trăm xuống còn vài call. Ghi 1 dòng log cho mỗi batch chunk.</td>
                </tr>
                <tr>
                    <td><code>bounce_found</code></td>
                    <td>Phân tích</td>
                    <td>Phân tích snippet của từng message để trích xuất địa chỉ email bounce. Nếu tìm thấy email: <code>status=success</code> và cột <em>Bounce Email</em> có giá trị. Nếu không tìm được: <code>status=skipped</code> — message vẫn được ghi lại (có <em>Gmail Message ID</em>) để không bị fetch lại ở lần chạy sau.</td>
                </tr>
                <tr style="background: #f9f9f9;">
                    <td><code>unsubscribe</code></td>
                    <td>DB Update</td>
                    <td>Cập nhật <code>is_unsubscribed = 1</code> trong bảng <code>mail_marketing</code> cho từng email bounce. Cột <em>Unsub</em> = ✓ nếu có ít nhất 1 row được cập nhật, ✗ nếu email không tồn tại trong DB. <code>status=error</code> khi SQL thất bại.</td>
                </tr>
                <tr>
                    <td><code>run_summary</code></td>
                    <td>Tổng kết</td>
                    <td>Ghi 1 dòng tổng kết cuối mỗi lần chạy (cron hoặc manual). Cột <em>Response Summary</em> chứa JSON đầy đủ: số message found/new/emails extracted/unsubscribed và danh sách lỗi. Đây là dòng log quan trọng nhất để theo dõi trạng thái tổng thể.</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Run Now JS (dùng mmi_ajax đã được localize sẵn) ── -->
<script>
    jQuery(function($) {
        // Auto submit filter form khi thay đổi select
        $('#mmi-log-filter-form').on('change', 'select', function() {
            $(this).closest('form').submit();
        });

        $('#mmi-run-now-log-page').on('click', function() {
            var btn = $(this);
            var result = $('#mmi-run-now-result');
            btn.prop('disabled', true).text('⏳ Running...');
            result.html('<p style="color:#0073aa">⏳ Đang chạy Auto Unsubscribe...</p>');
            $.post(mmi_ajax.ajax_url, {
                action: 'mmi_run_auto_unsubscribe',
                mmi_nonce: mmi_ajax.nonce
            }, function(response) {
                if (response.success) {
                    var d = response.data;
                    result.html(
                        '<div style="background:#e8fde8;border:1px solid #46b450;padding:10px;border-radius:4px;font-size:13px;">' +
                        '✅ <strong>Hoàn thành!</strong> ' +
                        'Messages found: <b>' + (d.messages_found || 0) + '</b> | ' +
                        'New: <b>' + (d.messages_new || 0) + '</b> | ' +
                        'Bounces: <b>' + (d.emails_extracted || 0) + '</b> | ' +
                        'Unsubscribed: <b>' + (d.emails_unsubscribed || 0) + '</b>' +
                        (d.errors && d.errors.length ? '<br>⚠️ Errors: ' + d.errors.join('; ') : '') +
                        '</div>'
                    );
                    // Reload trang sau 2s để xem log mới
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    result.html('<div style="background:#fde8e8;border:1px solid #dc3232;padding:10px;border-radius:4px;font-size:13px;">❌ ' + (response.data || 'Unknown error') + '</div>');
                    btn.prop('disabled', false).text('▶ Run Now');
                }
            }).fail(function() {
                result.html('<div style="background:#fde8e8;padding:10px;border-radius:4px;font-size:13px;">❌ Connection error</div>');
                btn.prop('disabled', false).text('▶ Run Now');
            });
        });
    });
</script>