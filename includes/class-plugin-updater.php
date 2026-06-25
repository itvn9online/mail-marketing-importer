<?php

/**
 * GitHub-based plugin updater for Mail Marketing Importer.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MMI_Plugin_Updater
{
    /** @var string */
    private $plugin_file;
    /** @var string */
    private $plugin_slug;
    /** @var string */
    private $github_user;
    /** @var string */
    private $github_repo;
    /** @var string */
    private $branch;

    public function __construct(string $plugin_file, string $github_user, string $github_repo, string $branch = 'main')
    {
        $this->plugin_file  = $plugin_file;
        $this->plugin_slug  = dirname(plugin_basename($plugin_file));
        $this->github_user  = $github_user;
        $this->github_repo  = $github_repo;
        $this->branch       = $branch;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_folder'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'after_upgrade'], 10, 2);
    }

    private function zip_url(): string
    {
        return sprintf(
            'https://github.com/%s/%s/archive/refs/heads/%s.zip',
            $this->github_user,
            $this->github_repo,
            $this->branch
        );
    }

    private function remote_version_urls(): array
    {
        $base = sprintf(
            'https://raw.githubusercontent.com/%s/%s/refs/heads/%s',
            $this->github_user,
            $this->github_repo,
            $this->branch
        );

        return [
            $base . '/version.txt',
            $base . '/VERSION',
        ];
    }

    public static function read_version_from_path(string $dir): string
    {
        foreach (['version.txt', 'VERSION'] as $file) {
            $path = trailingslashit($dir) . $file;
            if (is_readable($path)) {
                $version = trim((string) file_get_contents($path));
                if ($version !== '') {
                    return $version;
                }
            }
        }

        return '';
    }

    private function local_version(): string
    {
        $version = self::read_version_from_path(dirname($this->plugin_file));
        if ($version !== '') {
            return $version;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data($this->plugin_file, false, false);

        return $data['Version'] ?? '0.0.0';
    }

    /**
     * @return string|false
     */
    private function fetch_remote_version()
    {
        $cache_key = 'mmi_github_version_' . md5($this->github_user . $this->github_repo . $this->branch);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached === 'none' ? false : $cached;
        }

        foreach ($this->remote_version_urls() as $url) {
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'headers' => ['Accept' => 'text/plain'],
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                continue;
            }

            $version = trim(wp_remote_retrieve_body($response));
            if ($version !== '' && preg_match('/^[0-9]+(?:\.[0-9]+)*(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
                set_transient($cache_key, $version, 12 * HOUR_IN_SECONDS);
                return $version;
            }
        }

        set_transient($cache_key, 'none', HOUR_IN_SECONDS);

        return false;
    }

    public function check_for_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote_version = $this->fetch_remote_version();
        if ($remote_version === false) {
            return $transient;
        }

        $local_version = $this->local_version();
        if (version_compare($remote_version, $local_version, '<=')) {
            return $transient;
        }

        $plugin_basename = plugin_basename($this->plugin_file);

        $transient->response[$plugin_basename] = (object) [
            'slug'        => $this->github_repo,
            'plugin'      => $plugin_basename,
            'new_version' => $remote_version,
            'url'         => sprintf('https://github.com/%s/%s', $this->github_user, $this->github_repo),
            'package'     => $this->zip_url(),
            'icons'       => [],
            'banners'     => [],
            'tested'      => '',
            'requires'    => '',
            'compatibility' => new stdClass(),
        ];

        return $transient;
    }

    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->github_repo) {
            return $result;
        }

        $remote_version = $this->fetch_remote_version();
        if ($remote_version === false) {
            return $result;
        }

        return (object) [
            'name'          => 'Mail Marketing Importer',
            'slug'          => $this->github_repo,
            'version'       => $remote_version,
            'author'        => '<a href="https://github.com/' . esc_attr($this->github_user) . '">' . esc_html($this->github_user) . '</a>',
            'homepage'      => sprintf('https://github.com/%s/%s', $this->github_user, $this->github_repo),
            'download_link' => $this->zip_url(),
            'sections'      => [
                'description' => 'Import email marketing data from Excel files (.xlsx, .xls, .csv).',
                'changelog'   => '<p>See <a href="https://github.com/' . esc_attr($this->github_user) . '/' . esc_attr($this->github_repo) . '/commits/' . esc_attr($this->branch) . '">commit history on GitHub</a>.</p>',
            ],
            'last_updated'  => date('Y-m-d'),
        ];
    }

    /**
     * GitHub zip extracts to {repo}-main; normalize to {repo} before install.
     */
    public function fix_source_folder($source, $remote_source, $upgrader, $hook_extra)
    {
        if (($hook_extra['type'] ?? '') !== 'plugin' || empty($hook_extra['plugin'])) {
            return $source;
        }

        if ($hook_extra['plugin'] !== plugin_basename($this->plugin_file)) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return $source;
        }

        $target_folder = $this->github_repo;
        $source_name   = basename(untrailingslashit($source));

        if ($source_name === $target_folder) {
            return $source;
        }

        if (!$this->is_github_extract_folder($source_name)) {
            return $source;
        }

        $desired_source = trailingslashit(dirname($source)) . $target_folder;

        if ($wp_filesystem->exists($desired_source)) {
            $wp_filesystem->delete($desired_source, true);
        }

        if (!$wp_filesystem->move($source, $desired_source)) {
            return new WP_Error(
                'mmi_rename_failed',
                sprintf('Could not rename plugin folder from %s to %s.', $source_name, $target_folder)
            );
        }

        return $desired_source;
    }

    /**
     * After upgrade: if plugin was installed under *-main, move to canonical folder.
     */
    public function after_upgrade($upgrader, $hook_extra)
    {
        if (($hook_extra['type'] ?? '') !== 'plugin' || empty($hook_extra['plugins'])) {
            return;
        }

        $plugin_basename = plugin_basename($this->plugin_file);
        if (!in_array($plugin_basename, $hook_extra['plugins'], true)) {
            return;
        }

        if (function_exists('mail_marketing_importer_update_database')) {
            mail_marketing_importer_update_database();
        }

        $current_slug = $this->plugin_slug;
        if (!$this->is_github_extract_folder($current_slug)) {
            return;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if (!$wp_filesystem) {
            return;
        }

        $plugins_dir   = trailingslashit(WP_PLUGIN_DIR);
        $source_dir    = $plugins_dir . $current_slug;
        $target_dir    = $plugins_dir . $this->github_repo;

        if (!$wp_filesystem->exists($source_dir) || $source_dir === $target_dir) {
            return;
        }

        if ($wp_filesystem->exists($target_dir)) {
            $wp_filesystem->delete($target_dir, true);
        }

        if (!$wp_filesystem->move($source_dir, $target_dir)) {
            return;
        }

        $old_basename = $this->plugin_slug . '/' . basename($this->plugin_file);
        $new_basename = $this->github_repo . '/' . basename($this->plugin_file);

        if ($old_basename === $new_basename) {
            return;
        }

        $active_plugins = get_option('active_plugins', []);
        $changed        = false;

        foreach ($active_plugins as $index => $plugin) {
            if ($plugin === $old_basename) {
                $active_plugins[$index] = $new_basename;
                $changed                = true;
            }
        }

        if ($changed) {
            update_option('active_plugins', $active_plugins);
        }

        if (is_multisite()) {
            $network_active = get_site_option('active_sitewide_plugins', []);
            if (isset($network_active[$old_basename])) {
                $network_active[$new_basename] = $network_active[$old_basename];
                unset($network_active[$old_basename]);
                update_site_option('active_sitewide_plugins', $network_active);
            }
        }
    }

    private function is_github_extract_folder($folder_name)
    {
        if ($folder_name === $this->github_repo) {
            return false;
        }

        $branch_suffix = '-' . $this->branch;
        if ($folder_name === $this->github_repo . $branch_suffix) {
            return true;
        }

        // Thư mục cũ đã cài nhầm dạng *-main
        return (bool) preg_match('/^' . preg_quote($this->github_repo, '/') . '-main$/', $folder_name);
    }
}
