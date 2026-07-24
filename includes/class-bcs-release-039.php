<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_039 {
    private const MIGRATION_OPTION = 'bcs_release_039_migrated';
    private const STYLE_START = '<!-- BCS-AGREEMENT-LAYOUT-039-START -->';
    private const STYLE_END = '<!-- BCS-AGREEMENT-LAYOUT-039-END -->';

    public static function init(): void {
        self::migrate_existing_agreements();
        self::normalize_new_agreements();
        register_shutdown_function([__CLASS__, 'normalize_new_agreements']);
    }

    private static function strip_layout(string $html): string {
        return trim((string)preg_replace(
            '~\s*'.preg_quote(self::STYLE_START, '~').'.*?'.preg_quote(self::STYLE_END, '~').'\s*~s',
            '',
            $html
        ));
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

    private static function short_number(int $sequence): string {
        return str_pad((string)max(1, min(999, $sequence)), 3, '0', STR_PAD_LEFT);
    }

    private static function replace_number(string $html, string $old, string $new): string {
        if ($old === '' || $old === $new) return $html;
        return str_replace($old, $new, $html);
    }

    private static function migrate_existing_agreements(): void {
        if (get_option(self::MIGRATION_OPTION)) return;
        global $wpdb;
        $agreements = $wpdb->get_results(
            'SELECT id,agreement_number,html,document_hash,declaration_text FROM '.BCS_DB::table('agreements').' ORDER BY id ASC'
        );
        $sequence = 0;
        foreach ($agreements as $agreement) {
            $sequence++;
            $new_number = self::short_number($sequence);
            $old_number = (string)$agreement->agreement_number;
            $html = self::apply_layout(self::replace_number((string)$agreement->html, $old_number, $new_number));
            $hash = hash('sha256', $html);
            $declaration = self::replace_number((string)($agreement->declaration_text ?? ''), $old_number, $new_number);
            $wpdb->update(BCS_DB::table('agreements'), [
                'agreement_number' => $new_number,
                'html' => $html,
                'document_hash' => $hash,
                'declaration_text' => $declaration,
            ], ['id' => (int)$agreement->id]);

            $versions = $wpdb->get_results($wpdb->prepare(
                'SELECT id,html FROM '.BCS_DB::table('agreement_versions').' WHERE agreement_id=%d',
                (int)$agreement->id
            ));
            foreach ($versions as $version) {
                $version_html = self::apply_layout(self::replace_number((string)$version->html, $old_number, $new_number));
                $wpdb->update(BCS_DB::table('agreement_versions'), [
                    'agreement_number' => $new_number,
                    'html' => $version_html,
                    'document_hash' => hash('sha256', $version_html),
                ], ['id' => (int)$version->id]);
            }
        }
        update_option('bcs_agreement_sequence_039', (string)$sequence, false);
        update_option(self::MIGRATION_OPTION, 1, false);
    }

    public static function normalize_new_agreements(): void {
        if (!class_exists('BCS_DB')) return;
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT id,agreement_number,html,document_hash,declaration_text FROM '.BCS_DB::table('agreements').' ORDER BY id ASC'
        );
        $used = [];
        $max = 0;
        foreach ($rows as $row) {
            if (preg_match('/^\d{3}$/', (string)$row->agreement_number)) {
                $n = (int)$row->agreement_number;
                $used[$n] = true;
                $max = max($max, $n);
            }
        }
        foreach ($rows as $row) {
            $old = (string)$row->agreement_number;
            $new = $old;
            if (!preg_match('/^\d{3}$/', $old)) {
                do { $max++; } while (isset($used[$max]) && $max < 1000);
                if ($max > 999) continue;
                $used[$max] = true;
                $new = self::short_number($max);
            }
            $html = self::apply_layout(self::replace_number((string)$row->html, $old, $new));
            $data = ['html'=>$html, 'document_hash'=>hash('sha256', $html)];
            if ($new !== $old) {
                $data['agreement_number'] = $new;
                $data['declaration_text'] = self::replace_number((string)($row->declaration_text ?? ''), $old, $new);
            }
            $wpdb->update(BCS_DB::table('agreements'), $data, ['id'=>(int)$row->id]);

            $versions = $wpdb->get_results($wpdb->prepare(
                'SELECT id,html,agreement_number FROM '.BCS_DB::table('agreement_versions').' WHERE agreement_id=%d',
                (int)$row->id
            ));
            foreach ($versions as $version) {
                $version_html = self::apply_layout(self::replace_number((string)$version->html, $old, $new));
                $wpdb->update(BCS_DB::table('agreement_versions'), [
                    'agreement_number'=>$new,
                    'html'=>$version_html,
                    'document_hash'=>hash('sha256', $version_html),
                ], ['id'=>(int)$version->id]);
            }
        }
        update_option('bcs_agreement_sequence_039', (string)$max, false);
    }
}
