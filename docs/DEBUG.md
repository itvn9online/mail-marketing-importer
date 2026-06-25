# Debug Instructions - Mail Marketing Importer

## Vấn đề: Plugin không phản hồi khi upload file

### Bước 1: Kiểm tra Plugin đã được kích hoạt chưa

1. Vào **WordPress Admin → Plugins**
2. Tìm **Mail Marketing Importer**
3. Đảm bảo plugin đã được **Activated**

### Bước 2: Kiểm tra quyền truy cập

1. Vào **WordPress Admin → Tools → Import Marketing Data**
2. Nếu không thấy menu này, có thể user không có quyền `manage_options`

### Bước 3: Kiểm tra logs

1. Bật debug mode trong wp-config.php:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

2. Kiểm tra file log tại: `wp-content/debug.log`

### Bước 4: Test Plugin

1. Vào **Tools → Import Marketing Data**
2. Click nút **"Test Import Function"**
3. Nếu nút không hiển thị, có lỗi trong code

### Bước 5: Kiểm tra Database

Chạy query này trong phpMyAdmin:

```sql
SHOW TABLES LIKE 'wp_mail_marketing';
```

Nếu bảng không tồn tại, tạo bảng bằng query:

```sql
CREATE TABLE IF NOT EXISTS `wp_mail_marketing` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `phone` varchar(25) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `sended_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Bước 6: Test với file CSV đơn giản

Tạo file test.csv:

```csv
email,name,phone
test@example.com,Test User,0123456789
```

### Bước 7: Kiểm tra PHP errors

1. Mở browser Developer Tools (F12)
2. Vào tab Console
3. Upload file và xem có lỗi JavaScript không

### Bước 8: Kiểm tra server errors

1. Mở tab Network trong Developer Tools
2. Upload file
3. Xem response của request POST

## Các lỗi thường gặp:

### 1. "Nothing happens" khi click submit

- Kiểm tra JavaScript console
- Kiểm tra action URL trong form
- Kiểm tra WordPress nonce

### 2. "Class not found" errors

- Kiểm tra file paths
- Kiểm tra require_once statements

### 3. Database errors

- Kiểm tra MySQL user permissions
- Kiểm tra table prefix (wp\_ vs custom prefix)

### 4. File upload errors

- Kiểm tra PHP upload_max_filesize
- Kiểm tra post_max_size
- Kiểm tra temp directory permissions

## Quick Fix - Thử cách này:

1. **Deactivate và Activate lại plugin**
2. **Kiểm tra URL khi submit form** - nó phải redirect tới admin-post.php
3. **Thử upload file CSV nhỏ** (< 1MB) trước
4. **Kiểm tra PHP error log** tại wp-content/debug.log

## Contact

Nếu vẫn không hoạt động, hãy:

1. Bật debug mode
2. Thử upload file
3. Gửi nội dung file debug.log
4. Gửi screenshot của trang admin
