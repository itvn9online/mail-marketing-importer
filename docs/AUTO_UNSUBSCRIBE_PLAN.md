# Kế hoạch Tự động hóa Unsubscribe Email (Google Gmail API)

## 1. Phân tích luồng hiện tại (Manual)

### Tổng quan

Toàn bộ quy trình hiện tại phụ thuộc hoàn toàn vào thao tác thủ công của admin trong trình duyệt.

### Luồng chi tiết khi click `#fetch-google-failed-emails`

```
[Admin click nút]
       │
       ▼
[JS] jQuery.post → AJAX: action=mmi_google_fetch_failed_emails
       │           payload: search_query (vd: "subject:Delivery")
       │
       ▼
[PHP] handle_google_fetch_failed_emails()
  ├── Kiểm tra nonce + quyền manage_options
  ├── get_google_access_token()
  │     ├── Đọc client_id, client_secret, refresh_token từ wp_options (MMI_GOOGLE_CONFIG)
  │     ├── Kiểm tra WP transient cache (TTL 1 giờ)
  │     └── Nếu không có cache → POST https://oauth2.googleapis.com/token
  │           └── Lưu access_token vào WP transient
  └── Gọi Gmail API: GET /gmail/v1/users/{email}/messages
        query: q={search_query} after:{7 ngày trước} maxResults=500
        └── Trả về: [{id, threadId}, ...] (tối đa 500 message stub)
       │
       ▼
[JS] processGoogleEmailsSequentially(messages)
  └── Loop từng message ID:
        ├── Kiểm tra localStorage cache (key: cacheDetailedGoogleFailedEmails, TTL 7 ngày)
        │     ├── Nếu có cache → render ngay, setTimeout 100ms → next
        │     └── Nếu không có cache:
        │           └── AJAX POST: action=mmi_google_fetch_failed_emails, message_id={id}
        │                 [PHP] → GET /gmail/v1/users/{email}/messages/{id}
        │                        Trả về full message object
        │                 [JS]  → Xóa payload fields trừ headers
        │                        Lưu vào localStorage
        │                        setTimeout 300ms → next  (tránh rate limit)
        │
        ├── extractEmailFromGmailMessage(snippet)
        │     ├── Cắt bỏ phần sau "Reporting-MTA" nếu có
        │     └── Regex: /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g
        │           → lấy email đầu tiên khớp
        │
        └── arrGoogleFailedEmails.push(failedEmail)
              │
              ├── [LOGIC ĐẶC BIỆT] Nếu email trùng localStorage.firstGoogleFailedEmail:
              │     └── Nếu inCache=true → auto setTimeout 22s → bulkUnsubscribeGoogleEmails(false)
              │
              └── Sau khi xử lý hết tất cả:
                    └── auto setTimeout 6s → showBulkUnsubscribeSection()
                                           → bulkUnsubscribeGoogleEmails(false)
       │
       ▼
[JS] bulkUnsubscribeGoogleEmails(is_confirmed=false)
  └── jQuery.post → AJAX: action=bulk_unsubscribe_email
        payload: unsubscribe_email={email1,email2,...}
       │
       ▼
[PHP] handle_bulk_unsubscribe_ajax()
  ├── Kiểm tra nonce + quyền
  ├── explode(',', $emails_input)
  └── Loop từng email:
        └── UPDATE wp_mail_marketing
              SET is_unsubscribed=1, updated_at=NOW()
              WHERE email={email} AND is_unsubscribed=0
```

---

## 2. Vấn đề của luồng hiện tại

