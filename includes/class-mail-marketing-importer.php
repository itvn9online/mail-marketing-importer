<?php

/**
 * Main Mail Marketing Importer Class
 */
class Mail_Marketing_Importer
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_import_marketing_data', array($this, 'handle_import_ajax'));
        add_action('wp_ajax_read_file_headers', array($this, 'handle_read_file_headers'));
        add_action('admin_post_import_marketing_file', array($this, 'handle_file_upload'));

        // Campaign management actions
        add_action('admin_post_create_campaign', array($this, 'handle_create_campaign'));
        add_action('admin_post_update_campaign', array($this, 'handle_update_campaign'));
        add_action('admin_post_delete_campaign', array($this, 'handle_delete_campaign'));

        // Debug: Add a simple test action
        add_action('admin_post_test_import', array($this, 'test_import'));
        add_action('admin_post_test_unsubscribe', array($this, 'test_unsubscribe'));

        // Email status toggle actions
        add_action('wp_ajax_toggle_email_status', array($this, 'handle_toggle_email_status'));
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu()
    {
        // Main import page
        add_management_page(
            'Mail Marketing Importer',
            'Import Marketing Data',
            'manage_options',
            'mail-marketing-importer',
            array($this, 'admin_page')
        );

        // Campaign management page
        add_submenu_page(
            'tools.php',
            'Campaign Management',
            'Email Campaigns',
            'manage_options',
            'email-campaigns',
            array($this, 'campaigns_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook)
    {
        // Load scripts and styles for both import and campaign management pages
        if (!in_array($hook, ['tools_page_mail-marketing-importer', 'tools_page_email-campaigns'])) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_script('mmi-admin-js', MMI_PLUGIN_URL . 'assets/admin.js', array('jquery'), filemtime(MMI_PLUGIN_PATH . 'assets/admin.js'), true);
        wp_enqueue_style('mmi-admin-css', MMI_PLUGIN_URL . 'assets/admin.css', array(), filemtime(MMI_PLUGIN_PATH . 'assets/admin.css'));

        wp_localize_script('mmi-admin-js', 'mmi_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mmi_import_nonce')
        ));
    }

    /**
     * Admin page HTML
     */
    public function admin_page()
    {
        // Display messages based on URL parameters
        $this->display_admin_notices();

?>
        <div class="wrap">
            <h1>Mail Marketing Importer</h1>
            <div class="mmi-container">
                <div class="mmi-upload-section">
                    <h2>Campaign & File Upload</h2>
                    <p>Select or create a campaign, then upload an Excel file (.xlsx, .xls, .csv) containing email marketing data.</p>

                    <form id="mmi-upload-form" method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="import_marketing_file">
                        <?php wp_nonce_field('mmi_upload_nonce', 'mmi_nonce'); ?>

                        <!-- Campaign Selection Section -->
                        <div class="campaign-selection">
                            <h3>Campaign Management</h3>
                            <p class="description">Choose an existing campaign or create a new one for this import</p>

                            <div class="campaign-option">
                                <label>
                                    <input type="radio" name="campaign_option" value="existing" checked>
                                    Select Existing Campaign
                                </label>
                                <div class="campaign-field-group" id="existing-campaign-group">
                                    <label for="existing_campaign">Choose Campaign:</label>
                                    <select name="existing_campaign" id="existing_campaign">
                                        <option value="">-- Select Campaign --</option>
                                        <?php $this->render_campaign_options(); ?>
                                    </select>
                                </div>
                            </div>

                            <div class="campaign-option">
                                <label>
                                    <input type="radio" name="campaign_option" value="new">
                                    Create New Campaign
                                </label>
                                <div class="campaign-field-group" id="new-campaign-group">
                                    <label for="new_campaign_name">Campaign Name:</label>
                                    <input type="text" name="new_campaign_name" id="new_campaign_name" placeholder="Enter campaign name" maxlength="255">
                                    <small class="description">You can complete other details after importing emails</small>
                                </div>
                            </div>
                        </div>

                        <table class="form-table">
                            <tr>
                                <th scope="row">Select File (*)</th>
                                <td>
                                    <input type="file" name="import_file" id="import_file" accept=".xlsx,.xls,.csv" required>
                                    <p class="description">Supported formats: .xlsx, .xls, .csv</p>
                                    <div id="file-preview" style="display: none; margin-top: 10px;">
                                        <div class="loading-spinner" style="display: none;">
                                            <span class="spinner is-active"></span> Reading file headers...
                                        </div>
                                        <div id="file-info"></div>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <div id="column-mapping-section" style="display: none;">
                            <h3>Column Mapping</h3>
                            <p>Select the appropriate column for each field from your file:</p>

                            <table class="form-table">
                                <tr>
                                    <th scope="row">Email Column <span style="color: red;">*</span></th>
                                    <td>
                                        <select name="email_column" id="email_column" required>
                                            <option value="">-- Select Email Column --</option>
                                        </select>
                                        <p class="description">Column containing email addresses (required)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">First Name Column</th>
                                    <td>
                                        <select name="first_name_column" id="first_name_column">
                                            <option value="">-- Select First Name Column (Optional) --</option>
                                        </select>
                                        <p class="description">Column containing first names</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Last Name Column</th>
                                    <td>
                                        <select name="last_name_column" id="last_name_column">
                                            <option value="">-- Select Last Name Column (Optional) --</option>
                                        </select>
                                        <p class="description">Column containing last names</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Name Column (Legacy)</th>
                                    <td>
                                        <select name="name_column" id="name_column">
                                            <option value="">-- Select Full Name Column (Optional) --</option>
                                        </select>
                                        <p class="description">Column containing full names (for backward compatibility)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Phone Column</th>
                                    <td>
                                        <select name="phone_column" id="phone_column">
                                            <option value="">-- Select Phone Column (Optional) --</option>
                                        </select>
                                        <p class="description">Column containing phone numbers</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Address Column</th>
                                    <td>
                                        <select name="address_column" id="address_column">
                                            <option value="">-- Select Address Column (Optional) --</option>
                                        </select>
                                        <p class="description">Column containing street addresses</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">City Column</th>
                                    <td>
                                        <select name="city_column" id="city_column">
                                            <option value="">-- Select City Column (Optional) --</option>
                                        </select>
                                        <p class="description">Column containing city names</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">State Column</th>
                                    <td>
                                        <select name="state_column" id="state_column">
                                            <option value="">-- Select State Column (Optional) --</option>
                                        </select>
                                        <p class="description">Column containing state/province names</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Zip Code Column</th>
                                    <td>
                                        <select name="zip_code_column" id="zip_code_column">
                                            <option value="">-- Select Zip Code Column (Optional) --</option>
                                        </select>
                                        <p class="description">Column containing postal/ZIP codes</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Skip Header Row</th>
                                    <td>
                                        <input type="checkbox" name="skip_header" id="skip_header" value="1" checked>
                                        <label for="skip_header">Skip first row (header)</label>
                                        <p class="description">Check this if your file has column headers in the first row</p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p class="submit">
                            <input type="submit" name="submit" class="button-primary" value="Import Data">
                        </p>
                    </form>
                </div>

                <div class="mmi-progress-section" style="display: none;">
                    <h2>Import Progress</h2>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 0%"></div>
                    </div>
                    <div class="progress-text">0%</div>
                    <div class="import-log"></div>
                </div>

                <div class="mmi-stats-section">
                    <h2>Database Statistics</h2>
                    <?php $this->display_stats(); ?>

                    <div style="margin-top: 20px;">
                        <h3>Debug & Test</h3>
                        <p>Click the button below to test the import functionality:</p>
                        <a href="<?php echo admin_url('admin-post.php?action=test_import'); ?>" class="button button-secondary">Test Import Function</a>

                        <h4>Database Management</h4>
                        <p>Force update database tables and columns (use if new features aren't working):</p>
                        <a href="<?php echo admin_url('admin-post.php?action=mmi_force_db_update'); ?>" class="button button-secondary"
                            onclick="return confirm('This will update database tables and columns. Continue?')">Update Database Structure</a>

                        <h4>Test Unsubscribe Link</h4>
                        <p>Generate a test unsubscribe link for the latest email:</p>
                        <a href="<?php echo admin_url('admin-post.php?action=test_unsubscribe'); ?>" class="button button-secondary">Generate Test Unsubscribe Link</a>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Campaign management page
     */
    public function campaigns_page()
    {
        global $wpdb;

        // Debug: Check if campaigns table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mail_marketing_campaigns'");
        if (!$table_exists) {
            echo '<div class="notice notice-error"><p><strong>Error:</strong> Campaigns table does not exist. Please activate the plugin properly to create the database tables.</p></div>';
            return;
        }

        // Handle form submissions - now handled by admin_post hooks
        // Form submissions are handled by handle_create_campaign() and handle_update_campaign() via admin_post hooks

        // Display messages
        $this->display_admin_notices();

        // Check if we're editing a campaign
        $edit_campaign = null;
        $total_contacts = 0;
        if (isset($_GET['edit']) && !empty($_GET['edit'])) {
            $campaign_id = intval($_GET['edit']);
            $edit_campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mail_marketing_campaigns WHERE id = %d",
                $campaign_id
            ));

            if (!$edit_campaign) {
                echo '<div class="notice notice-error"><p>Campaign not found.</p></div>';
                $edit_campaign = null;
            } else {
                // Get total contacts for this campaign
                $total_contacts = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing WHERE campaign_id = %d",
                    $campaign_id
                ));
            }
        } else {
            $campaign_id = 0;
        }

        // Get all campaigns
        $campaigns = $wpdb->get_results("
            SELECT c.*, 
                   COUNT(m.id) as contact_count
            FROM {$wpdb->prefix}mail_marketing_campaigns c
            LEFT JOIN {$wpdb->prefix}mail_marketing m ON c.id = m.campaign_id
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ");

        // Get campaign stats
        $total_campaigns = count($campaigns);
        $active_campaigns = count(array_filter($campaigns, function ($c) {
            return $c->status === 'active';
        }));

        if (function_exists('get_email_content_settings')) {
            $email_content_settings = get_email_content_settings($campaign_id);
        } else {
            $email_content_settings = null;
        }

    ?>
        <div class="wrap">
            <h1>Email Campaign Management</h1>

            <!-- Campaign Stats -->
            <div class="campaign-stats-overview">
                <div class="campaign-stat-box">
                    <div class="campaign-stat-number"><?php echo number_format($total_campaigns); ?></div>
                    <div class="campaign-stat-label">Total Campaigns</div>
                </div>
                <div class="campaign-stat-box">
                    <div class="campaign-stat-number"><?php echo number_format($active_campaigns); ?></div>
                    <div class="campaign-stat-label">Active Campaigns</div>
                </div>
                <?php if ($edit_campaign): ?>
                    <div class="campaign-stat-box">
                        <div class="campaign-stat-number"><?php echo number_format($total_contacts); ?></div>
                        <div class="campaign-stat-label">Total Contacts</div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="campaign-container">
                <!-- Create/Edit Campaign -->
                <div class="campaign-section">
                    <h2><?php echo $edit_campaign ? 'Edit Campaign' : 'Create New Campaign'; ?></h2>
                    <?php if ($edit_campaign): ?>
                        <p><a href="<?php echo admin_url('admin.php?page=email-campaigns'); ?>" class="button button-secondary">← Back to Campaigns</a></p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('campaign_action', 'campaign_nonce'); ?>
                        <input type="hidden" name="action" value="<?php echo $edit_campaign ? 'update_campaign' : 'create_campaign'; ?>">
                        <?php if ($edit_campaign): ?>
                            <input type="hidden" name="campaign_id" value="<?php echo $edit_campaign->id; ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row">Campaign Name (*)</th>
                                <td>
                                    <input type="text" name="campaign_name" class="regular-text"
                                        value="<?php echo $edit_campaign ? esc_attr($edit_campaign->name) : ''; ?>" required>
                                    <p class="description">Enter a unique name for this campaign</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Description</th>
                                <td>
                                    <textarea name="campaign_description" rows="3" class="large-text"><?php echo $edit_campaign ? esc_textarea($edit_campaign->description) : ''; ?></textarea>
                                    <p class="description">Optional description of the campaign purpose</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Email Subject (*)</th>
                                <td>
                                    <input type="text" name="email_subject" class="regular-text"
                                        value="<?php echo (($edit_campaign && !empty($edit_campaign->email_subject)) ? esc_attr($edit_campaign->email_subject) : ($email_content_settings ? esc_attr($email_content_settings['subject']) : '')); ?>" required>
                                    <p class="description">Subject line for campaign emails.
                                        <span class="placeholder-item" data-placeholder="{SITE_NAME}" title="Double-click to copy">{SITE_NAME}</span>
                                        <span class="placeholder-item" data-placeholder="{USER_EMAIL}" title="Double-click to copy">{USER_EMAIL}</span>
                                        <span class="placeholder-item" data-placeholder="{USER_NAME}" title="Double-click to copy">{USER_NAME}</span>
                                        placeholders
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Email Template</th>
                                <td>
                                    <select name="email_template" class="regular-text">
                                        <?php
                                        $current_template = $edit_campaign && !empty($edit_campaign->email_template) ? $edit_campaign->email_template : 'default.html';
                                        echo $this->get_email_templates_options_with_selected($current_template);
                                        ?>
                                    </select>
                                    <p class="description">Choose an email template for this campaign</p>
                                    <p><a href="<?php echo MMI_PLUGIN_URL; ?>template-preview-page.php?template=<?php echo $current_template; ?>" target="_blank" style="font-size: 12px;">Preview Templates →</a></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Email Content</th>
                                <td>
                                    <?php

                                    // 
                                    $content = $edit_campaign ? $edit_campaign->email_content : '';
                                    // var_dump($content);
                                    if (empty($content)) {
                                        if ($email_content_settings) {
                                            $content = $email_content_settings['content'];
                                        } else {
                                            $content = '<p>Dear {USER_NAME},</p><p>We are excited to announce our latest campaign!</p>';
                                        }
                                    }
                                    // thêm unsubscribe link placeholder nếu chưa có
                                    if (strpos($content, '{UNSUBSCRIBE_URL}') === false) {
                                        $content .= '<hr><p>Don\'t want future emails? <a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p>';
                                    }

                                    // 
                                    wp_editor($content, 'email_content', array(
                                        'textarea_name' => 'email_content',
                                        'textarea_rows' => 20,
                                        'teeny' => false,
                                        'media_buttons' => true,
                                        'tinymce' => true,
                                        'quicktags' => true
                                    ));
                                    ?>
                                    <p class="description">
                                        <strong>💡 Smart Template System:</strong> Leave this field empty to automatically use the content from your selected email template.
                                        You can also customize the template content here with your own HTML/text.
                                    </p>
                                    <div class="email-placeholders">
                                        <strong>Available placeholders:</strong><br>
                                        <small style="color: #666; font-style: italic;">Double-click any placeholder to copy it to clipboard</small><br>
                                        <span class="placeholder-item" data-placeholder="{SITE_NAME}" title="Double-click to copy">{SITE_NAME}</span>
                                        <span class="placeholder-item" data-placeholder="{USER_EMAIL}" title="Double-click to copy">{USER_EMAIL}</span>
                                        <span class="placeholder-item" data-placeholder="{USER_NAME}" title="Double-click to copy">{USER_NAME}</span>
                                        <span class="placeholder-item" data-placeholder="{FIRST_NAME}" title="Double-click to copy">{FIRST_NAME}</span>
                                        <span class="placeholder-item" data-placeholder="{LAST_NAME}" title="Double-click to copy">{LAST_NAME}</span>
                                        <span class="placeholder-item" data-placeholder="{UNSUBSCRIBE_URL}" title="Double-click to copy">{UNSUBSCRIBE_URL}</span>
                                        <span class="placeholder-item" data-placeholder="{CITY}" title="Double-click to copy">{CITY}</span>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Ngày bắt đầu gửi</th>
                                <td>
                                    <input type="datetime-local" name="start_date" class="regular-text"
                                        value="<?php echo $edit_campaign && $edit_campaign->start_date ? date('Y-m-d\TH:i', strtotime($edit_campaign->start_date)) : ''; ?>">
                                    <p class="description">Thời gian bắt đầu gửi email (để trống sẽ gửi ngay lập tức)</p>
                                </td>
                            </tr>
                            <?php if ($edit_campaign): ?>
                                <tr>
                                    <th scope="row">Campaign Status</th>
                                    <td>
                                        <select name="campaign_status" class="regular-text">
                                            <option value="active" <?php selected($edit_campaign->status, 'active'); ?>>Active</option>
                                            <option value="draft" <?php selected($edit_campaign->status, 'draft'); ?>>Draft</option>
                                            <option value="paused" <?php selected($edit_campaign->status, 'paused'); ?>>Paused</option>
                                            <option value="completed" <?php selected($edit_campaign->status, 'completed'); ?>>Completed</option>
                                        </select>
                                        <p class="description">Current status of this campaign</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>

                        <p class="submit">
                            <input type="submit" class="button-primary" value="<?php echo $edit_campaign ? 'Update Campaign' : 'Create Campaign'; ?>">
                            <?php if ($edit_campaign): ?>
                                <a href="<?php echo admin_url('admin.php?page=email-campaigns'); ?>" class="button button-secondary">Cancel</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <?php if (!$edit_campaign): ?>
                    <!-- Existing Campaigns -->
                    <div class="campaign-section">
                        <h2>Existing Campaigns</h2>
                        <?php if (!empty($campaigns)): ?>
                            <table class="wp-list-table widefat fixed striped campaigns-table">
                                <thead>
                                    <tr>
                                        <th>Campaign Name</th>
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
                                                    onclick="return confirm('Are you sure you want to delete this campaign? This action cannot be undone.')">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No campaigns created yet. Create your first campaign above to get started!</p>
                        <?php endif; ?>
                    </div>

                    <!-- Email List Section - Show when not editing -->
                    <div id="imported-email-list" class="campaign-section">
                        <h2>Imported Email List</h2>
                        <?php $this->render_email_list(); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }

    /**
     * Render email list with filters
     */
    private function render_email_list()
    {
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
        $campaigns = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}mail_marketing_campaigns ORDER BY name");

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

                    <label for="search_email">Search Email:</label>
                    <input type="text" name="search_email" id="search_email" value="<?php echo esc_attr($search_email); ?>" placeholder="Enter email to search">

                    <label for="search_phone">Search Phone:</label>
                    <input type="text" name="search_phone" id="search_phone" value="<?php echo esc_attr($search_phone); ?>" placeholder="Enter phone to search">

                    <input type="submit" class="button button-primary" value="Filter">
                    <a href="<?php echo admin_url('admin.php?page=email-campaigns' . (isset($_GET['edit']) ? '&edit=' . intval($_GET['edit']) : '')); ?>&filter=1"
                        class="button">Clear Filters</a>
                </div>
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
                            <td><?php echo $email->id; ?></td>
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
                    $base_url = admin_url('admin.php?page=email-campaigns');
                    if (isset($_GET['edit'])) {
                        $base_url .= '&edit=' . intval($_GET['edit']);
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
                        $base_url .= '&' . implode('&', $filter_params);
                    }
                    ?>

                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo number_format($total_records); ?> items</span>
                        <span class="pagination-links">
                            <?php if ($current_page > 1): ?>
                                <a href="<?php echo $base_url . '&email_page=1'; ?>" class="first-page button">«</a>
                                <a href="<?php echo $base_url . '&email_page=' . ($current_page - 1); ?>" class="prev-page button">‹</a>
                            <?php endif; ?>

                            <span class="paging-input">
                                <span class="tablenav-paging-text">
                                    <?php echo $current_page; ?> of <span class="total-pages"><?php echo $total_pages; ?></span>
                                </span>
                            </span>

                            <?php if ($current_page < $total_pages): ?>
                                <a href="<?php echo $base_url . '&email_page=' . ($current_page + 1); ?>" class="next-page button">›</a>
                                <a href="<?php echo $base_url . '&email_page=' . $total_pages; ?>" class="last-page button">»</a>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <p>No emails found matching the selected filters.</p>
