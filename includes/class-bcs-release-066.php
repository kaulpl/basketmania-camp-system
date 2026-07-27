<?php
if (!defined('ABSPATH')) exit;

/**
 * Poprawki wersji 0.66:
 * - jeden komunikat „Wymaga akcji” na Liście Zgłoszeń,
 * - biały układ całej umowy,
 * - stały nagłówek z logo i stała stopka na każdej stronie PDF,
 * - ten sam układ w podglądach dokumentu.
 */
final class BCS_Release_066 {
    private const LOGO_URL = 'https://b4080341.smushcdn.com/4080341/wp-content/uploads/2026/07/basketmania-logo-navy-300x128.png?lossy=2&strip=1&avif=1';
    private const LOGO_CACHE_OPTION = 'bcs_release_066_navy_logo_data_uri';
    private const STYLE_ID = 'bcs-agreement-style-066';

    public static function init(): void {
        self::replace_preview_handlers();
        add_action('admin_head', [__CLASS__, 'action_required_css'], 100);
        add_action('admin_footer', [__CLASS__, 'action_required_script'], 100);
    }

    /**
     * Podglądy HTML z wersji 0.52 i 0.54 nie przechodzą przez BCS_PDF,
     * dlatego przechwytujemy ich wynik i stosujemy ten sam układ co w PDF.
     */
    private static function replace_preview_handlers(): void {
        if (class_exists('BCS_Release_052')) {
            remove_action('admin_post_bcs_agreement_view', ['BCS_Release_052', 'render_agreement_view'], 0);
            remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_052', 'render_agreement_view'], 0);
            add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
            add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        }
        if (class_exists('BCS_Release_054')) {
            remove_action('admin_post_bcs_agreement_version_preview_054', ['BCS_Release_054', 'render_version_preview']);
            add_action('admin_post_bcs_agreement_version_preview_054', [__CLASS__, 'render_version_preview']);
        }
    }

    public static function render_agreement_view(): void {
        ob_start([__CLASS__, 'buffer_agreement_html']);
        BCS_Release_052::render_agreement_view();
    }

    public static function render_version_preview(): void {
        ob_start([__CLASS__, 'buffer_agreement_html']);
        BCS_Release_054::render_version_preview();
    }

    public static function buffer_agreement_html(string $html): string {
        return self::prepare_agreement_html($html);
    }

    public static function logo_url(): string {
        return self::LOGO_URL;
    }

    /**
     * Logo jest pobierane z podanego przez Organizatora adresu i zapisywane jako
     * data URI. Dzięki temu Dompdf nie potrzebuje dostępu zdalnego podczas każdego
     * generowania dokumentu. Gdy serwer chwilowo nie może pobrać pliku, używany jest
     * lokalny zasób logo obecny już we wtyczce.
     */
    public static function logo_data_uri(): string {
        static $memo = null;
        if (is_string($memo)) return $memo;

        if (function_exists('get_option')) {
            $cached = (string)get_option(self::LOGO_CACHE_OPTION, '');
            if (self::valid_data_uri($cached)) return $memo = $cached;
        }

        $remote = self::download_logo_data_uri();
        if ($remote !== '') {
            if (function_exists('update_option')) update_option(self::LOGO_CACHE_OPTION, $remote, false);
            return $memo = $remote;
        }

        $base = defined('BCS_DIR') ? BCS_DIR : dirname(__DIR__).'/';
        $fallback = $base.'assets/images/logo-basketmania-camp-color-retina.png.b64';
        if (is_readable($fallback)) {
            $encoded = preg_replace('/\s+/', '', (string)file_get_contents($fallback));
            if (is_string($encoded) && $encoded !== '' && base64_decode($encoded, true) !== false) {
                return $memo = 'data:image/png;base64,'.$encoded;
            }
        }

        return $memo = '';
    }

    private static function valid_data_uri(string $value): bool {
        return (bool)preg_match('~^data:image/(?:png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$~', $value);
    }

