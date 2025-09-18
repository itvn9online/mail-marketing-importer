<?php

/**
 * Google OAuth 2.0 Callback Handler
 * 
 * This file handles the OAuth callback from Google after user authorization
 * It exchanges the authorization code for access token and refresh token
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if we have an authorization code
if (isset($_GET['code'])) {
    $auth_code = sanitize_text_field($_GET['code']);

    // Get Google config
    $google_config = get_option(MMI_GOOGLE_CONFIG, array());
    $client_id = $google_config['client_id'] ?? '';
    $client_secret = $google_config['client_secret'] ?? '';

    if (empty($client_id) || empty($client_secret)) {
        wp_die('<h1>OAuth Configuration Error</h1><p>Google Client ID and Client Secret are required.</p><p><a href="' . admin_url('tools.php?page=email-campaigns&google-workspace=true') . '">Back to Google Workspace Settings</a></p>');
    }

    // Build redirect URI
    $redirect_uri = home_url('/wp-admin/admin-ajax.php?action=mmi_google_callback');

    // Exchange code for tokens
    $token_data = array(
        'code' => $auth_code,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code',
    );

    // Make request to Google OAuth2 token endpoint
    $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
        'body' => $token_data,
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        wp_die('<h1>Token Exchange Error</h1><p>Failed to connect to Google: ' . esc_html($response->get_error_message()) . '</p><p><a href="' . admin_url('tools.php?page=email-campaigns&google-workspace=true') . '">Back to Google Workspace Settings</a></p>');
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['error'])) {
        $error_msg = $data['error_description'] ?? $data['error'] ?? 'Unknown error occurred';
        wp_die('<h1>Token Exchange Error</h1><p>' . esc_html($error_msg) . '</p><p><a href="' . admin_url('tools.php?page=email-campaigns&google-workspace=true') . '">Back to Google Workspace Settings</a></p>');
    }

    // Check if we got the refresh token
    if (!isset($data['refresh_token'])) {
        wp_die('<h1>Token Exchange Error</h1><p>No refresh token received from Google. This may happen if you\'ve already authorized this application before. Try revoking access and re-authorizing.</p><p><a href="' . admin_url('tools.php?page=email-campaigns&google-workspace=true') . '">Back to Google Workspace Settings</a></p>');
    }

    // Save refresh token to WordPress options
    $google_config['refresh_token'] = $data['refresh_token'];
    update_option(MMI_GOOGLE_CONFIG, $google_config);

    // Also save access token to transient for immediate use
    $expires_in = $data['expires_in'] ?? 3600;
    $cache_duration = max(300, $expires_in - 300); // 5 minutes before expiry
    set_transient('mmi_google_access_token', $data['access_token'], $cache_duration);

    // Success page
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Google OAuth Authorization Success</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                line-height: 1.6;
                max-width: 800px;
                margin: 40px auto;
                padding: 20px;
                background: #f1f1f1;
            }

            .container {
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }

            .success-header {
                color: #28a745;
                border-bottom: 2px solid #28a745;
                padding-bottom: 10px;
                margin-bottom: 20px;
            }

            .info-section {
                background: #e3f2fd;
                border-left: 4px solid #2196f3;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }

            .token-info {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 15px;
                margin: 15px 0;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                word-break: break-all;
            }

            .button {
                display: inline-block;
                background: #0073aa;
                color: white;
                padding: 10px 20px;
                text-decoration: none;
                border-radius: 4px;
                margin: 10px 10px 10px 0;
            }

            .button:hover {
                background: #005177;
                color: white;
            }

            .emoji {
                font-size: 1.2em;
            }

            ul {
                padding-left: 20px;
            }

            li {
                margin: 8px 0;
            }
        </style>
    </head>

    <body>
        <div class="container">
            <h1 class="success-header"><span class="emoji">✅</span> Google OAuth Authorization Successful!</h1>

            <p>Great! Your Google Workspace authorization has been completed successfully. The refresh token has been saved and you can now use Google Gmail API features.</p>

            <div class="info-section">
                <h3><span class="emoji">🔑</span> What was saved:</h3>
                <ul>
                    <li><strong>Refresh Token:</strong> Securely saved to WordPress database</li>
                    <li><strong>Access Token:</strong> Cached for immediate use (expires in <?php echo $expires_in; ?> seconds)</li>
                    <li><strong>Scope:</strong> <?php echo esc_html($data['scope'] ?? 'gmail.readonly'); ?></li>
                </ul>
            </div>

            <div class="info-section">
                <h3><span class="emoji">🚀</span> Next Steps:</h3>
                <ul>
                    <li>Go back to the Google Workspace settings page</li>
                    <li>Test the "Fetch Failed Emails" functionality</li>
                    <li>Use different search queries to find bounce/delivery failure emails</li>
                    <li>Bulk unsubscribe problematic email addresses</li>
                </ul>
            </div>

            <div class="token-info">
                <strong>Token Details (for debugging):</strong><br>
                <strong>Token Type:</strong> <?php echo esc_html($data['token_type'] ?? 'Bearer'); ?><br>
                <strong>Expires In:</strong> <?php echo esc_html($expires_in); ?> seconds<br>
                <strong>Scope:</strong> <?php echo esc_html($data['scope'] ?? 'gmail.readonly'); ?><br>
                <strong>Access Token Preview:</strong> <?php echo esc_html(substr($data['access_token'], 0, 20)); ?>...<br>
                <strong>Refresh Token Preview:</strong> <?php echo esc_html(substr($data['refresh_token'], 0, 20)); ?>...<br>
            </div>

            <div style="margin-top: 30px;">
                <a href="<?php echo admin_url('tools.php?page=email-campaigns&google-workspace=true'); ?>" class="button">
                    <span class="emoji">⚙️</span> Back to Google Workspace Settings
                </a>

                <a href="<?php echo admin_url('tools.php?page=email-campaigns'); ?>" class="button">
                    <span class="emoji">📧</span> Campaign Management
                </a>
            </div>

            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666;">
                <p><strong>Security Note:</strong> Your tokens are stored securely in the WordPress database and are only accessible by administrators. The refresh token will be used automatically to generate new access tokens when needed.</p>

                <p><strong>API Limits:</strong> Gmail API has usage limits. If you exceed them, you may need to wait or request quota increases from Google Cloud Console.</p>
            </div>
        </div>
    </body>

    </html>
<?php
} elseif (isset($_GET['error'])) {
    $error = sanitize_text_field($_GET['error']);
    wp_die('<h1>OAuth Authorization Error</h1><p>Error: ' . esc_html($error) . '</p><p><a href="' . admin_url('tools.php?page=email-campaigns&google-workspace=true') . '">Back to Google Workspace Settings</a></p>');
} else {
    wp_die('<h1>OAuth Callback</h1><p>No authorization code received.</p><p><a href="' . admin_url('tools.php?page=email-campaigns&google-workspace=true') . '">Back to Google Workspace Settings</a></p>');
}