| #   | Vấn đề                                                                            | Hậu quả                                                 |
| --- | --------------------------------------------------------------------------------- | ------------------------------------------------------- |
| 1   | **Phụ thuộc thao tác thủ công** – Admin phải đăng nhập, vào đúng trang, click nút | Phải làm định kỳ hàng ngày/tuần, dễ bỏ sót              |
| 2   | **Xử lý tuần tự trên trình duyệt** – 333 emails × 300ms delay = ~100 giây         | Trang phải mở trong suốt quá trình, mất thời gian       |
| 3   | **State trong localStorage** – Cache email chi tiết lưu ở client                  | Mất khi xóa browser data, không dùng được trên máy khác |
| 4   | **Cơ chế `firstGoogleFailedEmail`** – Hack để nhận biết email đã xử lý            | Fragile, chỉ track 1 email, dễ lỗi                      |
| 5   | **Không track message đã xử lý** – Mỗi lần fetch lại toàn bộ 7 ngày               | Update DB lặp lại các email đã unsubscribed             |
| 6   | **333 AJAX calls riêng lẻ** – Mỗi message detail = 1 AJAX → 1 Gmail API call      | Chậm, tốn tài nguyên server và API quota                |
| 7   | **Không có lịch tự động** – Chỉ chạy khi admin nhớ và thao tác                    | Bounce emails tích lũy không được xử lý                 |

---

## 3. Kế hoạch tự động hóa mới

### 3.1 Kiến trúc tổng quan

```
[my-cron.php (gọi từ run-cron.sh mỗi 5 phút)]
       │   hoặc
[Admin click "Run Now"]
       │
       ▼
[PHP] mmi_auto_unsubscribe_cron()  ← toàn bộ server-side, không cần trình duyệt
  ├── get_google_access_token()        (tái sử dụng method hiện có)
  ├── fetch_bounce_message_ids()       (gọi Gmail API list)
  ├── filter_unprocessed_ids()         (bỏ qua ID đã xử lý, so với DB)
  ├── fetch_message_details_batch()    (Gmail Batch API → giảm số call)
  ├── extract_bounce_emails()          (regex trên snippet, server-side)
  ├── bulk_unsubscribe_db()            (UPDATE hàng loạt, tái sử dụng logic hiện có)
  └── write_api_log()                  (INSERT vào bảng mmi_api_log, kèm domain hiện tại)

[Admin truy cập trang Log Viewer]
  └── SELECT * FROM mmi_api_log WHERE domain = MMI_DOMAIN_PREFIX → hiển thị bảng log
```

### 3.2 Chi tiết từng bước

#### Bước 1: Standalone cron script `my-cron.php`

Thay vì dùng WP-Cron (phụ thuộc request vào WordPress), plugin sử dụng file `my-cron.php` được gọi trực tiếp từ `run-cron.sh` qua curl:

```php
// my-cron.php load wp-load.php rồi chạy MMI_Auto_Unsubscribe::run('cron')
// Tự throttle 6h bằng cách kiểm tra bản ghi run_summary mới nhất trong mmi_api_log
$last_run_at = $wpdb->get_var(
    "SELECT created_at FROM mmi_api_log
     WHERE domain = MMI_DOMAIN_PREFIX AND api_action = 'run_summary'
     ORDER BY id DESC LIMIT 1"
);
if ($last_run_at && (current_time('mysql') - strtotime($last_run_at)) < 6 * HOUR_IN_SECONDS) {
    exit; // skip
}
$runner = new MMI_Auto_Unsubscribe();
$runner->run('cron');
```

- **Không cần** `wp_schedule_event`, `MMI_CRON_EVENT`, `cron_schedules`, hay `wp_clear_scheduled_hook`
- `HTTP_HOST` khi curl gọi đến `marketing.kimlashop.com/my-cron.php` sẽ đúng là `marketing.kimlashop.com` → `MMI_DOMAIN_PREFIX` luôn khớp
- Throttle logic dùng `mmi_api_log` thay vì `wp_options` → không bloat options table

#### Bước 2: Gmail Batch API để giảm số lượng HTTP requests

Gmail API hỗ trợ **batch requests** – gộp tối đa 100 request vào 1 HTTP call duy nhất:

```
POST https://www.googleapis.com/batch/gmail/v1
Content-Type: multipart/mixed; boundary=batch_boundary

--batch_boundary
Content-Type: application/http
Content-ID: <msg-001>

GET /gmail/v1/users/me/messages/{id1}?format=metadata&metadataHeaders=Subject,From,To
--batch_boundary
Content-Type: application/http
Content-ID: <msg-002>

GET /gmail/v1/users/me/messages/{id2}?format=metadata&metadataHeaders=Subject,From,To
--batch_boundary--
```

