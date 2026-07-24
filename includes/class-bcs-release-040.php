<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_040 {
    private const ACTIVATED_AT_OPTION = 'bcs_release_040_activated_at';
    private const STYLE_START = '<!-- BCS-AGREEMENT-LAYOUT-040-START -->';
    private const STYLE_END = '<!-- BCS-AGREEMENT-LAYOUT-040-END -->';

    public static function init(): void {
        if (!get_option(self::ACTIVATED_AT_OPTION)) {
            update_option(self::ACTIVATED_AT_OPTION, BCS_Utils::now(), false);
        }
        register_shutdown_function([__CLASS__, 'normalize_new_agreements']);
    }

    private static function strip_layout(string $html): string {
        $html = preg_replace(
            '~\s*'.preg_quote(self::STYLE_START, '~').'.*?'.preg_quote(self::STYLE_END, '~').'\s*~s',
            '',
            $html
        );
        return trim((string)$html);
    }

    private static function compact_attachment_one(string $html): string {
        $html = preg_replace('~<div\b[^>]*class=("|\')[^"\']*\bbcs-attachment-one\b[^"\']*\1[^>]*>(.*?)</div>~is', '$2', $html);
        $pattern = '~(<h[1-3]\b[^>]*>\s*Załącznik(?:\s+nr)?\s*1\b.*?</h[1-3]>)(.*?)(?=<h[1-3]\b[^>]*>\s*Załącznik(?:\s+nr)?\s*[2-9]\b|<div\b[^>]*class=("|\')[^"\']*\bproof\b|$)~is';
        return (string)preg_replace($pattern, '<div class="bcs-attachment-one">$1$2</div>', $html, 1);
    }

    private static function apply_layout(string $html): string {
        $body = self::compact_attachment_one(self::strip_layout($html));
        $style = self::STYLE_START.
            '<style>
                .bcs-agreement-document,.bcs-agreement-content{font-size:10pt;line-height:1.32}
                .bcs-agreement-content p{margin:0 0 5px}
                .bcs-agreement-content h1{font-size:16pt;margin:0 0 10px}
                .bcs-agreement-content h2{font-size:12pt;margin:10px 0 6px}
                .bcs-agreement-content h3{font-size:10.5pt;margin:8px 0 5px}
                .bcs-agreement-content table{font-size:9.5pt;line-height:1.22}
                .bcs-agreement-content td,.bcs-agreement-content th{padding:3px 5px}
                .bcs-attachment-one{font-size:9pt;line-height:1.15;page-break-inside:avoid;break-inside:avoid-page}
                .bcs-attachment-one p{margin:0 0 3px}
                .bcs-attachment-one h1,.bcs-attachment-one h2,.bcs-attachment-one h3{margin-top:5px;margin-bottom:4px}
                .proof{page-break-before:always;break-before:page;margin-top:0!important}
                @media print{
                    .proof{page-break-before:always;break-before:page}
                    .bcs-attachment-one{page-break-inside:avoid;break-inside:avoid-page}
                }
            </style>'.self::STYLE_END;

        if (str_contains($body, '<div class="bcs-agreement-document">')) {
            return str_replace('<div class="bcs-agreement-document">', $style.'<div class="bcs-agreement-document">', $body);
        }
        return $style.$body;
    }

    private static function replace_number(string $html, string $old, string $new): string {
        if ($old === '' || $old === $new) return $html;
        return str_replace($old, $new, $html);
    }

    private static function normalize_number(string $number): string {
        if (!preg_match('~^(.+)/(\d{6})$~', $number, $match)) return $number;
        return $match[1].'/'.str_pad((string)(int)$match[2], 3, '0', STR_PAD_LEFT);
    }

    public static function normalize_new_agreements(): void {
        if (!class_exists('BCS_DB')) return;
        global $wpdb;
        $activated_at = (string)get_option(self::ACTIVATED_AT_OPTION, '');
        if ($activated_at === '') return;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id,agreement_number,html,document_hash,declaration_text FROM '.BCS_DB::table('agreements').' WHERE created_at >= %s ORDER BY id ASC',
            $activated_at
        ));

        foreach ($rows as $row) {
            $old = (string)$row->agreement_number;
            $new = self::normalize_number($old);
            $html = self::apply_layout(self::replace_number((string)$row->html, $old, $new));
            $data = [
                'html' => $html,
                'document_hash' => hash('sha256', $html),
            ];
            if ($new !== $old) {
                $data['agreement_number'] = $new;
                $data['declaration_text'] = self::replace_number((string)($row->declaration_text ?? ''), $old, $new);
            }
            $wpdb->update(BCS_DB::table('agreements'), $data, ['id'=>(int)$row->id]);

            $versions = $wpdb->get_results($wpdb->prepare(
                'SELECT id,html FROM '.BCS_DB::table('agreement_versions').' WHERE agreement_id=%d',
                (int)$row->id
            ));
            foreach ($versions as $version) {
                $version_html = self::apply_layout(self::replace_number((string)$version->html, $old, $new));
                $wpdb->update(BCS_DB::table('agreement_versions'), [
                    'agreement_number' => $new,
                    'html' => $version_html,
                    'document_hash' => hash('sha256', $version_html),
                ], ['id'=>(int)$version->id]);
            }
        }
    }
}
