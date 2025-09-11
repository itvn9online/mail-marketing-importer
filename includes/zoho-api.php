<?php

/**
 * Tương tác với API Zoho
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load saved config
$zoho_config = get_option('mmi_zoho_config', array(
    'client_id' => '',
    'client_secret' => '',
    'refresh_token' => '',
    'account_id' => '',
));

?>
<div class="campaign-section">
    <!-- Zoho Mail API Integration -->
    <div class="zoho-mail-integration">
        <h4 style="margin-bottom: 10px; color: #666;">📬 Zoho Mail Failed Delivery Integration</h4>
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin-bottom: 15px; border-radius: 3px;">
            <h6 style="margin-top: 0; color: #856404;">🔧 Redirect URI quan trọng:</h6>
            <p style="margin: 5px 0; font-size: 13px; color: #856404;">
                <strong>Khi tạo Zoho API App, hãy sử dụng Redirect URI sau:</strong>
            </p>
            <ul style="margin: 5px 0 5px 20px; font-size: 12px; color: #856404;">
                <li><code style="background: #f4f4f4; padding: 2px 4px; border-radius: 2px; color: #333;"><?php echo home_url('/wp-admin/admin-ajax.php?action=mmi_zoho_callback'); ?></code> <span style="color: #28a745;">(Khuyến nghị - WordPress Callback)</span></li>
                <li>Hoặc tự tạo redirect URI tùy chỉnh</li>
            </ul>
            <p style="margin: 5px 0 0 0; font-size: 12px; color: #856404;">
                <strong>⚠️ Lưu ý:</strong> Redirect URI trong code phải khớp chính xác với Zoho API Console!
            </p>
        </div>
        <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
            Automatically fetch failed delivery emails from Zoho Mail and bulk unsubscribe them.
        </p>

        <div style="background: #d1ecf1; border-left: 4px solid #bee5eb; padding: 10px; margin-bottom: 15px; border-radius: 3px;">
            <h6 style="margin-top: 0; color: #0c5460;">💡 Cách sử dụng mới:</h6>
            <ul style="margin: 5px 0 0 20px; font-size: 13px; color: #0c5460;">
                <li><strong>Save Config:</strong> Bạn có thể lưu từng phần thông tin (không cần điền đầy đủ cùng lúc)</li>
                <li><strong>Fetch Emails:</strong> Sẽ tự động sử dụng Account ID đã lưu nếu không nhập</li>
            </ul>
        </div>

        <div class="zoho-api-settings" style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; align-items: end;">
                <div>
                    <label for="zoho_client_id" style="display: block; font-size: 12px; margin-bottom: 3px;">Zoho Client ID:</label>
                    <input type="text" name="zoho_client_id" id="zoho_client_id" placeholder="Enter Zoho Client ID"
                        value="<?php echo esc_attr($zoho_config['client_id']); ?>"
                        style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;">
                </div>
                <div>
                    <label for="zoho_client_secret" style="display: block; font-size: 12px; margin-bottom: 3px;">Zoho Client Secret:</label>
                    <input type="password" name="zoho_client_secret" id="zoho_client_secret" placeholder="Enter Client Secret"
                        value="<?php echo esc_attr($zoho_config['client_secret']); ?>"
                        style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;">
                </div>
                <div>
                    <label for="zoho_refresh_token" style="display: block; font-size: 12px; margin-bottom: 3px;">Refresh Token:</label>
                    <input type="password" name="zoho_refresh_token" id="zoho_refresh_token" placeholder="Enter Refresh Token"
                        value="<?php echo esc_attr($zoho_config['refresh_token']); ?>"
                        style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;" readonly>
                </div>
                <div>
                    <label for="zoho_account_id" style="display: block; font-size: 12px; margin-bottom: 3px;">Account ID:</label>
                    <input type="text" name="zoho_account_id" id="zoho_account_id" placeholder="Auto-filled from OAuth"
                        value="<?php echo esc_attr($zoho_config['account_id']); ?>"
                        style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px; background: #f9f9f9;" readonly>
                </div>
            </div>

            <div style="margin-top: 10px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="button" id="save-zoho-config" class="button button-primary" style="font-size: 12px;">
                    Save Config
                </button>
                <button type="button" id="fetch-failed-emails" class="button button-primary" style="font-size: 12px;">
                    Fetch Failed Delivery Emails
                </button> last 7 days: <?php echo ((time() - 7 * DAY_IN_SECONDS) * 1000); ?>
                <button type="button" id="check-token-cache" class="button button-secondary" style="font-size: 12px;">
                    Check Token Cache
                </button>
                <button type="button" id="clear-token-cache" class="button button-secondary" style="font-size: 12px; color: #d63638;">
                    Clear Token Cache
                </button>
                <div id="zoho-status" style="font-size: 12px; color: #666;"></div>
            </div>

            <!-- Token Cache Information -->
            <div id="zoho-cache-info" style="margin-top: 15px; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; font-size: 12px;">
                <strong>🔑 Access Token Cache:</strong> Not checked yet - Click "Check Token Cache" to view status
            </div>
        </div>

        <!-- Failed Emails List -->
        <div id="failed-emails-container" style="display: none; margin-bottom: 20px;">
            <h5 style="margin-bottom: 10px; color: #d63638;">🚨 Failed Delivery Emails Found:</h5>
            <div id="failed-emails-list" style="max-height: 555px; overflow-y: auto; background: #fefefe; border: 1px solid #ddd; border-radius: 3px; padding: 10px; margin-bottom: 10px; max-width: 88%;">
                <!-- Failed emails will be populated here -->
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <?php wp_nonce_field('bulk_unsubscribe_nonce', 'bulk_unsubscribe_nonce'); ?>
                <button type="button" id="bulk-unsubscribe-failed" class="button button-secondary" style="font-size: 12px;">
                    Bulk Unsubscribe Selected
                </button>
                <button type="button" id="select-all-failed" class="button" style="font-size: 12px;">
                    Select All
                </button>
                <span id="failed-emails-count" style="font-size: 12px; color: #666;"></span>
            </div>
        </div>

        <!-- Refresh Token Generator Tool -->
        <div class="refresh-token-tool" style="background: #fff3cd; padding: 15px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #ffc107;">
            <h5 style="margin-top: 0; color: #856404;">🔑 Refresh Token Generator</h5>
            <p style="color: #856404; font-size: 13px; margin-bottom: 10px;">
                Nếu chưa có Refresh Token, hãy sử dụng công cụ này để tạo bằng dữ liệu Client ID và Client Secret đã cấu hình ở trên.
                <strong>Quá trình sẽ được tự động xử lý sau khi authorization!</strong>
            </p>

            <div style="margin-bottom: 15px;">
                <label for="zoho_scope" style="display: block; font-size: 12px; margin-bottom: 3px; color: #856404;">Chọn Scope:</label>
                <select id="zoho_scope" style="width: 100%; max-width: 555px; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px; background: #fff;">
                    <option value="ZohoMail.messages.READ" selected>ZohoMail.messages.READ (dùng để lấy access_token và refresh_token)</option>
                    <option value="ZohoMail.messages.ALL">ZohoMail.messages.ALL (không nên dùng)</option>
                    <option value="ZohoMail.accounts.READ">ZohoMail.accounts.READ (dùng để lấy accountId)</option>
                    <option value="ZohoMail.accounts.ALL">ZohoMail.accounts.ALL (không nên dùng)</option>
                </select>
                <p style="font-size: 11px; color: #856404; margin: 3px 0 0 0;">
                    💡 <strong>Khuyến nghị:</strong> Sử dụng scope nhỏ nhất cần thiết để bảo mật tốt hơn.
                </p>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-start; align-items: center; margin-bottom: 10px;">
                <button type="button" id="generate-auth-url" class="button button-secondary" style="font-size: 12px;">
                    🔗 Tạo Auth URL
                </button>
            </div>

            <div id="auth-url-section" style="display: none; margin-bottom: 10px;">
                <label style="display: block; font-size: 12px; margin-bottom: 3px;">Bước 1: Truy cập URL này để authorize (nhớ đăng nhập vào tài khoản Zoho cần cấp quyền trước khi truy cập):</label>
                <div style="display: flex; gap: 5px; align-items: center;">
                    <input type="text" id="generated-auth-url" readonly
                        style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 11px; background: #f9f9f9;">
                    <button type="button" id="copy-auth-url" class="button" style="font-size: 11px;">Copy</button>
                    <button type="button" id="open-auth-url" class="button" style="font-size: 11px;">Mở</button>
                </div>
                <p style="font-size: 11px; color: #666; margin: 5px 0;">
                    💡 <strong>Hướng dẫn:</strong> Sau khi authorize, Zoho sẽ redirect đến URL có dạng:
                    <code style="background: #f4f4f4; padding: 1px 3px;"><?php echo home_url('/wp-admin/admin-ajax.php?action=mmi_zoho_callback&code=ABC123...'); ?></code>
                    - Sẽ tự động xử lý và hiển thị kết quả.
                </p>
            </div>

            <div id="token-status" style="margin-top: 10px; font-size: 12px;"></div>
        </div>

        <details style="margin-top: 15px;">
            <summary style="cursor: pointer; color: #0073aa; font-size: 12px;">📋 Hướng dẫn chi tiết</summary>
            <div style="margin-top: 10px; padding: 10px; background: #f0f8ff; border-radius: 3px; font-size: 12px; line-height: 1.5;">
                <h6 style="margin-top: 0; color: #0066cc;">Bước 1: Tạo Zoho API App</h6>
                <ol style="margin: 0; padding-left: 20px;">
                    <li>Truy cập <a href="https://api-console.zoho.com" target="_blank" style="color: #0073aa;">Zoho Developer Console</a></li>
                    <li>Nhấn "GET STARTED" → Chọn "Server-based Applications"</li>
                    <li>Điền thông tin:
                        <ul style="margin: 5px 0; padding-left: 15px;">
                            <li><strong>Client Name:</strong> Mail Marketing Importer</li>
                            <li><strong>Homepage URL:</strong> <?php echo home_url(); ?></li>
                            <li><strong>Authorized Redirect URLs:</strong> <code style="background: #f4f4f4; padding: 2px 4px;"><?php echo home_url('/wp-admin/admin-ajax.php?action=mmi_zoho_callback'); ?></code></li>
                        </ul>
                    </li>
                    <li>Sau khi tạo, lưu lại <strong>Client ID</strong> và <strong>Client Secret</strong></li>
                </ol>

                <h6 style="color: #0066cc; margin-top: 15px;">Bước 2: Thiết lập Scopes</h6>
                <ul style="margin: 0; padding-left: 20px;">
                    <li>Trong app vừa tạo, vào tab "Scope"</li>
                    <li>Thêm scope: <code style="background: #f4f4f4; padding: 2px 4px; border-radius: 2px;">ZohoMail.messages.READ</code></li>
                </ul>

                <h6 style="color: #0066cc; margin-top: 15px;">Bước 4: Tạo Refresh Token</h6>
                <ul style="margin: 0; padding-left: 20px;">
                    <li>Điền Client ID và Client Secret vào form trên</li>
                    <li>Nhấn "🔗 Tạo Auth URL"</li>
                    <li>Nhấn "Mở" để truy cập URL authorization</li>
                    <li>Authorize trên Zoho - sẽ tự động redirect về WordPress</li>
                    <li>WordPress sẽ tự động lấy và lưu Refresh Token</li>
                </ul>
                <h6 style="color: #0066cc; margin-top: 15px;">Bước 3: Lấy Account ID</h6>
                <ul style="margin: 0; padding-left: 20px;">
                    <li>Đăng nhập <a href="https://mail.zoho.com/" target="_blank" style="color: #0073aa;">Zoho Mail</a></li>
                    <li>Vào Settings → Account Details</li>
                    <li>Copy Account ID (dạng số dài)</li>
                </ul>

                <p style="margin: 15px 0 0 0; color: #d63638; background: #fff5f5; padding: 8px; border-radius: 3px;">
                    <strong>⚠️ Lưu ý:</strong> Refresh Token có thể expire sau một thời gian. Nếu gặp lỗi authentication, hãy tạo lại token mới.
                </p>
            </div>
        </details>
    </div>
</div>