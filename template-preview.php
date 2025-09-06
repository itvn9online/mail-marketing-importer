<?php

/**
 * Template Preview Script
 * 
 * This script loads and displays email templates with sample data
 * for preview purposes.
 */

// Get the template parameter
$template = isset($_GET['template']) ? basename($_GET['template']) : 'default.html';

// Remove any path characters for security
$template = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $template);

// Validate template phải là file .html
if (substr($template, -5) !== '.html') {
    $template = 'default.html';
}

// Template directory
$template_dir = dirname(__FILE__) . '/html-template/';
$template_path = $template_dir . $template;

// Check if template exists
if (!is_file($template_path)) {
    echo '<p style="padding: 20px; color: #d63638;">Template not found: ' . htmlspecialchars($template) . '</p>';
    exit;
}

// Load template content
$template_content = file_get_contents($template_path);

if ($template_content === false) {
    echo '<p style="padding: 20px; color: #d63638;">Error loading template.</p>';
    exit;
}

// Sample data for preview
$sample_data = array(
    '{FIRST_NAME}' => 'John',
    '{LAST_NAME}' => 'Doe',
    '{USER_NAME}' => 'John Doe',
    '{USER_EMAIL}' => 'john.doe@example.com',
    '{SITE_NAME}' => 'Your Website Name',
    '{SITE_URL}' => 'https://yourwebsite.com',
    '{UNSUBSCRIBE_URL}' => '#unsubscribe',
    '{CURRENT_DATE}' => date('F j, Y'),
    '{CURRENT_YEAR}' => date('Y')
);

// Replace placeholders with sample data
foreach ($sample_data as $placeholder => $value) {
    $template_content = str_replace($placeholder, $value, $template_content);
}

// Output the processed template
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
    <?php echo $template_content; ?>
</body>

</html>