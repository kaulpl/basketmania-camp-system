<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_038 {
    private const MARKER_START = '<!-- BCS-AGREEMENT-BRAND-038-START -->';
    private const MARKER_END = '<!-- BCS-AGREEMENT-BRAND-038-END -->';

    public static function init(): void {
        self::decorate_all_agreements();
        register_shutdown_function([__CLASS__, 'decorate_all_agreements']);
    }

    private static function strip_branding(string $html): string {
        /*
         * W starszej implementacji usuwany był cały fragment pomiędzy markerami,
         * a więc razem z właściwą treścią umowy. Przy kolejnym przebiegu dekoratora
         * w bazie pozostawała wyłącznie sekcja dowodowa albo pusty dokument.
         * Teraz zdejmujemy tylko opakowanie nagłówka i stopki, zachowując zawartość
         * .bcs-agreement-content oraz wszystko, co znajduje się poza markerami.
         */
        $pattern = '~'.preg_quote(self::MARKER_START, '~').'.*?'
            .'<div\b[^>]*class=("|\')[^"\']*\bbcs-agreement-content\b[^"\']*\1[^>]*>(.*?)</div>\s*'
            .'<div\b[^>]*class=("|\')[^"\']*\bbcs-agreement-footer\b[^"\']*\3[^>]*>.*?</div>\s*</div>\s*'
            .preg_quote(self::MARKER_END, '~').'~is';
        $unwrapped = preg_replace($pattern, '$2', $html);
        if (is_string($unwrapped) && $unwrapped !== $html) return trim($unwrapped);

        // Bezpieczny fallback: nigdy więcej nie kasujemy zawartości dokumentu.
        return trim(str_replace([self::MARKER_START, self::MARKER_END], '', $html));
    }

    private static function company_identity(object $row): string {
        $parts = [];
        $name = trim((string)($row->organizer_name ?? ''));
        $legal = trim((string)($row->organizer_legal_form ?? ''));
        if ($name !== '') $parts[] = trim($name.' '.$legal);
        if (!empty($row->organizer_address)) $parts[] = (string)$row->organizer_address;
        if (!empty($row->organizer_nip)) $parts[] = 'NIP: '.(string)$row->organizer_nip;
        if (!empty($row->organizer_regon)) $parts[] = 'REGON: '.(string)$row->organizer_regon;
        if (!empty($row->organizer_krs)) $parts[] = 'KRS: '.(string)$row->organizer_krs;
        if (!empty($row->organizer_email)) $parts[] = (string)$row->organizer_email;
        if (!empty($row->organizer_phone)) $parts[] = (string)$row->organizer_phone;
        return implode(' · ', $parts);
    }

    private static function decorate(string $html, object $row): string {
        $body = self::strip_branding($html);
        $logo = esc_url(BCS_URL.'assets/images/logo-basketmania-camp-white.png');
        $identity = esc_html(self::company_identity($row));

        $header = self::MARKER_START.
            '<style>
                @page{margin:92px 45px 72px 45px}
                .bcs-agreement-document{font-family:"DejaVu Sans",Arial,sans-serif}
                .bcs-agreement-header{position:fixed;top:-72px;left:0;right:0;height:58px;background:#172033;border-bottom:3px solid #f97316;padding:7px 18px 6px 18px;box-sizing:border-box}
                .bcs-agreement-header img{height:42px;width:auto;display:block}
                .bcs-agreement-content{position:relative}
                .bcs-agreement-footer{position:fixed;bottom:-55px;left:0;right:0;min-height:42px;border-top:2px solid #f97316;padding:7px 4px 0 4px;font-size:8.5pt;line-height:1.35;color:#4b5563;text-align:center;box-sizing:border-box}
                @media screen{.bcs-agreement-document{max-width:900px;margin:0 auto}.bcs-agreement-header,.bcs-agreement-footer{position:relative;top:auto;bottom:auto}.bcs-agreement-header{margin-bottom:24px}.bcs-agreement-footer{margin-top:30px}}
            </style>'.
            '<div class="bcs-agreement-document"><div class="bcs-agreement-header"><img src="'.$logo.'" alt="Basketmania Camp"></div><div class="bcs-agreement-content">';

        $footer = '</div><div class="bcs-agreement-footer">'.$identity.'</div></div>'.self::MARKER_END;
        return $header.$body.$footer;
    }

    public static function decorate_all_agreements(): void {
        if (!class_exists('BCS_DB')) return;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT a.id,a.html,o.name organizer_name,o.legal_form organizer_legal_form,o.address organizer_address,o.nip organizer_nip,o.regon organizer_regon,o.krs organizer_krs,o.email organizer_email,o.phone organizer_phone
             FROM ".BCS_DB::table('agreements')." a
             LEFT JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id
             LEFT JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id"
        );
        foreach ($rows as $row) {
            $decorated = self::decorate((string)$row->html, $row);
            if ($decorated !== (string)$row->html) {
                $wpdb->update(BCS_DB::table('agreements'), ['html'=>$decorated], ['id'=>(int)$row->id]);
            }
            $versions = $wpdb->get_results($wpdb->prepare(
                "SELECT id,html FROM ".BCS_DB::table('agreement_versions')." WHERE agreement_id=%d",
                (int)$row->id
            ));
            foreach ($versions as $version) {
                $version_html = self::decorate((string)$version->html, $row);
                if ($version_html !== (string)$version->html) {
                    $wpdb->update(BCS_DB::table('agreement_versions'), ['html'=>$version_html], ['id'=>(int)$version->id]);
                }
            }
        }
    }
}
