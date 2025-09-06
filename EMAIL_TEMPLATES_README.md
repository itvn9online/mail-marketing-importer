# Email Templates - Hướng dẫn sử dụng

## Tổng quan

Plugin Mail Marketing Importer đã được cập nhật với 6 mẫu email marketing đẹp mắt và chuyên nghiệp. Người dùng có thể chọn mẫu email khi tạo hoặc chỉnh sửa chiến dịch.

## Các mẫu email có sẵn

### 1. Default Simple (`default.html`)

- Mẫu email đơn giản, cơ bản
- Phù hợp cho thông báo thông thường
- Layout tối giản, dễ đọc

### 2. Modern Minimal (`modern-minimal.html`)

- Thiết kế hiện đại với gradient đẹp mắt
- Header có màu nền gradient tím/xanh
- Button với hiệu ứng hover
- Phù hợp cho thông báo chào mừng, giới thiệu dịch vụ

### 3. Corporate Elegant (`corporate-elegant.html`)

- Phong cách doanh nghiệp trang trọng
- Màu sắc chuyên nghiệp (xám, đỏ)
- Có quote box nổi bật
- Phù hợp cho newsletter B2B, thông báo chính thức

### 4. Creative Colorful (`creative-colorful.html`)

- Thiết kế sáng tạo với màu sắc tươi sáng
- Có background pattern
- Header với gradient cam/hồng
- Danh sách tính năng với icon
- Phù hợp cho startup, công ty công nghệ

### 5. Professional Business (`professional-business.html`)

- Thiết kế business chuyên nghiệp
- Layout dạng bảng với thống kê
- Màu xanh corporate
- Phù hợp cho báo cáo, thống kê kinh doanh

### 6. Tech Modern (`tech-modern.html`)

- Phong cách Apple-inspired
- Màu đen/xanh dương
- Typography hiện đại
- Phù hợp cho sản phẩm công nghệ, innovation

## Cách sử dụng

### 1. Khi tạo chiến dịch mới

1. Đi đến **Tools > Import Marketing Data**
2. Chọn "Create New Campaign"
3. Nhập **tên chiến dịch** (chỉ cần tên, các thông tin khác sẽ hoàn thiện sau)
4. Upload file và tiến hành import
5. Sau khi import xong, bạn sẽ được chuyển đến trang edit campaign để hoàn thiện thông tin (email subject, content, template)

### 2. Khi chỉnh sửa chiến dịch

1. Đi đến **Tools > Email Campaigns**
2. Click "Edit" trên chiến dịch muốn chỉnh sửa
3. Thay đổi **Email Template** trong form
4. Lưu thay đổi

### 3. Xem trước mẫu email

- Truy cập: `yoursite.com/wp-content/plugins/mail-marketing-importer/template-preview-page.php`
- Chọn mẫu từ dropdown để xem trước
- Mẫu sẽ hiển thị với dữ liệu demo

## Placeholders có sẵn

Tất cả mẫu email đều hỗ trợ các placeholder sau:

- `{USER_NAME}` - Tên người dùng
- `{USER_EMAIL}` - Email người dùng
- `{SITE_NAME}` - Tên website
- `{SITE_URL}` - URL website
- `{UNSUBSCRIBE_URL}` - Link hủy đăng ký
- `{CURRENT_DATE}` - Ngày hiện tại
- `{CURRENT_YEAR}` - Năm hiện tại

## Cập nhật cơ sở dữ liệu

Nếu plugin đã được cài đặt trước đó, cần chạy script cập nhật:

1. Chỉnh sửa thông tin database trong `update_email_template_column.php`
2. Truy cập: `yoursite.com/update_email_template_column.php`
3. Xóa file sau khi hoàn tất

## Tùy chỉnh mẫu email

### Thêm mẫu mới

1. Tạo file `.html` mới trong thư mục `html-template/`
2. Cập nhật mảng `$template_info` trong class `Mail_Marketing_Importer`
3. Thêm vào `template-preview.php` và `template-preview-page.php`

### Chỉnh sửa mẫu có sẵn

1. Mở file mẫu trong `html-template/`
2. Chỉnh sửa HTML/CSS theo ý muốn
3. Giữ nguyên các placeholder `{...}`

## Lưu ý kỹ thuật

- Các mẫu sử dụng inline CSS để tương thích với email clients
- Hỗ trợ responsive design
- Test trên các email clients phổ biến (Gmail, Outlook, etc.)
- Backup file trước khi chỉnh sửa

## Troubleshooting

### Mẫu không hiển thị

- Kiểm tra file có tồn tại trong `html-template/`
- Kiểm tra quyền đọc file
- Xem logs lỗi PHP

### Placeholder không được thay thế

- Kiểm tra tên placeholder chính xác
- Đảm bảo function `load_email_template()` được gọi khi gửi email

### Cơ sở dữ liệu

- Kiểm tra cột `email_template` đã được thêm vào bảng `wp_mail_marketing_campaigns`
- Chạy lại script update nếu cần

---

**Phiên bản:** 1.0  
**Ngày cập nhật:** Tháng 9, 2025
