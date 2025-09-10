<?php

/**
 * Excel Reader Class for importing data from Excel files
 */
class Excel_Reader
{

    /**
     * Import data from Excel file
     */
    public function import_file($file_path, $file_ext, $options = array())
    {
        global $wpdb;

        try {
            // error_log('Excel_Reader: Starting import for file: ' . $file_path);

            $data = $this->read_file($file_path, $file_ext);

            if (empty($data)) {
                // error_log('Excel_Reader: No data found in file');
                return array('success' => false, 'message' => 'No data found in file');
            }

            // error_log('Excel_Reader: Found ' . count($data) . ' rows');

            // Get column mappings
            $email_column = $this->get_column_index($options['email_column'] ?? 'email');
            $name_column = $this->get_column_index($options['name_column'] ?? 'name');
            $phone_column = $this->get_column_index($options['phone_column'] ?? 'phone');
            $skip_header = !empty($options['skip_header']);

            $imported = 0;
            $skipped = 0;
            $errors = array();

            // Skip header row if specified
            if ($skip_header && count($data) > 0) {
                array_shift($data);
                // error_log('Excel_Reader: Skipped header row');
            }

            foreach ($data as $row_index => $row) {
                $email = $this->get_cell_value($row, $email_column);
                $name = $this->get_cell_value($row, $name_column);
                $phone = $this->get_cell_value($row, $phone_column);

                // Skip empty rows
                if (empty($email) && empty($name) && empty($phone)) {
                    continue;
                }

                // Validate email
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Row " . ($row_index + 1) . ": Invalid email - " . $email;
                    $skipped++;
                    continue;
                }

                // Clean phone number
                if (!empty($phone)) {
                    $phone = preg_replace('/[^0-9+]/', '', $phone);
                    if (strlen($phone) > 25) {
                        $phone = substr($phone, 0, 25);
                    }
                }

                // Insert or update record
                $result = $wpdb->replace(
                    $wpdb->prefix . 'mail_marketing',
                    array(
                        'email' => filter_var($email, FILTER_SANITIZE_EMAIL),
                        'name' => $this->sanitize_text($name),
                        'phone' => $this->sanitize_text($phone),
                        'status' => 0, // Default to pending
                        'created_at' => date_i18n('Y-m-d H:i:s'),
                        'updated_at' => date_i18n('Y-m-d H:i:s')
                    ),
                    array('%s', '%s', '%s', '%d', '%s', '%s')
                );

                if ($result !== false) {
                    $imported++;
                } else {
                    $errors[] = "Row " . ($row_index + 1) . ": Database error - " . $wpdb->last_error;
                    $skipped++;
                }
            }

            return array(
                'success' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'message' => "Imported: $imported, Skipped: $skipped"
            );
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Read file based on extension
     */
    private function read_file($file_path, $file_ext)
    {
        switch ($file_ext) {
            case 'csv':
                return $this->read_csv($file_path);
            case 'xlsx':
            case 'xls':
                return $this->read_excel($file_path);
            default:
                throw new Exception('Unsupported file format');
        }
    }

    /**
     * Read CSV file
     */
    private function read_csv($file_path)
    {
        $data = array();

        if (($handle = fopen($file_path, "r")) !== false) {
            while (($row = fgetcsv($handle, 1000, ",")) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }

        return $data;
    }

    /**
     * Read Excel file (basic implementation)
     * For production use, consider using PhpSpreadsheet library
     */
    private function read_excel($file_path)
    {
        // This is a simplified implementation
        // In production, you should use PhpSpreadsheet library

        // Try to convert to CSV first (requires server-side conversion)
        $csv_content = $this->excel_to_csv($file_path);

        if ($csv_content) {
            $temp_file = tempnam(sys_get_temp_dir(), 'excel_import');
            file_put_contents($temp_file, $csv_content);
            $data = $this->read_csv($temp_file);
            unlink($temp_file);
            return $data;
        }

        throw new Exception('Unable to read Excel file. Please convert to CSV format.');
    }

    /**
     * Convert Excel to CSV (basic implementation)
     */
    private function excel_to_csv($file_path)
    {
        // This is a placeholder - in production you would use PhpSpreadsheet
        // or another library to properly read Excel files

        // For now, return false to force CSV usage
        return false;
    }

    /**
     * Get column index from column name or letter
     */
    private function get_column_index($column)
    {
        if (is_numeric($column)) {
            return intval($column);
        }

        if (is_string($column)) {
            // If it's a letter (A, B, C, etc.), convert to index
            if (preg_match('/^[A-Za-z]$/', $column)) {
                return ord(strtoupper($column)) - ord('A');
            }

            // Return as is for column name lookup
            return $column;
        }

        return 0;
    }

    /**
     * Sanitize text input
     */
    private function sanitize_text($text)
    {
        if (empty($text)) {
            return '';
        }

        // Remove HTML tags and trim
        $text = strip_tags(trim($text));

        // Convert to UTF-8 if needed
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8');
        }

        return $text;
    }

    /**
     * Get cell value by column index
     */
    private function get_cell_value($row, $column)
    {
        if (is_numeric($column)) {
            return isset($row[$column]) ? trim($row[$column]) : '';
        }

        return '';
    }
}
