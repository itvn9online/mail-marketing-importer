<?php

/**
 * Tương tác với Google Workspace Gmail API
 * 
 * API dùng để lấy access token và refresh token từ Google Workspace qua OAuth 2.0
 * https://developers.google.com/gmail/api/guides
 * 
 * Gmail API - Search messages:
 * https://developers.google.com/gmail/api/reference/rest/v1/users.messages/list
 * 
 * OAuth 2.0 for Server Side Web Apps:
 * https://developers.google.com/identity/protocols/oauth2/web-server
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load saved config
$google_config = get_option('mmi_google_config', array(
    'client_id' => '',
    'client_secret' => '',
    'refresh_token' => '',
    'user_email' => '', // Gmail address to access
));

?>
<div class="campaign-section">
    <!-- Google Workspace Gmail API Integration -->
    <div class="google-workspace-integration">
        <h4 style="margin-bottom: 10px; color: #666;">📧 Google Workspace Gmail Failed Delivery Integration</h4>
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin-bottom: 15px; border-radius: 3px;">
            <h6 style="margin-top: 0; color: #856404;">🔧 Redirect URI quan trọng:</h6>
            <p style="margin: 5px 0; font-size: 13px; color: #856404;">
                <strong>Khi tạo Google Cloud Console OAuth 2.0 Client, hãy sử dụng Redirect URI sau:</strong>
            </p>
            <ul style="margin: 5px 0 5px 20px; font-size: 12px; color: #856404;">
                <li>
                    <input type="text" readonly style="width: 100%; padding: 2px 4px; border: 1px solid #ddd; border-radius: 2px; font-size: 12px; background: #f4f4f4; color: #333; max-width: 555px;" value="<?php echo home_url('/wp-admin/admin-ajax.php?action=mmi_google_callback'); ?>">
                    <span style="color: #28a745;">(Khuyến nghị - WordPress Callback)</span>
                </li>
                <li>Hoặc tự tạo redirect URI tùy chỉnh</li>
            </ul>
            <p style="margin: 5px 0 0 0; font-size: 12px; color: #856404;">
                <strong>⚠️ Lưu ý:</strong> Redirect URI trong code phải khớp chính xác với Google Cloud Console!
            </p>
        </div>
        <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
            Automatically fetch failed delivery emails from Google Workspace Gmail and bulk unsubscribe them.
        </p>

        <div style="background: #d1ecf1; border-left: 4px solid #bee5eb; padding: 10px; margin-bottom: 15px; border-radius: 3px;">
            <h6 style="margin-top: 0; color: #0c5460;">💡 Cách sử dụng:</h6>
            <ul style="margin: 5px 0 0 20px; font-size: 13px; color: #0c5460;">
                <li><strong>Save Config:</strong> Lưu từng phần thông tin (không cần điền đầy đủ cùng lúc)</li>
                <li><strong>Fetch Emails:</strong> Sử dụng Gmail API để tìm email bounce/failed delivery</li>
                <li><strong>User Email:</strong> Email Gmail/Workspace để truy cập (thường là admin email)</li>
            </ul>
        </div>

        <div class="google-api-settings" style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; align-items: end;">
                <div>
                    <label for="google_client_id" style="display: block; font-size: 12px; margin-bottom: 3px;">Google Client ID:</label>
                    <input type="text" name="google_client_id" id="google_client_id" placeholder="Enter Google Client ID"
                        value="<?php echo esc_attr($google_config['client_id']); ?>"
                        style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;">
                </div>
                <div>
                    <label for="google_client_secret" style="display: block; font-size: 12px; margin-bottom: 3px;">Google Client Secret:</label>
                    <input type="text" name="google_client_secret" id="google_client_secret" placeholder="Enter Client Secret"
                        value="<?php echo esc_attr($google_config['client_secret']); ?>" class="is-token-hidden"
                        style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;">
                </div>
                <div>
                    <label for="google_refresh_token" style="display: block; font-size: 12px; margin-bottom: 3px;">Refresh Token:</label>
                    <input type="text" name="google_refresh_token" id="google_refresh_token" placeholder="Auto from OAuth"
                        value="<?php echo esc_attr($google_config['refresh_token']); ?>" class="is-token-hidden"
                        style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;" readonly>
                </div>
                <div>
                    <label for="google_user_email" style="display: block; font-size: 12px; margin-bottom: 3px;">Gmail/Workspace Email:</label>
                    <input type="email" name="google_user_email" id="google_user_email" placeholder="admin@domain.com"
                        value="<?php echo esc_attr($google_config['user_email']); ?>"
                        style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;">
                </div>
            </div>
            <ul style="font-size: 11px; color: #666; margin-top: 5px;">
                <li>Refresh Token: sẽ tự động lưu trong quá trình OAuth authorization.</li>
                <li>User Email: Email Gmail/Workspace để truy cập mailbox (thường là admin email).</li>
                <li>Scope cần thiết: https://www.googleapis.com/auth/gmail.readonly</li>
            </ul>

            <div style="margin-top: 10px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="button" id="save-google-config" class="button button-primary" style="font-size: 12px;">
                    Save Config
                </button>
                <div style="display: flex; gap: 5px; align-items: center;">
                    <select id="google-search-type" style="border: 1px solid #ddd; border-radius: 3px; font-size: 12px;">
                        <option value="subject:Delivery">Subject: Delivery</option>
                        <option value="subject:Undelivered">Subject: Undelivered</option>
                        <option value="subject:Failed">Subject: Failed</option>
                        <option value="subject:Bounce">Subject: Bounce</option>
                        <option value="subject:returned">Subject: Returned</option>
                        <option value="from:mailer-daemon">From: Mailer-Daemon</option>
                        <option value="from:postmaster">From: Postmaster</option>
                        <option value="has:attachment subject:delivery">Has attachment + Delivery</option>
                    </select>
                    <button type="button" id="fetch-google-failed-emails" class="button button-secondary" style="font-size: 12px;">
                        🔍 Fetch Failed Emails
                    </button>
                </div>
                <div style="display: flex; gap: 5px; align-items: center;">
                    <button type="button" id="clear-google-token-cache" class="button" style="font-size: 11px;">
                        Clear Token Cache
                    </button>
                    <button type="button" id="google-token-cache-info" class="button" style="font-size: 11px;">
                        Token Cache Info
                    </button>
                </div>
            </div>
        </div>

        <div class="google-results-section" style="margin-top: 15px;">
            <div id="google-failed-emails-result" style="margin-top: 10px;"></div>
        </div>

        <div class="refresh-token-tool" style="background: #fff3cd; padding: 15px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #ffc107;">
            <h5 style="margin-top: 0; color: #856404;">🔑 Google OAuth Token Generator</h5>
            <p style="color: #856404; font-size: 13px; margin-bottom: 10px;">
                Nếu chưa có Refresh Token, hãy sử dụng công cụ này để tạo bằng Google Client ID và Client Secret đã cấu hình ở trên.
                <strong>Quá trình sẽ được tự động xử lý sau khi authorization!</strong>
                <br><small style="color: #28a745;">✅ Scope: https://www.googleapis.com/auth/gmail.readonly</small>
            </p>

            <div style="display: flex; gap: 10px; justify-content: flex-start; align-items: center; margin-bottom: 10px;">
                <button type="button" id="generate-google-auth-url" class="button button-secondary" style="font-size: 12px;">
                    🔗 Tạo Google Auth URL
                </button>
            </div>

            <div id="google-auth-url-section" style="display: none; margin-bottom: 10px;">
                <label style="display: block; font-size: 12px; margin-bottom: 3px;">Bước 1: Truy cập URL này để authorize (đăng nhập Google account cần cấp quyền trước):</label>
                <div style="display: flex; gap: 5px; align-items: center;">
                    <input type="text" id="generated-google-auth-url" readonly
                        style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 11px; background: #f9f9f9;">
                    <button type="button" id="copy-google-auth-url" class="button" style="font-size: 11px;">Copy</button>
                    <button type="button" id="open-google-auth-url" class="button" style="font-size: 11px;">Mở</button>
                </div>
                <p style="font-size: 11px; color: #666; margin: 5px 0;">
                    💡 <strong>Hướng dẫn:</strong> Sau khi authorize, Google sẽ redirect đến URL có dạng:
                    <code style="background: #f4f4f4; padding: 1px 3px;"><?php echo home_url('/wp-admin/admin-ajax.php?action=mmi_google_callback&code=ABC123...'); ?></code>
                    - Sẽ tự động xử lý và hiển thị kết quả.
                </p>
            </div>

            <div id="google-token-status" style="margin-top: 10px; font-size: 12px;"></div>
        </div>

        <details style="margin-top: 15px;">
            <summary style="cursor: pointer; color: #0073aa; font-size: 12px;">📋 Google Workspace Setup Guide</summary>
            <div style="margin-top: 10px; padding: 10px; background: #f0f8ff; border-radius: 3px; font-size: 12px; line-height: 1.5;">
                <h6 style="margin-top: 0; color: #0066cc;">Bước 1: Tạo Google Cloud Project & OAuth Client</h6>
                <ol style="margin: 0; padding-left: 20px;">
                    <li>Truy cập <a href="https://console.cloud.google.com" target="_blank" style="color: #0073aa;">Google Cloud Console</a></li>
                    <li>Tạo new project hoặc chọn existing project</li>
                    <li>Vào "APIs & Services" → "Library"</li>
                    <li>Tìm và enable "Gmail API"</li>
                    <li>Vào "APIs & Services" → "Credentials"</li>
                    <li>Nhấn "Create Credentials" → "OAuth 2.0 Client IDs"</li>
                    <li>Chọn "Web application" và điền:
                        <ul style="margin: 5px 0; padding-left: 15px;">
                            <li><strong>Name:</strong> Mail Marketing Importer</li>
                            <li><strong>Authorized redirect URIs:</strong> <code style="background: #f4f4f4; padding: 2px 4px;"><?php echo home_url('/wp-admin/admin-ajax.php?action=mmi_google_callback'); ?></code></li>
                        </ul>
                    </li>
                    <li>Sau khi tạo, lưu lại <strong>Client ID</strong> và <strong>Client Secret</strong></li>
                </ol>

                <h6 style="color: #0066cc; margin-top: 15px;">Bước 2: Cấu hình OAuth Consent Screen</h6>
                <ul style="margin: 0; padding-left: 20px;">
                    <li>Vào "APIs & Services" → "OAuth consent screen"</li>
                    <li>Chọn "Internal" (cho Google Workspace) hoặc "External"</li>
                    <li>Điền thông tin cơ bản của app</li>
                    <li>Thêm scope: <code style="background: #f4f4f4; padding: 2px 4px; border-radius: 2px;">https://www.googleapis.com/auth/gmail.readonly</code></li>
                    <li>Thêm test users (email accounts cần truy cập)</li>
                </ul>

                <h6 style="color: #0066cc; margin-top: 15px;">Bước 3: Generate Refresh Token</h6>
                <ul style="margin: 0; padding-left: 20px;">
                    <li>Điền Client ID và Client Secret vào form trên</li>
                    <li>Nhập Gmail/Workspace email cần truy cập</li>
                    <li>Nhấn "🔗 Tạo Google Auth URL"</li>
                    <li>Nhấn "Mở" để truy cập URL authorization</li>
                    <li>Đăng nhập và authorize trên Google</li>
                    <li>Google sẽ redirect về WordPress và tự động lưu token</li>
                </ul>

                <h6 style="color: #0066cc; margin-top: 15px;">Bước 4: Test Functionality</h6>
                <ul style="margin: 0; padding-left: 20px;">
                    <li>Chọn search type (ví dụ: "Subject: Delivery")</li>
                    <li>Nhấn "🔍 Fetch Failed Emails"</li>
                    <li>Xem danh sách email bounce/failed delivery</li>
                    <li>Bulk unsubscribe các email có vấn đề</li>
                </ul>

                <p style="margin: 15px 0 0 0; color: #d63638; background: #fff5f5; padding: 8px; border-radius: 3px;">
                    <strong>⚠️ Lưu ý:</strong>
                    <br>• Gmail API có rate limits: 1 billion quota units per day
                    <br>• Refresh Token có thể expire nếu không sử dụng trong 6 tháng
                    <br>• Cần quyền admin để truy cập Google Workspace mailboxes
                </p>
            </div>
        </details>
    </div>
</div>