    private static function download_logo_data_uri(): string {
        if (!function_exists('wp_remote_get')
            || !function_exists('wp_remote_retrieve_response_code')
            || !function_exists('wp_remote_retrieve_body')) {
            return '';
        }

        $urls = [self::LOGO_URL, (string)strtok(self::LOGO_URL, '?')];
        foreach (array_unique($urls) as $url) {
            $response = wp_remote_get($url, [
                'timeout'=>10,
                'redirection'=>3,
                'sslverify'=>true,
                'headers'=>[
                    'Accept'=>'image/png,image/jpeg,image/gif;q=0.9,*/*;q=0.1',
                    'User-Agent'=>'Basketmania-Camp-System/0.66',
                ],
            ]);
            if (function_exists('is_wp_error') && is_wp_error($response)) continue;
            if ((int)wp_remote_retrieve_response_code($response) !== 200) continue;

            $bytes = (string)wp_remote_retrieve_body($response);
            if (strlen($bytes) < 100 || strlen($bytes) > 2 * 1024 * 1024) continue;

            $mime = '';
            if (function_exists('getimagesizefromstring')) {
                $info = @getimagesizefromstring($bytes);
                if (is_array($info) && !empty($info['mime'])) $mime = strtolower((string)$info['mime']);
            }
            if ($mime === '' && function_exists('wp_remote_retrieve_header')) {
                $mime = strtolower(trim((string)wp_remote_retrieve_header($response, 'content-type')));
                $mime = trim((string)strtok($mime, ';'));
            }
            if (!in_array($mime, ['image/png','image/jpeg','image/gif','image/webp'], true)) continue;

            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        }
        return '';
    }

