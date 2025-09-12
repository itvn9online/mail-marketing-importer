<?php

/**
 * 
 * API dùng để lấy access token và refresh token từ Zoho Mail qua OAuth 2.0
 * https://www.zoho.com/mail/help/api/using-oauth-2.html
 * 
 * API lấy accountId:
 * https://www.zoho.com/mail/help/api/get-all-users-accounts.html
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// print_r($_SERVER);

// Get authorization code from callback
$auth_code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
$state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
$error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';

if (!empty($error)) {
    wp_die('<h1>OAuth Authorization Error</h1><p>Error: ' . esc_html($error) . '</p><p><a href="' . admin_url('tools.php?page=email-campaigns&zoho-api=true') . '">Back to Zoho API Settings</a></p>');
}

if (empty($auth_code)) {
    wp_die('<h1>OAuth Callback</h1><p>No authorization code received.</p><p><a href="' . admin_url('tools.php?page=email-campaigns&zoho-api=true') . '">Back to Zoho API Settings</a></p>');
}

// Get stored client credentials
$zoho_config = get_option('mmi_zoho_config', array());
$client_id = $zoho_config['client_id'] ?? '';
$client_secret = $zoho_config['client_secret'] ?? '';

if (empty($client_id) || empty($client_secret)) {
    wp_die('<h1>OAuth Configuration Error</h1><p>Client ID and Client Secret are required.</p><p><a href="' . admin_url('tools.php?page=email-campaigns&zoho-api=true') . '">Back to Zoho API Settings</a></p>');
}

// Exchange authorization code for tokens
$redirect_uri = home_url('/wp-admin/admin-ajax.php?action=mmi_zoho_callback');

// Get selected scope from transient (saved during auth URL generation)
$selected_scope = get_transient('mmi_zoho_selected_scope');
if (!$selected_scope) {
    $selected_scope = 'ZohoMail.messages.READ'; // Fallback to default
}

$token_data = array(
    'grant_type' => 'authorization_code',
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri' => $redirect_uri,
    'code' => $auth_code,
    'scope' => $selected_scope
);
// echo '<pre>' . print_r($token_data, JSON_PRETTY_PRINT) . '</pre>';

$response = wp_remote_post('https://accounts.zoho.com/oauth/v2/token', array(
    'body' => $token_data,
    'timeout' => 30,
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded'
    )
));
// echo '<pre>' . print_r($response, JSON_PRETTY_PRINT) . '</pre>';

if (is_wp_error($response)) {
    wp_die('<h1>Token Exchange Error</h1><p>Failed to connect to Zoho: ' . esc_html($response->get_error_message()) . '</p><p><a href="' . admin_url('tools.php?page=email-campaigns&zoho-api=true') . '">Back to Zoho API Settings</a></p>');
}

$body = wp_remote_retrieve_body($response);
$token_response = json_decode($body, true);

if (isset($token_response['error'])) {
    $error_msg = $token_response['error'] . ': ' . ($token_response['error_description'] ?? '');
    wp_die('<h1>Token Exchange Error</h1><p>' . esc_html($error_msg) . '</p><p><a href="' . admin_url('tools.php?page=email-campaigns&zoho-api=true') . '">Back to Zoho API Settings</a></p>');
}

// Extract tokens from response
$access_token = $token_response['access_token'] ?? '';
$refresh_token = $token_response['refresh_token'] ?? '';
$token_type = $token_response['token_type'] ?? 'Bearer';
$expires_in = $token_response['expires_in'] ?? 3600;

if (empty($refresh_token) && empty($access_token)) {
    // echo '<pre>' . print_r($token_response, JSON_PRETTY_PRINT) . '</pre>';
    wp_die('<h1>Token Exchange Error</h1><p>No refresh token received from Zoho.</p><p><a href="' . admin_url('tools.php?page=email-campaigns&zoho-api=true') . '">Back to Zoho API Settings</a></p>');
}

// Cache the access token for immediate use
if (!empty($access_token)) {
    // If scope includes accounts access, get account information
    if ($selected_scope == 'ZohoMail.accounts.READ' || $selected_scope == 'ZohoMail.accounts.ALL') {
        // Call Get All Accounts API
        $accounts_response = wp_remote_get('https://mail.zoho.com/api/accounts', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Zoho-oauthtoken ' . $access_token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            )
        ));
        // echo '<pre>' . print_r($accounts_response, JSON_PRETTY_PRINT) . '</pre>';

        if (!is_wp_error($accounts_response)) {
            $accounts_body = wp_remote_retrieve_body($accounts_response);
            $accounts_data = json_decode($accounts_body, true);
            // echo '<pre>' . print_r($accounts_data, JSON_PRETTY_PRINT) . '</pre>';

            // Check if API call was successful and contains account data
            if (isset($accounts_data['data']) && is_array($accounts_data['data']) && count($accounts_data['data']) > 0) {
                // Get the first account's ID (or you could iterate through all accounts)
                $first_account = $accounts_data['data'][0];
                if (isset($first_account['accountId'])) {
                    $zoho_config['account_id'] = $first_account['accountId'];
                    update_option('mmi_zoho_config', $zoho_config);

                    // Store accounts info for display
                    $all_accounts = $accounts_data['data'];
                    $accounts_api_success = true;
                }
            } else {
                // Store error info for display
                $accounts_api_error = 'No accounts found or invalid response format.';
                if (isset($accounts_data['status'])) {
                    if (isset($accounts_data['description'])) {
                        $accounts_api_error = $accounts_data['description'];
                    } else if (isset($accounts_data['message'])) {
                        $accounts_api_error = $accounts_data['message'];
                    }
                }
            }
        } else {
            // Store API error for display
            $accounts_api_error = 'Failed to connect to Zoho Accounts API: ' . $accounts_response->get_error_message();
        }
    } else {
        // Lưu cache với thời gian hết hạn trước 5 phút để đảm bảo an toàn
        $cache_duration = max(300, $expires_in - 300); // Tối thiểu 5 phút, trừ 5 phút từ thời gian hết hạn thực
        set_transient('mmi_zoho_access_token', $access_token, $cache_duration);
    }
}

// Update configuration with refresh token - only for messages scopes
if (!empty($refresh_token) && ($selected_scope == 'ZohoMail.messages.READ' || $selected_scope == 'ZohoMail.messages.ALL')) {
    $zoho_config['refresh_token'] = $refresh_token;
    update_option('mmi_zoho_config', $zoho_config);
}

// Display success page with tokens
?>
<!DOCTYPE html>
<html>

<head>
    <title>OAuth Success - Mail Marketing Importer</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            margin: 40px;
            line-height: 1.6;
        }

        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .token-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 15px;
            border-radius: 4px;
            margin: 10px 0;
        }

        .token-value {
            font-family: monospace;
            word-break: break-all;
            font-size: 12px;
            background: white;
            padding: 8px;
            border-radius: 3px;
            margin-top: 5px;
        }

        .button {
            background: #0073aa;
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 4px;
            display: inline-block;
            margin-right: 10px;
        }

        .button:hover {
            background: #005177;
            color: white;
        }

        .copy-btn {
            background: #6c757d;
            font-size: 12px;
            padding: 5px 10px;
            cursor: pointer;
        }

        .copy-btn:hover {
            background: #545b62;
        }
    </style>
</head>

<body>
    <h1>🎉 OAuth Authorization Successful!</h1>

    <div class="success">
        <strong>Success!</strong> Your Zoho Mail API authorization was completed successfully! The refresh token has been automatically saved to your WordPress configuration, and the access token has been cached for immediate use.
    </div>

    <h3>📋 Token Information:</h3>

    <div class="token-box">
        <strong>Access Token:</strong>
        <div class="token-value" id="access-token"><?php echo esc_html($access_token); ?></div>
        <button class="copy-btn button" onclick="copyToClipboard('access-token')">Copy Access Token</button>
        <small style="color: #666;">⚠️ Expires in <?php echo esc_html($expires_in); ?> seconds | 📦 Cached for <?php echo esc_html(round(($expires_in - 300) / 60)); ?> minutes</small>
    </div>

    <?php if (!empty($refresh_token) && ($selected_scope == 'ZohoMail.messages.READ' || $selected_scope == 'ZohoMail.messages.ALL')): ?>
        <div class="token-box">
            <strong>Refresh Token:</strong>
            <div class="token-value" id="refresh-token"><?php echo esc_html($refresh_token); ?></div>
            <button class="copy-btn button" onclick="copyToClipboard('refresh-token')">Copy Refresh Token</button>
            <small style="color: #666;">✅ Already saved to WordPress configuration</small>
        </div>
    <?php elseif (!empty($refresh_token) && ($selected_scope == 'ZohoMail.accounts.READ' || $selected_scope == 'ZohoMail.accounts.ALL')): ?>
        <div class="token-box" style="background: #fff3cd; border-color: #ffc107; color: #856404;">
            <strong>Refresh Token (Not Saved):</strong>
            <div class="token-value" id="refresh-token"><?php echo esc_html($refresh_token); ?></div>
            <button class="copy-btn button" onclick="copyToClipboard('refresh-token')">Copy Refresh Token</button>
            <small style="color: #856404;">⚠️ Refresh token not saved to configuration (accounts scope only)</small>
        </div>
    <?php endif; ?>

    <div class="token-box">
        <strong>Token Type:</strong> <?php echo esc_html($token_type); ?><br>
        <strong>Expires In:</strong> <?php echo esc_html($expires_in); ?> seconds<br>
        <strong>Scope Used:</strong> <code style="background: #f0f0f0; padding: 2px 4px; border-radius: 2px;"><?php echo esc_html($selected_scope); ?></code>
        <?php
        // Clean up the transient after successful use
        delete_transient('mmi_zoho_selected_scope');
        ?>
    </div>

    <?php if (isset($all_accounts) && is_array($all_accounts) && count($all_accounts) > 0): ?>
        <h3>📧 Zoho Mail Accounts Retrieved:</h3>
        <div class="token-box" style="background: #e8f5e8; border-color: #4caf50;">
            <?php foreach ($all_accounts as $index => $account): ?>
                <div style="padding: 8px 0; border-bottom: 1px solid #ddd; <?php echo $index === count($all_accounts) - 1 ? 'border-bottom: none;' : ''; ?>">
                    <strong>Account <?php echo $index + 1; ?>:</strong><br>
                    <strong>Account ID:</strong> <code style="background: #f0f0f0; padding: 2px 4px; border-radius: 2px;"><?php echo esc_html($account['accountId'] ?? 'N/A'); ?></code><br>
                    <?php if (isset($account['displayName'])): ?>
                        <strong>Display Name:</strong> <?php echo esc_html($account['displayName']); ?><br>
                    <?php endif; ?>
                    <?php if (isset($account['primaryEmailAddress'])): ?>
                        <strong>Primary Email:</strong> <?php echo esc_html($account['primaryEmailAddress']); ?><br>
                    <?php endif; ?>
                    <?php if (isset($account['accountType'])): ?>
                        <strong>Account Type:</strong> <?php echo esc_html($account['accountType']); ?>
                    <?php endif; ?>
                    <?php if ($index === 0 && isset($account['accountId'])): ?>
                        <br><small style="color: #4caf50;">✅ This Account ID has been automatically saved to your configuration</small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (isset($accounts_api_error)): ?>
        <h3>⚠️ Accounts API Call Failed:</h3>
        <div class="token-box" style="background: #fff3cd; border-color: #ffc107; color: #856404;">
            <strong>Error:</strong> <?php echo esc_html($accounts_api_error); ?><br>
            <small>Note: This doesn't affect your OAuth tokens, but Account ID was not automatically retrieved.</small>
        </div>
    <?php elseif (($selected_scope == 'ZohoMail.accounts.READ' || $selected_scope == 'ZohoMail.accounts.ALL') && !isset($accounts_api_success)): ?>
        <h3>📧 Account Information:</h3>
        <div class="token-box" style="background: #d1ecf1; border-color: #bee5eb; color: #0c5460;">
            <strong>Info:</strong> Accounts scope was selected but no account information was retrieved.<br>
            <small>You may need to manually enter the Account ID in the Zoho API settings.</small>
        </div>
    <?php endif; ?>

    <h3>🚀 Next Steps:</h3>

    <?php if ($selected_scope == 'ZohoMail.messages.READ' || $selected_scope == 'ZohoMail.messages.ALL'): ?>
        <div class="token-box" style="background: #fff3cd; border-color: #ffc107; color: #856404; margin-bottom: 15px;">
            <strong>💡 Important:</strong> With the <code><?php echo esc_html($selected_scope); ?></code> scope, you may need to manually enter your Account ID in the Zoho API settings to use the "Fetch Failed Delivery Emails" feature.
        </div>
    <?php elseif ($selected_scope == 'ZohoMail.accounts.READ' || $selected_scope == 'ZohoMail.accounts.ALL'): ?>
        <div class="token-box" style="background: #d1ecf1; border-color: #bee5eb; color: #0c5460; margin-bottom: 15px;">
            <strong>📋 Note:</strong> With the <code><?php echo esc_html($selected_scope); ?></code> scope, the refresh token is not automatically saved to configuration. This scope is primarily for retrieving account information. For ongoing email operations, consider using a messages scope.
        </div>
    <?php endif; ?>

    <p>
        <a href="<?php echo admin_url('tools.php?page=email-campaigns&zoho-api=true'); ?>" class="button">
            Go to Zoho API Settings
        </a>
        <a href="<?php echo admin_url('tools.php?page=mail-marketing-importer'); ?>" class="button">
            Back to Main Page
        </a>
    </p>

    <script>
        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            navigator.clipboard.writeText(text).then(function() {
                const button = event.target;
                const originalText = button.textContent;
                button.textContent = '✓ Copied!';
                button.style.background = '#28a745';
                setTimeout(function() {
                    button.textContent = originalText;
                    button.style.background = '#6c757d';
                }, 2000);
            });
        }

        // tự động đóng cửa sổ sau 5 phút
        setTimeout(function() {
            window.close();
        }, 5 * 60 * 1000);
    </script>
</body>

</html>
<?php
wp_die(); // Stop execution