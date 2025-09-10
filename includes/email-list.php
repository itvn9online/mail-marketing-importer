<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render email list with filters
 */
$base_url = admin_url('admin.php?page=email-campaigns');

?>
<!-- Email List Section - Show when not editing -->
<div id="imported-email-list" class="campaign-section">
    <h2>Imported Email List</h2>
    <?php
    global $wpdb;

    // Get filter parameters
    $status_filter = isset($_GET['email_status']) ? sanitize_text_field($_GET['email_status']) : '';
    $deleted_filter = isset($_GET['email_deleted']) ? sanitize_text_field($_GET['email_deleted']) : '';
    $unsubscribed_filter = isset($_GET['email_unsubscribed']) ? sanitize_text_field($_GET['email_unsubscribed']) : '';
    $campaign_filter = isset($_GET['email_campaign']) ? intval($_GET['email_campaign']) : '';
    $search_email = isset($_GET['search_email']) ? sanitize_text_field($_GET['search_email']) : '';
    $search_phone = isset($_GET['search_phone']) ? sanitize_text_field($_GET['search_phone']) : '';

    // Pagination
    $per_page = 50;
    $current_page = isset($_GET['email_page']) ? max(1, intval($_GET['email_page'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Build WHERE clause
    $where_conditions = array();
    $where_values = array();

    if ($status_filter !== '') {
        $where_conditions[] = "mm.status = %s";
        $where_values[] = $status_filter;
    }

    if ($deleted_filter !== '') {
        $where_conditions[] = "mm.is_deleted = %s";
        $where_values[] = $deleted_filter;
    }

    if ($unsubscribed_filter !== '') {
        $where_conditions[] = "mm.is_unsubscribed = %s";
        $where_values[] = $unsubscribed_filter;
    }

    if ($campaign_filter) {
        $where_conditions[] = "mm.campaign_id = %d";
        $where_values[] = $campaign_filter;
    }

    if (!empty($search_email)) {
        $where_conditions[] = "mm.email LIKE %s";
        $where_values[] = '%' . $search_email . '%';
    }

    if (!empty($search_phone)) {
        $where_conditions[] = "mm.phone LIKE %s";
        $where_values[] = '%' . $search_phone . '%';
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Get total count for pagination
    $count_query = "
            SELECT COUNT(mm.id) 
            FROM {$wpdb->prefix}mail_marketing mm 
            LEFT JOIN {$wpdb->prefix}mail_marketing_campaigns mmc ON mm.campaign_id = mmc.id 
            $where_clause
        ";

    if (!empty($where_values)) {
        $total_records = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
    } else {
        $total_records = $wpdb->get_var($count_query);
    }

    // Get emails with pagination
    $query = "
            SELECT mm.*, mmc.name as campaign_name
            FROM {$wpdb->prefix}mail_marketing mm 
            LEFT JOIN {$wpdb->prefix}mail_marketing_campaigns mmc ON mm.campaign_id = mmc.id 
            $where_clause
            ORDER BY mm.id DESC 
            LIMIT %d OFFSET %d
        ";

    $query_values = array_merge($where_values, array($per_page, $offset));
    $emails = $wpdb->get_results($wpdb->prepare($query, $query_values));
    // print_r($emails);

    // Get campaigns for filter dropdown
    $campaigns = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}mail_marketing_campaigns ORDER BY id DESC LIMIT 50");

    // Calculate pagination
    $total_pages = ceil($total_records / $per_page);

    ?>
    <div class="email-list-filters">
        <form method="get" action="">
            <!-- Preserve existing GET parameters -->
            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? ''); ?>">
            <input type="hidden" name="filter" value="1">
            <?php if (isset($_GET['edit'])): ?>
                <input type="hidden" name="edit" value="<?php echo esc_attr($_GET['edit']); ?>">
            <?php endif; ?>

            <div class="filter-row">
                <label for="email_status">Status:</label>
                <select name="email_status" id="email_status">
                    <option value="">All Status</option>
                    <option value="0" <?php selected($status_filter, '0'); ?>>Pending</option>
                    <option value="1" <?php selected($status_filter, '1'); ?>>Sent</option>
                </select>

                <label for="email_deleted">Deleted:</label>
                <select name="email_deleted" id="email_deleted">
                    <option value="">All</option>
                    <option value="0" <?php selected($deleted_filter, '0'); ?>>Active</option>
                    <option value="1" <?php selected($deleted_filter, '1'); ?>>Deleted</option>
                </select>

                <label for="email_unsubscribed">Unsubscribed:</label>
                <select name="email_unsubscribed" id="email_unsubscribed">
                    <option value="">All</option>
                    <option value="0" <?php selected($unsubscribed_filter, '0'); ?>>Subscribed</option>
                    <option value="1" <?php selected($unsubscribed_filter, '1'); ?>>Unsubscribed</option>
                </select>

                <label for="email_campaign">Campaign:</label>
                <select name="email_campaign" id="email_campaign">
                    <option value="">All Campaigns</option>
                    <?php foreach ($campaigns as $campaign): ?>
                        <option value="<?php echo esc_attr($campaign->id); ?>" <?php selected($campaign_filter, $campaign->id); ?>>
                            <?php echo esc_html($campaign->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="search_email">Email:</label>
                <input type="text" name="search_email" id="search_email" value="<?php echo esc_attr($search_email); ?>" placeholder="Enter email to search">

                <label for="search_phone">Phone:</label>
                <input type="text" name="search_phone" id="search_phone" value="<?php echo esc_attr($search_phone); ?>" placeholder="Enter phone to search">

                <input type="submit" class="button button-primary" value="Filter">
                <a href="<?php echo $base_url . (isset($_GET['edit']) ? '&edit=' . intval($_GET['edit']) : ''); ?>&filter=1"
                    class="button">Clear</a>
            </div>
        </form>
    </div>

    <!-- Bulk Unsubscribe Section -->
    <div class="bulk-unsubscribe-section" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0, 0, 0, .04);">
        <?php

        // Bulk unsubscribe notification
        if (isset($_GET['bulk_unsubscribed']) && $_GET['bulk_unsubscribed'] == '1') {
            $affected_rows = isset($_GET['affected_rows']) ? intval($_GET['affected_rows']) : 0;
            $email = isset($_GET['email']) ? urldecode($_GET['email']) : '';
        ?>
            <div class="redcolor" style="text-align: center;">
                <strong>Bulk Unsubscribe Completed!</strong> Successfully unsubscribed <?php echo $affected_rows; ?> record(s) with email: <code><?php echo esc_html($email); ?></code>
            </div>
        <?php
        }

        ?>
        <h3 style="margin-top: 0; color: #d63638; font-size: 1.1em;">🚫 Bulk Unsubscribe Email</h3>
        <p style="color: #666; margin-bottom: 15px;">Enter an email address to unsubscribe all records with that email from future campaigns.</p>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <input type="hidden" name="action" value="bulk_unsubscribe_email">
            <?php wp_nonce_field('bulk_unsubscribe_nonce', 'bulk_unsubscribe_nonce'); ?>

            <label for="unsubscribe_email" style="font-weight: 500;">Email to unsubscribe:</label>
            <input type="email" name="unsubscribe_email" id="unsubscribe_email"
                placeholder="example@domain.com" required
                style="width: 250px; border: 1px solid #ddd; border-radius: 3px;">

            <input type="submit" id="unsubscribe_submit" class="button button-secondary" value="Unsubscribe Email" disabled>

            <small style="color: #666; width: 100%; margin-top: 5px;">
                ⚠️ This will mark ALL database records with this email as unsubscribed (is_unsubscribed = 1)
            </small>
        </form>
    </div>

    <div class="email-list-stats">
        <p><strong>Total Records:</strong> <?php echo number_format($total_records); ?> emails found</p>
    </div>

    <?php if (!empty($emails)): ?>
        <table class="wp-list-table widefat fixed striped emails-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Email</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Campaign</th>
                    <th>Status</th>
                    <th>Deleted</th>
                    <th>Unsubscribed</th>
                    <th>Sent At</th>
                    <th>Opened At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emails as $email): ?>
                    <tr>
                        <td><a href="<?php echo $base_url . '&details=' . $email->id; ?>"><?php echo $email->id; ?></a></td>
                        <td><?php echo esc_html($email->email); ?></td>
                        <td>
                            <?php
                            $display_name = '';
                            if ($email->first_name || $email->last_name) {
                                $display_name = trim($email->first_name . ' ' . $email->last_name);
                            } elseif ($email->name) {
                                $display_name = $email->name;
                            }
                            echo esc_html($display_name ?: 'N/A');
                            ?>
                        </td>
                        <td><?php echo esc_html($email->phone ?: 'N/A'); ?></td>
                        <td><?php echo esc_html($email->campaign_name ?: 'No Campaign'); ?></td>
                        <td>
                            <span class="email-status email-status-<?php echo $email->status; ?> clickable-status"
                                data-email-id="<?php echo $email->id; ?>"
                                data-field="status"
                                data-current-value="<?php echo $email->status; ?>"
                                title="Click to toggle status">
                                <?php echo $email->status == 1 ? 'Sent' : 'Pending'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="email-deleted email-is_deleted-<?php echo $email->is_deleted; ?> clickable-status"
                                data-email-id="<?php echo $email->id; ?>"
                                data-field="is_deleted"
                                data-current-value="<?php echo $email->is_deleted; ?>"
                                title="Click to toggle deleted status">
                                <?php echo $email->is_deleted == 1 ? 'Yes' : 'No'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="email-unsubscribed email-is_unsubscribed-<?php echo $email->is_unsubscribed; ?> clickable-status"
                                data-email-id="<?php echo $email->id; ?>"
                                data-field="is_unsubscribed"
                                data-current-value="<?php echo $email->is_unsubscribed; ?>"
                                title="Click to toggle unsubscribed status">
                                <?php echo $email->is_unsubscribed == 1 ? 'Yes' : 'No'; ?>
                            </span>
                        </td>
                        <td><?php echo $email->sended_at ? $email->sended_at : 'N/A'; ?></td>
                        <td><?php echo $email->opened_at ? $email->opened_at : 'N/A'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="email-pagination">
                <?php
                $pagination_url = $base_url;
                if (isset($_GET['edit'])) {
                    $pagination_url .= '&edit=' . intval($_GET['edit']);
                }

                // Add filter parameters to pagination links
                $filter_params = array();
                if ($status_filter !== '') $filter_params[] = 'email_status=' . urlencode($status_filter);
                if ($deleted_filter !== '') $filter_params[] = 'email_deleted=' . urlencode($deleted_filter);
                if ($unsubscribed_filter !== '') $filter_params[] = 'email_unsubscribed=' . urlencode($unsubscribed_filter);
                if ($campaign_filter) $filter_params[] = 'email_campaign=' . urlencode($campaign_filter);
                if (!empty($search_email)) $filter_params[] = 'search_email=' . urlencode($search_email);
                if (!empty($search_phone)) $filter_params[] = 'search_phone=' . urlencode($search_phone);

                if (!empty($filter_params)) {
                    $pagination_url .= '&' . implode('&', $filter_params);
                }
                ?>

                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo number_format($total_records); ?> items</span>
                    <span class="pagination-links">
                        <?php if ($current_page > 1): ?>
                            <a href="<?php echo $pagination_url . '&email_page=1'; ?>" class="first-page button">«</a>
                            <a href="<?php echo $pagination_url . '&email_page=' . ($current_page - 1); ?>" class="prev-page button">‹</a>
                        <?php endif; ?>

                        <span class="paging-input">
                            <span class="tablenav-paging-text">
                                <?php echo $current_page; ?> of <span class="total-pages"><?php echo $total_pages; ?></span>
                            </span>
                        </span>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="<?php echo $pagination_url . '&email_page=' . ($current_page + 1); ?>" class="next-page button">›</a>
                            <a href="<?php echo $pagination_url . '&email_page=' . $total_pages; ?>" class="last-page button">»</a>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <p>No emails found matching the selected filters.</p>
    <?php endif; ?>
</div>