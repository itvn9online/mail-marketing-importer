<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

?>
<!-- Email List Section - Show when not editing -->
<div id="imported-email-list" class="campaign-section">
    <h2>Imported Email List</h2>
    <?php $this->render_email_list(); ?>
</div>