Thay vì 333 lần AJAX + 333 Gmail API calls → chỉ cần **4 batch requests** (ceil(333/100)).

> **Lưu ý:** Dùng `format=metadata` + `metadataHeaders=Subject,From,To` thay vì `format=full`
> → response nhỏ hơn nhiều lần, chỉ lấy headers + snippet cần thiết.

#### Bước 3: Track processed message IDs (tránh xử lý lại)

Thay vì lưu vào `wp_options` (dễ bloat), dùng bảng `mmi_api_log` để tra cứu:

```sql
-- Kiểm tra message đã xử lý chưa:
SELECT gmail_message_id FROM mmi_api_log
WHERE gmail_message_id = 'msgId123' AND domain = 'marketing_24offjumper_com'
```

Logic:

1. Fetch message ID list từ Gmail API
2. Truy vấn batch `WHERE gmail_message_id IN (...)` trong `mmi_api_log`
3. `array_diff($new_ids, $already_logged_ids)` → chỉ xử lý IDs mới
4. Sau khi xử lý → INSERT log entries kèm domain vào bảng

#### Bước 4: Trích xuất email từ snippet (PHP, tái sử dụng logic JS)

```php
private function extract_email_from_snippet(string $snippet): ?string {
    // Xóa phần sau "Reporting-MTA" nếu có
    if (($pos = strpos($snippet, 'Reporting-MTA')) !== false) {
        $snippet = substr($snippet, 0, $pos);
    }

    $pattern = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';
    if (preg_match($pattern, $snippet, $matches)) {
        return $matches[0];
    }
    return null;
}
```

#### Bước 5: Bảng DB log `mmi_api_log`

##### Schema bảng

```sql
CREATE TABLE IF NOT EXISTS {prefix}mmi_api_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain          VARCHAR(100)    NOT NULL,           -- MMI_DOMAIN_PREFIX (vd: marketing_24offjumper_com)
    domain_host     VARCHAR(255)    NOT NULL,           -- $_SERVER['HTTP_HOST'] raw (vd: marketing.24offjumper.com)
    run_type        VARCHAR(20)     NOT NULL DEFAULT 'cron',  -- 'cron' | 'manual'
    gmail_message_id VARCHAR(100)   NULL,               -- Gmail message ID (NULL nếu là log tổng hợp)
    bounce_email    VARCHAR(255)    NULL,               -- địa chỉ email bounce trích xuất được
    unsubscribed    TINYINT(1)      NOT NULL DEFAULT 0, -- 1 nếu đã UPDATE DB thành công
    api_action      VARCHAR(50)     NOT NULL,           -- 'fetch_list' | 'fetch_batch' | 'unsubscribe'
    status          VARCHAR(20)     NOT NULL DEFAULT 'success', -- 'success' | 'error' | 'skipped'
    response_summary TEXT           NULL,               -- tóm tắt response (JSON hoặc text)
    error_message   TEXT            NULL,               -- nội dung lỗi nếu status='error'
    created_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_domain          (domain),
    KEY idx_gmail_message   (gmail_message_id),
    KEY idx_bounce_email    (bounce_email(100)),
    KEY idx_created_at      (created_at),
    KEY idx_status          (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

##### Các loại log entry

| `api_action`   | Khi nào ghi                             | `gmail_message_id` | `bounce_email`       |
| -------------- | --------------------------------------- | ------------------ | -------------------- |
| `fetch_list`   | Sau khi gọi Gmail messages.list         | NULL               | NULL                 |
| `fetch_batch`  | Sau mỗi batch request (tối đa 100 msg)  | NULL               | NULL                 |
| `bounce_found` | Khi tìm thấy bounce email trong message | ID cụ thể          | email bounce         |
| `unsubscribe`  | Sau khi UPDATE DB (mỗi email)           | NULL               | email đã unsubscribe |
| `run_summary`  | Cuối mỗi lần chạy cron/manual           | NULL               | NULL                 |

##### Cách ghi log

```php
private function write_log(array $data): void {
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'mmi_api_log',
        array_merge([
            'domain'       => MMI_DOMAIN_PREFIX,
            'domain_host'  => sanitize_text_field($_SERVER['HTTP_HOST'] ?? 'unknown'),
            'created_at'   => current_time('mysql'),
            'status'       => 'success',
        ], $data)
    );
}

