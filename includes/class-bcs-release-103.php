<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.03 – publiczny, przyjazny adres wypisania z mailingu.
 */
final class BCS_Release_103 {
    private const PREFIX = 'mailing/wypisz/';

    public static function init(): void {
        add_action('parse_request', [__CLASS__, 'handle_pretty_unsubscribe'], 1);
        add_filter('wp_mail', [__CLASS__, 'rewrite_outgoing_unsubscribe_links'], 999);
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

    /**
     * 0.96 generowało link do admin-post.php. Zachowujemy stary endpoint dla
     * już wysłanych wiadomości, ale przed wysłaniem nowych maili zamieniamy go
     * na publiczny adres /mailing/wypisz/{token}/ – również w nagłówku
     * List-Unsubscribe używanym przez One-Click Unsubscribe.
     */
    public static function rewrite_outgoing_unsubscribe_links(array $mail): array {
        $rewrite = static function(string $value): string {
            if (!str_contains($value, 'bcs_marketing_unsubscribe_096')) return $value;
            $pattern = '#https?://[^\s"\'<>]+/wp-admin/admin-post\.php\?action=bcs_marketing_unsubscribe_096(?:&|&amp;)token=([a-f0-9]{64})#i';
            return (string)preg_replace_callback($pattern, static function(array $m): string {
                $pretty = self::pretty_unsubscribe_url((string)$m[1]);
                return $pretty !== '' ? $pretty : (string)$m[0];
            }, $value);
        };

        if (isset($mail['message'])) $mail['message'] = $rewrite((string)$mail['message']);
        if (isset($mail['headers'])) {
            if (is_array($mail['headers'])) {
                foreach ($mail['headers'] as $i => $header) $mail['headers'][$i] = $rewrite((string)$header);
            } else {
                $mail['headers'] = $rewrite((string)$mail['headers']);
            }
        }
        return $mail;
    }
}
