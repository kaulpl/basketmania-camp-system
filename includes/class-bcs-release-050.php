<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_050 {
    private const MIGRATION_OPTION = 'bcs_release_050_agreements_repaired';
    private const PROOF_START = '<!-- BCS-AGREEMENT-PROOF-050-START -->';
    private const PROOF_END = '<!-- BCS-AGREEMENT-PROOF-050-END -->';
    private const BRAND_START = '<!-- BCS-AGREEMENT-BRAND-038-START -->';
    private const BRAND_END = '<!-- BCS-AGREEMENT-BRAND-038-END -->';
    private const LAYOUT_START = '<!-- BCS-AGREEMENT-LAYOUT-039-START -->';
    private const LAYOUT_END = '<!-- BCS-AGREEMENT-LAYOUT-039-END -->';

    public static function init(): void {
        // 0.46 kończy proces podpisu Organizatora. Przejmujemy wyłącznie weryfikację,
        // aby finalny dokument został zbudowany przed wysyłką e-mail i płatności.
        remove_action('wp_ajax_bcs_046_organizer_otp_verify', ['BCS_Release_046', 'ajax_verify_organizer_otp']);
        add_action('wp_ajax_bcs_046_organizer_otp_verify', [__CLASS__, 'ajax_verify_organizer_otp']);

        add_action('admin_init', [__CLASS__, 'repair_existing_once'], 2);
        add_action('template_redirect', [__CLASS__, 'repair_before_download'], 1);
        register_shutdown_function([__CLASS__, 'repair_after_signature_request']);
    }

    private static function request_key(int $registration_id): string {
        return 'bcs_046_org_otp_' . get_current_user_id() . '_' . $registration_id;
    }

    private static function proof_key(int $agreement_id): string {
        return 'bcs_org_proof_' . $agreement_id;
    }

    private static function agreement_row(int $registration_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,
                    c.name camp_name,c.start_date,c.end_date,c.location,c.organizer_id camp_organizer_id,
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

    private static function strip_layout(string $html): string {
        return trim((string)preg_replace(
            '~\s*'.preg_quote(self::LAYOUT_START, '~').'.*?'.preg_quote(self::LAYOUT_END, '~').'\s*~s',
            '',
            $html
        ));
    }

    private static function unwrap_branding(string $html): string {
        $pattern = '~'.preg_quote(self::BRAND_START, '~').'.*?'
            .'<div\b[^>]*class=("|\')[^"\']*\bbcs-agreement-content\b[^"\']*\1[^>]*>(.*?)</div>\s*'
            .'<div\b[^>]*class=("|\')[^"\']*\bbcs-agreement-footer\b[^"\']*\3[^>]*>.*?</div>\s*</div>\s*'
            .preg_quote(self::BRAND_END, '~').'~is';
        $unwrapped = preg_replace($pattern, '$2', $html);
        if (is_string($unwrapped) && $unwrapped !== $html) return trim($unwrapped);
        return trim(str_replace([self::BRAND_START, self::BRAND_END], '', $html));
    }

    private static function strip_proofs(string $html): string {
        $html = preg_replace(
            '~\s*'.preg_quote(self::PROOF_START, '~').'.*?'.preg_quote(self::PROOF_END, '~').'\s*~is',
            '',
            $html
        );
        // Historyczne sekcje dowodowe były zawsze ostatnim elementem treści.
        $html = preg_replace(
            '~\s*<div\b[^>]*class=("|\')[^"\']*\bproof\b[^"\']*\1[^>]*>.*$~is',
            '',
            (string)$html
        );
        return trim((string)$html);
    }

    private static function extract_core(string $html): string {
        return self::strip_proofs(self::strip_layout(self::unwrap_branding($html)));
    }

    private static function looks_like_agreement(string $html): bool {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if (mb_strlen((string)$text) < 240) return false;
        return (bool)preg_match('/\b(umow|uczestnictw|organizator|przedmiot)\w*/iu', (string)$text);
    }

    private static function replace_unresolved(string $html): string {
        return (string)preg_replace('/\{\{[A-Z0-9_]+\}\}/', '—', $html);
    }

    private static function agreement_date(object $row): string {
        $value = (string)($row->agreement_created_at ?? '');
        $timestamp = $value !== '' ? strtotime($value) : false;
        return $timestamp ? wp_date('d.m.Y', $timestamp) : BCS_Utils::today('d.m.Y');
    }

    private static function render_template(object $row): string {
        $template = BCS_Template_Engine::get('documents', 'agreement', BCS_Agreements::default_template());
        if (trim($template) === '') $template = BCS_Agreements::default_template();

        $weight = !empty($row->child_weight)
            ? rtrim(rtrim(number_format((float)$row->child_weight, 1, ',', ''), '0'), ',')
            : '';
        $invoice_requested = !empty($row->invoice_requested) ? 'tak' : 'nie';
        $transfer_title = trim((string)($row->transfer_title_template ?? ''));
        $transfer_title = strtr($transfer_title, [
            '{{AGREEMENT_NUMBER}}' => (string)$row->agreement_number,
            '{{CHILD_NAME}}' => trim((string)$row->child_first_name.' '.(string)$row->child_last_name),
        ]);

        $vars = [
            '{{AGREEMENT_NUMBER}}' => esc_html((string)$row->agreement_number),
            '{{AGREEMENT_DATE}}' => esc_html(self::agreement_date($row)),
            '{{PARENT_NAME}}' => esc_html(trim((string)$row->parent_first_name.' '.(string)$row->parent_last_name)),
            '{{PARENTS_NAMES}}' => esc_html((string)($row->parents_names ?? '')),
            '{{PARENT_ADDRESS}}' => nl2br(esc_html((string)BCS_Utils::registration_address($row))),
            '{{PARENT_EMAIL}}' => esc_html((string)$row->parent_email),
            '{{PARENT_PHONE}}' => esc_html((string)$row->parent_phone),
            '{{PARENT_PHONE_ALT}}' => esc_html((string)($row->second_parent_phone ?? $row->parent_phone_alt ?? '')),
            '{{PARENT_PHONE_2}}' => esc_html((string)($row->second_parent_phone ?? $row->parent_phone_alt ?? '')),
            '{{SECOND_PARENT_NAME}}' => esc_html(trim((string)($row->second_parent_first_name ?? '').' '.(string)($row->second_parent_last_name ?? ''))),
            '{{SECOND_PARENT_EMAIL}}' => esc_html((string)($row->second_parent_email ?? '')),
            '{{SECOND_PARENT_PHONE}}' => esc_html((string)($row->second_parent_phone ?? '')),
            '{{SOLE_GUARDIAN}}' => !empty($row->sole_guardian) ? 'tak' : 'nie',
            '{{CHILD_NAME}}' => esc_html(trim((string)$row->child_first_name.' '.(string)$row->child_last_name)),
            '{{CHILD_BIRTH_DATE}}' => esc_html((string)$row->child_birth_date),
            '{{CHILD_PESEL}}' => esc_html((string)$row->child_pesel),
            '{{CHILD_ADDRESS}}' => nl2br(esc_html((string)($row->child_address ?? ''))),
            '{{CHILD_HEIGHT}}' => esc_html((string)$row->child_height),
            '{{CHILD_WEIGHT}}' => esc_html($weight),
            '{{SHIRT_SIZE}}' => esc_html((string)$row->shirt_size),
            '{{CHILD_CLUB}}' => esc_html((string)$row->child_club),
            '{{SPECIAL_EDUCATIONAL_NEEDS}}' => nl2br(esc_html((string)($row->special_educational_needs ?? ''))),
            '{{MEDICAL_NOTES}}' => nl2br(esc_html((string)$row->medical_notes)),
            '{{DIETARY_NOTES}}' => nl2br(esc_html((string)$row->dietary_notes)),
            '{{VACCINATION_TETANUS}}' => esc_html((string)($row->vaccination_tetanus ?? '')),
            '{{VACCINATION_DIPHTHERIA}}' => esc_html((string)($row->vaccination_diphtheria ?? '')),
            '{{VACCINATION_OTHER}}' => nl2br(esc_html((string)($row->vaccination_other ?? ''))),
            '{{STAY_CONTACT}}' => nl2br(esc_html((string)$row->stay_contact)),
            '{{AUTHORIZED_PICKUP}}' => nl2br(esc_html((string)$row->authorized_pickup)),
            '{{CAMP_NOTES}}' => nl2br(esc_html((string)$row->camp_notes)),
            '{{INVOICE_REQUESTED}}' => esc_html($invoice_requested),
            '{{INVOICE_BUYER_NAME}}' => esc_html((string)($row->invoice_buyer_name ?? '')),
            '{{INVOICE_STREET}}' => esc_html((string)($row->invoice_street ?? '')),
            '{{INVOICE_POSTAL_CODE}}' => esc_html((string)($row->invoice_postal_code ?? '')),
            '{{INVOICE_CITY}}' => esc_html((string)($row->invoice_city ?? '')),
            '{{INVOICE_NIP}}' => esc_html((string)($row->invoice_nip ?? '')),
            '{{INVOICE_NOTES}}' => nl2br(esc_html((string)($row->invoice_notes ?? ''))),
            '{{CAMP_NAME}}' => esc_html((string)$row->camp_name),
            '{{CAMP_DATES}}' => esc_html(trim((string)$row->start_date.' – '.(string)$row->end_date)),
            '{{CAMP_LOCATION}}' => esc_html((string)$row->location),
            '{{TOTAL_AMOUNT}}' => esc_html(number_format((float)$row->total_amount, 2, ',', ' ').' zł'),
            '{{ORGANIZER_NAME}}' => esc_html((string)$row->organizer_name),
            '{{ORGANIZER_LEGAL_FORM}}' => esc_html((string)$row->organizer_legal_form),
            '{{ORGANIZER_ADDRESS}}' => nl2br(esc_html((string)$row->organizer_address)),
            '{{ORGANIZER_NIP}}' => esc_html((string)$row->organizer_nip),
            '{{ORGANIZER_REGON}}' => esc_html((string)$row->organizer_regon),
            '{{ORGANIZER_KRS}}' => esc_html((string)$row->organizer_krs),
            '{{ORGANIZER_EMAIL}}' => esc_html((string)$row->organizer_email),
            '{{ORGANIZER_PHONE}}' => esc_html((string)$row->organizer_phone),
            '{{ORGANIZER_REPRESENTATIVE}}' => esc_html((string)$row->organizer_representative),
            '{{BANK_NAME}}' => esc_html((string)$row->bank_name),
            '{{BANK_ACCOUNT}}' => esc_html(BCS_Utils::format_bank_account((string)$row->bank_account)),
            '{{TRANSFER_TITLE}}' => esc_html($transfer_title),
        ];

        return trim(self::replace_unresolved(BCS_Template_Engine::render($template, $vars)));
    }

    private static function recover_core(object $row, string &$source): string {
        global $wpdb;
        $versions = $wpdb->get_results($wpdb->prepare(
            "SELECT stage,html FROM ".BCS_DB::table('agreement_versions')."
             WHERE agreement_id=%d ORDER BY
             CASE stage WHEN 'sent' THEN 1 WHEN 'draft' THEN 2 WHEN 'signed' THEN 3 ELSE 4 END,
             id DESC",
            (int)$row->agreement_real_id
        ));

        $candidates = [];
        foreach ($versions as $version) {
            if ((string)$version->stage === 'sent') $candidates[] = ['sent_version', (string)$version->html];
        }
        $candidates[] = ['agreement_record', (string)$row->agreement_html];
        foreach ($versions as $version) {
            if ((string)$version->stage === 'draft') $candidates[] = ['draft_version', (string)$version->html];
        }
        foreach ($versions as $version) {
            if ((string)$version->stage === 'signed') $candidates[] = ['signed_version', (string)$version->html];
        }

        foreach ($candidates as [$candidate_source, $candidate_html]) {
            $core = self::extract_core($candidate_html);
            if (self::looks_like_agreement($core)) {
                $source = $candidate_source;
                return self::replace_unresolved($core);
            }
        }

        $source = 'current_template';
        return self::render_template($row);
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

    private static function brand_and_layout(string $content, object $row): string {
        $content = self::strip_layout(self::unwrap_branding($content));
        $logo = esc_url(BCS_URL.'assets/images/logo-basketmania-camp-white.png');
        $identity = esc_html(self::company_identity($row));

        $layout = self::LAYOUT_START.'<style>
            .bcs-agreement-document,.bcs-agreement-content{font-size:10pt;line-height:1.32}
            .bcs-agreement-content p{margin:0 0 5px}
            .bcs-agreement-content h1{font-size:16pt;margin:0 0 10px}
            .bcs-agreement-content h2{font-size:12pt;margin:10px 0 6px}
            .bcs-agreement-content h3{font-size:10.5pt;margin:8px 0 5px}
            .bcs-agreement-content table{font-size:9.5pt;line-height:1.22}
            .bcs-agreement-content td,.bcs-agreement-content th{padding:3px 5px}
            .bcs-attachment-one{font-size:9pt;line-height:1.15;page-break-inside:avoid;break-inside:avoid-page}
            .bcs-attachment-one p{margin:0 0 3px}
            .proof{page-break-before:always;break-before:page;margin-top:0!important}
            @media print{.proof{page-break-before:always;break-before:page}.bcs-attachment-one{page-break-inside:avoid;break-inside:avoid-page}}
        </style>'.self::LAYOUT_END;

        $brand = self::BRAND_START.'<style>
            @page{margin:92px 45px 72px 45px}
            .bcs-agreement-document{font-family:"DejaVu Sans",Arial,sans-serif}
            .bcs-agreement-header{position:fixed;top:-72px;left:0;right:0;height:58px;background:#172033;border-bottom:3px solid #f97316;padding:7px 18px 6px 18px;box-sizing:border-box}
            .bcs-agreement-header img{height:42px;width:auto;display:block}
            .bcs-agreement-content{position:relative}
            .bcs-agreement-footer{position:fixed;bottom:-55px;left:0;right:0;min-height:42px;border-top:2px solid #f97316;padding:7px 4px 0;font-size:8.5pt;line-height:1.35;color:#4b5563;text-align:center;box-sizing:border-box}
            @media screen{.bcs-agreement-document{max-width:900px;margin:0 auto}.bcs-agreement-header,.bcs-agreement-footer{position:relative;top:auto;bottom:auto}.bcs-agreement-header{margin-bottom:24px}.bcs-agreement-footer{margin-top:30px}}
        </style><div class="bcs-agreement-document"><div class="bcs-agreement-header"><img src="'.$logo.'" alt="Basketmania Camp"></div><div class="bcs-agreement-content">';

        return $layout.$brand.$content.'</div><div class="bcs-agreement-footer">'.$identity.'</div></div>'.self::BRAND_END;
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

        $template = BCS_Template_Engine::get(
            'documents',
            'agreement_proof',
            BCS_Release_029::default_proof_template()
        );
        if (trim($template) === '') $template = BCS_Release_029::default_proof_template();

        $parent_phone = (string)$row->parent_phone;
        $parent_sms = (string)$row->agreement_sms_message_id;
        $parent_accepted = self::format_datetime((string)$row->agreement_accepted_at);
        $organizer_phone = (string)($org['phone'] ?? $row->organizer_phone);
        $organizer_sms = (string)($org['sms_id'] ?? '—');
        $organizer_accepted = self::format_datetime((string)($org['accepted_at'] ?? ''));

        $vars = [
            '{{AGREEMENT_NUMBER}}' => esc_html((string)$row->agreement_number),
            '{{ORGANIZER_ACCEPTED_AT}}' => esc_html($organizer_accepted),
            '{{ORGANIZER_PHONE}}' => esc_html($organizer_phone !== '' ? $organizer_phone : '—'),
            '{{ORGANIZER_SMS_ID}}' => esc_html($organizer_sms !== '' ? $organizer_sms : '—'),
            '{{ORGANIZER_USER}}' => esc_html($representative !== '' ? $representative : '—'),
            '{{ORGANIZER_REPRESENTATIVE}}' => esc_html($representative !== '' ? $representative : '—'),
            '{{ORGANIZER_NAME}}' => esc_html((string)$row->organizer_name),
            '{{PARENT_NAME}}' => esc_html(trim((string)$row->parent_first_name.' '.(string)$row->parent_last_name)),
            '{{PARENT_OPENED_AT}}' => esc_html(self::format_datetime($opened)),
            '{{PARENT_ACCEPTED_AT}}' => esc_html($parent_accepted),
            '{{PARENT_PHONE}}' => esc_html($parent_phone !== '' ? $parent_phone : '—'),
            '{{PARENT_SMS_ID}}' => esc_html($parent_sms !== '' ? $parent_sms : '—'),
            '{{PARENT_DECLARATION}}' => esc_html((string)$row->agreement_declaration_text),
            '{{PARENT_IP}}' => esc_html((string)$row->agreement_accepted_ip),
            '{{DOCUMENT_HASH}}' => esc_html($hash),
            // Zgodność ze starszym szablonem sekcji dowodowej.
            '{{ACCEPTED_AT}}' => esc_html($parent_accepted),
            '{{PHONE_MASKED}}' => esc_html($parent_phone !== '' ? $parent_phone : '—'),
            '{{PHONE}}' => esc_html($parent_phone !== '' ? $parent_phone : '—'),
            '{{SMS_ID}}' => esc_html($parent_sms !== '' ? $parent_sms : '—'),
        ];

        $proof = trim(self::replace_unresolved(BCS_Template_Engine::render($template, $vars)));
        if (!preg_match('~class=("|\')[^"\']*\bproof\b~i', $proof)) {
            $proof = '<div class="proof">'.$proof.'</div>';
        }

        // Komentarz zachowuje zgodność z historycznym wykrywaniem sekcji w BCS_Documents.
        return self::PROOF_START.'<!-- Cyfrowe potwierdzenie podpisania umowy -->'.$proof.self::PROOF_END;
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
            'agreement_id' => $agreement_id,
            'registration_id' => $registration_id,
            'stage' => $stage,
            'html' => $html,
            'document_hash' => $hash,
            'agreement_number' => $number,
        ];
        if ($id) {
            $wpdb->update($table, $data, ['id'=>$id]);
        } else {
            $data['created_at'] = BCS_Utils::now();
            $wpdb->insert($table, $data);
        }
    }

    public static function repair_registration(int $registration_id, bool $force_signed = false): bool {
        $row = self::agreement_row($registration_id);
        if (!$row || empty($row->agreement_real_id)) return false;

        $source = '';
        $core = self::recover_core($row, $source);
        if (!self::looks_like_agreement($core)) return false;

        global $wpdb;
        $agreement_id = (int)$row->agreement_real_id;
        $base_html = self::brand_and_layout($core, $row);
        $hash = hash('sha256', $base_html);
        $changed = (string)$row->agreement_html !== $base_html
            || (string)$row->agreement_document_hash !== $hash;

        $wpdb->update(BCS_DB::table('agreements'), [
            'html' => $base_html,
            'document_hash' => $hash,
        ], ['id'=>$agreement_id]);

        $versions = $wpdb->get_results($wpdb->prepare(
            "SELECT id,stage,html,document_hash FROM ".BCS_DB::table('agreement_versions')."
             WHERE agreement_id=%d AND stage IN ('draft','sent')",
            $agreement_id
        ));
        foreach ($versions as $version) {
            $version_core = self::extract_core((string)$version->html);
            if (!self::looks_like_agreement($version_core)) $version_core = $core;
            $version_html = self::brand_and_layout(self::replace_unresolved($version_core), $row);
            $version_hash = hash('sha256', $version_html);
            if ($version_html !== (string)$version->html || $version_hash !== (string)$version->document_hash) {
                $changed = true;
                $wpdb->update(BCS_DB::table('agreement_versions'), [
                    'html' => $version_html,
                    'document_hash' => $version_hash,
                    'agreement_number' => (string)$row->agreement_number,
                ], ['id'=>(int)$version->id]);
            }
        }

        $needs_signed = $force_signed
            || in_array((string)$row->agreement_status, ['parent_signed','accepted'], true)
            || (string)$row->agreement_record_status === 'accepted';
        if ($needs_signed) {
            $proof = self::render_proof($row, $hash);
            $signed_html = self::brand_and_layout($core.$proof, $row);
            self::save_version(
                $agreement_id,
                $registration_id,
                'signed',
                $signed_html,
                $hash,
                (string)$row->agreement_number
            );
            $changed = true;
        }

        if ($changed) {
            BCS_Utils::log('agreement_document_repaired_050', [
                'source' => $source,
                'full_agreement_restored' => true,
                'single_evidence_section' => $needs_signed,
                'unresolved_placeholders_removed' => true,
                'hash' => $hash,
            ], $registration_id, $agreement_id);
        }
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

    public static function repair_before_download(): void {
        if (empty($_GET['bcs_document'])) return;
        $document = sanitize_key(wp_unslash($_GET['document'] ?? ''));
        if (!in_array($document, ['agreement_signed','complete'], true)) return;
        self::repair_registration(absint($_GET['registration'] ?? 0), true);
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
            'accepted_at' => $now,
            'phone' => (string)$data['phone'],
            'sms_id' => (string)$data['sms_id'],
            'user' => trim($user->display_name.' (ID '.get_current_user_id().')'),
            'registration_id' => $registration_id,
        ];
        update_option(self::proof_key((int)$data['agreement_id']), $proof, false);

        $due = (new DateTimeImmutable('+7 days', BCS_Utils::timezone()))->format('Y-m-d');
        $updated = $wpdb->update(BCS_DB::table('registrations'), [
            'agreement_status' => 'accepted',
            'status' => 'awaiting_bank_payment',
            'payment_due_date' => $due,
            'updated_at' => $now,
        ], ['id'=>$registration_id]);
        if ($updated === false) {
            wp_send_json_error(['message'=>'Nie udało się zakończyć podpisywania umowy.'], 500);
        }

        // Najpierw tworzymy kompletny dokument: pełna treść zamrożonej umowy + jedna sekcja dowodowa.
        // Dopiero później wolno wysłać e-mail i rozpocząć etap płatności.
        if (!self::repair_registration($registration_id, true)) {
            $wpdb->update(BCS_DB::table('registrations'), [
                'agreement_status' => 'parent_signed',
                'status' => 'agreement_parent_signed',
                'payment_due_date' => null,
                'updated_at' => BCS_Utils::now(),
            ], ['id'=>$registration_id]);
            BCS_Utils::log('agreement_final_document_failed_050', [
                'reason' => 'Nie udało się odtworzyć pełnej treści umowy przed zakończeniem podpisu Organizatora.',
            ], $registration_id, (int)$data['agreement_id']);
            wp_send_json_error([
                'message'=>'Kod jest poprawny, ale system nie zbudował kompletnego dokumentu umowy. Proces zatrzymano przed płatnością. Spróbuj ponownie po odświeżeniu strony.',
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
            'phone' => (string)$proof['phone'],
            'sms_message_id' => $proof['sms_id'],
            'workflow' => 'parent_first_050',
            'final_document_verified' => true,
        ], $registration_id, (int)$data['agreement_id']);

        wp_send_json_success([
            'message'=>'Umowa została podpisana przez Organizatora. Kompletny dokument z treścią umowy i sekcją dowodową udostępniono rodzicowi.',
        ]);
    }
}
