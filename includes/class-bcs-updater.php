<?php

if (!defined('ABSPATH')) exit;

final class BCS_Updater {
    private const API_URL = 'https://update.basketmania.pl/api/v1/plugin';
    private const PLUGIN_SLUG = 'basketmania-camp-system';
    private const CACHE_KEY = 'bcs_update_manifest';

    public static function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'check_for_update']);
        add_filter('plugins_api', [self::class, 'plugin_information'], 20, 3);
        add_filter('upgrader_post_install', [self::class, 'fix_folder_after_update'], 10, 3);
    }

    public static function check_for_update($transient) {
        if (!is_object($transient) || empty($transient->checked) || !BCS_License::is_valid()) return $transient;

        $manifest = self::get_manifest();
        if (is_wp_error($manifest) || empty($manifest['version']) || empty($manifest['download_url'])) return $transient;

        if (version_compare(BCS_VERSION, (string) $manifest['version'], '<')) {
            $plugin = plugin_basename(BCS_FILE);
            $transient->response[$plugin] = (object) [
                'slug' => self::PLUGIN_SLUG,
                'plugin' => $plugin,
                'new_version' => sanitize_text_field((string) $manifest['version']),
                'url' => isset($manifest['homepage']) ? esc_url_raw((string) $manifest['homepage']) : '',
                'package' => esc_url_raw((string) $manifest['download_url']),
                'tested' => isset($manifest['tested']) ? sanitize_text_field((string) $manifest['tested']) : '',
                'requires_php' => isset($manifest['requires_php']) ? sanitize_text_field((string) $manifest['requires_php']) : '8.1',
            ];
        }

        return $transient;
    }

    public static function plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) return $result;
        if (!BCS_License::is_valid()) return $result;

        $manifest = self::get_manifest();
        if (is_wp_error($manifest)) return $result;

        return (object) [
            'name' => 'Basketmania Camp System',
            'slug' => self::PLUGIN_SLUG,
            'version' => sanitize_text_field((string) ($manifest['version'] ?? BCS_VERSION)),
            'author' => '<a href="https://basketmania.pl">Basketmania Camp</a>',
            'homepage' => esc_url_raw((string) ($manifest['homepage'] ?? 'https://basketmania.pl')),
            'requires' => sanitize_text_field((string) ($manifest['requires'] ?? '6.5')),
            'tested' => sanitize_text_field((string) ($manifest['tested'] ?? '')),
            'requires_php' => sanitize_text_field((string) ($manifest['requires_php'] ?? '8.1')),
            'download_link' => esc_url_raw((string) ($manifest['download_url'] ?? '')),
            'sections' => [
                'description' => wp_kses_post((string) ($manifest['description'] ?? 'System obsługi Basketmania Camp.')),
                'changelog' => wp_kses_post((string) ($manifest['changelog'] ?? '')),
            ],
        ];
    }

    private static function get_manifest(bool $force = false) {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) return $cached;
        }

        $key = BCS_License::get_key();
        $url = add_query_arg([
            'plugin' => self::PLUGIN_SLUG,
            'site_url' => home_url('/'),
            'version' => BCS_VERSION,
            'license_key' => $key,
        ], self::API_URL . '/manifest');

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) return $response;

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new WP_Error('bcs_update_api', 'Nie udało się pobrać informacji o aktualizacji.');
        }

        set_site_transient(self::CACHE_KEY, $body, 6 * HOUR_IN_SECONDS);
        return $body;
    }

    public static function fix_folder_after_update($response, $hook_extra, $result) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(BCS_FILE)) return $result;
        global $wp_filesystem;
        $proper_destination = trailingslashit(WP_PLUGIN_DIR) . self::PLUGIN_SLUG;
        if (!empty($result['destination']) && untrailingslashit($result['destination']) !== untrailingslashit($proper_destination)) {
            $wp_filesystem->move($result['destination'], $proper_destination, true);
            $result['destination'] = $proper_destination;
            $result['destination_name'] = self::PLUGIN_SLUG;
        }
        return $result;
    }
}
