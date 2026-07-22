<?php

if (!defined('ABSPATH')) exit;

final class BCS_Updater {
    private const API_URL = 'https://api.github.com/repos/kaulpl/basketmania-camp-system/releases/latest';
    private const REPOSITORY_URL = 'https://github.com/kaulpl/basketmania-camp-system';
    private const PLUGIN_SLUG = 'basketmania-camp-system';
    private const CACHE_KEY = 'bcs_github_release';
    private const LAST_CHECK_KEY = 'bcs_github_update_last_check';

    public static function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'check_for_update']);
        add_filter('plugins_api', [self::class, 'plugin_information'], 20, 3);
        add_filter('upgrader_post_install', [self::class, 'fix_folder_after_update'], 10, 3);
        add_action('upgrader_process_complete', [self::class, 'clear_cache_after_update'], 10, 2);
    }

    public static function check_for_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        // Ten filtr jest uruchamiany, gdy WordPress faktycznie odświeża listę aktualizacji.
        // Pomijamy wtedy własny cache, aby „Sprawdź ponownie” zawsze pytało GitHub o świeży release.
        $release = self::get_release(true);
        $plugin = plugin_basename(BCS_FILE);

        if (is_wp_error($release) || empty($release['version']) || empty($release['download_url'])) {
            return $transient;
        }

        $item = (object) [
            'slug' => self::PLUGIN_SLUG,
            'plugin' => $plugin,
            'new_version' => sanitize_text_field((string) $release['version']),
            'url' => self::REPOSITORY_URL,
            'package' => esc_url_raw((string) $release['download_url']),
            'tested' => '',
            'requires_php' => '8.1',
        ];

        if (version_compare(BCS_VERSION, (string) $release['version'], '<')) {
            $transient->response[$plugin] = $item;
            unset($transient->no_update[$plugin]);
        } else {
            $transient->no_update[$plugin] = $item;
            unset($transient->response[$plugin]);
        }

        return $transient;
    }

    public static function plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $release = self::get_release();
        if (is_wp_error($release)) {
            return $result;
        }

        return (object) [
            'name' => 'Basketmania Camp System',
            'slug' => self::PLUGIN_SLUG,
            'version' => sanitize_text_field((string) ($release['version'] ?? BCS_VERSION)),
            'author' => '<a href="https://basketmania.pl">Basketmania Camp</a>',
            'homepage' => self::REPOSITORY_URL,
            'requires' => '6.5',
            'tested' => '',
            'requires_php' => '8.1',
            'download_link' => esc_url_raw((string) ($release['download_url'] ?? '')),
            'sections' => [
                'description' => 'System zapisów, CRM, umów, płatności i dokumentów dla Basketmania Camp.',
                'changelog' => wpautop(esc_html((string) ($release['changelog'] ?? ''))),
            ],
        ];
    }

    private static function get_release(bool $force = false) {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $checked_at = current_time('mysql');
        $response = wp_remote_get(self::API_URL, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Basketmania-Camp-System/' . BCS_VERSION,
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        if (is_wp_error($response)) {
            update_site_option(self::LAST_CHECK_KEY, [
                'checked_at' => $checked_at,
                'ok' => false,
                'error' => $response->get_error_message(),
            ]);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            update_site_option(self::LAST_CHECK_KEY, [
                'checked_at' => $checked_at,
                'ok' => false,
                'error' => 'HTTP ' . $code,
            ]);
            return new WP_Error('bcs_github_update', 'Nie udało się pobrać informacji o aktualizacji z GitHuba.');
        }

        $tag = isset($body['tag_name']) ? ltrim((string) $body['tag_name'], 'vV') : '';
        $download_url = self::find_zip_asset($body['assets'] ?? [], $tag);

        if ($download_url === '' && !empty($body['zipball_url'])) {
            $download_url = (string) $body['zipball_url'];
        }

        $release = [
            'version' => $tag,
            'download_url' => $download_url,
            'changelog' => isset($body['body']) ? (string) $body['body'] : '',
        ];

        set_site_transient(self::CACHE_KEY, $release, 30 * MINUTE_IN_SECONDS);
        update_site_option(self::LAST_CHECK_KEY, [
            'checked_at' => $checked_at,
            'ok' => $tag !== '' && $download_url !== '',
            'version' => $tag,
            'download_url' => $download_url,
            'update_available' => $tag !== '' && version_compare(BCS_VERSION, $tag, '<'),
            'error' => ($tag === '' || $download_url === '') ? 'Release nie zawiera wersji lub adresu paczki.' : '',
        ]);

        return $release;
    }

    private static function find_zip_asset(array $assets, string $version): string {
        $preferred_name = self::PLUGIN_SLUG . '-' . $version . '.zip';

        foreach ($assets as $asset) {
            if (!is_array($asset)) continue;
            $name = isset($asset['name']) ? (string) $asset['name'] : '';
            $url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
            if ($name === $preferred_name && $url !== '') {
                return $url;
            }
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) continue;
            $name = isset($asset['name']) ? (string) $asset['name'] : '';
            $url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
            if ($url !== '' && str_ends_with(strtolower($name), '.zip')) {
                return $url;
            }
        }

        return '';
    }

    public static function force_refresh(): array {
        self::clear_cache();
        $release = self::get_release(true);
        if (is_wp_error($release)) {
            return ['ok' => false, 'error' => $release->get_error_message()];
        }
        wp_clean_plugins_cache(true);
        return [
            'ok' => !empty($release['version']) && !empty($release['download_url']),
            'version' => (string) ($release['version'] ?? ''),
            'download_url' => (string) ($release['download_url'] ?? ''),
            'update_available' => !empty($release['version']) && version_compare(BCS_VERSION, (string) $release['version'], '<'),
        ];
    }

    public static function diagnostics(): array {
        $last = get_site_option(self::LAST_CHECK_KEY, []);
        return is_array($last) ? $last : [];
    }

    public static function clear_cache(): void {
        delete_site_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
    }

    public static function fix_folder_after_update($response, $hook_extra, $result) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(BCS_FILE)) {
            return $result;
        }

        global $wp_filesystem;
        $proper_destination = trailingslashit(WP_PLUGIN_DIR) . self::PLUGIN_SLUG;

        if (!empty($result['destination']) && untrailingslashit($result['destination']) !== untrailingslashit($proper_destination)) {
            $wp_filesystem->move($result['destination'], $proper_destination, true);
            $result['destination'] = $proper_destination;
            $result['destination_name'] = self::PLUGIN_SLUG;
        }

        return $result;
    }

    public static function clear_cache_after_update($upgrader, array $options): void {
        if (($options['action'] ?? '') === 'update' && ($options['type'] ?? '') === 'plugin') {
            self::clear_cache();
        }
    }
}
