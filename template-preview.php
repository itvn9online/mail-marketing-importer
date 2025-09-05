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

// Define allowed templates
$allowed_templates = array(
    'default.html',
    'modern-minimal.html',
    'corporate-elegant.html',
    'creative-colorful.html',
    'professional-business.html',
    'tech-modern.html'
);

// Validate template
if (!in_array($template, $allowed_templates)) {
    $template = 'default.html';
}

// Template directory
$template_dir = dirname(__FILE__) . '/html-template/';
$template_path = $template_dir . $template;

// Check if template exists
if (!file_exists($template_path)) {
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
echo $template_content;
