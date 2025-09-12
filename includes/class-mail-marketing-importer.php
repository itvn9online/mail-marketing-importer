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

        // Bulk unsubscribe action
        add_action('admin_post_bulk_unsubscribe_email', array($this, 'handle_bulk_unsubscribe'));
        add_action('wp_ajax_bulk_unsubscribe_email', array($this, 'handle_bulk_unsubscribe_ajax'));

        // Zoho API actions
        add_action('wp_ajax_mmi_save_zoho_config', array($this, 'handle_save_zoho_config'));
        add_action('wp_ajax_mmi_save_zoho_scope', array($this, 'handle_save_zoho_scope'));
        // add_action('wp_ajax_mmi_get_zoho_config', array($this, 'handle_get_zoho_config'));
        add_action('wp_ajax_mmi_zoho_fetch_failed_emails', array($this, 'handle_zoho_fetch_failed_emails'));

        // Zoho token cache management
        add_action('wp_ajax_mmi_clear_zoho_token_cache', array($this, 'clear_zoho_token_cache'));
        add_action('wp_ajax_mmi_get_zoho_token_cache_info', array($this, 'get_zoho_token_cache_info'));

        // Zoho OAuth callback (no priv needed for OAuth callback)
        // add_action('wp_ajax_nopriv_mmi_zoho_callback', array($this, 'handle_zoho_callback'));
        add_action('wp_ajax_mmi_zoho_callback', array($this, 'handle_zoho_callback'));
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
            'nonce' => wp_create_nonce('mmi_import_nonce'),
            'home_url' => home_url()
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
        $total_sent = 0;
        $total_unsubscribed = 0;
        $total_deleted = 0;
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

                // Get total sent for this campaign
                $total_sent = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing WHERE campaign_id = %d AND status = '1'",
                    $campaign_id
                ));

                // Get total unsubscribed for this campaign
                $total_unsubscribed = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing WHERE campaign_id = %d AND is_unsubscribed = '1'",
                    $campaign_id
                ));

                // Get total deleted for this campaign
                $total_deleted = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(id) FROM {$wpdb->prefix}mail_marketing WHERE campaign_id = %d AND is_deleted = '1'",
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
                <?php if (isset($_GET['edit'])): ?>
                    <div class="campaign-stat-box">
                        <div class="campaign-stat-number"><?php echo number_format($total_contacts); ?></div>
                        <div class="campaign-stat-label">Total Contacts</div>
                    </div>
                    <div class="campaign-stat-box">
                        <div class="campaign-stat-number"><?php echo number_format($total_sent); ?></div>
                        <div class="campaign-stat-label">Total Sent</div>
                    </div>
                    <div class="campaign-stat-box">
                        <div class="campaign-stat-number"><?php echo number_format($total_unsubscribed); ?></div>
                        <div class="campaign-stat-label">Total Unsubscribed</div>
                    </div>
                    <div class="campaign-stat-box">
                        <div class="campaign-stat-number"><?php echo number_format($total_deleted); ?></div>
                        <div class="campaign-stat-label">Total Deleted</div>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <a href="<?php echo admin_url('tools.php?page=email-campaigns'); ?>" class="button button-secondary">Existing campaign</a>

                <a href="<?php echo admin_url('tools.php?page=email-campaigns&list=true'); ?>" class="button button-secondary <?php echo (isset($_GET['list']) || isset($_GET['filter'])) ? 'bold redcolor' : ''; ?>">Email list</a>

                <a href="<?php echo admin_url('tools.php?page=email-campaigns&add=true'); ?>" class="button button-primary <?php echo isset($_GET['add']) ? 'bold' : ''; ?>">Add new campaign</a>

                <a href="<?php echo admin_url('tools.php?page=email-campaigns&zoho-api=true'); ?>" class="button button-secondary <?php echo isset($_GET['zoho-api']) ? 'bold redcolor' : ''; ?>">Zoho API</a>
            </div>

            <div class="campaign-container">
                <?php
                if (isset($_GET['add']) || isset($_GET['edit'])) {
                    include __DIR__ . '/create-edit-campaign.php';
                } else if (isset($_GET['details'])) {
                    include __DIR__ . '/email-details.php';
                } else if (isset($_GET['zoho-api'])) {
                    include __DIR__ . '/zoho-api.php';
                } else if (isset($_GET['list']) || isset($_GET['filter'])) {
                    include __DIR__ . '/email-list.php';
                } else {
                    include __DIR__ . '/existing-campaigns.php';
                }
                ?>
            </div>
        </div>