    private static function load_document(string $html): ?DOMDocument {
        if (!class_exists('DOMDocument')) return null;
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    private static function class_query(string $class): string {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' ".$class." ')]";
    }

    private static function add_class(DOMElement $element, string $class): void {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        if (!in_array($class, $classes, true)) $classes[] = $class;
        $element->setAttribute('class', trim(implode(' ', array_filter($classes))));
    }

    private static function clear_node(DOMElement $node): void {
        while ($node->firstChild) $node->removeChild($node->firstChild);
    }

    public static function agreement_css(): string {
        return '
            @page{margin:27mm 14mm 25mm 14mm;background:#fff}
            html,body{margin:0!important;padding:0!important;background:#fff!important;background-color:#fff!important;color:#172033!important}
            .bcs-document-052,.bcs-document-066{font-family:"DejaVu Sans",Arial,sans-serif!important;font-size:10pt!important;line-height:1.38!important;background:#fff!important;background-color:#fff!important;color:#172033!important;border:0!important;box-shadow:none!important}
            .bcs-document-header{position:fixed!important;top:-23mm!important;left:0!important;right:0!important;height:18mm!important;padding:1mm 0 2mm!important;box-sizing:border-box!important;background:#fff!important;background-color:#fff!important;border:0!important;border-bottom:0!important;box-shadow:none!important;text-align:left!important}
            .bcs-document-header img{display:block!important;height:14mm!important;width:auto!important;max-width:66mm!important;object-fit:contain!important;margin:0!important}
            .bcs-document-footer{position:fixed!important;bottom:-21mm!important;left:0!important;right:0!important;height:17mm!important;min-height:17mm!important;padding:2.2mm 4mm 1.6mm!important;box-sizing:border-box!important;background:#172033!important;background-color:#172033!important;color:#fff!important;border:0!important;text-align:center!important;font-size:7.5pt!important;line-height:1.28!important}
            .bcs-document-footer-rule{display:block!important;height:1px!important;min-height:1px!important;margin:0 0 2mm!important;padding:0!important;background:#fff!important;background-color:#fff!important;border:0!important}
            .bcs-document-footer-text{display:block!important;background:transparent!important;color:#fff!important}
            .bcs-document-content{display:block!important;position:relative!important;background:#fff!important;background-color:#fff!important;color:#172033!important;box-shadow:none!important}
            .bcs-document-content *{background:#fff!important;background-color:#fff!important;background-image:none!important;box-shadow:none!important;color:#172033!important}
            .bcs-document-content h1{font-size:16pt!important;line-height:1.2!important;text-align:center!important;margin:0 0 10px!important;color:#172033!important}
            .bcs-document-content h2{font-size:11.5pt!important;line-height:1.25!important;margin:11px 0 5px!important;color:#172033!important;page-break-after:avoid!important}
            .bcs-document-content h3{font-size:10.5pt!important;margin:8px 0 4px!important;color:#172033!important;page-break-after:avoid!important}
            .bcs-document-content p{margin:0 0 5px!important}
            .bcs-document-content ol,.bcs-document-content ul{margin:4px 0 7px 18px!important;padding:0!important}
            .bcs-document-content li{margin:0 0 3px!important}
            .bcs-document-content table{width:100%!important;border-collapse:collapse!important;margin:6px 0 9px!important;font-size:9.2pt!important;page-break-inside:auto!important;background:#fff!important}
            .bcs-document-content tr{page-break-inside:avoid!important;background:#fff!important}
            .bcs-document-content td,.bcs-document-content th{border:1px solid #172033!important;padding:4px 5px!important;vertical-align:top!important;background:#fff!important;color:#172033!important}
            .bcs-document-content th{font-weight:700!important}
            .proof,.bcs-proof-page,.bcs-proof-start-057{page-break-before:always!important;break-before:page!important;border:0!important;padding:0!important;margin:0!important;box-sizing:border-box!important;background:#fff!important;background-color:#fff!important;box-shadow:none!important}
            .proof h2,.bcs-proof-page h2,.bcs-proof-start-057 h2{color:#172033!important;margin:0 0 10px!important}
            .bcs-page-break-before{page-break-before:always!important;break-before:page!important}
            .bcs-keep-with-next{page-break-after:avoid!important;break-after:avoid-page!important}
            @media screen{
                html,body{background:#fff!important;padding:0!important}
                .bcs-document-052,.bcs-document-066{max-width:820px!important;margin:0 auto!important;display:flex!important;flex-direction:column!important;border:0!important;box-shadow:none!important;background:#fff!important}
                .bcs-document-header{position:static!important;order:1!important;height:auto!important;min-height:68px!important;padding:13px 34px!important;background:#fff!important}
                .bcs-document-header img{height:54px!important;max-width:260px!important}
                .bcs-document-content{order:2!important;padding:30px 38px 34px!important;background:#fff!important}
                .bcs-document-footer{position:static!important;order:3!important;height:auto!important;min-height:0!important;padding:12px 28px 15px!important;background:#172033!important;color:#fff!important}
                .bcs-page-break-before,.proof,.bcs-proof-page,.bcs-proof-start-057{page-break-before:auto!important;break-before:auto!important;margin-top:30px!important}
            }
        ';
    }

    /**
     * Końcowy dekorator układu. Jest uruchamiany po warstwach 0.52, 0.55 i 0.57,
     * więc usuwa pozostałe szare tła także ze starszych zapisanych wersji umów.
     */
    public static function prepare_agreement_html(string $html): string {
        if (trim($html) === '') return $html;
        if (!str_contains($html, 'bcs-document-052')
            && !str_contains($html, 'bcs-agreement')
            && stripos($html, 'UMOWA UDZIAŁU') === false) {
            return $html;
        }

        $dom = self::load_document($html);
        if (!$dom) {
            return '<style id="'.self::STYLE_ID.'">'.self::agreement_css().'</style>'.$html;
        }

        $xpath = new DOMXPath($dom);
        $document = $xpath->query(self::class_query('bcs-document-052'))->item(0);
        if (!$document instanceof DOMElement) {
            $document = $xpath->query(self::class_query('bcs-agreement-document'))->item(0);
        }
        if (!$document instanceof DOMElement) {
            return '<style id="'.self::STYLE_ID.'">'.self::agreement_css().'</style>'.$html;
        }
        self::add_class($document, 'bcs-document-066');

        $header = $xpath->query(self::class_query('bcs-document-header'))->item(0);
        if (!$header) $header = $xpath->query(self::class_query('bcs-agreement-header'))->item(0);
        if ($header instanceof DOMElement) {
            self::clear_node($header);
            $image = $dom->createElement('img');
            $logo = self::logo_data_uri();
            $image->setAttribute('src', $logo !== '' ? $logo : self::LOGO_URL);
            $image->setAttribute('alt', 'Basketmania Camp');
            $image->setAttribute('data-bcs-logo-source', self::LOGO_URL);
            $header->appendChild($image);
        }

        $content = $xpath->query(self::class_query('bcs-document-content'))->item(0);
        if (!$content) $content = $xpath->query(self::class_query('bcs-agreement-content'))->item(0);
        $footer = $xpath->query(self::class_query('bcs-document-footer'))->item(0);
        if (!$footer) $footer = $xpath->query(self::class_query('bcs-agreement-footer'))->item(0);

        if ($footer instanceof DOMElement) {
            $identity = trim((string)preg_replace('/\s+/u', ' ', $footer->textContent ?? ''));
            self::clear_node($footer);
            $rule = $dom->createElement('div');
            $rule->setAttribute('class', 'bcs-document-footer-rule');
            $text = $dom->createElement('div');
            $text->setAttribute('class', 'bcs-document-footer-text');
            $text->appendChild($dom->createTextNode($identity));
            $footer->appendChild($rule);
            $footer->appendChild($text);

            // Dompdf powtarza element fixed na wszystkich stronach, gdy znajduje się
            // on przed przepływową treścią dokumentu.
            if ($content instanceof DOMElement && $footer->parentNode === $content->parentNode) {
                $footer->parentNode->insertBefore($footer, $content);
            }
        }

        foreach ($xpath->query('//*[@id="'.self::STYLE_ID.'"]') as $oldStyle) {
            if ($oldStyle->parentNode) $oldStyle->parentNode->removeChild($oldStyle);
        }
        $style = $dom->createElement('style');
        $style->setAttribute('id', self::STYLE_ID);
        $style->appendChild($dom->createTextNode(self::agreement_css()));
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head) $head->appendChild($style);
        elseif ($document->parentNode) $document->parentNode->insertBefore($style, $document);

        $result = (string)$dom->saveHTML();
        $result = preg_replace('/^<\?xml[^>]*>\s*/i', '', $result) ?? $result;
        return $result;
    }

    private static function registrations_page(): bool {
        return is_admin() && sanitize_key((string)($_GET['page'] ?? '')) === 'bcs-registrations';
    }

    public static function action_required_css(): void {
        if (!self::registrations_page()) return;
        ?>
        <style id="bcs-action-required-label-066">
            .bcs-row-action-marker{font-size:0!important}
            .bcs-row-action-marker::after{content:"Wymaga akcji";font-size:11px!important;line-height:1.2!important}
        </style>
        <?php
    }

    public static function action_required_script(): void {
        if (!self::registrations_page()) return;
        ?>
        <script id="bcs-action-required-label-066-script">
        (() => {
            'use strict';
            const aliases = new Set([
                'wymagające akcji',
                'wymagające akcji!',
                'wymaga akcji!'
            ]);
            const normalize = () => {
                document.querySelectorAll('table tr span,table tr small,table tr strong,table tr div').forEach((node) => {
                    if (node.classList.contains('bcs-row-action-marker')) {
                        node.setAttribute('aria-label', 'Wymaga akcji');
                        node.title = 'Wymaga akcji';
                        return;
                    }
                    if (node.childElementCount > 0) return;
                    const text = (node.textContent || '').trim().toLocaleLowerCase('pl-PL');
                    if (aliases.has(text)) node.textContent = 'Wymaga akcji';
                });
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', normalize, {once:true});
            else normalize();
            new MutationObserver(normalize).observe(document.body, {childList:true, subtree:true, characterData:true});
        })();
        </script>
        <?php
    }
}
