<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

?>
<!-- Existing Campaigns -->
<div class="campaign-section">
    <h2>Existing Campaigns</h2>
    <?php if (!empty($campaigns)): ?>
        <table class="wp-list-table widefat fixed striped campaigns-table">
            <thead>
                <tr>
                    <th>Campaign Name</th>
                    <th>Email title</th>
                    <th>Status</th>
                    <th>Contacts</th>
                    <th>Ngày bắt đầu</th>
                    <th>Created</th>
                    <th>Actions</th>
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
                            <a href="<?php echo admin_url('admin.php?page=email-campaigns&edit=' . $campaign->id); ?>" class="button button-small">Edit</a>
                            <a href="<?php echo home_url(); ?>/wp-content/themes/marketing/api/v1/?token=9557ff3fc1295832f54c9fe3351d977b&action=mail_marketing&campaign_id=<?php echo $campaign->id; ?>"
                                class="button button-small button-primary" target="_blank">Send Email</a>
                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=delete_campaign&campaign_id=' . $campaign->id), 'delete_campaign_' . $campaign->id); ?>"
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