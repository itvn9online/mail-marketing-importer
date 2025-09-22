<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

?>
<!-- Create/Edit Campaign -->
<div class="campaign-section">
    <h2><?php echo isset($_GET['edit']) ? 'Edit Campaign' : 'Create New Campaign'; ?></h2>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <?php wp_nonce_field('campaign_action', 'campaign_nonce'); ?>
        <input type="hidden" name="action" value="<?php echo isset($_GET['edit']) ? 'update_campaign' : 'create_campaign'; ?>">
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
                        <?php
                        foreach (
                            [
                                '{SITE_NAME}',
                                '{USER_EMAIL}',
                                '{USER_NAME}',
                            ] as $v
                        ) {
                        ?>
                            <span class="placeholder-item" data-placeholder="<?php echo $v; ?>" title="Double-click to copy"><?php echo $v; ?></span>
                        <?php
                        }
                        ?> Use the above placeholders
                    </p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li>Ngắn gọn, không spammy (tránh: “MIỄN PHÍ!!!”, “CLICK NGAY”).</li>
                        <li>Cá nhân hóa (VD: “Anh Nam, ưu đãi dành riêng cho anh”).</li>
                        <li>Thêm giá trị (VD: “Nhận ưu đãi 20% cho đơn hàng tiếp theo của bạn”).</li>
                    </ul>
                </td>
            </tr>

            <tr>
                <th scope="row">Email URL</th>
                <td>
                    <input type="url" name="email_url" id="email_url" class="large-text"
                        value="<?php echo $edit_campaign ? esc_attr($edit_campaign->email_url ?? '') : ''; ?>"
                        placeholder="https://example.com/landing-page" style="max-width: 80%;">
                    <button type="button" id="add-utm-params-btn" class="button button-secondary" style="margin-left: 10px;">Add UTM Parameters</button>
                    <p class="description">Main URL that this campaign directs to (landing page, product page, etc.)</p>
                </td>
            </tr>

            <tr>
                <th scope="row">Email URL (2)</th>
                <td>
                    <input type="url" name="email2_url" id="email2_url" class="large-text"
                        value="<?php echo $edit_campaign ? esc_attr($edit_campaign->email2_url ?? '') : ''; ?>"
                        placeholder="https://example.com/landing-page" style="max-width: 80%;">
                    <button type="button" id="add-utm2-params-btn" class="button button-secondary" style="margin-left: 10px;">Add UTM Parameters</button>
                    <p class="description">Main URL that this campaign directs to (landing page, product page, etc.)</p>
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
                    <p>
                        <a href="<?php echo MMI_PLUGIN_URL; ?>template-preview-page.php?template=<?php echo $current_template; ?>" target="_blank" style="font-size: 12px;">Preview Templates →</a>
                        <button type="button" id="reset-to-template-btn" class="button button-secondary" style="margin-left: 10px; font-size: 11px;">Clear template content</button>
                    </p>
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

                    // Thay thế các URL mẫu
                    wp_editor(str_replace([
                        'http://{',
                        'https://{'
                    ], '{', $content), 'email_content', array(
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
                        <?php
                        foreach (
                            [
                                '{SITE_NAME}',
                                '{USER_EMAIL}',
                                '{USER_PHONE}',
                                '{USER_NAME}',
                                '{FIRST_NAME}',
                                '{LAST_NAME}',
                                '{UNSUBSCRIBE_URL}',
                                '{CITY}',
                                '{CURRENT_DATE}',
                                '{EMAIL_URL}',
                                '{EMAIL2_URL}',
                            ] as $v
                        ) {
                        ?>
                            <span class="placeholder-item" data-placeholder="<?php echo $v; ?>" title="Double-click to copy"><?php echo $v; ?></span>
                        <?php
                        }
                        ?>
                    </div>

                    <ul style="list-style: disc; margin-left: 20px;">
                        <li>Link trong mail: Dùng domain của chính bạn, không rút gọn link (bit.ly, tinyurl dễ bị đánh spam).</li>
                        <li>Không sử dụng link đến các trang đen, vi phạm bản quyền.</li>
                        <li>SSL (https) càng tốt.</li>
                        <li>Không copy/paste từ Word → gây lỗi định dạng.</li>
                        <li>Không chèn quá nhiều từ khóa spam.</li>
                    </ul>

                    <!-- URL Detection Section -->
                    <div class="email-url-detection" style="margin-top: 15px; padding: 10px; background-color: #f0f8ff; border-left: 4px solid #0073aa; border-radius: 3px;">
                        <strong>🔗 URLs detected in email content:</strong><br>
                        <small style="color: #666; font-style: italic;">URLs are automatically detected and updated when you edit the content</small>
                        <div id="detected-urls-list" style="margin-top: 8px; min-height: 20px;">
                            <span style="color: #999; font-style: italic;">No URLs detected yet...</span>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">Ngày bắt đầu gửi</th>
                <td>
                    <input type="datetime-local" name="start_date" class="regular-text"
                        value="<?php echo $edit_campaign && $edit_campaign->start_date ? date_i18n('Y-m-d\TH:i', strtotime($edit_campaign->start_date)) : ''; ?>">
                    <p class="description">Thời gian bắt đầu gửi email (để trống sẽ gửi ngay lập tức)</p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li>Warm-up: Gửi từ ít → tăng dần theo ngày/tuần.</li>
                        <li>Giờ gửi hợp lý: thường là sáng sớm (7-9h) hoặc sau giờ làm (19-21h).</li>
                    </ul>
                </td>
            </tr>
            <?php if ($edit_campaign): ?>
                <tr>
                    <th scope="row">Campaign Status</th>
                    <td>
                        <select name="campaign_status" class="regular-text">
                            <option value="active" <?php selected($edit_campaign->status, 'active'); ?>>Active</option>
                            <option value="inactive" <?php selected($edit_campaign->status, 'inactive'); ?>>Inactive</option>
                            <option value="completed" <?php selected($edit_campaign->status, 'completed'); ?>>Completed</option>
                        </select>
                        <p class="description">Current status of this campaign</p>
                    </td>
                </tr>
            <?php endif; ?>
        </table>

        <p class="text-center">
            <input type="submit" class="button-primary" value="<?php echo isset($_GET['edit']) ? 'Update Campaign' : 'Create Campaign'; ?>">
            <?php if (isset($_GET['edit'])): ?>
                <a href="<?php echo admin_url('tools.php?page=email-campaigns'); ?>" class="button button-secondary">Cancel</a>
            <?php endif; ?>
        </p>
    </form>
</div>
<?php

// Email List Section
if ($edit_campaign) {
    $_GET['email_campaign'] = $edit_campaign->id;
    include __DIR__ . '/email-list.php';
}
