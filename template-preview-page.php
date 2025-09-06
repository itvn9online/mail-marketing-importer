<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Template Preview</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .preview-header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .template-selector {
            margin-bottom: 15px;
        }

        .template-selector select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            min-width: 200px;
        }

        .preview-button {
            background: #0073aa;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-left: 10px;
        }

        .preview-button:hover {
            background: #005a87;
        }

        .email-preview {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            min-height: 400px;
        }

        .email-preview iframe {
            width: 100%;
            min-height: 600px;
            border: none;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #0073aa;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="preview-header">
        <h1>Email Template Preview</h1>
        <div class="template-selector">
            <select id="templateSelect">
                <?php
                // lấy danh sách file .html trong thư mục html-template
                $template_dir = __DIR__ . '/html-template/';
                $templates = glob($template_dir . '*.html');
                foreach ($templates as $template_file) {
                    $template_name = basename($template_file);
                    echo '<option value="' . htmlspecialchars($template_name) . '">' . htmlspecialchars($template_name) . '</option>';
                }
                ?>
            </select>
            <button class="preview-button" onclick="loadTemplate()">Preview Template</button>
        </div>
    </div>

    <div class="email-preview">
        <iframe id="templatePreview" src=""></iframe>
    </div>

    <script>
        function loadTemplate() {
            const templateSelect = document.getElementById('templateSelect');
            const preview = document.getElementById('templatePreview');
            const selectedTemplate = templateSelect.value;

            // Update URL parameter
            const url = new URL(window.location);
            url.searchParams.set('template', selectedTemplate);
            window.history.pushState({}, '', url);

            // Create preview URL
            const previewUrl = 'template-preview.php?template=' + encodeURIComponent(selectedTemplate);
            preview.src = previewUrl;
        }

        // Function to get URL parameter
        function getUrlParameter(name) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        }

        // Load default template on page load
        window.onload = function() {
            // Check if template parameter exists in URL
            const templateParam = getUrlParameter('template');
            const templateSelect = document.getElementById('templateSelect');

            if (templateParam) {
                // Set the select option to match the URL parameter
                templateSelect.value = templateParam;
            }

            // Load the template (either from URL param or default)
            loadTemplate();
        };

        // Update preview when selection changes
        document.getElementById('templateSelect').addEventListener('change', loadTemplate);
    </script>
</body>

</html>