// Ví dụ:
$this->write_log([
    'run_type'        => 'cron',
    'api_action'      => 'bounce_found',
    'gmail_message_id'=> $message_id,
    'bounce_email'    => $email,
    'unsubscribed'    => 1,
    'response_summary'=> 'Updated 1 row',
]);
```

---

## 4. Các thay đổi cần thực hiện

### 4.1 File mới cần tạo

| File                                     | Mô tả                                                          |
| ---------------------------------------- | -------------------------------------------------------------- |
| `includes/class-auto-unsubscribe.php`    | Class chứa toàn bộ logic cron tự động + ghi `mmi_api_log`      |
| `includes/auto-unsubscribe-log-page.php` | UI trang admin xem log: bảng `mmi_api_log`, filter, pagination |

### 4.2 File cần sửa

| File                                         | Thay đổi                                                                                                                                      |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `mail-marketing-importer.php`                | Thêm require cả 2 class mới; đăng ký cron hooks + activation/deactivation; đăng ký menu trang Log Viewer; tạo bảng `mmi_api_log` khi activate |
| `includes/class-mail-marketing-importer.php` | Chuyển `get_google_access_token()` từ `private` → `public`; thêm AJAX handler "Run Now"                                                       |
| `includes/google-workspace-api.php`          | Thêm UI block "Auto Unsubscribe Status" (last run, next run, summary) với nút "Run Now"                                                       |
| `assets/admin-google-workspace.js`           | Thêm handler cho nút "Run Now" (AJAX trigger + hiển thị kết quả inline)                                                                       |

### 4.3 Tạo bảng `mmi_api_log` khi activate plugin

```php
// Trong register_activation_hook:
global $wpdb;
$charset_collate = $wpdb->get_charset_collate();
$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mmi_api_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain VARCHAR(100) NOT NULL,
    domain_host VARCHAR(255) NOT NULL,
    run_type VARCHAR(20) NOT NULL DEFAULT 'cron',
    gmail_message_id VARCHAR(100) NULL,
    bounce_email VARCHAR(255) NULL,
    unsubscribed TINYINT(1) NOT NULL DEFAULT 0,
    api_action VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    response_summary TEXT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_domain (domain),
    KEY idx_gmail_message (gmail_message_id),
    KEY idx_bounce_email (bounce_email(100)),
    KEY idx_created_at (created_at),
    KEY idx_status (status)
) $charset_collate;";
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta($sql);
```

### 4.4 Thêm AJAX action cho "Run Now"

```php
add_action('wp_ajax_mmi_run_auto_unsubscribe', [$this, 'handle_run_auto_unsubscribe_ajax']);
```

### 4.5 Trang Log Viewer (menu con trong WordPress Admin)

Đăng ký menu con dưới trang plugin chính:

```php
add_submenu_page(
    'email-campaigns',            // parent slug
    'Auto Unsubscribe Log',       // page title
    'Unsubscribe Log',            // menu label
    'manage_options',
    'mmi-unsubscribe-log',        // slug
    'mmi_render_unsubscribe_log_page'
);
```

Tính năng trang log:

| Feature                  | Mô tả                                                                                      |
| ------------------------ | ------------------------------------------------------------------------------------------ |
| Filter theo `domain`     | Dropdown chọn domain (lấy DISTINCT từ bảng)                                                |
| Filter theo `status`     | All / success / error / skipped                                                            |
| Filter theo `api_action` | All / fetch_list / bounce_found / unsubscribe / run_summary                                |
| Filter theo ngày         | Date range picker                                                                          |
| Bảng log                 | ID, Domain, Host, Action, Status, Bounce Email, Unsubscribed, Response Summary, Created At |
| Pagination               | 50 rows/page, WP_List_Table style                                                          |
| Nút "Clear Old Logs"     | Xóa log cũ hơn 30 ngày (theo domain hiện tại)                                              |
| Nút "Export CSV"         | Export kết quả filter ra file CSV                                                          |

---

## 5. So sánh Before / After

| Tiêu chí                   | Hiện tại (Manual)                             | Mới (Tự động)                            |
| -------------------------- | --------------------------------------------- | ---------------------------------------- |
| Kích hoạt                  | Admin click thủ công                          | `my-cron.php` gọi từ VPS cron mỗi 5 phút |
| Thời gian xử lý 333 emails | ~100 giây (browser mở)                        | ~5-10 giây (server background)           |
| Số HTTP calls Gmail API    | 1 (list) + 333 (detail) = 334 calls           | 1 (list) + 4 (batch) = 5 calls           |
| State lưu ở đâu            | localStorage (browser)                        | `wp_options` + `mmi_api_log`             |
| Track đã xử lý             | Không (dựa vào `firstGoogleFailedEmail` hack) | Có (message IDs trong `mmi_api_log`)     |
| Cần trình duyệt mở         | Có                                            | Không                                    |
| Visibility kết quả         | JS alert + console                            | Admin UI log page                        |
| Deduplication              | Không                                         | Có (bỏ qua IDs đã xử lý)                 |

---

## 6. Thứ tự thực hiện (Implementation Order)

1. **[DB]** Thêm `CREATE TABLE mmi_api_log` vào activation hook trong `mail-marketing-importer.php`
2. **[Prep]** Chuyển `get_google_access_token()` thành `public` trong `class-mail-marketing-importer.php`
3. **[Core]** Tạo `class-auto-unsubscribe.php`
4. **[Script]** Tạo `my-cron.php` — standalone script load `wp-load.php`, tự throttle 6h qua `mmi_api_log`
5. **[LogPage]** Tạo `auto-unsubscribe-log-page.php` với filter, pagination, export CSV
6. **[AJAX]** Thêm "Run Now" handler trong `class-mail-marketing-importer.php`
7. **[UI]** Thêm status block + "Run Now" button vào `google-workspace-api.php`
8. **[JS]** Thêm "Run Now" AJAX call trong `admin-google-workspace.js`
9. **[Test]** Trigger thủ công qua "Run Now", kiểm tra bảng `mmi_api_log`, xác nhận DB update

---

## 7. Ghi chú quan trọng

### Multi-domain / Alias domain

- Plugin chạy trên nhiều domain alias cùng 1 codebase → `MMI_DOMAIN_PREFIX` được tạo từ `$_SERVER['HTTP_HOST']` (dấu `.` và `-` thay bằng `_`)
- **`mmi_api_log.domain`** = `MMI_DOMAIN_PREFIX` (dạng `marketing_24offjumper_com`) → dùng để filter log theo domain
- **`mmi_api_log.domain_host`** = `$_SERVER['HTTP_HOST']` raw (dạng `marketing.24offjumper.com`) → để đọc dễ hơn trên UI
- Mọi `wp_options` key đều phải prefix `MMI_DOMAIN_PREFIX` – nếu thiếu prefix, các domain alias sẽ ghi đè lên nhau
- Bảng `mmi_api_log` **dùng chung 1 bảng** cho tất cả domain (dễ quản lý, không cần tạo bảng riêng mỗi domain), phân biệt qua cột `domain`

### Kỹ thuật

- **Gmail Batch API** cần header `multipart/mixed` và parse response multipart – phức tạp hơn nhưng đáng làm (giảm 98% số HTTP calls)
- Nếu không muốn dùng Batch API, có thể dùng **`snippet` từ messages.list** với field projection `fields=messages(id,snippet)` → 1 call duy nhất đủ extract email
- **`my-cron.php`** — standalone script gọi từ VPS cron, không phụ thuộc WP-Cron. Load `wp-load.php`, kiểm tra throttle 6h qua `mmi_api_log`, rồi chạy `MMI_Auto_Unsubscribe::run('cron')`
- `HTTP_HOST` luôn đúng vì curl gọi trực tiếp đến từng domain trong DOMAINS list
- Search queries (7 loại) → cron chạy tuần tự qua tất cả 7 queries, deduplicate email trùng trước khi UPDATE DB
- Xử lý Gmail API rate limit (429): retry sau 5 giây, tối đa 3 lần, ghi log `status='error'` nếu vẫn thất bại
- **Lock mechanism**: set `MMI_DOMAIN_PREFIX . '_auto_unsubscribe_lock'` = 1 khi bắt đầu chạy, xóa khi kết thúc → tránh 2 cron chạy đồng thời cùng domain

## 8. Triển khai cronjob trên VPS

File `run-cron.sh` gọi `my-cron.php` trực tiếp cho từng domain, không qua WP-Cron.

### 8.1 Nội dung file `run-cron.sh`

```bash
# Xem tại: /mail-marketing-importer/run-cron.sh
```

### 8.2 Giải thích các tham số curl

| Tham số                      | Mục đích                                                  |
| ---------------------------- | --------------------------------------------------------- |
| `--output <tmpfile>`         | Ghi body ra file tạm để log response                      |
| `--write-out "%{http_code}"` | In HTTP status code ra stdout để log                      |
| `--max-time 30`              | Timeout 30 giây / request – tránh script treo vô thời hạn |
| `--location`                 | Tự động follow redirect (HTTP → HTTPS, v.v.)              |

### 8.3 Thêm/xóa/sửa domain

Chỉ cần chỉnh mảng `DOMAINS` trong khối `if` tương ứng (norcaljump / kimlashop), phần còn lại tự động:

```bash
if [ "$maindomain" = "kimlashop" ]; then
  DOMAINS=(
    "marketing.kimlashop.com"
    "marketing.echbay.com"
    "marketing.newdomain.net"   # ← thêm domain mới vào đây
    # "marketing.old.com"       # ← comment để tạm tắt
  )
