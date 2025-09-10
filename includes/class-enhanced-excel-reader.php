<?php

/**
 * Enhanced Excel Reader with PhpSpreadsheet support
 * 
 * Install PhpSpreadsheet first:
 * composer require phpoffice/phpspreadsheet
 */

// Check if PhpSpreadsheet is available
if (is_file(MMI_PLUGIN_PATH . 'vendor/phpspreadsheet/vendor/autoload.php')) {
    require_once MMI_PLUGIN_PATH . 'vendor/phpspreadsheet/vendor/autoload.php';
} else {
    // If the zip file exists, try to extract it
    if (is_file(MMI_PLUGIN_PATH . 'vendor/phpspreadsheet.zip')) {
        $zip = new ZipArchive;
        if ($zip->open(MMI_PLUGIN_PATH . 'vendor/phpspreadsheet.zip') === TRUE) {
            $zip->extractTo(MMI_PLUGIN_PATH . 'vendor/');
            $zip->close();
        }
    }

    // 
    echo MMI_PLUGIN_PATH . 'vendor/phpspreadsheet/vendor/autoload.php' . '<br>' . PHP_EOL;

    die('PhpSpreadsheet library is required. Please install it using: composer require phpoffice/phpspreadsheet');
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class Enhanced_Excel_Reader
{

    private $use_phpspreadsheet = false;

    public function __construct()
    {
        $this->use_phpspreadsheet = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
    }

    /**
     * Read only the headers (first row) from a file
     *
     * @param string $file_path
     * @param string $file_type 'csv' or 'excel'
     * @return array
     */
    public function read_file_headers($file_path, $file_type)
    {
        try {
            if ($file_type === 'csv') {
                // For CSV files, read first line
                if (($handle = fopen($file_path, "r")) !== FALSE) {
                    $headers = fgetcsv($handle, 1000, ",");
                    fclose($handle);

                    // Clean headers and remove empty ones
                    $headers = array_map('trim', $headers);
                    $headers = array_filter($headers, function ($header) {
                        return !empty($header);
                    });

                    return array_values($headers);
                }
            } else {
                // For Excel files, use PhpSpreadsheet
                if ($this->use_phpspreadsheet) {
                    $spreadsheet = IOFactory::load($file_path);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $highestColumn = $worksheet->getHighestColumn();

                    $headers = array();
                    $columnIterator = $worksheet->getRowIterator(1, 1)->current()->getCellIterator('A', $highestColumn);

                    foreach ($columnIterator as $cell) {
                        $value = trim($cell->getCalculatedValue());
                        if (!empty($value)) {
                            $headers[] = $value;
                        }
                    }

                    return $headers;
                }
            }

            return array();
        } catch (Exception $e) {
            error_log('Enhanced_Excel_Reader: Error reading headers - ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Import data from Excel file
     */
    public function import_file($file_path, $file_ext, $options = array())
    {
        global $wpdb;

        try {
            $data = $this->read_file($file_path, $file_ext);

            if (empty($data)) {
                return array('success' => false, 'message' => 'No data found in file');
            }

            // Get campaign ID
            $campaign_id = isset($options['campaign_id']) ? (int)$options['campaign_id'] : null;
            // Validate campaign ID
            if (empty($campaign_id)) {
                return array('success' => false, 'message' => 'Invalid campaign ID');
            }

            // Determine if this is a new campaign
            $is_new_campaign = isset($options['campaign_option']) && $options['campaign_option'] === 'new' ? true : false;

            // Get column mappings - now expects direct indices from the form
            $email_column = isset($options['email_column']) && $options['email_column'] !== '' ? (int)$options['email_column'] : null;
            $first_name_column = isset($options['first_name_column']) && $options['first_name_column'] !== '' ? (int)$options['first_name_column'] : null;
            $last_name_column = isset($options['last_name_column']) && $options['last_name_column'] !== '' ? (int)$options['last_name_column'] : null;
            $name_column = isset($options['name_column']) && $options['name_column'] !== '' ? (int)$options['name_column'] : null;
            $phone_column = isset($options['phone_column']) && $options['phone_column'] !== '' ? (int)$options['phone_column'] : null;
            $address_column = isset($options['address_column']) && $options['address_column'] !== '' ? (int)$options['address_column'] : null;
            $city_column = isset($options['city_column']) && $options['city_column'] !== '' ? (int)$options['city_column'] : null;
            $state_column = isset($options['state_column']) && $options['state_column'] !== '' ? (int)$options['state_column'] : null;
            $zip_code_column = isset($options['zip_code_column']) && $options['zip_code_column'] !== '' ? (int)$options['zip_code_column'] : null;
            $skip_header = !empty($options['skip_header']);

            // Validate required email column
            if ($email_column === null) {
                die('Error: Email column is required for import');
            }

            $imported = 0;
            $skipped = 0;
            $errors = array();

            // Skip header row if specified
            if ($skip_header && count($data) > 0) {
                array_shift($data);
            }

            foreach ($data as $row_index => $row) {
                $email = $this->get_cell_value($row, $email_column);
                // Clean and validate email
                $email = trim($email);
                if (empty($email)) {
                    $errors[] = "Row " . ($row_index + 1) . ": Email is required";
                    $skipped++;
                    continue;
                }
                // chuyển email về chữ thường
                $email = strtolower($email);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Row " . ($row_index + 1) . ": Invalid email format - " . $email;
                    $skipped++;
                    continue;
                }
                $first_name = $this->get_cell_value($row, $first_name_column);
                $last_name = $this->get_cell_value($row, $last_name_column);
                $name = $this->get_cell_value($row, $name_column);
                $phone = $this->get_cell_value($row, $phone_column);
                $address = $this->get_cell_value($row, $address_column);
                $city = $this->get_cell_value($row, $city_column);
                $state = $this->get_cell_value($row, $state_column);
                $zip_code = $this->get_cell_value($row, $zip_code_column);
                $city = $this->get_cell_value($row, $city_column);
                $state = $this->get_cell_value($row, $state_column);
                $zip_code = $this->get_cell_value($row, $zip_code_column);

                // If first_name and last_name are provided, combine them for the name field
                if (!empty($first_name) || !empty($last_name)) {
                    $combined_name = trim($first_name . ' ' . $last_name);
                    if (!empty($combined_name)) {
                        $name = $combined_name;
                    }
                }

                // Clean data fields
                if (!empty($first_name)) {
                    $first_name = trim($first_name);
                    if (strlen($first_name) > 255) {
                        $first_name = substr($first_name, 0, 255);
                    }
                }

                // Clean last_name
                if (!empty($last_name)) {
                    $last_name = trim($last_name);
                    if (strlen($last_name) > 255) {
                        $last_name = substr($last_name, 0, 255);
                    }
                }

                // Clean name
                if (!empty($name)) {
                    $name = trim($name);
                    if (strlen($name) > 255) {
                        $name = substr($name, 0, 255);
                    }
                }

                // Clean phone number
                if (!empty($phone)) {
                    $phone = trim($phone);
                    // Remove all non-numeric characters except +, -, (), and spaces
                    $phone = preg_replace('/[^0-9+\-\(\)\s]/', '', $phone);
                    // Remove extra spaces
                    $phone = preg_replace('/\s+/', ' ', $phone);
                    $phone = trim($phone);

                    if (strlen($phone) > 25) {
                        $phone = substr($phone, 0, 25);
                    }
                }

                // Clean address
                if (!empty($address)) {
                    $address = trim($address);
                    if (strlen($address) > 255) {
                        $address = substr($address, 0, 255);
                    }
                }

                // Clean city
                if (!empty($city)) {
                    $city = trim($city);
                    if (strlen($city) > 100) {
                        $city = substr($city, 0, 100);
                    }
                }

                // Clean state
                if (!empty($state)) {
                    $state = trim($state);
                    if (strlen($state) > 50) {
                        $state = substr($state, 0, 50);
                    }
                }

                // Clean zip code
                if (!empty($zip_code)) {
                    $zip_code = trim($zip_code);
                    // Remove all non-alphanumeric characters except hyphens and spaces
                    $zip_code = preg_replace('/[^0-9A-Za-z\-\s]/', '', $zip_code);
                    $zip_code = trim($zip_code);

                    if (strlen($zip_code) > 20) {
                        $zip_code = substr($zip_code, 0, 20);
                    }
                }

                // Database operations
                $insert_new = true;
                $result = false;

                // nếu đây không phải là một chiến dịch mới
                if (!$is_new_campaign) {
                    // kiểm tra xem email + campaign_id đã tồn tại trong bảng mail_marketing
                    $existing_record = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}mail_marketing WHERE email = %s AND campaign_id = %d",
                            $email,
                            $campaign_id
                        )
                    );

                    // chạy lệnh update nếu đã tồn tại
                    if (!empty($existing_record)) {
                        $result = $wpdb->update(
                            $wpdb->prefix . 'mail_marketing',
                            array(
                                'first_name' => $first_name,
                                'last_name' => $last_name,
                                'name' => $name,
                                'phone' => $phone,
                                'address' => $address,
                                'city' => $city,
                                'state' => $state,
                                'zip_code' => $zip_code,
                                'updated_at' => current_time('mysql')
                            ),
                            array('id' => $existing_record->id),
                            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                            array('%d')
                        );

                        $insert_new = false; // không chèn bản ghi mới
                    }
                }

                // chạy lệnh insert vào bảng mail_marketing
                if ($insert_new) {
                    $result = $wpdb->insert(
                        $wpdb->prefix . 'mail_marketing',
                        array(
                            'campaign_id' => $campaign_id,
                            'email' => $email,
                            'first_name' => $first_name,
                            'last_name' => $last_name,
                            'name' => $name,
                            'phone' => $phone,
                            'address' => $address,
                            'city' => $city,
                            'state' => $state,
                            'zip_code' => $zip_code,
                            'status' => 0, // Default to pending
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql')
                        ),
                        array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
                    );
                }

                // Handle database errors
                if ($result === false) {
                    $error_msg = 'Database error: ' . $wpdb->last_error;
                    die($error_msg);
                }

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
                if ($this->use_phpspreadsheet) {
                    return $this->read_excel_phpspreadsheet($file_path);
                } else {
                    return $this->read_excel_fallback($file_path);
                }
            default:
                throw new Exception('Unsupported file format');
        }
    }

    /**
     * Read CSV file with better encoding detection
     */
    private function read_csv($file_path)
    {
        $data = array();

        // Try to detect encoding
        $content = file_get_contents($file_path);
        if ($content === false) {
            die('Error: Cannot read CSV file');
        }
        $encoding = mb_detect_encoding($content, ['UTF-8', 'UTF-16', 'Windows-1252', 'ISO-8859-1'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            $temp_file = tempnam(sys_get_temp_dir(), 'csv_utf8');
            file_put_contents($temp_file, $content);
            $file_path = $temp_file;
        }

        if (($handle = fopen($file_path, "r")) !== false) {
            while (($row = fgetcsv($handle, 10000, ",")) !== false) {
                // Skip completely empty rows
                if (array_filter($row, 'strlen')) {
                    $data[] = $row;
                }
            }
            fclose($handle);
        } else {
            die('Error: Failed to open CSV file');
        }

        // Clean up temp file if created
        if (isset($temp_file) && is_file($temp_file)) {
            unlink($temp_file);
        }

        return $data;
    }

    /**
     * Read Excel file using PhpSpreadsheet
     */
    private function read_excel_phpspreadsheet($file_path)
    {
        try {
            $reader = IOFactory::createReaderForFile($file_path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file_path);

            $worksheet = $spreadsheet->getActiveSheet();
            $data = array();

            foreach ($worksheet->getRowIterator() as $row) {
                $rowData = array();
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                foreach ($cellIterator as $cell) {
                    $value = $cell->getValue();

                    // Handle date values
                    if (Date::isDateTime($cell)) {
                        $value = $cell->getFormattedValue();
                    }

                    $rowData[] = (string) $value;
                }

                // Skip completely empty rows
                if (array_filter($rowData, 'strlen')) {
                    $data[] = $rowData;
                }
            }

            return $data;
        } catch (Exception $e) {
            throw new Exception('Error reading Excel file: ' . $e->getMessage());
        }
    }

    /**
     * Fallback Excel reader (limited functionality)
     */
    private function read_excel_fallback($file_path)
    {
        throw new Exception('Excel file support requires PhpSpreadsheet library. Please install it using: composer require phpoffice/phpspreadsheet');
    }

    /**
     * Find column index by name in header row
     */
    private function find_column_index($header_row, $column_name)
    {
        if (is_numeric($column_name)) {
            return intval($column_name);
        }

        if (is_string($column_name)) {
            // If it's a letter (A, B, C, etc.), convert to index
            if (preg_match('/^[A-Za-z]+$/', $column_name) && strlen($column_name) <= 2) {
                return $this->letter_to_number($column_name);
            }

            // Search for column name in header
            $column_lower = strtolower(trim($column_name));
            foreach ($header_row as $index => $header_value) {
                if (strtolower(trim($header_value)) === $column_lower) {
                    return $index;
                }
            }
        }

        return $column_name; // Return as-is if not found
    }

    /**
     * Convert Excel column letters to numbers (A=0, B=1, etc.)
     */
    private function letter_to_number($letters)
    {
        $letters = strtoupper($letters);
        $result = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $result = $result * 26 + (ord($letters[$i]) - ord('A') + 1);
        }

        return $result - 1; // Convert to 0-based index
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
            if (preg_match('/^[A-Za-z]+$/', $column) && strlen($column) <= 2) {
                return $this->letter_to_number($column);
            }

            // Return as is for column name lookup
            return $column;
        }

        return 0;
    }

    /**
     * Get cell value by column index
     */
    private function get_cell_value($row, $column)
    {
        if ($column === null || !isset($row[$column])) {
            return '';
        }

        $value = $row[$column];

        // Handle different data types
        if (is_object($value)) {
            // PhpSpreadsheet cell object
            return trim((string)$value->getCalculatedValue());
        }

        return trim((string)$value);
    }
}
