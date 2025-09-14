<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// full URL của trang hiện tại
$current_url = home_url($_SERVER['REQUEST_URI']);

?>
<!-- Pagination Navigation -->
<div class="tablenav top" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 20px;">
    <!-- Campaign Status Filter (Left Column) -->
    <div style="flex: 0 0 auto;">
        <label for="campaign-status-filter">Filter by status: </label>
        <select id="campaign-status-filter" onchange="filterCampaignsByStatus(this.value)">
            <option value="active" <?php selected($status_filter, 'active'); ?>>Active</option>
            <option value="inactive" <?php selected($status_filter, 'inactive'); ?>>Inactive</option>
            <option value="completed" <?php selected($status_filter, 'completed'); ?>>Completed</option>
            <option value="all" <?php selected($status_filter, 'all'); ?>>All Status</option>
        </select>
        (<?php echo number_format($total_campaigns); ?> items)
    </div>
    <?php if ($total_pages > 1): ?>
        <!-- Pagination Controls (Right Column) -->
        <div class="tablenav-pages" style="flex: 0 0 auto;">
            <span class="pagination-links">
                <?php if ($current_page > 1): ?>
                    <a class="first-page button" href="<?php echo admin_url('tools.php?page=email-campaigns&campaign_status=' . $status_filter . '&paged=1'); ?>">&laquo;</a>
                    <a class="prev-page button" href="<?php echo admin_url('tools.php?page=email-campaigns&campaign_status=' . $status_filter . '&paged=' . ($current_page - 1)); ?>">&lsaquo;</a>
                <?php else: ?>
                    <span class="tablenav-pages-navspan button disabled">&laquo;</span>
                    <span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
                <?php endif; ?>

                <span class="paging-input">
                    <label for="current-page-selector" class="screen-reader-text">Current Page</label>
                    <input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php echo $current_page; ?>" size="2" aria-describedby="table-paging" />
                    <span class="tablenav-paging-text"> of <span class="total-pages"><?php echo $total_pages; ?></span></span>
                </span>

                <?php if ($current_page < $total_pages): ?>
                    <a class="next-page button" href="<?php echo admin_url('tools.php?page=email-campaigns&campaign_status=' . $status_filter . '&paged=' . ($current_page + 1)); ?>">&rsaquo;</a>
                    <a class="last-page button" href="<?php echo admin_url('tools.php?page=email-campaigns&campaign_status=' . $status_filter . '&paged=' . $total_pages); ?>">&raquo;</a>
                <?php else: ?>
                    <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
                    <span class="tablenav-pages-navspan button disabled">&raquo;</span>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
</div>
<!-- Existing Campaigns -->
<div class="campaign-section">
    <h2>Existing Campaigns</h2>
    <?php if (!empty($campaigns)): ?>
        <table class="wp-list-table widefat fixed striped campaigns-table">
            <thead>
                <tr>
                    <th>Campaign Name</th>
                    <th>Email title</th>
                    <th style="width: 110px;">Status</th>
                    <th style="width: 110px;">Contacts</th>
                    <th style="width: 168px;">Ngày bắt đầu</th>
                    <th style="width: 168px;">Created</th>
                    <th style="width: 220px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($campaigns as $campaign): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($campaign->name); ?></strong>
                            <?php if ($campaign->description): ?>
                                <br><em><?php echo esc_html(substr($campaign->description, 0, 80) . (strlen($campaign->description) > 80 ? '...' : '')); ?></em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($campaign->email_subject); ?></td>
                        <td>
                            <span class="campaign-status campaign-status-<?php echo esc_attr($campaign->status); ?>">
                                <?php echo ucfirst($campaign->status); ?>
                            </span>
                        </td>
                        <td><?php echo number_format($campaign->contact_count); ?></td>
                        <td>
                            <?php if ($campaign->start_date): ?>
                                <?php echo $campaign->start_date; ?>
                            <?php else: ?>
                                <em>Gửi ngay</em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $campaign->created_at; ?></td>
                        <td>
                            <a href="<?php echo admin_url('tools.php?page=email-campaigns&edit=' . $campaign->id); ?>" class="button button-small">Edit</a>
                            <a href="<?php echo home_url(); ?>/wp-content/themes/marketing/api/v1/?token=9557ff3fc1295832f54c9fe3351d977b&action=mail_marketing&campaign_id=<?php echo $campaign->id; ?>"
                                class="button button-small button-primary" target="_blank">Send Email</a>
                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=delete_campaign&campaign_id=' . $campaign->id), 'delete_campaign_' . $campaign->id) . '&redirect_to=' . urlencode($current_url); ?>"
                                class="button button-small button-link-delete"
                                onclick="return confirm('Are you sure you want to Inactive this campaign?')">Inactive</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No campaigns created yet. Create your first campaign above to get started!</p>
    <?php endif; ?>
</div>

<!-- JavaScript for campaign status filter -->
<script type="text/javascript">
    function filterCampaignsByStatus(status) {
        var currentUrl = window.location.href;
        var baseUrl = '<?php echo admin_url('tools.php?page=email-campaigns'); ?>';

        if (status === 'active') {
            // Default to active, no need for status parameter
            window.location.href = baseUrl;
        } else {
            window.location.href = baseUrl + '&campaign_status=' + status;
        }
    }

    // Handle pagination form submission
    document.addEventListener('DOMContentLoaded', function() {
        var pageInput = document.getElementById('current-page-selector');
        if (pageInput) {
            pageInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var page = parseInt(this.value);
                    var totalPages = <?php echo $total_pages; ?>;
                    var status = '<?php echo $status_filter; ?>';

                    if (page >= 1 && page <= totalPages) {
                        var baseUrl = '<?php echo admin_url('tools.php?page=email-campaigns'); ?>';
                        var url = baseUrl;

                        if (status !== 'active') {
                            url += '&campaign_status=' + status;
                        }

                        if (page > 1) {
                            url += (url.indexOf('?') !== -1 ? '&' : '?') + 'paged=' + page;
                        }

                        window.location.href = url;
                    }
                }
            });
        }
    });
</script>