# Mail Marketing Importer Plugin v1.2.0

WordPress plugin for importing email marketing data with contact information from Excel files (.xlsx, .xls, .csv) into a custom database table.

## Features

- Import email marketing data from Excel files (.xlsx, .xls, .csv)
- Supports both First Name/Last Name columns and single Name column
- Supports address information (Address, City, State, Zip Code)
- Automatic column mapping detection for CSV files
- Drag and drop file upload
- Data validation and sanitization
- Duplicate email handling (updates existing records)
- Progress tracking and statistics
- Admin interface with intuitive UI
- Responsive design

## Installation

1. Copy the `mail-marketing-importer` folder to your WordPress `wp-content/plugins/` directory
2. Activate the plugin through the WordPress admin panel
3. The plugin will automatically create the `wp_mail_marketing` table upon activation

## Usage

1. Go to **Tools → Import Marketing Data** in your WordPress admin
2. Select your Excel file (.xlsx, .xls, .csv)
3. Configure column mappings:
   - **Email Column**: Column name or letter (e.g., "email" or "A") - Required
   - **First Name Column**: Column name or letter (e.g., "first_name" or "B") - Optional
   - **Last Name Column**: Column name or letter (e.g., "last_name" or "C") - Optional
   - **Name Column (Legacy)**: Column name or letter (e.g., "name" or "D") - Optional (for backward compatibility)
   - **Phone Column**: Column name or letter (e.g., "phone" or "E") - Optional
   - **Address Column**: Column name or letter (e.g., "address" or "F") - Optional
   - **City Column**: Column name or letter (e.g., "city" or "G") - Optional
   - **State Column**: Column name or letter (e.g., "state" or "H") - Optional
   - **Zip Code Column**: Column name or letter (e.g., "zip_code" or "I") - Optional
4. Check "Skip Header Row" if your file has column headers
5. Click "Import Data"

## File Format Requirements

### CSV Files

- UTF-8 encoding recommended
- Comma-separated values
- First row can contain headers

### Excel Files

- .xlsx and .xls formats supported
- For best compatibility, save as CSV format

### Required Columns

- **Email**: Valid email address (required)
- **First Name**: Customer first name (optional)
- **Last Name**: Customer last name (optional)
- **Name**: Customer full name (optional, for backward compatibility)
- **Phone**: Phone number (optional, max 25 characters)
- **Address**: Street address (optional, max 255 characters)
- **City**: City name (optional, max 100 characters)
- **State**: State or province (optional, max 50 characters)
- **Zip Code**: Postal/ZIP code (optional, max 20 characters)

**Note**: If both First Name and Last Name are provided, they will be combined into the Name field automatically.

## Database Structure

The plugin creates a table `wp_mail_marketing` with the following structure:

```sql
CREATE TABLE `wp_mail_marketing` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `phone` varchar(25) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `sended_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Status Field

- `0` = Pending (ready to send)
- `1` = Sent

## Integration with Email Marketing System

This plugin works with the existing email marketing system. After importing data:

1. Records are created with `status = 0` (pending)
2. The email marketing system (from `mail_marketing.php`) will process these records
3. Once an email is sent, the status changes to `1` and `sended_at` is updated

## Features in Detail

### Data Validation

- Email format validation
- First name and last name sanitization and length validation
- Phone number sanitization (removes non-numeric characters except +)
- Text field sanitization
- Duplicate email handling (updates existing records)
- Automatic name combination from first_name and last_name fields

### Error Handling

- Invalid email addresses are skipped
- Database errors are logged
- File format validation
- File size limits (max 10MB)

### Statistics

- Total records count
- Pending emails count
- Sent emails count

### Security

- Nonce verification for all forms
- User capability checks
- File type validation
- SQL injection prevention

## Technical Notes

### Dependencies

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

### File Size Limits

- Maximum file size: 10MB
- For larger files, consider splitting into smaller chunks

### Performance

- Batch processing for large imports
- Progress tracking
- Memory optimization

## Troubleshooting

### Common Issues

1. **File upload fails**

   - Check file size (must be < 10MB)
   - Verify file format (.xlsx, .xls, .csv)
   - Ensure proper permissions

2. **Excel files not reading properly**

   - Convert to CSV format for best compatibility
   - Ensure UTF-8 encoding

3. **Column mapping issues**

   - Use exact column names or letters (A, B, C, etc.)
   - Check for extra spaces in column names

4. **Database errors**
   - Verify table exists and has proper permissions
   - Check MySQL version compatibility

### Debug Mode

To enable debug mode, add this to your wp-config.php:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Support

For support and bug reports, please check:

- WordPress debug logs
- Browser console for JavaScript errors
- Database error logs

## License

This plugin is licensed under the GPL v2 or later.

## Changelog

### Version 1.1.0

- Added support for separate First Name and Last Name columns
- Added backward compatibility for legacy Name column
- Updated database schema to include first_name and last_name fields
- Auto-combine first_name and last_name into name field
- Updated admin interface with new column mapping options

### Version 1.0.0

- Initial release
- Basic Excel/CSV import functionality
- Admin interface
- Data validation and sanitization
- Statistics dashboard
