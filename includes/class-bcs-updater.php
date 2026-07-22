<?php

if (!defined('ABSPATH')) exit;

final class BCS_Updater {
    private const LATEST_RELEASE_URL = 'https://github.com/kaulpl/basketmania-camp-system/releases/latest';
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
                'changelog' => 'Szczegóły wydania są dostępne na stronie GitHub Releases.',
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
        $response = wp_remote_head(self::LATEST_RELEASE_URL, [
            'timeout' => 20,
            'redirection' => 0,
            'headers' => [
                'User-Agent' => 'Basketmania-Camp-System/' . BCS_VERSION,
                'Accept' => 'text/html,application/xhtml+xml',
            ],
        ]);

        if (is_wp_error($response)) {
            self::save_error($checked_at, $response->get_error_message());
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $location = (string) wp_remote_retrieve_header($response, 'location');

        if (!in_array($code, [301, 302, 303, 307, 308], true) || $location === '') {
            $error = 'Nie udało się odczytać przekierowania do najnowszego wydania (HTTP ' . $code . ').';
            self::save_error($checked_at, $error);
            return new WP_Error('bcs_github_update', $error);
        }

        if (!preg_match('~/releases/tag/v?([^/?#]+)~', $location, $matches)) {
            $error = 'GitHub nie zwrócił poprawnego numeru wersji w adresie wydania.';
            self::save_error($checked_at, $error);
            return new WP_Error('bcs_github_update', $error);
        }

        $tag = sanitize_text_field((string) $matches[1]);
        $download_url = self::REPOSITORY_URL . '/releases/download/v' . rawurlencode($tag) . '/' . self::PLUGIN_SLUG . '-' . rawurlencode($tag) . '.zip';

        $release = [
            'version' => $tag,
            'download_url' => $download_url,
            'changelog' => '',
        ];

        set_site_transient(self::CACHE_KEY, $release, 30 * MINUTE_IN_SECONDS);
        update_site_option(self::LAST_CHECK_KEY, [
            'checked_at' => $checked_at,
            'ok' => true,
            'source' => 'GitHub Releases redirect',
            'http_code' => $code,
            'version' => $tag,
            'download_url' => $download_url,
            'update_available' => version_compare(BCS_VERSION, $tag, '<'),
            'error' => '',
        ]);

        return $release;
    }

    private static function save_error(string $checked_at, string $error): void {
        update_site_option(self::LAST_CHECK_KEY, [
            'checked_at' => $checked_at,
            'ok' => false,
            'source' => 'GitHub Releases redirect',
            'error' => $error,
        ]);
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