<?php
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
                case 'invalid_email':
                    echo !empty($message) ? $message : 'Please enter a valid email address.';
                    break;
                case 'unsubscribe_failed':
                    echo !empty($message) ? $message : 'Failed to unsubscribe email. Please try again.';
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
            wp_die('Security check failed ' . __FUNCTION__);
        }

        if (!wp_verify_nonce($_POST['mmi_nonce'], 'mmi_upload_nonce')) {
            wp_die('Security check failed ' . __FUNCTION__);
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
            wp_send_json_error('Security check failed ' . __FUNCTION__);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
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
            '{EMAIL_URL}' => home_url(),
            '{UNSUBSCRIBE_URL}' => $data['unsubscribe_url'] ?? '#',
            '{CURRENT_DATE}' => date_i18n('F j, Y'),
            '{CURRENT_YEAR}' => date_i18n('Y')
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
            wp_die('Security check failed ' . __FUNCTION__);
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
            wp_die('Security check failed ' . __FUNCTION__);
        }

        global $wpdb;
        $name = sanitize_text_field($_POST['campaign_name']);
        $description = sanitize_textarea_field($_POST['campaign_description']);
        $email_subject = sanitize_text_field($_POST['email_subject']);
        $email_url = esc_url_raw($_POST['email_url'] ?? '');
        $email_content = wp_kses_post($_POST['email_content']);
        $email_template = sanitize_text_field($_POST['email_template'] ?? 'default.html');
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;

        // Convert datetime-local format to MySQL datetime format
        if ($start_date) {
            $start_date = date_i18n('Y-m-d H:i:s', strtotime($start_date));
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'mail_marketing_campaigns',
            array(
                'name' => $name,
                'description' => $description,
                'email_subject' => $email_subject,
                'email_url' => $email_url,
                'email_content' => $email_content,
                'email_template' => $email_template,
                'start_date' => $start_date,
                'status' => 'active',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
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
            wp_die('Security check failed ' . __FUNCTION__);
        }

        global $wpdb;
        $campaign_id = intval($_POST['campaign_id']);
        $name = sanitize_text_field($_POST['campaign_name']);
        $description = sanitize_textarea_field($_POST['campaign_description']);
        $email_subject = sanitize_text_field($_POST['email_subject']);
        $email_url = esc_url_raw($_POST['email_url'] ?? '');
        $email_content = wp_kses_post($_POST['email_content']);
        $email_template = sanitize_text_field($_POST['email_template'] ?? 'default.html');
        $status = sanitize_text_field($_POST['campaign_status']);
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;

        // Convert datetime-local format to MySQL datetime format
        if ($start_date) {
            $start_date = date_i18n('Y-m-d H:i:s', strtotime($start_date));
        }

        // Load email content from template if not provided
        if (empty($email_content)) {
            $template_path = MMI_PLUGIN_PATH . 'html-template/' . $email_template;
            if (is_file($template_path)) {
                $email_content = file_get_contents($template_path);

                // Convert email content to single line
                if (function_exists('convert_to_single_line')) {
                    $email_content = convert_to_single_line($email_content);
                }
                // echo $email_content;
                // die(__FILE__ . ':' . __LINE__);
            }
        }

        // 
        $result = $wpdb->update(
            $wpdb->prefix . 'mail_marketing_campaigns',
            array(
                'name' => $name,
                'description' => $description,
                'email_subject' => $email_subject,
                'email_url' => $email_url,
                'email_content' => $email_content,
                'email_template' => $email_template,
                'start_date' => $start_date,
                'status' => $status,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $campaign_id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
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
            wp_die('Security check failed ' . __FUNCTION__);
        }

        $campaign_id = intval($_GET['campaign_id']);
        if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_campaign_' . $campaign_id)) {
            wp_die('Security check failed ' . __FUNCTION__);
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
            wp_send_json_error('Security check failed ' . __FUNCTION__);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
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

    /**
     * Handle bulk unsubscribe email
     */
    public function handle_bulk_unsubscribe()
    {
        global $wpdb;

        // Security checks
        if (!current_user_can('manage_options')) {
            wp_die('Security check failed ' . __FUNCTION__);
        }

        if (!wp_verify_nonce($_POST['bulk_unsubscribe_nonce'], 'bulk_unsubscribe_nonce')) {
            wp_die('Security check failed ' . __FUNCTION__);
        }

        // Validate email input
        $emails = sanitize_email($_POST['unsubscribe_email'] ?? '');
        $emails = explode(',', $emails);
        foreach ($emails as $email) {
            if (empty($email) || !is_email($email)) {
                wp_redirect(admin_url('admin.php?page=email-campaigns&list=true&error=invalid_email&message=' . urlencode('Please enter a valid email address ' . $email)));
                exit;
            }

            // Update all records with this email
            $result = $wpdb->update(
                $wpdb->prefix . 'mail_marketing',
                array(
                    'is_unsubscribed' => '1',
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'is_unsubscribed' => '0',
                    'email' => $email,
                ),
                array('%d', '%s'),
                array('%d', '%s')
            );
        }

        if ($result !== false) {
            $affected_rows = $wpdb->rows_affected;
            wp_redirect(admin_url('admin.php?page=email-campaigns&list=true&bulk_unsubscribed=1&email_unsubscribed=1&affected_rows=' . $affected_rows . '&email=' . urlencode($email)));
        } else {
            wp_redirect(admin_url('admin.php?page=email-campaigns&list=true&error=unsubscribe_failed&message=' . urlencode('Failed to update database')));
        }

        exit;
    }

    /**
     * Handle bulk unsubscribe via AJAX
     */
    public function handle_bulk_unsubscribe_ajax()
    {
        global $wpdb;

        // Security checks
        if (!current_user_can('manage_options')) {
            wp_die(json_encode(['success' => false, 'message' => 'Permission denied']));
        }

        if (!wp_verify_nonce($_POST['bulk_unsubscribe_nonce'] ?? '', 'bulk_unsubscribe_nonce')) {
            wp_die(json_encode(['success' => false, 'message' => 'Security check failed']));
        }

        // Validate email input
        $emails_input = sanitize_email($_POST['unsubscribe_email'] ?? '');
        if (empty($emails_input)) {
            wp_die(json_encode(['success' => false, 'message' => 'Email is required']));
        }

        $emails = explode(',', $emails_input);
        $total_affected = 0;
        $processed_emails = [];
        $errors = [];

        foreach ($emails as $email) {
            $email = trim($email);
            if (empty($email) || !is_email($email)) {
                $errors[] = "Invalid email: $email";
                continue;
            }

            // Update all records with this email
            $result = $wpdb->update(
                $wpdb->prefix . 'mail_marketing',
                array(
                    'is_unsubscribed' => '1',
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'is_unsubscribed' => '0',
                    'email' => $email,
                ),
                array('%d', '%s'),
                array('%d', '%s')
            );

            if ($result !== false) {
                $affected_rows = $wpdb->rows_affected;
                $total_affected += $affected_rows;
                $processed_emails[] = $email;
                // $processed_emails[] = $result;
            } else {
                $errors[] = "Failed to unsubscribe: $email";
            }
        }

        // Return JSON response
        header('Content-Type: application/json');
        wp_die(json_encode([
            'success' => true,
            'message' => 'Bulk unsubscribe completed',
            'affected_rows' => $total_affected,
            'processed_emails' => $processed_emails,
            'errors' => $errors
        ]));
    }

    /**
     * Handle saving Zoho API configuration
     */
    public function handle_save_zoho_config()
    {
        // Sử dụng cùng nonce với JavaScript
        if (!wp_verify_nonce($_POST['security'], 'mmi_import_nonce')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        // Lấy config hiện tại để merge với dữ liệu mới
        $existing_config = get_option('mmi_zoho_config', array(
            'client_id' => '',
            'client_secret' => '',
            'refresh_token' => '',
            'account_id' => '',
        ));

        // Chỉ cập nhật các trường có dữ liệu, giữ nguyên trường trống
        $new_config = array();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');
        // Bỏ account_id từ save config thủ công - sẽ được lưu tự động từ OAuth callback
        // Bỏ refresh_token từ save config thủ công - chỉ được lưu từ OAuth callback

        // Merge dữ liệu: nếu có dữ liệu mới thì dùng, không thì giữ dữ liệu cũ
        $new_config['client_id'] = !empty($client_id) ? $client_id : $existing_config['client_id'];
        $new_config['client_secret'] = !empty($client_secret) ? $client_secret : $existing_config['client_secret'];
        $new_config['refresh_token'] = $existing_config['refresh_token']; // Luôn giữ nguyên từ config cũ
        $new_config['account_id'] = $existing_config['account_id']; // Luôn giữ nguyên từ config cũ (được lưu từ OAuth)

        $result = update_option('mmi_zoho_config', $new_config);

        // Đếm số trường đã được lưu
        $saved_fields = array_filter($new_config, function ($value) {
            return !empty($value);
        });

        $total_fields = count($new_config);
        $filled_fields = count($saved_fields);

        if ($result !== false) {
            wp_send_json_success(array(
                'message' => "Configuration saved successfully! ({$filled_fields}/{$total_fields} fields filled)",
                'config' => $new_config,
                'progress' => round(($filled_fields / $total_fields) * 100)
            ));
        } else {
            wp_send_json_error('Failed to save configuration');
        }
    }

    /**
     * Handle saving selected Zoho OAuth scope
     */
    public function handle_save_zoho_scope()
    {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['security'], 'mmi_import_nonce')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        $scope = sanitize_text_field($_POST['scope']) ?: 'ZohoMail.messages.READ';

        // Validate scope (must be one of the allowed values)
        $allowed_scopes = [
            'ZohoMail.messages.READ',
            'ZohoMail.messages.ALL',
            'ZohoMail.accounts.READ',
            'ZohoMail.accounts.ALL'
        ];

        if (!in_array($scope, $allowed_scopes)) {
            $scope = 'ZohoMail.messages.READ'; // Fallback to default
        }

        // Save scope in transient for 10 minutes (enough time for OAuth flow)
        set_transient('mmi_zoho_selected_scope', $scope, 600);

        wp_send_json_success(array(
            'message' => 'Scope saved for OAuth flow',
            'scope' => $scope
        ));
    }

    /**
     * Get cached Zoho access token or fetch new one if expired
     */
    private function get_zoho_access_token()
    {
        // Lấy cấu hình từ database
        $zoho_config = get_option('mmi_zoho_config', array());

        $client_id = $zoho_config['client_id'] ?? '';
        $client_secret = $zoho_config['client_secret'] ?? '';
        $refresh_token = $zoho_config['refresh_token'] ?? '';

        if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
            return array(
                'success' => false,
                'error' => 'Không có cấu hình Zoho Mail được lưu hoặc cấu hình không đầy đủ'
            );
        }

        // Kiểm tra cache hiện có
        $cached_token = get_transient('mmi_zoho_access_token');

        if ($cached_token && !empty($cached_token)) {
            return array(
                'success' => true,
                'access_token' => $cached_token,
                'from_cache' => true
            );
        }

        // Khởi tạo mảng chứa dữ liệu token
        $token_data = [
            'grant_type' => 'refresh_token',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            // Scope không cần thiết khi dùng refresh_token
            'scope' => 'ZohoMail.messages.READ',
        ];

        // Khởi tạo URI cho yêu cầu
        $uri = 'https://accounts.zoho.com/oauth/v2/token';
        $uri = add_query_arg($token_data, $uri);
        // die($uri);

        // Nếu không có cache hoặc đã hết hạn, lấy token mới
        $token_response = wp_remote_post($uri, array(
            // 'body' => $token_data,
            'body' => [],
            'timeout' => 30
        ));
        // die(print_r($token_response));

        if (is_wp_error($token_response)) {
            return array(
                'success' => false,
                'error' => 'Lỗi khi lấy access token: ' . $token_response->get_error_message()
            );
        }

        $token_body = wp_remote_retrieve_body($token_response);
        $token_data = json_decode($token_body, true);

        if (!isset($token_data['access_token'])) {
            return array(
                'success' => false,
                'error' => 'Không thể lấy access token từ Zoho API'
            );
        }
        // die(print_r($token_data));

        // Lưu token vào cache với thời gian hết hạn
        $access_token = $token_data['access_token'];
        $expires_in = $token_data['expires_in'] ?? 3600; // Default 1 hour if not provided

        // Lưu cache với thời gian hết hạn trước 5 phút để đảm bảo an toàn
        $cache_duration = max(300, $expires_in - 300); // Tối thiểu 5 phút, trừ 5 phút từ thời gian hết hạn thực
        set_transient('mmi_zoho_access_token', $access_token, $cache_duration);
        sleep(1); // Đợi 1 giây để đảm bảo token được lưu trước khi sử dụng

        return array(
            'success' => true,
            'access_token' => $access_token,
            'expires_in' => $expires_in,
            'cache_duration' => $cache_duration,
            'from_cache' => false
        );
    }

    /**
     * Clear cached Zoho access token
     */
    public function clear_zoho_token_cache()
    {
        // Security check
        if (!wp_verify_nonce($_POST['security'], 'mmi_import_nonce')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        delete_transient('mmi_zoho_access_token');

        wp_send_json_success(array(
            'message' => 'Zoho access token cache cleared successfully'
        ));
    }

    /**
     * Get Zoho token cache info
     */
    public function get_zoho_token_cache_info()
    {
        // Security check
        if (!wp_verify_nonce($_POST['security'], 'mmi_import_nonce')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        $cached_token = get_transient('mmi_zoho_access_token');
        $cache_exists = !empty($cached_token);

        // Get remaining cache time
        $cache_timeout = 0;
        if ($cache_exists) {
            // WordPress không cung cấp cách trực tiếp để lấy thời gian còn lại của transient
            // Nên chúng ta chỉ có thể biết token có tồn tại trong cache hay không
            $cache_timeout = 'Unknown (exists in cache)';
        }

        wp_send_json_success(array(
            'cache_exists' => $cache_exists,
            'cache_timeout' => $cache_timeout,
            'token_preview' => $cache_exists ? $cached_token : ''
        ));
    }

    /**
     * Handle fetching failed emails from Zoho
     * https://www.zoho.com/mail/help/api/get-search-emails.html
     */
    public function handle_zoho_fetch_failed_emails()
    {
        // Sử dụng cùng nonce với JavaScript
        if (!wp_verify_nonce($_POST['security'], 'mmi_import_nonce')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Security check failed ' . __FUNCTION__);
            return;
        }

        // Lấy cấu hình từ database
        $zoho_config = get_option('mmi_zoho_config', array());
        $account_id = $zoho_config['account_id'] ?? '';

        if (empty($account_id)) {
            wp_send_json_error('Không có Account ID được lưu trong cấu hình');
            return;
        }

        // Lấy access token từ cache hoặc API
        $token_result = $this->get_zoho_access_token();

        if (!$token_result['success']) {
            wp_send_json_error($token_result['error']);
            return;
        }

        $access_token = $token_result['access_token'];

        // Search for failed delivery emails
        // $search_query = 'subject:("Delivery Status Notification" OR "Undelivered Mail" OR "Mail Delivery Failed" OR "returned mail")';
        // To search for new emails, provide the searchKey as newMails
        $search_query = 'newMails';

        $response = wp_remote_get('https://mail.zoho.com/api/accounts/' . $account_id . '/messages/search?' . http_build_query(array(
            'searchKey' => $search_query,
            // receivedTime (long): Specifies the time before which emails were received. It allows users to filter emails based on their received timestamp. Format: Unix timestamp in milliseconds
            'receivedTime' => ((time() - 7 * DAY_IN_SECONDS) * 1000),
            // start (int): Specifies the starting sequence number of the emails to be retrieved. The default value is 1.
            'start' => 1,
            // limit (int): Allowed values : Min. value: 1 and max. value: 200. The default value is 10.
            'limit' => 200,
        )), array(
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Zoho-oauthtoken ' . $access_token
            ),
            'timeout' => 30
        ));
        // die(print_r($response));

        if (is_wp_error($response)) {
            wp_send_json_error('API request failed: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['status']) && $data['status']['code'] === 200) {
            $messages = $data['data'] ?? array();

            // Thêm thông tin cache vào response
            $response_data = array(
                'messages' => $messages,
                'token_info' => array(
                    'from_cache' => $token_result['from_cache'],
                    'expires_in' => $token_result['expires_in'] ?? null,
                    'cache_duration' => $token_result['cache_duration'] ?? null
                )
            );

            wp_send_json_success($response_data);
        } else {
            $error_msg = json_encode($data);
            wp_send_json_error('API error: ' . $error_msg);
        }
    }

    /**
     * Handle Zoho OAuth callback
     */
    public function handle_zoho_callback()
    {
        include_once __DIR__ . '/zoho_callback.php';
    }
}
