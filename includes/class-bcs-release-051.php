<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_051 {
    private const MIGRATION_OPTION = 'bcs_release_051_documents_rebuilt';
    private const PROOF_START = '<!-- BCS-AGREEMENT-PROOF-051-START -->';
    private const PROOF_END = '<!-- BCS-AGREEMENT-PROOF-051-END -->';

    public static function init(): void {
        remove_action('wp_ajax_bcs_046_organizer_otp_verify', ['BCS_Release_046', 'ajax_verify_organizer_otp']);
        add_action('wp_ajax_bcs_046_organizer_otp_verify', [__CLASS__, 'ajax_verify_organizer_otp']);

        remove_action('admin_post_bcs_agreement_view', ['BCS_Release_037', 'render_agreement_view'], 0);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_037', 'render_agreement_view'], 0);
        remove_action('admin_post_bcs_agreement_view', ['BCS_Release_029', 'render_agreement_view'], 0);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Release_029', 'render_agreement_view'], 0);
        remove_action('admin_post_bcs_agreement_view', ['BCS_Agreements', 'view_agreement']);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Agreements', 'view_agreement']);
        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);

        add_action('admin_init', [__CLASS__, 'repair_existing_once'], 3);
        add_action('template_redirect', [__CLASS__, 'intercept_agreement_download'], 1);
        register_shutdown_function([__CLASS__, 'repair_after_signature_request']);
    }

    private static function request_key(int $registration_id): string {
        return 'bcs_046_org_otp_' . get_current_user_id() . '_' . $registration_id;
    }

    private static function proof_key(int $agreement_id): string {
        return 'bcs_org_proof_' . $agreement_id;
    }

    private static function row(int $registration_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,
                    c.name camp_name,c.start_date,c.end_date,c.location,
                    o.name organizer_name,o.legal_form organizer_legal_form,o.address organizer_address,
                    o.nip organizer_nip,o.regon organizer_regon,o.krs organizer_krs,
                    o.email organizer_email,o.phone organizer_phone,o.bank_name,o.bank_account,
                    o.representative organizer_representative,o.transfer_title_template,
                    a.id agreement_real_id,a.agreement_number,a.version agreement_version,
                    a.html agreement_html,a.document_hash agreement_document_hash,
                    a.status agreement_record_status,a.accepted_at agreement_accepted_at,
                    a.accepted_ip agreement_accepted_ip,a.accepted_user_agent agreement_accepted_user_agent,
                    a.accepted_phone_masked,a.sms_message_id agreement_sms_message_id,
                    a.declaration_text agreement_declaration_text,a.created_at agreement_created_at
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id
             LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d LIMIT 1",
            $registration_id
        )) ?: null;
    }

    private static function inner_html(DOMNode $node): string {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    private static function load_fragment(string $html): ?DOMDocument {
        if (!class_exists('DOMDocument')) return null;
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="bcs-051-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    private static function class_xpath(string $class): string {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' ".$class." ')]";
    }

    private static function strip_old_document_shell(string $html): string {
        $html = preg_replace(
            '~<!--\s*BCS-AGREEMENT-(?:BRAND|LAYOUT|PROOF)-\d+-(?:START|END)\s*-->~i',
            '',
            $html
        );
        $html = (string)$html;

        for ($pass = 0; $pass < 6; $pass++) {
            $dom = self::load_fragment($html);
            if (!$dom) break;
            $xpath = new DOMXPath($dom);
            $node = $xpath->query(self::class_xpath('bcs-agreement-content'))->item(0);
            if (!$node) $node = $xpath->query(self::class_xpath('bcs-document-content'))->item(0);
            if (!$node) break;
            $next = self::inner_html($node);
            if ($next === '' || $next === $html) break;
            $html = $next;
        }

        $dom = self::load_fragment($html);
        if ($dom) {
            $xpath = new DOMXPath($dom);
            $queries = [
                '//style',
                '//script',
                '//noscript',
                self::class_xpath('bcs-agreement-header'),
                self::class_xpath('bcs-agreement-footer'),
                self::class_xpath('bcs-document-header'),
                self::class_xpath('bcs-document-footer'),
                self::class_xpath('proof'),
            ];
            foreach ($queries as $query) {
                $nodes = $xpath->query($query);
                if (!$nodes) continue;
                $remove = [];
                foreach ($nodes as $node) $remove[] = $node;
                foreach ($remove as $node) {
                    if ($node->parentNode) $node->parentNode->removeChild($node);
                }
            }
            $root = $dom->getElementById('bcs-051-root');
            if ($root) $html = self::inner_html($root);
        } else {
            $html = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $html);
            $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', (string)$html);
            $html = preg_replace(
                '~<div\b[^>]*class=("|\')[^"\']*\b(?:bcs-agreement-header|bcs-agreement-footer|bcs-document-header|bcs-document-footer|proof)\b[^"\']*\1[^>]*>.*?</div>~is',
                '',
                (string)$html
            );
        }

        $html = preg_replace('~<!doctype[^>]*>|</?(?:html|head|body)\b[^>]*>~i', '', (string)$html);
        $html = preg_replace('~<!--.*?-->~s', '', (string)$html);
        $html = preg_replace(
            '~^\s*(?:@page\s*\{.*?\}\s*)?(?:(?:\.[a-z0-9_-][^{]*)\{[^}]*\}\s*)+(?=<)~is',
            '',
            (string)$html
        );
        return trim((string)$html);
    }

    private static function normalized_text(string $html): string {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string)preg_replace('/\s+/u', ' ', trim($text));
    }

    private static function is_full_agreement(string $html): bool {
        $text = self::normalized_text($html);
        $length = mb_strlen($text);
        if ($length < 1200) return false;
        if (str_contains($text, '{{')) return false;

        $markers = [
            'przedmiot umowy',
            'obowiązki organizatora',
            'obowiazki organizatora',
            'postanowienia końcowe',
            'postanowienia koncowe',
            'dane uczestnika',
            'rodzicem / opiekunem',
            'zasady płatności',
            'zasady platnosci',
        ];
        $score = 0;
        $lower = mb_strtolower($text);
        foreach ($markers as $marker) {
            if (str_contains($lower, $marker)) $score++;
        }

        $headings = preg_match_all('~<h[1-3]\b~i', $html);
        $items = preg_match_all('~<li\b~i', $html);
        return $score >= 2 || ($headings >= 5 && $items >= 10 && $length >= 1800);
    }

    private static function replace_unresolved(string $html): string {
        return (string)preg_replace('/\{\{\s*[A-Z0-9_]+\s*\}\}/', '—', $html);
    }

    private static function agreement_date(object $row): string {
        $timestamp = !empty($row->agreement_created_at)
            ? strtotime((string)$row->agreement_created_at)
            : false;
        return $timestamp ? wp_date('d.m.Y', $timestamp) : BCS_Utils::today('d.m.Y');
    }

    private static function template_vars(object $row): array {
        $weight = !empty($row->child_weight)
            ? rtrim(rtrim(number_format((float)$row->child_weight, 1, ',', ''), '0'), ',')
            : '';
        $transfer_title = trim((string)($row->transfer_title_template ?? ''));
        $transfer_title = strtr($transfer_title, [
            '{{AGREEMENT_NUMBER}}'=>(string)$row->agreement_number,
            '{{CHILD_NAME}}'=>trim((string)$row->child_first_name.' '.(string)$row->child_last_name),
            '{{PARENT_NAME}}'=>trim((string)$row->parent_first_name.' '.(string)$row->parent_last_name),
            '{{CAMP_NAME}}'=>(string)$row->camp_name,
            '{{REGISTRATION_ID}}'=>(string)$row->id,
        ]);

        return [
            '{{AGREEMENT_NUMBER}}'=>esc_html((string)$row->agreement_number),
            '{{AGREEMENT_DATE}}'=>esc_html(self::agreement_date($row)),
            '{{PARENT_NAME}}'=>esc_html(trim((string)$row->parent_first_name.' '.(string)$row->parent_last_name)),
            '{{PARENTS_NAMES}}'=>esc_html((string)($row->parents_names ?? '')),
            '{{PARENT_ADDRESS}}'=>nl2br(esc_html((string)BCS_Utils::registration_address($row))),
            '{{PARENT_EMAIL}}'=>esc_html((string)$row->parent_email),
            '{{PARENT_PHONE}}'=>esc_html((string)$row->parent_phone),
            '{{PARENT_PHONE_ALT}}'=>esc_html((string)($row->second_parent_phone ?? $row->parent_phone_alt ?? '')),
            '{{PARENT_PHONE_2}}'=>esc_html((string)($row->second_parent_phone ?? $row->parent_phone_alt ?? '')),
            '{{SECOND_PARENT_NAME}}'=>esc_html(trim((string)($row->second_parent_first_name ?? '').' '.(string)($row->second_parent_last_name ?? ''))),
            '{{SECOND_PARENT_EMAIL}}'=>esc_html((string)($row->second_parent_email ?? '')),
            '{{SECOND_PARENT_PHONE}}'=>esc_html((string)($row->second_parent_phone ?? '')),
            '{{SOLE_GUARDIAN}}'=>!empty($row->sole_guardian) ? 'tak' : 'nie',
            '{{CHILD_NAME}}'=>esc_html(trim((string)$row->child_first_name.' '.(string)$row->child_last_name)),
            '{{CHILD_BIRTH_DATE}}'=>esc_html((string)$row->child_birth_date),
            '{{CHILD_PESEL}}'=>esc_html((string)$row->child_pesel),
            '{{CHILD_ADDRESS}}'=>nl2br(esc_html((string)($row->child_address ?? ''))),
            '{{CHILD_HEIGHT}}'=>esc_html((string)$row->child_height),
            '{{CHILD_WEIGHT}}'=>esc_html($weight),
            '{{SHIRT_SIZE}}'=>esc_html((string)$row->shirt_size),
            '{{CHILD_CLUB}}'=>esc_html((string)$row->child_club),
            '{{SPECIAL_EDUCATIONAL_NEEDS}}'=>nl2br(esc_html((string)($row->special_educational_needs ?? ''))),
            '{{MEDICAL_NOTES}}'=>nl2br(esc_html((string)$row->medical_notes)),
            '{{DIETARY_NOTES}}'=>nl2br(esc_html((string)$row->dietary_notes)),
            '{{VACCINATION_TETANUS}}'=>esc_html((string)($row->vaccination_tetanus ?? '')),
            '{{VACCINATION_DIPHTHERIA}}'=>esc_html((string)($row->vaccination_diphtheria ?? '')),
            '{{VACCINATION_OTHER}}'=>nl2br(esc_html((string)($row->vaccination_other ?? ''))),
            '{{STAY_CONTACT}}'=>nl2br(esc_html((string)$row->stay_contact)),
            '{{AUTHORIZED_PICKUP}}'=>nl2br(esc_html((string)$row->authorized_pickup)),
            '{{CAMP_NOTES}}'=>nl2br(esc_html((string)$row->camp_notes)),
            '{{INVOICE_REQUESTED}}'=>!empty($row->invoice_requested) ? 'tak' : 'nie',
            '{{INVOICE_BUYER_NAME}}'=>esc_html((string)($row->invoice_buyer_name ?? '')),
            '{{INVOICE_STREET}}'=>esc_html((string)($row->invoice_street ?? '')),
            '{{INVOICE_POSTAL_CODE}}'=>esc_html((string)($row->invoice_postal_code ?? '')),
            '{{INVOICE_CITY}}'=>esc_html((string)($row->invoice_city ?? '')),
            '{{INVOICE_NIP}}'=>esc_html((string)($row->invoice_nip ?? '')),
            '{{INVOICE_NOTES}}'=>nl2br(esc_html((string)($row->invoice_notes ?? ''))),
            '{{CAMP_NAME}}'=>esc_html((string)$row->camp_name),
            '{{CAMP_DATES}}'=>esc_html(trim((string)$row->start_date.' – '.(string)$row->end_date)),
            '{{CAMP_LOCATION}}'=>esc_html((string)$row->location),
            '{{TOTAL_AMOUNT}}'=>esc_html(number_format((float)$row->total_amount, 2, ',', ' ').' zł'),
            '{{ORGANIZER_NAME}}'=>esc_html((string)$row->organizer_name),
            '{{ORGANIZER_LEGAL_FORM}}'=>esc_html((string)$row->organizer_legal_form),
            '{{ORGANIZER_ADDRESS}}'=>nl2br(esc_html((string)$row->organizer_address)),
            '{{ORGANIZER_NIP}}'=>esc_html((string)$row->organizer_nip),
            '{{ORGANIZER_REGON}}'=>esc_html((string)$row->organizer_regon),
            '{{ORGANIZER_KRS}}'=>esc_html((string)$row->organizer_krs),
            '{{ORGANIZER_EMAIL}}'=>esc_html((string)$row->organizer_email),
            '{{ORGANIZER_PHONE}}'=>esc_html((string)$row->organizer_phone),
            '{{ORGANIZER_REPRESENTATIVE}}'=>esc_html((string)$row->organizer_representative),
            '{{BANK_NAME}}'=>esc_html((string)$row->bank_name),
            '{{BANK_ACCOUNT}}'=>esc_html(BCS_Utils::format_bank_account((string)$row->bank_account)),
            '{{TRANSFER_TITLE}}'=>esc_html($transfer_title),
        ];
    }

    private static function render_template_candidate(string $template, object $row): string {
        $rendered = BCS_Template_Engine::render($template, self::template_vars($row));
        $rendered = self::replace_unresolved($rendered);
        $rendered = self::strip_old_document_shell($rendered);
        return trim(wp_kses_post($rendered));
    }

    private static function render_current_template(object $row): string {
        $templates = [];
        $saved = BCS_Template_Engine::get('documents', 'agreement', '');
        if (trim($saved) !== '') $templates[] = $saved;

        $file = BCS_DIR.'templates/agreement-default.html';
        if (is_readable($file)) {
            $fallback = file_get_contents($file);
            if (is_string($fallback) && trim($fallback) !== '') $templates[] = $fallback;
        }
        $templates[] = BCS_Agreements::default_template();

        foreach ($templates as $template) {
            $rendered = self::render_template_candidate($template, $row);
            if (self::is_full_agreement($rendered)) return $rendered;
        }
        return '';
    }

    private static function canonical_body(object $row, string &$source, string &$hash): string {
        global $wpdb;
        $versions = $wpdb->get_results($wpdb->prepare(
            "SELECT id,stage,html,document_hash FROM ".BCS_DB::table('agreement_versions')."
             WHERE agreement_id=%d ORDER BY id DESC",
            (int)$row->agreement_real_id
        ));

        $candidates = [];
        foreach ($versions as $version) {
            if ((string)$version->stage === 'sent') {
                $candidates[] = ['sent_version', (string)$version->html, (string)$version->document_hash];
            }
        }
        foreach ($versions as $version) {
            if ((string)$version->stage === 'draft') {
                $candidates[] = ['draft_version', (string)$version->html, (string)$version->document_hash];
            }
        }
        $candidates[] = ['agreement_record', (string)$row->agreement_html, (string)$row->agreement_document_hash];
        foreach ($versions as $version) {
            if ((string)$version->stage === 'signed') {
                $candidates[] = ['signed_version', (string)$version->html, (string)$version->document_hash];
            }
        }

        foreach ($candidates as [$candidate_source, $candidate_html, $candidate_hash]) {
            $body = self::strip_old_document_shell($candidate_html);
            $body = self::replace_unresolved($body);
            $body = trim(wp_kses_post($body));
            if (!self::is_full_agreement($body)) continue;
            $source = $candidate_source;
            $hash = trim($candidate_hash) !== '' ? $candidate_hash : hash('sha256', $body);
            return $body;
        }

        $body = self::render_current_template($row);
        if ($body !== '') {
            $source = 'current_template_reconstruction';
            $hash = hash('sha256', $body);
            return $body;
        }

        $source = 'unavailable';
        $hash = '';
        return '';
    }

    private static function format_datetime(string $value): string {
        return trim($value) !== '' ? BCS_Utils::format_datetime($value) : '—';
    }

    private static function render_proof(object $row, string $hash): string {
        $org = get_option(self::proof_key((int)$row->agreement_real_id), []);
        if (!is_array($org)) $org = [];

        global $wpdb;
        $opened = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT MIN(created_at) FROM ".BCS_DB::table('logs')."
             WHERE registration_id=%d AND agreement_id=%d AND event_type='agreement_opened_for_signature'",
            (int)$row->id,
            (int)$row->agreement_real_id
        ));

        $representative = trim((string)$row->organizer_representative);
        if ($representative === '') $representative = trim((string)($org['user'] ?? ''));
        if ($representative === '') $representative = trim((string)$row->organizer_name);

        $parent_phone = trim((string)$row->parent_phone);
        $parent_sms = trim((string)$row->agreement_sms_message_id);
        $organizer_phone = trim((string)($org['phone'] ?? $row->organizer_phone));
        $organizer_sms = trim((string)($org['sms_id'] ?? ''));

        $vars = [
            '{{AGREEMENT_NUMBER}}'=>esc_html((string)$row->agreement_number),
            '{{ORGANIZER_ACCEPTED_AT}}'=>esc_html(self::format_datetime((string)($org['accepted_at'] ?? ''))),
            '{{ORGANIZER_PHONE}}'=>esc_html($organizer_phone !== '' ? $organizer_phone : '—'),
            '{{ORGANIZER_SMS_ID}}'=>esc_html($organizer_sms !== '' ? $organizer_sms : '—'),
            '{{ORGANIZER_USER}}'=>esc_html($representative !== '' ? $representative : '—'),
            '{{ORGANIZER_REPRESENTATIVE}}'=>esc_html($representative !== '' ? $representative : '—'),
            '{{ORGANIZER_NAME}}'=>esc_html((string)$row->organizer_name),
            '{{PARENT_NAME}}'=>esc_html(trim((string)$row->parent_first_name.' '.(string)$row->parent_last_name)),
            '{{PARENT_OPENED_AT}}'=>esc_html(self::format_datetime($opened)),
            '{{PARENT_ACCEPTED_AT}}'=>esc_html(self::format_datetime((string)$row->agreement_accepted_at)),
            '{{PARENT_PHONE}}'=>esc_html($parent_phone !== '' ? $parent_phone : '—'),
            '{{PARENT_SMS_ID}}'=>esc_html($parent_sms !== '' ? $parent_sms : '—'),
            '{{PARENT_DECLARATION}}'=>esc_html(trim((string)$row->agreement_declaration_text) !== '' ? (string)$row->agreement_declaration_text : '—'),
            '{{PARENT_IP}}'=>esc_html(trim((string)$row->agreement_accepted_ip) !== '' ? (string)$row->agreement_accepted_ip : '—'),
            '{{DOCUMENT_HASH}}'=>esc_html($hash),
            '{{ACCEPTED_AT}}'=>esc_html(self::format_datetime((string)$row->agreement_accepted_at)),
            '{{PHONE_MASKED}}'=>esc_html($parent_phone !== '' ? $parent_phone : '—'),
            '{{PHONE}}'=>esc_html($parent_phone !== '' ? $parent_phone : '—'),
            '{{SMS_ID}}'=>esc_html($parent_sms !== '' ? $parent_sms : '—'),
        ];

        $templates = [
            BCS_Template_Engine::get('documents', 'agreement_proof', ''),
            BCS_Release_029::default_proof_template(),
        ];
        foreach ($templates as $template) {
            if (trim($template) === '') continue;
            $proof = BCS_Template_Engine::render($template, $vars);
            $proof = trim(wp_kses_post(self::replace_unresolved($proof)));
            if ($proof === '' || str_contains($proof, '{{')) continue;
            if (!preg_match('~class=("|\')[^"\']*\bproof\b~i', $proof)) {
                $proof = '<div class="proof">'.$proof.'</div>';
            }
            return self::PROOF_START
                .'<!-- Cyfrowe potwierdzenie podpisania umowy -->'
                .$proof
                .self::PROOF_END;
        }
        return '';
    }

    private static function logo_data_uri(): string {
        $path = BCS_DIR.'assets/images/logo-basketmania-camp-white.png';
        if (!is_readable($path)) return '';
        $data = file_get_contents($path);
        if (!is_string($data) || $data === '') return '';
        return 'data:image/png;base64,'.base64_encode($data);
    }

    private static function company_identity(object $row): string {
        $parts = [];
        $name = trim((string)$row->organizer_name.' '.(string)$row->organizer_legal_form);
        if ($name !== '') $parts[] = $name;
        if (!empty($row->organizer_address)) $parts[] = (string)$row->organizer_address;
        if (!empty($row->organizer_nip)) $parts[] = 'NIP: '.(string)$row->organizer_nip;
        if (!empty($row->organizer_regon)) $parts[] = 'REGON: '.(string)$row->organizer_regon;
        if (!empty($row->organizer_krs)) $parts[] = 'KRS: '.(string)$row->organizer_krs;
        if (!empty($row->organizer_email)) $parts[] = (string)$row->organizer_email;
        if (!empty($row->organizer_phone)) $parts[] = (string)$row->organizer_phone;
        return implode(' · ', $parts);
    }

    private static function document_fragment(object $row, string $body, bool $with_proof, string $hash): string {
        $logo = self::logo_data_uri();
        $logo_html = $logo !== ''
            ? '<img src="'.esc_attr($logo).'" alt="Basketmania Camp" style="display:block;height:12mm;width:auto;max-width:72mm">'
            : '<strong class="bcs-document-logo-text">Basketmania Camp</strong>';

        $proof = $with_proof ? self::render_proof($row, $hash) : '';
        $identity = esc_html(self::company_identity($row));

        return '<style id="bcs-agreement-style-051">
            @page{margin:29mm 14mm 20mm 14mm}
            .bcs-document-051{font-family:"DejaVu Sans",Arial,sans-serif;font-size:10pt;line-height:1.38;color:#172033}
            .bcs-document-header{position:fixed;top:-23mm;left:0;right:0;height:18mm;background:#172033;border-bottom:2.5px solid #f97316;padding:3mm 6mm;box-sizing:border-box}
            .bcs-document-header img{display:block!important;height:12mm!important;width:auto!important;max-width:72mm!important;object-fit:contain}
            .bcs-document-logo-text{display:block;color:#fff;font-size:15pt;line-height:12mm}
            .bcs-document-content{display:block;position:relative}
            .bcs-document-content h1{font-size:16pt;line-height:1.2;text-align:center;margin:0 0 10px;color:#172033}
            .bcs-document-content h2{font-size:11.5pt;line-height:1.25;margin:11px 0 5px;color:#c2410c;page-break-after:avoid}
            .bcs-document-content h3{font-size:10.5pt;margin:8px 0 4px;color:#172033;page-break-after:avoid}
            .bcs-document-content p{margin:0 0 5px}
            .bcs-document-content ol,.bcs-document-content ul{margin:4px 0 7px 18px;padding:0}
            .bcs-document-content li{margin:0 0 3px}
            .bcs-document-content table{width:100%;border-collapse:collapse;margin:6px 0 9px;font-size:9.2pt;page-break-inside:auto}
            .bcs-document-content tr{page-break-inside:avoid}
            .bcs-document-content td,.bcs-document-content th{border:1px solid #cfd5df;padding:4px 5px;vertical-align:top}
            .bcs-document-content th{background:#fff7ed}
            .bcs-document-footer{position:fixed;bottom:-14mm;left:0;right:0;min-height:10mm;border-top:1.5px solid #f97316;padding-top:2.5mm;font-size:7.7pt;line-height:1.3;color:#4b5563;text-align:center}
            .proof{page-break-before:always;border:1.5px solid #f97316;padding:14px 16px;margin:0;box-sizing:border-box}
            .proof h2{font-size:14pt;color:#c2410c;margin:0 0 10px}
            .proof h3{font-size:10.5pt;color:#172033;margin:0 0 8px}
            .proof p{margin:0 0 4px;line-height:1.3}
            .proof code{font-family:"DejaVu Sans Mono",monospace;font-size:8pt;word-wrap:break-word}
            @media screen{
                html,body{background:#eef2f7;margin:0;padding:0}
                body{padding:24px}
                .bcs-document-051{max-width:820px;margin:0 auto;background:#fff;box-shadow:0 12px 34px rgba(23,32,51,.12);border-radius:10px;overflow:hidden}
                .bcs-document-header,.bcs-document-footer{position:static}
                .bcs-document-header{height:auto;min-height:72px;padding:14px 24px}
                .bcs-document-header img{height:44px!important;max-width:260px!important}
                .bcs-document-content{padding:30px 38px 34px}
                .bcs-document-footer{padding:13px 24px 16px;min-height:0}
                .proof{page-break-before:auto;margin-top:28px}
            }
        </style>'
        .'<div class="bcs-document-051">'
        .'<div class="bcs-document-header">'.$logo_html.'</div>'
        .'<div class="bcs-document-content">'.$body.$proof.'</div>'
        .'<div class="bcs-document-footer">'.$identity.'</div>'
        .'</div>';
    }

    private static function save_version(
        int $agreement_id,
        int $registration_id,
        string $stage,
        string $html,
        string $hash,
        string $number
    ): void {
        global $wpdb;
        $table = BCS_DB::table('agreement_versions');
        $id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE agreement_id=%d AND stage=%s ORDER BY id DESC LIMIT 1",
            $agreement_id,
            $stage
        ));
        $data = [
            'agreement_id'=>$agreement_id,
            'registration_id'=>$registration_id,
            'stage'=>$stage,
            'html'=>$html,
            'document_hash'=>$hash,
            'agreement_number'=>$number,
        ];
        if ($id) {
            $wpdb->update($table, $data, ['id'=>$id]);
        } else {
            $data['created_at'] = BCS_Utils::now();
            $wpdb->insert($table, $data);
        }
    }

    public static function repair_registration(int $registration_id, bool $force_signed=false): bool {
        $row = self::row($registration_id);
        if (!$row || empty($row->agreement_real_id)) return false;

        $source = '';
        $hash = '';
        $body = self::canonical_body($row, $source, $hash);
        if (!self::is_full_agreement($body)) {
            BCS_Utils::log('agreement_document_failed_051', [
                'source'=>$source,
                'reason'=>'Nie odnaleziono pełnej treści umowy ani prawidłowego szablonu.',
            ], $registration_id, (int)$row->agreement_real_id);
            return false;
        }

        $hash = $hash !== '' ? $hash : hash('sha256', $body);
        $base = self::document_fragment($row, $body, false, $hash);
        $signed_needed = $force_signed
            || in_array((string)$row->agreement_status, ['parent_signed','accepted'], true)
            || (string)$row->agreement_record_status === 'accepted';

        global $wpdb;
        $agreement_id = (int)$row->agreement_real_id;
        $wpdb->update(BCS_DB::table('agreements'), [
            'html'=>$base,
            'document_hash'=>$hash,
        ], ['id'=>$agreement_id]);

        $versions = $wpdb->get_results($wpdb->prepare(
            "SELECT id,stage FROM ".BCS_DB::table('agreement_versions')."
             WHERE agreement_id=%d AND stage IN ('draft','sent')",
            $agreement_id
        ));
        foreach ($versions as $version) {
            $wpdb->update(BCS_DB::table('agreement_versions'), [
                'html'=>$base,
                'document_hash'=>$hash,
                'agreement_number'=>(string)$row->agreement_number,
            ], ['id'=>(int)$version->id]);
        }

        if ($signed_needed) {
            $signed = self::document_fragment($row, $body, true, $hash);
            self::save_version(
                $agreement_id,
                $registration_id,
                'signed',
                $signed,
                $hash,
                (string)$row->agreement_number
            );
        }

        BCS_Utils::log('agreement_document_rebuilt_051', [
            'source'=>$source,
            'body_characters'=>mb_strlen(self::normalized_text($body)),
            'signed_version'=>$signed_needed,
            'unresolved_placeholders'=>str_contains($base, '{{'),
            'hash'=>$hash,
        ], $registration_id, $agreement_id);
        return true;
    }

    public static function repair_existing_once(): void {
        if (get_option(self::MIGRATION_OPTION)) return;
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT id FROM ".BCS_DB::table('registrations')."
             WHERE agreement_id IS NOT NULL AND agreement_id > 0"
        );
        foreach ($ids as $id) self::repair_registration((int)$id);
        update_option(self::MIGRATION_OPTION, 1, false);
    }

    private static function agreement_html_for_stage(int $registration_id, string $stage): string {
        global $wpdb;
        $row = self::row($registration_id);
        if (!$row || empty($row->agreement_real_id)) return '';

        $with_proof = $stage === 'signed'
            || in_array((string)$row->agreement_status, ['parent_signed','accepted'], true);
        self::repair_registration($registration_id, $with_proof);
        $row = self::row($registration_id);
        if (!$row) return '';

        if ($with_proof) {
            $signed = $wpdb->get_var($wpdb->prepare(
                "SELECT html FROM ".BCS_DB::table('agreement_versions')."
                 WHERE agreement_id=%d AND stage='signed' ORDER BY id DESC LIMIT 1",
                (int)$row->agreement_real_id
            ));
            if (is_string($signed) && trim($signed) !== '') return $signed;
        }

        if (in_array($stage, ['draft','sent'], true)) {
            $version = $wpdb->get_var($wpdb->prepare(
                "SELECT html FROM ".BCS_DB::table('agreement_versions')."
                 WHERE agreement_id=%d AND stage=%s ORDER BY id DESC LIMIT 1",
                (int)$row->agreement_real_id,
                $stage
            ));
            if (is_string($version) && trim($version) !== '') return $version;
        }
        return (string)$row->agreement_html;
    }

    public static function render_agreement_view(): void {
        global $wpdb;
        $agreement_id = absint($_GET['agreement'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $access = $wpdb->get_row($wpdb->prepare(
            "SELECT a.registration_id,a.agreement_number,r.public_token,r.agreement_status
             FROM ".BCS_DB::table('agreements')." a
             JOIN ".BCS_DB::table('registrations')." r ON r.id=a.registration_id
             WHERE a.id=%d LIMIT 1",
            $agreement_id
        ));
        if (!$access || (!current_user_can('manage_options') && !hash_equals((string)$access->public_token, $token))) {
            wp_die(BCS_Template_Engine::get('ui', 'access_denied', 'Brak dostępu.'), 403);
        }

        $stage = in_array((string)$access->agreement_status, ['parent_signed','accepted'], true)
            ? 'signed'
            : 'current';
        $fragment = self::agreement_html_for_stage((int)$access->registration_id, $stage);
        if ($fragment === '') wp_die('Dokument umowy nie jest dostępny.', 404);

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            .esc_html((string)$access->agreement_number)
            .'</title></head><body>'
            .$fragment
            .'</body></html>';
        exit;
    }

    public static function intercept_agreement_download(): void {
        if (empty($_GET['bcs_document'])) return;
        $document = sanitize_key(wp_unslash($_GET['document'] ?? ''));
        $map = [
            'agreement_draft'=>'draft',
            'agreement_sent'=>'sent',
            'agreement_current'=>'current',
            'agreement_signed'=>'signed',
        ];
        if (!isset($map[$document])) return;

        $registration_id = absint($_GET['registration'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if (!$registration_id || $token === '' || !hash_equals(
            BCS_Documents::document_token($registration_id, $document),
            $token
        )) {
            wp_die('Nieprawidłowy dostęp.', 'Basketmania Camp', ['response'=>403]);
        }

        $row = self::row($registration_id);
        if (!$row || empty($row->agreement_number)) {
            wp_die('Dokument umowy nie jest dostępny.', 'Basketmania Camp', ['response'=>404]);
        }
        if ($document === 'agreement_signed'
            && (string)$row->agreement_status !== 'accepted'
            && !current_user_can('manage_options')) {
            wp_die('Podpisana umowa będzie dostępna po podpisie Organizatora.', 'Basketmania Camp', ['response'=>403]);
        }

        $fragment = self::agreement_html_for_stage($registration_id, $map[$document]);
        if ($fragment === '') {
            wp_die('Dokument umowy nie jest dostępny.', 'Basketmania Camp', ['response'=>404]);
        }

        $html = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>'
            .esc_html((string)$row->agreement_number)
            .'</title></head><body>'.$fragment.'</body></html>';

        $dir = BCS_Documents::uploads_dir().'/registration-'.$registration_id;
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $name = $document === 'agreement_signed'
            ? '02-umowa-podpisana.pdf'
            : '02-umowa.pdf';
        $path = $dir.'/'.$name;

        if (!BCS_PDF::generate($html, $path, 'Umowa '.$row->agreement_number) || !file_exists($path)) {
            wp_die(
                BCS_PDF::available()
                    ? 'Nie udało się wygenerować kompletnego dokumentu PDF.'
                    : 'Dompdf nie jest zainstalowany.',
                'Basketmania Camp'
            );
        }

        BCS_Utils::log('document_downloaded', [
            'document'=>$document,
            'file'=>basename($path),
            'renderer'=>'051',
            'ip'=>BCS_Utils::client_ip(),
        ], $registration_id, (int)$row->agreement_real_id);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.basename($path).'"');
        header('Content-Length: '.filesize($path));
        readfile($path);
        exit;
    }

    public static function repair_after_signature_request(): void {
        $action = sanitize_key((string)($_REQUEST['action'] ?? ''));
        $registration_id = 0;
        if ($action === 'bcs_046_organizer_otp_verify') {
            $registration_id = absint($_REQUEST['registration_id'] ?? 0);
        } elseif ($action === 'bcs_verify_otp') {
            $agreement_id = absint($_REQUEST['agreement_id'] ?? 0);
            if ($agreement_id) {
                global $wpdb;
                $registration_id = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT registration_id FROM ".BCS_DB::table('agreements')." WHERE id=%d",
                    $agreement_id
                ));
            }
        }
        if ($registration_id) self::repair_registration($registration_id);
    }

    public static function ajax_verify_organizer_otp(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        }
        check_ajax_referer('bcs_046', 'nonce');

        global $wpdb;
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        if (strlen($code) !== 6) {
            wp_send_json_error(['message'=>'Wpisz pełny 6-cyfrowy kod SMS.'], 400);
        }

        $data = get_transient(self::request_key($registration_id));
        if (!is_array($data) || empty($data['agreement_id'])) {
            wp_send_json_error(['message'=>'Kod wygasł albo nie został wysłany.'], 410);
        }
        if ((int)($data['expires'] ?? 0) < time()) {
            delete_transient(self::request_key($registration_id));
            wp_send_json_error(['message'=>'Kod wygasł. Wyślij nowy.'], 410);
        }

        $attempts = (int)($data['attempts'] ?? 0);
        if ($attempts >= 5) {
            delete_transient(self::request_key($registration_id));
            wp_send_json_error(['message'=>'Przekroczono liczbę prób. Wyślij nowy kod.'], 429);
        }
        if (!wp_check_password($code, (string)$data['code_hash'])) {
            $data['attempts'] = $attempts + 1;
            set_transient(
                self::request_key($registration_id),
                $data,
                max(1, (int)$data['expires'] - time())
            );
            wp_send_json_error(['message'=>'Kod jest nieprawidłowy.'], 400);
        }

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,a.status agreement_record_status
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d",
            $registration_id
        ));
        if (!$registration || (string)$registration->agreement_status !== 'parent_signed') {
            wp_send_json_error(['message'=>'Najpierw umowę musi podpisać rodzic.'], 409);
        }

        $user = wp_get_current_user();
        $now = BCS_Utils::now();
        $proof = [
            'accepted_at'=>$now,
            'phone'=>(string)$data['phone'],
            'sms_id'=>(string)$data['sms_id'],
            'user'=>trim($user->display_name.' (ID '.get_current_user_id().')'),
            'registration_id'=>$registration_id,
        ];
        update_option(self::proof_key((int)$data['agreement_id']), $proof, false);

        $due = (new DateTimeImmutable('+7 days', BCS_Utils::timezone()))->format('Y-m-d');
        $updated = $wpdb->update(BCS_DB::table('registrations'), [
            'agreement_status'=>'accepted',
            'status'=>'awaiting_bank_payment',
            'payment_due_date'=>$due,
            'updated_at'=>$now,
        ], ['id'=>$registration_id]);
        if ($updated === false) {
            wp_send_json_error(['message'=>'Nie udało się zakończyć podpisywania umowy.'], 500);
        }

        if (!self::repair_registration($registration_id, true)) {
            $wpdb->update(BCS_DB::table('registrations'), [
                'agreement_status'=>'parent_signed',
                'status'=>'agreement_parent_signed',
                'payment_due_date'=>null,
                'updated_at'=>BCS_Utils::now(),
            ], ['id'=>$registration_id]);
            BCS_Utils::log('agreement_final_document_failed_051', [
                'reason'=>'Nie udało się zbudować pełnej treści finalnej umowy.',
            ], $registration_id, (int)$data['agreement_id']);
            wp_send_json_error([
                'message'=>'Kod jest poprawny, ale system nie zbudował pełnej umowy. Proces zatrzymano przed płatnością.',
            ], 500);
        }

        delete_transient(self::request_key($registration_id));

        if (class_exists('BCS_Workflow_Engine')) {
            BCS_Workflow_Engine::refresh_invoice_readiness($registration_id);
        }
        if (class_exists('BCS_Communication_Engine')) {
            BCS_Communication_Engine::send_to_registration($registration_id, 'agreement_signed', 'email');
            BCS_Communication_Engine::send_to_registration($registration_id, 'payment_reminder', 'both');
        }

        BCS_Utils::log('organizer_agreement_otp_verified', [
            'phone'=>(string)$proof['phone'],
            'sms_message_id'=>$proof['sms_id'],
            'workflow'=>'parent_first_051',
            'final_document_verified'=>true,
        ], $registration_id, (int)$data['agreement_id']);

        wp_send_json_success([
            'message'=>'Umowa została podpisana przez Organizatora. Pełny dokument jest gotowy do pobrania.',
        ]);
    }
}
