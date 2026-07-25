<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_054 {
    private const ACTION = 'bcs_agreement_version_preview_054';

    public static function init(): void {
        add_action('admin_post_'.self::ACTION, [__CLASS__, 'render_version_preview']);
        add_action('admin_head', [__CLASS__, 'admin_preview_css'], 99);
        add_action('admin_footer', [__CLASS__, 'admin_preview_script'], 1);
    }

    private static function detail_registration_id(): int {
        if (!is_admin()) return 0;
        if (sanitize_key((string)($_GET['page'] ?? '')) !== 'bcs-registrations') return 0;
        return absint($_GET['view'] ?? 0);
    }

    private static function nonce_action(int $registration_id): string {
        return self::ACTION.'_'.$registration_id;
    }

    public static function admin_preview_css(): void {
        if (!self::detail_registration_id()) return;
        ?>
        <style id="bcs-agreement-preview-054-css">
            .bcs-agreements-preview .bcs-document-preview{
                min-height:720px;
                padding:0!important;
                overflow:hidden;
                position:relative;
                background:#f8fafc;
                border:1px solid #dbe2ea;
                border-radius:12px;
                color:transparent!important;
                font-size:0!important;
            }
            .bcs-agreements-preview .bcs-document-preview>*:not(.bcs-agreement-version-frame-054){display:none!important}
            .bcs-agreements-preview .bcs-agreement-version-frame-054{
                display:block!important;
                width:100%;
                height:900px;
                min-height:720px;
                border:0;
                background:#fff;
                color:#172033;
            }
            .bcs-agreements-preview .bcs-preview-loading-054{
                display:flex!important;
                align-items:center;
                justify-content:center;
                min-height:720px;
                padding:30px;
                color:#64748b!important;
                font-size:14px!important;
                font-weight:700;
                text-align:center;
            }
            @media(max-width:782px){
                .bcs-agreements-preview .bcs-document-preview,
                .bcs-agreements-preview .bcs-agreement-version-frame-054{min-height:620px}
            }
        </style>
        <?php
    }

    public static function admin_preview_script(): void {
        $registration_id = self::detail_registration_id();
        if (!$registration_id) return;

        $config = [
            'endpoint' => admin_url('admin-post.php'),
            'action' => self::ACTION,
            'registrationId' => $registration_id,
            'nonce' => wp_create_nonce(self::nonce_action($registration_id)),
        ];
        ?>
        <script id="bcs-agreement-preview-054-script">
        (() => {
            'use strict';
            const config = <?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

            const stageFromCard = (card) => {
                const heading = (card.querySelector(':scope > summary strong')?.textContent || '').toLocaleLowerCase('pl-PL');
                if (heading.includes('draft')) return 'draft';
                if (heading.includes('wysłana') || heading.includes('wyslana')) return 'sent';
                if (heading.includes('podpisana')) return 'signed';
                return '';
            };

            const previewUrl = (stage) => {
                const url = new URL(config.endpoint, window.location.href);
                url.searchParams.set('action', config.action);
                url.searchParams.set('registration_id', String(config.registrationId));
                url.searchParams.set('stage', stage);
                url.searchParams.set('_wpnonce', config.nonce);
                return url.toString();
            };

            const mount = (root = document) => {
                root.querySelectorAll('.bcs-agreements-preview .bcs-document-stage.is-ready').forEach((card) => {
                    const preview = card.querySelector('.bcs-document-preview');
                    if (!preview || preview.dataset.bcsPreview054 === 'ready') return;

                    const stage = stageFromCard(card);
                    if (!stage) return;

                    preview.dataset.bcsPreview054 = 'ready';
                    preview.replaceChildren();

                    const loading = document.createElement('div');
                    loading.className = 'bcs-preview-loading-054';
                    loading.textContent = 'Ładowanie czytelnego podglądu dokumentu…';
                    preview.appendChild(loading);

                    const frame = document.createElement('iframe');
                    frame.className = 'bcs-agreement-version-frame-054';
                    frame.title = 'Podgląd wersji umowy: ' + stage;
                    frame.loading = 'lazy';
                    frame.src = previewUrl(stage);
                    frame.dataset.bcsStage = stage;
                    frame.addEventListener('load', () => loading.remove(), {once:true});
                    preview.appendChild(frame);
                });
            };

            window.addEventListener('message', (event) => {
                if (event.origin !== window.location.origin) return;
                const data = event.data || {};
                if (data.type !== 'bcs-agreement-preview-height-054') return;
                const frame = Array.from(document.querySelectorAll('.bcs-agreement-version-frame-054'))
                    .find((item) => item.contentWindow === event.source);
                if (!frame) return;
                const height = Math.max(720, Math.min(1800, Number(data.height) || 900));
                frame.style.height = height + 'px';
            });

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => mount(), {once:true});
            } else {
                mount();
            }

            new MutationObserver((records) => {
                for (const record of records) {
                    for (const node of record.addedNodes) {
                        if (node.nodeType === 1) mount(node);
                    }
                }
            }).observe(document.documentElement, {childList:true, subtree:true});
        })();
        </script>
        <?php
    }

    private static function agreement_row(int $registration_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.id,r.agreement_id,r.agreement_status,
                    a.agreement_number,a.html agreement_html,a.status agreement_record_status
             FROM ".BCS_DB::table('registrations')." r
             LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d LIMIT 1",
            $registration_id
        )) ?: null;
    }

    private static function version_html(object $row, string $stage): string {
        global $wpdb;
        $html = '';
        if (!empty($row->agreement_id)) {
            $html = (string)$wpdb->get_var($wpdb->prepare(
                "SELECT html FROM ".BCS_DB::table('agreement_versions')."
                 WHERE agreement_id=%d AND stage=%s ORDER BY id DESC LIMIT 1",
                (int)$row->agreement_id,
                $stage
            ));
        }
        if (trim($html) === '') $html = (string)($row->agreement_html ?? '');
        return trim($html);
    }

    private static function full_document(string $html, int $registration_id, string $stage, string $number): string {
        $title = match ($stage) {
            'draft' => 'Draft umowy '.$number,
            'sent' => 'Umowa wysłana '.$number,
            default => 'Umowa podpisana '.$number,
        };

        $had_registration = array_key_exists('registration', $_GET);
        $previous_registration = $_GET['registration'] ?? null;
        $_GET['registration'] = $registration_id;
        try {
            if (class_exists('BCS_Release_052')) {
                $rendered = BCS_Release_052::prepare_pdf_html($html, $title);
                if (stripos($rendered, '<!doctype') !== false || stripos($rendered, '<html') !== false) {
                    return $rendered;
                }
                $html = $rendered;
            }
        } finally {
            if ($had_registration) $_GET['registration'] = $previous_registration;
            else unset($_GET['registration']);
        }

        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            .esc_html($title)
            .'</title><style>html,body{margin:0;background:#fff;color:#172033;font-family:"DejaVu Sans",Arial,sans-serif}body{padding:24px}img{max-width:100%;height:auto}table{width:100%;border-collapse:collapse}td,th{border:1px solid #d5dbe4;padding:6px}</style></head><body>'
            .$html
            .'</body></html>';
    }

    public static function render_version_preview(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.', 'Basketmania Camp', ['response'=>403]);
        }

        $registration_id = absint($_GET['registration_id'] ?? 0);
        $stage = sanitize_key((string)($_GET['stage'] ?? ''));
        if (!$registration_id || !in_array($stage, ['draft','sent','signed'], true)) {
            wp_die('Nieprawidłowe dane podglądu.', 'Basketmania Camp', ['response'=>422]);
        }
        check_admin_referer(self::nonce_action($registration_id));

        $row = self::agreement_row($registration_id);
        if (!$row || empty($row->agreement_id)) {
            wp_die('Nie znaleziono umowy.', 'Basketmania Camp', ['response'=>404]);
        }

        if ($stage === 'signed' && class_exists('BCS_Release_051')) {
            BCS_Release_051::repair_registration($registration_id, true);
            $row = self::agreement_row($registration_id) ?: $row;
        }

        $html = self::version_html($row, $stage);
        if ($html === '') {
            wp_die('Ta wersja dokumentu nie jest dostępna.', 'Basketmania Camp', ['response'=>404]);
        }

        $document = self::full_document(
            $html,
            $registration_id,
            $stage,
            (string)($row->agreement_number ?? '')
        );

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow', true);
        header("Content-Security-Policy: frame-ancestors 'self'", true);

        $height_script = '<script>(()=>{const send=()=>parent.postMessage({type:"bcs-agreement-preview-height-054",height:Math.max(document.documentElement.scrollHeight,document.body.scrollHeight)},location.origin);addEventListener("load",send);new ResizeObserver(send).observe(document.body);setTimeout(send,250);})();</script>';
        if (stripos($document, '</body>') !== false) {
            $document = preg_replace('~</body>~i', $height_script.'</body>', $document, 1);
        } else {
            $document .= $height_script;
        }

        echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- izolowany dokument HTML zapisanej umowy, dostępny tylko administratorowi.
        exit;
    }
}
