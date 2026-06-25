<?php

/**
 * MMI Auto Unsubscribe
 *
 * Tự động fetch bounce emails từ Gmail và unsubscribe server-side.
 * Chạy qua WP-Cron mỗi 6 giờ hoặc trigger thủ công qua "Run Now".
 * Mọi kết quả được ghi vào bảng {prefix}mmi_api_log, phân biệt theo domain.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MMI_Auto_Unsubscribe
{
    // Tất cả search queries — khớp với dropdown trong UI
    const SEARCH_QUERIES = [
        'subject:Delivery',
        'subject:Undelivered',
        'subject:Failed',
        'subject:Bounce',
        'subject:returned',
        'from:mailer-daemon',
        'from:postmaster',
    ];

    // ─────────────────────────────────────────────────────────────
    // Entry point
    // ─────────────────────────────────────────────────────────────

    /**
     * Chạy toàn bộ luồng auto-unsubscribe.
     *
     * @param string $run_type  'cron' | 'manual'
     * @return array  Summary của lần chạy
     */
    public function run(string $run_type = 'cron'): array
    {
        global $wpdb;

        $min_run = GG_AUTO_UNSUB_NEXT_RUN - 1;

        // Throttle cho cron: bỏ qua nếu fetch_list đã chạy trong vòng $min_run giờ
        if ($run_type === 'cron') {
            $last_fetch = $wpdb->get_var($wpdb->prepare(
                "SELECT created_at FROM {$wpdb->prefix}mmi_api_log
                 WHERE domain = %s AND api_action = 'fetch_list'
                 ORDER BY id DESC LIMIT 1",
                MMI_DOMAIN_PREFIX
            ));
            if ($last_fetch && (time() - strtotime(get_gmt_from_date($last_fetch))) < $min_run * HOUR_IN_SECONDS) {
                return [
                    'status' => 'skipped',
                    'message' => 'Last fetch_list was ' . $last_fetch . ', within ' . $min_run . 'h throttle.'
                ];
            }
        }

        $summary = [
            'run_type'            => $run_type,
            'started_at'          => current_time('mysql'),
            'finished_at'         => null,
            'messages_found'      => 0,
            'messages_new'        => 0,
            'emails_extracted'    => 0,
            'emails_unsubscribed' => 0,
            'errors'              => [],
        ];

        try {
            // 1. Lấy access token
            $token = $this->get_access_token();
            if (!$token['success']) {
                throw new Exception('Token error: ' . $token['error']);
            }
            $access_token = $token['access_token'];

            // 2. Lấy Gmail user email từ config
            $google_config = get_option(MMI_GOOGLE_CONFIG, []);
            $user_email    = $google_config['user_email'] ?? '';
            if (empty($user_email)) {
                throw new Exception('No Gmail user email configured in Google Workspace settings.');
            }

            // 3. Fetch tất cả bounce message IDs từ 7 search queries (7 ngày gần nhất)
            $all_ids = $this->fetch_bounce_ids($access_token, $user_email, $summary);
            $summary['messages_found'] = count($all_ids);

            // 4. Lọc bỏ IDs đã xử lý (có trong mmi_api_log)
            $new_ids = $this->filter_new_ids($all_ids);
            $summary['messages_new'] = count($new_ids);

            if (empty($new_ids)) {
                $summary['finished_at'] = current_time('mysql');
                $this->write_log([
                    'run_type'         => $run_type,
                    'api_action'       => 'run_summary',
                    'status'           => 'skipped',
                    'response_summary' => json_encode($summary),
                ]);
                return $summary;
            }

            // 5. Fetch message details qua Gmail Batch API (tối đa 100/batch)
            $messages = $this->fetch_message_details_batch($new_ids, $access_token, $user_email, $summary);

            // 6. Extract bounce emails từ snippet
            $bounce_emails = $this->extract_emails($messages, $summary, $run_type);
            $summary['emails_extracted'] = count($bounce_emails);

            // 7. Bulk unsubscribe
            if (!empty($bounce_emails)) {
                $summary['emails_unsubscribed'] = $this->bulk_unsubscribe($bounce_emails, $summary, $run_type);
            }

            $summary['finished_at'] = current_time('mysql');

            // 8. Ghi run_summary log
            $this->write_log([
                'run_type'         => $run_type,
                'api_action'       => 'run_summary',
                'status'           => 'success',
                'response_summary' => json_encode($summary),
            ]);
        } catch (Exception $e) {
            $summary['errors'][]  = $e->getMessage();
            $summary['finished_at'] = current_time('mysql');
            $this->write_log([
                'run_type'         => $run_type,
                'api_action'       => 'run_summary',
                'status'           => 'error',
                'error_message'    => $e->getMessage(),
                'response_summary' => json_encode($summary),
            ]);
        }

        return $summary;
    }

    /**
     * Build response summary string cho log từ query và số lượng found để thống nhất format, phục vụ cho việc throttle lần sau. Format: "Query: {query} | Found: {found}"
     **/
    private function buildResponseSummary($query, $found)
    {
        return 'Query: ' . $query . ' | Found: ' . $found;
    }

    /**
     * Gmail API - Fetch bounce IDs
     */
    private function fetch_bounce_ids(string $access_token, string $user_email, array &$summary): array
    {
        global $wpdb;

        // Chỉ chạy nếu có email được gửi trong vòng (GG_AUTO_UNSUB_NEXT_RUN + 1) giờ gần nhất
        $cutoff_sent = wp_date('Y-m-d H:i:s', time() - (GG_AUTO_UNSUB_NEXT_RUN + 1) * HOUR_IN_SECONDS);
        $last_sent = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT sent_at FROM {$wpdb->prefix}echbay_mail_queue WHERE sent_at >= %s ORDER BY sent_at DESC LIMIT 1",
                $cutoff_sent
            )
        );
        if (!$last_sent) {
            $this->write_log([
                'run_type'         => $summary['run_type'],
                'api_action'       => 'fetch_list',
                'status'           => 'skipped',
                'response_summary' => 'No emails sent in the last ' . $cutoff_sent . ' (echbay_mail_queue.sent_at). Skipping Gmail fetch.',
            ]);
            return [];
        }

        $all_ids    = [];
        $after_date = wp_date('Y/m/d', time() - (7 * DAY_IN_SECONDS));
        $cutoff_24h = wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        foreach (self::SEARCH_QUERIES as $query) {
            // Bỏ qua các query đã trả về Found: 0 trong vòng 24h
            $recent_zero = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}mmi_api_log
                 WHERE domain = %s
                   AND api_action = 'fetch_list'
                   AND status = 'success'
                   AND response_summary = %s
                   AND created_at >= %s
                 LIMIT 1",
                MMI_DOMAIN_PREFIX,
                $this->buildResponseSummary($query, 0),
                $cutoff_24h
            ));
            if ($recent_zero) {
                $this->write_log([
                    'run_type'         => $summary['run_type'],
                    'api_action'       => 'fetch_list',
                    'status'           => 'skipped',
                    'response_summary' => 'Skipped query "' . $query . '" due to recent zero results within ' . $cutoff_24h,
                ]);
                continue;
            }

            $url = 'https://gmail.googleapis.com/gmail/v1/users/' . urlencode($user_email) . '/messages';
            $url .= '?' . http_build_query([
                'q'          => $query . ' after:' . $after_date,
                'maxResults' => GG_AUTO_UNSUB_MAXRESULTS,
                'fields'     => 'messages/id,nextPageToken',
            ]);

            $response = wp_remote_get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $access_token, 'Accept' => 'application/json'],
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                $err = 'fetch_list error [' . $query . ']: ' . $response->get_error_message();
                $summary['errors'][] = $err;
                $this->write_log([
                    'run_type'      => $summary['run_type'],
                    'api_action'    => 'fetch_list',
                    'status'        => 'error',
                    'error_message' => $err,
                ]);
                continue;
            }

            $data     = json_decode(wp_remote_retrieve_body($response), true);
            $messages = $data['messages'] ?? [];
            $found    = count($messages);

            if (isset($data['error'])) {
                $err = 'Gmail API error [' . $query . ']: ' . json_encode($data['error']);
                $summary['errors'][] = $err;
                $this->write_log([
                    'run_type'      => $summary['run_type'],
                    'api_action'    => 'fetch_list',
                    'status'        => 'error',
                    'error_message' => $err,
                ]);
                continue;
            }

            $this->write_log([
                'run_type'         => $summary['run_type'],
                'api_action'       => 'fetch_list',
                'status'           => 'success',
                // Ghi rõ query và số lượng found để phục vụ throttle lần sau
                'response_summary' => $this->buildResponseSummary($query, $found),
            ]);

            foreach ($messages as $msg) {
                $all_ids[$msg['id']] = $msg['id']; // deduplicate ngay từ đây
            }
        }

        return array_values($all_ids);
    }

    // ─────────────────────────────────────────────────────────────
    // Filter IDs đã xử lý
    // ─────────────────────────────────────────────────────────────

    private function filter_new_ids(array $message_ids): array
    {
        if (empty($message_ids)) {
            return [];
        }

        global $wpdb;

        // Chia nhỏ thành batch 500 để tránh quá dài SQL IN clause
        $existing = [];
        foreach (array_chunk($message_ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT gmail_message_id
                     FROM {$wpdb->prefix}mmi_api_log
                     WHERE domain = %s
                       AND gmail_message_id IN ({$placeholders})
                       AND gmail_message_id IS NOT NULL",
                    array_merge([MMI_DOMAIN_PREFIX], $chunk)
                )
            );
            $existing = array_merge($existing, $rows);
        }

        return array_values(array_diff($message_ids, $existing));
    }

    // ─────────────────────────────────────────────────────────────
    // Gmail Batch API
    // ─────────────────────────────────────────────────────────────

    private function fetch_message_details_batch(
        array $message_ids,
        string $access_token,
        string $user_email,
        array &$summary
    ): array {
        $results = [];
        $chunks  = array_chunk($message_ids, 100);

        foreach ($chunks as $chunk_idx => $chunk) {
            $boundary = 'mmi_batch_' . uniqid('', true);
            $body     = '';

            foreach ($chunk as $i => $msg_id) {
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Type: application/http\r\n";
                $body .= "Content-ID: <item-{$i}>\r\n\r\n";
                // Chỉ lấy id + snippet — nhẹ nhất có thể
                $body .= "GET /gmail/v1/users/" . rawurlencode($user_email)
                    . "/messages/{$msg_id}?format=metadata&fields=id%2Csnippet HTTP/1.1\r\n\r\n";
            }
            $body .= "--{$boundary}--\r\n";

            $response = wp_remote_post('https://www.googleapis.com/batch/gmail/v1', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'multipart/mixed; boundary=' . $boundary,
                ],
                'body'    => $body,
                'timeout' => 60,
            ]);

            if (is_wp_error($response)) {
                $err = 'Batch error (chunk ' . ($chunk_idx + 1) . '): ' . $response->get_error_message();
                $summary['errors'][] = $err;
                $this->write_log([
                    'run_type'      => $summary['run_type'],
                    'api_action'    => 'fetch_batch',
                    'status'        => 'error',
                    'error_message' => $err,
                ]);
                continue;
            }

            $resp_body    = wp_remote_retrieve_body($response);
            $resp_headers = wp_remote_retrieve_headers($response);
            $content_type = is_array($resp_headers) ? ($resp_headers['content-type'] ?? '') : $resp_headers->offsetGet('content-type');

            // Extract boundary từ Content-Type header của response
            if (preg_match('/boundary="?([^";\s]+)"?/i', $content_type, $m)) {
                $resp_boundary = $m[1];
                $parsed = $this->parse_batch_response($resp_body, $resp_boundary);
                $results = array_merge($results, $parsed);

                $this->write_log([
                    'run_type'         => $summary['run_type'],
                    'api_action'       => 'fetch_batch',
                    'status'           => 'success',
                    'response_summary' => sprintf(
                        'Chunk %d/%d: parsed %d/%d messages',
                        $chunk_idx + 1,
                        count($chunks),
                        count($parsed),
                        count($chunk)
                    ),
                ]);
            } else {
                $err = 'Cannot parse batch response boundary from Content-Type: ' . $content_type;
                $summary['errors'][] = $err;
                $this->write_log([
                    'run_type'      => $summary['run_type'],
                    'api_action'    => 'fetch_batch',
                    'status'        => 'error',
                    'error_message' => $err,
                ]);
            }
        }

        return $results;
    }

    private function parse_batch_response(string $body, string $boundary): array
    {
        $messages = [];

        // Split bởi boundary lines
        $parts = explode('--' . $boundary, $body);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part) || $part === '--') {
                continue;
            }

            // Tìm phần JSON - bắt đầu từ dấu { đầu tiên
            $json_start = strpos($part, '{');
            if ($json_start === false) {
                continue;
            }

            $json_str = substr($part, $json_start);
            // Cắt bỏ phần thừa sau JSON (nếu có text rác)
            $json_end = strrpos($json_str, '}');
            if ($json_end !== false) {
                $json_str = substr($json_str, 0, $json_end + 1);
            }

            $data = json_decode($json_str, true);
            if (is_array($data) && isset($data['id'])) {
                $messages[] = $data;
            }
        }

        return $messages;
    }

    // ─────────────────────────────────────────────────────────────
    // Extract email từ snippet
    // ─────────────────────────────────────────────────────────────

    private function extract_emails(array $messages, array &$summary, string $run_type): array
    {
        $emails = [];

        foreach ($messages as $message) {
            $snippet = $message['snippet'] ?? '';
            $msg_id  = $message['id'] ?? '';
            $email   = $this->extract_email_from_snippet($snippet);

            if ($email) {
                $emails[$email] = $email; // deduplicate

                $this->write_log([
                    'run_type'         => $run_type,
                    'api_action'       => 'bounce_found',
                    'gmail_message_id' => $msg_id,
                    'bounce_email'     => $email,
                    'status'           => 'success',
                    'response_summary' => mb_substr($snippet, 0, 200),
                ]);
            } else {
                // Vẫn ghi log với message_id để không fetch lại lần sau
                $this->write_log([
                    'run_type'         => $run_type,
                    'api_action'       => 'bounce_found',
                    'gmail_message_id' => $msg_id,
                    'bounce_email'     => null,
                    'status'           => 'skipped',
                    'response_summary' => 'No email extracted. Snippet: ' . mb_substr($snippet, 0, 100),
                ]);
            }
        }

        return array_values($emails);
    }

    private function extract_email_from_snippet(string $snippet): ?string
    {
        if (empty($snippet)) {
            return null;
        }

        // Loại bỏ nội dung sau "Reporting-MTA" (giống logic JS hiện tại)
        if (($pos = strpos($snippet, 'Reporting-MTA')) !== false) {
            $snippet = substr($snippet, 0, $pos);
        }

        $pattern = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';
        if (preg_match($pattern, $snippet, $matches)) {
            return strtolower($matches[0]);
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // Bulk unsubscribe
    // ─────────────────────────────────────────────────────────────

    private function bulk_unsubscribe(array $emails, array &$summary, string $run_type): int
    {
        global $wpdb;
        $total = 0;

        foreach ($emails as $email) {
            $email = sanitize_email(strtolower($email));
            if (!is_email($email)) {
                continue;
            }

            $result = $wpdb->update(
                $wpdb->prefix . 'mail_marketing',
                ['is_unsubscribed' => 1, 'updated_at' => current_time('mysql')],
                ['is_unsubscribed' => 0, 'email' => $email],
                ['%d', '%s'],
                ['%d', '%s']
            );

            $affected = (int) $wpdb->rows_affected;
            $total   += $affected;

            $this->write_log([
                'run_type'         => $run_type,
                'api_action'       => 'unsubscribe',
                'bounce_email'     => $email,
                'unsubscribed'     => ($affected > 0) ? 1 : 0,
                'status'           => ($result !== false) ? 'success' : 'error',
                'response_summary' => 'Affected rows: ' . $affected,
                'error_message'    => ($result === false) ? $wpdb->last_error : null,
            ]);
        }

        return $total;
    }

    // ─────────────────────────────────────────────────────────────
    // Google Access Token (self-contained, không phụ thuộc class khác)
    // ─────────────────────────────────────────────────────────────

    private function get_access_token(): array
    {
        $config        = get_option(MMI_GOOGLE_CONFIG, []);
        $client_id     = $config['client_id']     ?? '';
        $client_secret = $config['client_secret'] ?? '';
        $refresh_token = $config['refresh_token'] ?? '';

        if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
            return ['success' => false, 'error' => 'Google Workspace config incomplete (client_id/secret/refresh_token missing).'];
        }

        // Kiểm tra WP transient cache
        $cache_key    = MMI_DOMAIN_PREFIX . '_mmi_google_access_token';
        $cached_token = get_transient($cache_key);
        if ($cached_token) {
            return ['success' => true, 'access_token' => $cached_token, 'from_cache' => true];
        }

        // Lấy token mới
        $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body'    => [
                'grant_type'    => 'refresh_token',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => 'Token request failed: ' . $resp->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($resp), true);

        if (empty($data['access_token'])) {
            return ['success' => false, 'error' => 'No access_token in response: ' . json_encode($data)];
        }

        $expires_in     = (int) ($data['expires_in'] ?? 3600);
        $cache_duration = max(300, $expires_in - 300);
        set_transient($cache_key, $data['access_token'], $cache_duration);

        return [
            'success'        => true,
            'access_token'   => $data['access_token'],
            'expires_in'     => $expires_in,
            'cache_duration' => $cache_duration,
            'from_cache'     => false,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Logging
    // ─────────────────────────────────────────────────────────────

    public function write_log(array $data): void
    {
        global $wpdb;

        $defaults = [
            'domain'      => MMI_DOMAIN_PREFIX,
            'domain_host' => sanitize_text_field($_SERVER['HTTP_HOST'] ?? 'unknown'),
            'run_type'    => 'cron',
            'status'      => 'success',
            'created_at'  => current_time('mysql'),
        ];

        $result = $wpdb->insert(
            $wpdb->prefix . 'mmi_api_log',
            array_merge($defaults, $data)
        );
        if ($result === false && $wpdb->last_error) {
            update_option(
                MMI_DOMAIN_PREFIX . '_mmi_db_write_error',
                ['error' => $wpdb->last_error, 'time' => current_time('mysql'), 'table' => $wpdb->prefix . 'mmi_api_log'],
                false
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Static helpers cho UI
    // ─────────────────────────────────────────────────────────────

    /**
     * @param string $domain  MMI_DOMAIN_PREFIX của domain cần xem.
     *                        Truyền rỗng để dùng domain hiện tại.
     */
    public static function get_status(string $domain = ''): array
    {
        if ($domain === '') {
            $domain = MMI_DOMAIN_PREFIX;
        }

        global $wpdb;

        $last_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT created_at, response_summary
                 FROM {$wpdb->prefix}mmi_api_log
                 WHERE domain = %s AND api_action = 'run_summary'
                 ORDER BY id DESC LIMIT 1",
                $domain
            )
        );

        $last_run = $last_row->created_at ?? '';
        $next_run = $last_run ? wp_date('Y-m-d H:i:s', strtotime(get_gmt_from_date($last_run)) + GG_AUTO_UNSUB_NEXT_RUN * HOUR_IN_SECONDS) : '';

        return [
            'last_run'     => $last_run,
            'next_run'     => $next_run,
            'last_summary' => $last_row ? (json_decode($last_row->response_summary, true) ?: []) : [],
        ];
    }

    /**
     * Xóa log cũ hơn $days ngày — toàn bộ domain (không phân biệt).
     *
     * @param int $days  Số ngày giữ lại.
     */
    public static function cleanup_old_logs(int $days = 30): int
    {
        global $wpdb;
        $cutoff = wp_date('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}mmi_api_log WHERE created_at < %s",
                $cutoff
            )
        );
        return (int) $result;
    }
}