<?php endif;
    }

    /**
     * Render campaign options for select dropdown
     */
    private function render_campaign_options()
    {
        global $wpdb;

        $campaigns = $wpdb->get_results("SELECT id, name, status FROM {$wpdb->prefix}mail_marketing_campaigns ORDER BY created_at DESC");

        if (empty($campaigns)) {
            echo '<option value="">No campaigns found</option>';
            return;
        }

        foreach ($campaigns as $campaign) {
            $status_label = '';
            switch ($campaign->status) {
                case 'draft':
                    $status_label = ' (Draft)';
                    break;
                case 'active':
                    $status_label = ' (Active)';
                    break;
                case 'paused':
                    $status_label = ' (Paused)';
                    break;
                case 'completed':
                    $status_label = ' (Completed)';
                    break;
            }

            echo '<option value="' . esc_attr($campaign->id) . '">' . esc_html($campaign->name . $status_label) . '</option>';
        }
    }

    /**
     * Display admin notices for import results
     */
    private function display_admin_notices()
    {
        if (isset($_GET['success']) && $_GET['success'] == '1') {
            $imported = isset($_GET['imported']) ? intval($_GET['imported']) : 0;
            $skipped = isset($_GET['skipped']) ? intval($_GET['skipped']) : 0;
            $new_campaign = isset($_GET['new_campaign']) ? true : false;

            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>Success!</strong> Import completed successfully. Imported ' . $imported . ' records';
            if ($skipped > 0) {
                echo ', skipped ' . $skipped . ' duplicates';
            }
            echo '.';

            if ($new_campaign) {
                echo '<br><strong>Note:</strong> Please complete your campaign details below (email subject, content, template) before sending emails.';
            }
            echo '</p></div>';
        }

        if (isset($_GET['db_updated']) && $_GET['db_updated'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>Database Updated!</strong> Database tables and columns have been successfully updated. Campaign management features are now available.';
            echo '</p></div>';
        }

        // Campaign action notifications
        if (isset($_GET['campaign_created']) && $_GET['campaign_created'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>Success!</strong> Campaign created successfully.';
            echo '</p></div>';
        }

        if (isset($_GET['campaign_updated']) && $_GET['campaign_updated'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>Success!</strong> Campaign updated successfully.';
            echo '</p></div>';
        }

        if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>Success!</strong> Campaign update status to draft successfully.';
            echo '</p></div>';
        }

        if (isset($_GET['sent'])) {
            $sent = intval($_GET['sent']);
            $failed = isset($_GET['failed']) ? intval($_GET['failed']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>Email Campaign Sent!</strong> Successfully sent to ' . $sent . ' contacts.';
            if ($failed > 0) {
                echo ' Failed to send to ' . $failed . ' contacts.';
            }
            echo '</p></div>';
        }

        if (isset($_GET['error'])) {
            $error_type = sanitize_text_field($_GET['error']);
            $message = isset($_GET['message']) ? urldecode($_GET['message']) : '';

            echo '<div class="notice notice-error is-dismissible"><p>';
            echo '<strong>Error:</strong> ';

            switch ($error_type) {
                case 'no_file':
                    echo 'Please select a file to import.';
                    break;
                case 'invalid_format':
                    echo 'Invalid file format. Please use .xlsx, .xls, or .csv files.';
                    break;
                case 'import_failed':
                    echo !empty($message) ? $message : 'Import failed due to an unknown error.';
                    break;
                case 'campaign_not_found':
                    echo 'Campaign not found.';
                    break;
                case 'no_contacts':
                    echo 'No contacts found in this campaign.';
                    break;
                case 'delete_failed':
                    echo 'Failed to delete campaign.';
                    break;
                case 'create_failed':
                    echo 'Failed to create campaign.';
                    break;
                case 'update_failed':
                    echo 'Failed to update campaign.';
                    break;
                default:
                    echo 'An unknown error occurred.';
            }
            echo '</p></div>';
        }
    }

    /**
     * Display database statistics
     */
    private function display_stats()
    {
        global $wpdb;

        $total = $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing");
        $active = $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing WHERE status = 0 AND is_deleted = 0 AND is_unsubscribed = 0");
        $sent = $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing WHERE status = 1");
        $delete = $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing WHERE is_deleted = 1");
        $unsubscribed = $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing WHERE is_unsubscribed = 1");
        $opened = $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing WHERE opened_at IS NOT NULL");

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Status</th><th>Count</th></tr></thead>';
        echo '<tbody>';
        echo '<tr><td>Total Records</td><td>' . number_format($total) . '</td></tr>';
        echo '<tr><td>Pending (Status 0)</td><td>' . number_format($active) . '</td></tr>';
        echo '<tr><td>Sent (Status 1)</td><td>' . number_format($sent) . '</td></tr>';
        echo '<tr><td>Opened Emails</td><td>' . number_format($opened) . '</td></tr>';
        echo '<tr><td>Deleted (is_deleted = 1)</td><td>' . number_format($delete) . '</td></tr>';
        echo '<tr><td>Unsubscribed (is_unsubscribed = 1)</td><td>' . number_format($unsubscribed) . '</td></tr>';
        echo '</tbody></table>';

        // Add recent opened emails section
        echo '<div style="margin-top: 30px;">';
        echo '<h3>Recent Email Opens</h3>';
        echo '<p>Recently opened emails:</p>';

        $recent_opens = $wpdb->get_results("
            SELECT email, name, phone, opened_at 
            FROM {$wpdb->prefix}mail_marketing 
            WHERE opened_at IS NOT NULL 
            ORDER BY opened_at DESC 
            LIMIT 20
        ");

        if (!empty($recent_opens)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Email</th><th>Name</th><th>Phone</th><th>Opened At</th></tr></thead>';
            echo '<tbody>';
            foreach ($recent_opens as $open) {
                echo '<tr>';
                echo '<td>' . esc_html($open->email) . '</td>';
                echo '<td>' . esc_html($open->name ?: 'N/A') . '</td>';
                echo '<td><a href="tel:' . esc_html($open->phone) . '">' . esc_html($open->phone ?: 'N/A') . '</a></td>';
                echo '<td>' . esc_html($open->opened_at) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No opened emails yet.</p>';
        }

        echo '</div>';

        // Add unsubscribe management section
        echo '<div style="margin-top: 30px;">';
        echo '<h3>Unsubscribe Management</h3>';
        echo '<p>Recent unsubscribed users:</p>';

        $recent_unsubscribed = $wpdb->get_results("
            SELECT email, name, updated_at 
            FROM {$wpdb->prefix}mail_marketing 
            WHERE is_unsubscribed = 1 
            ORDER BY updated_at DESC 
            LIMIT 10
        ");

        if (!empty($recent_unsubscribed)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Email</th><th>Name</th><th>Unsubscribed At</th></tr></thead>';
            echo '<tbody>';
            foreach ($recent_unsubscribed as $user) {
                echo '<tr>';
                echo '<td>' . esc_html($user->email) . '</td>';
                echo '<td>' . esc_html($user->name ?: 'N/A') . '</td>';
                echo '<td>' . esc_html($user->updated_at) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No recent unsubscribed users.</p>';
        }

        echo '</div>';
    }

    /**
     * Handle file upload
     */
    public function handle_file_upload()
    {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        if (!wp_verify_nonce($_POST['mmi_nonce'], 'mmi_upload_nonce')) {
            wp_die('Security check failed');
        }

        if (empty($_FILES['import_file']['tmp_name'])) {
            wp_redirect(admin_url('tools.php?page=mail-marketing-importer&error=no_file'));
            exit;
        }

        $file = $_FILES['import_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, ['xlsx', 'xls', 'csv'])) {
            wp_redirect(admin_url('tools.php?page=mail-marketing-importer&error=invalid_format'));
            exit;
        }

        // Check if Enhanced_Excel_Reader class exists
        if (!class_exists('Enhanced_Excel_Reader')) {
            wp_redirect(admin_url('tools.php?page=mail-marketing-importer&error=import_failed&message=' . urlencode('Enhanced_Excel_Reader class not found')));
            exit;
        }

        // Process campaign selection/creation
        $campaign_id = null;

        if (isset($_POST['campaign_option'])) {
            if ($_POST['campaign_option'] === 'existing' && !empty($_POST['existing_campaign'])) {
                $campaign_id = intval($_POST['existing_campaign']);

                // Verify campaign exists
                $campaign_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing_campaigns WHERE id = %d",
                    $campaign_id
                ));

                if (!$campaign_exists) {
                    wp_redirect(admin_url('tools.php?page=mail-marketing-importer&error=import_failed&message=' . urlencode('Selected campaign does not exist')));
                    exit;
                }
            } elseif ($_POST['campaign_option'] === 'new' && !empty($_POST['new_campaign_name'])) {
                $campaign_name = sanitize_text_field($_POST['new_campaign_name']);

                // Create new campaign with minimal info - details can be added later
                $result = $wpdb->insert(
                    $wpdb->prefix . 'mail_marketing_campaigns',
                    array(
                        'name' => $campaign_name,
                        'description' => '',
                        'email_template' => 'default.html',
                        'status' => 'active',
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%s')
                );

                if ($result !== false) {
                    $campaign_id = $wpdb->insert_id;
                } else {
                    wp_redirect(admin_url('tools.php?page=mail-marketing-importer&error=import_failed&message=' . urlencode('Failed to create campaign')));
                    exit;
                }
            }
        }

        // Process the file
        try {
            $excel_reader = new Enhanced_Excel_Reader();
            $import_options = $_POST;
            $import_options['campaign_id'] = $campaign_id; // Add campaign ID to import options

            $result = $excel_reader->import_file($file['tmp_name'], $file_ext, $import_options);

            if ($result['success']) {
                $imported = isset($result['imported']) ? $result['imported'] : 0;
                $skipped = isset($result['skipped']) ? $result['skipped'] : 0;
                wp_redirect(admin_url('admin.php?page=email-campaigns&edit=' . $campaign_id . '&success=1&imported=' . $imported . '&skipped=' . $skipped . '&new_campaign=1'));
            } else {
                wp_redirect(admin_url('tools.php?page=mail-marketing-importer&error=import_failed&message=' . urlencode($result['message'])));
            }
        } catch (Exception $e) {
            wp_redirect(admin_url('tools.php?page=mail-marketing-importer&error=import_failed&message=' . urlencode($e->getMessage())));
        }

        exit;
    }

    /**
     * Handle AJAX request to read file headers
     */
    public function handle_read_file_headers()
    {
        // Security check
        if (!wp_verify_nonce($_POST['nonce'], 'mmi_import_nonce')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }

        if (empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error('No file uploaded');
        }

        $file = $_FILES['file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, ['xlsx', 'xls', 'csv'])) {
            wp_send_json_error('Invalid file format. Please use .xlsx, .xls, or .csv files.');
        }

        try {
            $excel_reader = new Enhanced_Excel_Reader();
            $headers = $excel_reader->read_file_headers($file['tmp_name'], $file_ext);

            if (empty($headers)) {
                wp_send_json_error('Could not read file headers. Please check your file format.');
            }

            // Return headers with auto-suggestion mapping
            $suggested_mapping = $this->suggest_column_mapping($headers);

            wp_send_json_success(array(
                'headers' => $headers,
                'suggested_mapping' => $suggested_mapping,
                'file_info' => array(
                    'name' => $file['name'],
                    'size' => $this->format_file_size($file['size']),
                    'type' => $file_ext
                )
            ));
        } catch (Exception $e) {
            wp_send_json_error('Error reading file: ' . $e->getMessage());
        }
    }

    /**
     * Suggest column mapping based on common patterns
     */
    private function suggest_column_mapping($headers)
    {
        $mapping = array();

        foreach ($headers as $index => $header) {
            $header_lower = strtolower(trim($header));

            // Email patterns
            if (preg_match('/email|e-mail|mail/', $header_lower)) {
                $mapping['email_column'] = $index;
            }
            // First name patterns
            elseif (preg_match('/first.*name|fname|firstname/', $header_lower)) {
                $mapping['first_name_column'] = $index;
            }
            // Last name patterns
            elseif (preg_match('/last.*name|lname|lastname|surname/', $header_lower)) {
                $mapping['last_name_column'] = $index;
            }
            // Full name patterns
            elseif (preg_match('/^name$|full.*name|customer.*name|contact.*name/', $header_lower)) {
                $mapping['name_column'] = $index;
            }
            // Phone patterns
            elseif (preg_match('/phone|tel|mobile|cell|number/', $header_lower)) {
                $mapping['phone_column'] = $index;
            }
            // Address patterns
            elseif (preg_match('/address|street/', $header_lower)) {
                $mapping['address_column'] = $index;
            }
            // City patterns
            elseif (preg_match('/city|town/', $header_lower)) {
                $mapping['city_column'] = $index;
            }
            // State patterns
            elseif (preg_match('/state|province|region/', $header_lower)) {
                $mapping['state_column'] = $index;
            }
            // Zip code patterns
            elseif (preg_match('/zip|postal|postcode|zip.*code/', $header_lower)) {
                $mapping['zip_code_column'] = $index;
            }
        }

        return $mapping;
    }

    /**
     * Format file size for display
     */
    private function format_file_size($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Get email templates options for select dropdown
     */
    private function get_email_templates_options()
    {
        return $this->get_email_templates_options_with_selected('default.html');
    }

    /**
     * Get email templates options with specific selection
     */
    private function get_email_templates_options_with_selected($selected_template)
    {
        // lấy danh sách file .html trong thư mục html-template
        $template_dir = MMI_PLUGIN_PATH . 'html-template/';
        $templates = glob($template_dir . '*.html');
        $options = '';
        foreach ($templates as $template_file) {
            $template_name = basename($template_file);
            $selected = ($template_name === $selected_template) ? ' selected' : '';
            $options .= '<option value="' . esc_attr($template_name) . '"' . $selected . '>' . esc_html($template_name) . '</option>';
        }

        return $options;
    }

    /**
     * Load and process email template
     */
    private function load_email_template($template_name, $data = array())
    {
        $templates_dir = MMI_PLUGIN_PATH . 'html-template/';
        $template_path = $templates_dir . $template_name;

        // Fallback to default template if specified template doesn't exist
        if (!is_file($template_path)) {
            $template_path = $templates_dir . 'default.html';
        }

        // Load template content
        $template_content = file_get_contents($template_path);

        if ($template_content === false) {
            return '<p>Error loading email template.</p>';
        }

        // Replace placeholders
        $placeholders = array(
            '{FIRST_NAME}' => $data['first_name'] ?? 'Valued Customer',
            '{LAST_NAME}' => $data['last_name'] ?? 'Valued Customer',
            '{USER_NAME}' => $data['user_name'] ?? 'Valued Customer',
            '{USER_EMAIL}' => $data['user_email'] ?? '',
            '{SITE_NAME}' => get_bloginfo('name'),
            '{SITE_URL}' => home_url(),
            '{UNSUBSCRIBE_URL}' => $data['unsubscribe_url'] ?? '#',
            '{CURRENT_DATE}' => date('F j, Y'),
            '{CURRENT_YEAR}' => date('Y')
        );

        foreach ($placeholders as $placeholder => $value) {
            $template_content = str_replace($placeholder, $value, $template_content);
        }

        return $template_content;
    }

    /**
     * Handle AJAX import progress
     */
    public function handle_import_ajax()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'mmi_import_nonce')) {
            wp_die('Security check failed');
        }

        // This can be used for progress updates during large imports
        wp_send_json_success(array('progress' => 100, 'message' => 'Import completed'));
    }

    /**
     * Test import functionality
     */
    public function test_import()
    {
        global $wpdb;

        // Simple test to check if everything is working
        $result = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}mail_marketing'");

        if (empty($result)) {
            echo '<div style="padding: 20px; background: #f0f0f0; margin: 20px;"><h2>Debug Info</h2>';
            echo '<p>Table ' . $wpdb->prefix . 'mail_marketing does not exist. Please re-activate the plugin for table creation...</p>';
            echo '</div>';
        } else {
            echo '<div style="padding: 20px; background: #f0f0f0; margin: 20px;"><h2>Debug Info</h2>';
            echo '<p>Table ' . $wpdb->prefix . 'mail_marketing exists!</p>';

            // Show table structure
            $structure = $wpdb->get_results("DESCRIBE {$wpdb->prefix}mail_marketing");
            echo '<h3>Table Structure:</h3><pre>';
            print_r($structure);
            echo '</pre>';

            // Show sample data
            $sample_data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mail_marketing LIMIT 5");
            echo '<h3>Sample Data:</h3><pre>';
            print_r($sample_data);
            echo '</pre>';

            echo '</div>';
        }

        // Test file reading
        $test_file = MMI_PLUGIN_PATH . 'test-data.csv';
        if (is_file($test_file)) {
            echo '<div style="padding: 20px; background: #e8f4f8; margin: 20px;"><h2>Test File Import</h2>';
            echo '<p>Test file exists: ' . $test_file . '</p>';
            echo '<p>File size: ' . filesize($test_file) . ' bytes</p>';
            echo '<p>File content:</p><pre>' . htmlspecialchars(file_get_contents($test_file)) . '</pre>';

            try {
                $excel_reader = new Enhanced_Excel_Reader();
                $result = $excel_reader->import_file($test_file, 'csv', array(
                    'email_column' => 'email',
                    'first_name_column' => 'first_name',
                    'last_name_column' => 'last_name',
                    'name_column' => 'name',
                    'phone_column' => 'phone',
                    'address_column' => 'address',
                    'city_column' => 'city',
                    'state_column' => 'state',
                    'zip_code_column' => 'zip_code',
                    'skip_header' => true
                ));

                echo '<h3>Import Result:</h3><pre>';
                print_r($result);
                echo '</pre>';
            } catch (Exception $e) {
                echo '<p>Error: ' . $e->getMessage() . '</p>';
                echo '<p>Stack trace:</p><pre>' . $e->getTraceAsString() . '</pre>';
            }

            echo '</div>';
        } else {
            echo '<div style="padding: 20px; background: #ffe6e6; margin: 20px;"><h2>Test File Not Found</h2>';
            echo '<p>Test file does not exist: ' . $test_file . '</p>';
            echo '<p>Plugin path: ' . MMI_PLUGIN_PATH . '</p>';
            echo '<p>Files in plugin directory:</p><ul>';

            if (is_dir(MMI_PLUGIN_PATH)) {
                $files = scandir(MMI_PLUGIN_PATH);
                foreach ($files as $file) {
                    if ($file != '.' && $file != '..') {
                        echo '<li>' . $file . '</li>';
                    }
                }
            }
            echo '</ul></div>';
        }

        exit;
    }

    /**
     * Test unsubscribe functionality
     */
    public function test_unsubscribe()
    {
        global $wpdb;

        echo '<div style="padding: 20px; background: #f0f0f0; margin: 20px;"><h2>Test Unsubscribe Link Generator</h2>';

        // Get a sample email record
        $sample_record = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}mail_marketing WHERE is_deleted = 0 AND is_unsubscribed = 0 LIMIT 1");

        if (!$sample_record) {
            echo '<p>No active email records found. Please import some data first.</p>';
            echo '</div>';
            exit;
        }

        // Generate unsubscribe token
        $unsubscribe_token = md5($sample_record->email . '|' . $sample_record->id . '|' . wp_salt());
        $unsubscribe_url = home_url() . '/wp-content/themes/marketing/api/v1/unsubscribe/?token=' . $unsubscribe_token . '&email=' . urlencode($sample_record->email) . '&id=' . $sample_record->id;

        echo '<p><strong>Sample Email Record:</strong></p>';
        echo '<ul>';
        echo '<li><strong>ID:</strong> ' . $sample_record->id . '</li>';
        echo '<li><strong>Email:</strong> ' . $sample_record->email . '</li>';
        echo '<li><strong>Name:</strong> ' . ($sample_record->name ?: 'N/A') . '</li>';
        echo '<li><strong>Status:</strong> ' . $sample_record->status . '</li>';
        echo '<li><strong>Is Deleted:</strong> ' . $sample_record->is_deleted . '</li>';
        echo '<li><strong>Is Unsubscribed:</strong> ' . $sample_record->is_unsubscribed . '</li>';
        echo '</ul>';

        echo '<p><strong>Generated Unsubscribe Link:</strong></p>';
        echo '<div style="background: #fff; padding: 10px; border: 1px solid #ddd; word-break: break-all;">';
        echo '<a href="' . $unsubscribe_url . '" target="_blank">' . $unsubscribe_url . '</a>';
        echo '</div>';

        echo '<p><strong>Token Details:</strong></p>';
        echo '<ul>';
        echo '<li><strong>MD5 Token:</strong> ' . $unsubscribe_token . '</li>';
        echo '<li><strong>Token Source:</strong> ' . $sample_record->email . '|' . $sample_record->id . '|' . wp_salt() . '</li>';
        echo '<li><strong>Token Length:</strong> ' . strlen($unsubscribe_token) . ' characters</li>';
        echo '</ul>';

        echo '<p><strong>How to test:</strong></p>';
        echo '<ol>';
        echo '<li>Click the unsubscribe link above</li>';
        echo '<li>Confirm the unsubscribe action</li>';
        echo '<li>Check back in the admin panel to see the record marked as unsubscribed (is_unsubscribed = 1)</li>';
        echo '</ol>';

        echo '</div>';

        exit;
    }

    /**
     * Handle campaign creation
     */
    public function handle_create_campaign()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['campaign_nonce'], 'campaign_action')) {
            wp_die('Unauthorized access');
        }

        global $wpdb;
        $name = sanitize_text_field($_POST['campaign_name']);
        $description = sanitize_textarea_field($_POST['campaign_description']);
        $email_subject = sanitize_text_field($_POST['email_subject']);
        $email_content = wp_kses_post($_POST['email_content']);
        $email_template = sanitize_text_field($_POST['email_template'] ?? 'default.html');
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;

        // Convert datetime-local format to MySQL datetime format
        if ($start_date) {
            $start_date = date('Y-m-d H:i:s', strtotime($start_date));
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'mail_marketing_campaigns',
            array(
                'name' => $name,
                'description' => $description,
                'email_subject' => $email_subject,
                'email_content' => $email_content,
                'email_template' => $email_template,
                'start_date' => $start_date,
                'status' => 'active',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$result) {
            die('Campaign creation failed: ' . $wpdb->last_error);
        }

        if ($result) {
            wp_redirect(admin_url('admin.php?page=email-campaigns&edit=' . $wpdb->insert_id . '&campaign_created=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=email-campaigns&error=create_failed'));
        }
        exit;
    }

    /**
     * Handle campaign update
     */
    public function handle_update_campaign()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['campaign_nonce'], 'campaign_action')) {
            wp_die('Unauthorized access');
        }

        global $wpdb;
        $campaign_id = intval($_POST['campaign_id']);
        $name = sanitize_text_field($_POST['campaign_name']);
        $description = sanitize_textarea_field($_POST['campaign_description']);
        $email_subject = sanitize_text_field($_POST['email_subject']);
        $email_content = wp_kses_post($_POST['email_content']);
        $email_template = sanitize_text_field($_POST['email_template'] ?? 'default.html');
        $status = sanitize_text_field($_POST['campaign_status']);
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;

        // Convert datetime-local format to MySQL datetime format
        if ($start_date) {
            $start_date = date('Y-m-d H:i:s', strtotime($start_date));
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'mail_marketing_campaigns',
            array(
                'name' => $name,
                'description' => $description,
                'email_subject' => $email_subject,
                'email_content' => $email_content,
                'email_template' => $email_template,
                'start_date' => $start_date,
                'status' => $status,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $campaign_id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            wp_redirect(admin_url('admin.php?page=email-campaigns&edit=' . $campaign_id . '&campaign_updated=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=email-campaigns&edit=' . $campaign_id . '&error=update_failed'));
        }
        exit;
    }

    /**
     * Handle campaign deletion
     */
    public function handle_delete_campaign()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        $campaign_id = intval($_GET['campaign_id']);
        if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_campaign_' . $campaign_id)) {
            wp_die('Security check failed');
        }

        global $wpdb;

        // tạm thời không xóa campaign, chỉ cập nhật trạng thái
        if (1 > 2) {
            // Delete the campaign
            $result = $wpdb->delete(
                $wpdb->prefix . 'mail_marketing_campaigns',
                array('id' => $campaign_id),
                array('%d')
            );

            // Set all contacts in this campaign to have null campaign_id
            $wpdb->update(
                $wpdb->prefix . 'mail_marketing',
                array('campaign_id' => null),
                array('campaign_id' => $campaign_id),
                array('%s'),
                array('%d')
            );
        } else {
            // Update campaign status to inactive
            $result = $wpdb->update(
                $wpdb->prefix . 'mail_marketing_campaigns',
                array(
                    'status' => 'inactive',
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $campaign_id),
                array('%s', '%s'),
                array('%d')
            );
        }

        if ($result) {
            wp_redirect(admin_url('admin.php?page=email-campaigns&deleted=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=email-campaigns&error=delete_failed'));
        }
        exit;
    }

    /**
     * Handle AJAX toggle email status
     */
    public function handle_toggle_email_status()
    {
        // Security check
        if (!wp_verify_nonce($_POST['nonce'], 'mmi_import_nonce')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }

        $email_id = intval($_POST['email_id']);
        $field = sanitize_text_field($_POST['field']);
        $current_value = intval($_POST['current_value']);

        // Validate field name
        $allowed_fields = array('status', 'is_deleted', 'is_unsubscribed');
        if (!in_array($field, $allowed_fields)) {
            wp_send_json_error('Invalid field');
        }

        global $wpdb;

        // Toggle the value (0 becomes 1, 1 becomes 0)
        $new_value = $current_value == 1 ? 0 : 1;

        // Update the database
        $result = $wpdb->update(
            $wpdb->prefix . 'mail_marketing',
            array($field => $new_value, 'updated_at' => current_time('mysql')),
            array('id' => $email_id),
            array('%d', '%s'),
            array('%d')
        );

        if ($result !== false) {
            wp_send_json_success(array(
                'new_value' => $new_value,
                'display_text' => $this->get_display_text($field, $new_value)
            ));
        } else {
            wp_send_json_error('Failed to update database');
        }
    }

    /**
     * Get display text for field values
     */
    private function get_display_text($field, $value)
    {
        switch ($field) {
            case 'status':
                return $value == 1 ? 'Sent' : 'Pending';
            case 'is_deleted':
                return $value == 1 ? 'Yes' : 'No';
            case 'is_unsubscribed':
                return $value == 1 ? 'Yes' : 'No';
            default:
                return $value;
        }
    }
}