fi
```

### 8.4 Cài đặt trên VPS

**Bước 1:** Upload file vào VPS, cấp quyền execute:

```bash
chmod +x /path/to/plugin/run-cron.sh
```

**Bước 2:** Mở crontab

```bash
crontab -e
```

**Bước 3:** Thêm dòng sau (chạy mỗi 5 phút, ghi log vào file)

```
*/5 * * * * /bin/bash /path/to/run-cron.sh kimlashop >> /var/log/wp-cron-runner.log 2>&1
*/5 * * * * /bin/bash /path/to/run-cron.sh norcaljump >> /var/log/wp-cron-runner.log 2>&1
```

> **Lý do dùng 5 phút:** `my-cron.php` tự throttle 6h bên trong. Chạy mỗi 5 phút đảm bảo các URL khác trong `run-cron.sh` (email queue...) vẫn được kích hoạt đỜng hồ.

### 8.5 Xem log

```bash
# Xem log realtime
tail -f /var/log/wp-cron-runner.log

# Xem 50 dòng cuối
tail -n 50 /var/log/wp-cron-runner.log
```

Ví dụ output log:

```
[2026-04-06 06:00:01] --- marketing.kimlashop.com ---
[2026-04-06 06:00:01] [OK 200] https://marketing.kimlashop.com/wp-content/plugins/echbay-email-queue/cron-send.php?active_wp_mail=1
[2026-04-06 06:00:02] [OK 200] https://marketing.kimlashop.com/wp-content/plugins/mail-marketing-importer/my-cron.php
[2026-04-06 06:00:02] [RESPONSE] [MMI-CRON] SKIP domain=marketing_kimlashop_com | last_run=2026-04-06 02:00:01 | elapsed=239min | next_in=121min
[2026-04-06 06:00:03] --- marketing.echbay.com ---
[2026-04-06 06:00:04] [OK 200] https://marketing.echbay.com/wp-content/plugins/mail-marketing-importer/my-cron.php
[2026-04-06 06:00:04] [RESPONSE] [MMI-CRON] DONE domain=marketing_echbay_com | status=skipped | messages_new=0 | unsubscribed=0 | errors=
[2026-04-06 06:00:04] Done.
```

Nếu thấy `[ERR ...]` → kiểm tra domain còn hoạt động không, hoặc URL plugin có đúng không.
