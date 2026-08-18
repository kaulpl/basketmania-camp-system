<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.03 – publiczny, przyjazny adres wypisania z mailingu.
 */
final class BCS_Release_103 {
    private const PREFIX = 'mailing/wypisz/';

    public static function init(): void {
        add_action('parse_request', [__CLASS__, 'handle_pretty_unsubscribe'], 1);
    }

    public static function pretty_unsubscribe_url(string $token): string {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return '';
        return home_url('/'.self::PREFIX.rawurlencode($token).'/');
    }

    public static function handle_pretty_unsubscribe($wp): void {
        $request = trim((string)($wp->request ?? ''), '/');
        if (!preg_match('#^mailing/wypisz/([a-f0-9]{64})$#i', $request, $m)) return;

        $_GET['token'] = strtolower((string)$m[1]);
        BCS_Release_096::handle_unsubscribe();
    }
}
