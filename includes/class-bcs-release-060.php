<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_060 {
    private const DISPLAY_ACTION = 'bcs_060_card_form_display';

    public static function init(): void {
        /*
         * 0.42, 0.58 i 0.59 modyfikowały ten sam fragment DOM w różnej kolejności.
         * Wyłączamy wyłącznie ich warstwę widoku Karty Zgłoszenia. Endpointy edycji
         * z 0.42 pozostają aktywne, a popup z 0.58 nadal działa na Liście Zgłoszeń.
         */
        remove_action('admin_footer', ['BCS_Release_042', 'admin_footer'], 10);
        remove_action('admin_footer', ['BCS_Release_058', 'admin_footer'], 5);
        remove_action('admin_footer', ['BCS_Release_059', 'admin_footer'], 4);

        add_action('admin_footer', [__CLASS__, 'list_footer'], 5);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_card_assets']);
        add_action('wp_ajax_'.self::DISPLAY_ACTION, [__CLASS__, 'ajax_display']);
    }

    private static function card_id(): int {
        if (!is_admin()) return 0;
        if (sanitize_key((string)($_GET['page'] ?? '')) !== 'bcs-registrations') return 0;
        foreach (['view', 'registration_id', 'id'] as $key) {
            $id = absint($_GET[$key] ?? 0);
            if ($id > 0) return $id;
        }
        return 0;
    }

    public static function list_footer(): void {
        if (self::card_id() > 0) return;
        if (class_exists('BCS_Release_058')) BCS_Release_058::admin_footer();
    }

    private static function row(int $registration_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,c.name camp_name,c.start_date,c.end_date,c.location,
                    a.status agreement_record_status,
                    EXISTS(SELECT 1 FROM ".BCS_DB::table('agreement_versions')." av
                           WHERE av.registration_id=r.id AND av.stage IN ('sent','signed')) has_final_agreement,
                    EXISTS(SELECT 1 FROM ".BCS_DB::table('invoices')." i
                           WHERE i.registration_id=r.id AND i.status IN ('generated','sent')) has_invoice
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             WHERE r.id=%d LIMIT 1",
            $registration_id
        )) ?: null;
    }

    private static function display_sections(object $r): array {
        return [
            'Rodzic / opiekun prawny' => [
                ['Imię i nazwisko', trim((string)$r->parent_first_name.' '.(string)$r->parent_last_name)],
                ['Imiona i nazwiska rodziców', $r->parents_names ?? ''],
                ['E-mail', $r->parent_email ?? ''],
                ['Telefon I', $r->parent_phone ?? ''],
                ['Telefon II', $r->parent_phone_alt ?? ''],
                ['Kod pocztowy', $r->parent_postal_code ?? ''],
                ['Miejscowość', $r->parent_city ?? ''],
                ['Ulica', $r->parent_street ?? ''],
                ['Nr domu / lokalu', $r->parent_house_number ?? ''],
            ],
            'Uczestnik obozu' => [
                ['Imię i nazwisko', trim((string)$r->child_first_name.' '.(string)$r->child_last_name)],
                ['Adres uczestnika, jeżeli inny', $r->child_address ?? '', true],
                ['Data urodzenia', $r->child_birth_date ?? ''],
                ['PESEL', $r->child_pesel ?? ''],
                ['Wzrost', !empty($r->child_height) ? (string)$r->child_height.' cm' : ''],
                ['Waga', !empty($r->child_weight) ? (string)$r->child_weight.' kg' : ''],
                ['Rozmiar stroju', $r->shirt_size ?? ''],
                ['Klub', $r->child_club ?? ''],
            ],
            'Zdrowie, żywienie i szczepienia' => [
                ['Specjalne potrzeby edukacyjne', $r->special_educational_needs ?? '', true],
                ['Uwagi zdrowotne', $r->medical_notes ?? '', true],
                ['Dieta i żywienie', $r->dietary_notes ?? '', true],
                ['Szczepienie przeciw tężcowi – rok', $r->vaccination_tetanus ?? ''],
                ['Szczepienie przeciw błonicy – rok', $r->vaccination_diphtheria ?? ''],
                ['Inne szczepienia', $r->vaccination_other ?? '', true],
            ],
            'Informacje dotyczące pobytu' => [
                ['Kontakt podczas pobytu', $r->stay_contact ?? '', true],
                ['Osoby upoważnione do odbioru', $r->authorized_pickup ?? '', true],
                ['Dodatkowe informacje dla organizatora', $r->camp_notes ?? '', true],
            ],
            'Dane do faktury' => [
                ['Faktura', !empty($r->invoice_requested) ? 'tak' : 'nie'],
                ['Nabywca faktury', $r->invoice_buyer_name ?? ''],
                ['Ulica do faktury', $r->invoice_street ?? ''],
                ['Kod pocztowy do faktury', $r->invoice_postal_code ?? ''],
                ['Miejscowość do faktury', $r->invoice_city ?? ''],
                ['NIP nabywcy', $r->invoice_nip ?? ''],
                ['Dodatkowe dane na fakturze', $r->invoice_notes ?? '', true],
            ],
            'Turnus' => [
                ['Nazwa turnusu', $r->camp_name ?? ''],
                ['Termin', trim((string)$r->start_date.' – '.(string)$r->end_date)],
                ['Miejsce', $r->location ?? ''],
            ],
        ];
    }

    private static function editor_groups(): array {
        return [
            ['title'=>'Rodzic / opiekun prawny', 'fields'=>[
                ['name'=>'parent_first_name','label'=>'Imię opiekuna','type'=>'text'],
                ['name'=>'parent_last_name','label'=>'Nazwisko opiekuna','type'=>'text'],
                ['name'=>'parents_names','label'=>'Imiona i nazwiska rodziców','type'=>'text'],
                ['name'=>'parent_email','label'=>'E-mail','type'=>'email'],
                ['name'=>'parent_phone','label'=>'Telefon I','type'=>'text'],
                ['name'=>'parent_phone_alt','label'=>'Telefon II','type'=>'text'],
                ['name'=>'parent_postal_code','label'=>'Kod pocztowy','type'=>'text'],
                ['name'=>'parent_city','label'=>'Miejscowość','type'=>'text'],
                ['name'=>'parent_street','label'=>'Ulica','type'=>'text'],
                ['name'=>'parent_house_number','label'=>'Nr domu / lokalu','type'=>'text'],
            ]],
            ['title'=>'Uczestnik obozu', 'fields'=>[
                ['name'=>'child_first_name','label'=>'Imię uczestnika','type'=>'text'],
                ['name'=>'child_last_name','label'=>'Nazwisko uczestnika','type'=>'text'],
                ['name'=>'child_address','label'=>'Adres uczestnika, jeżeli inny','type'=>'textarea','wide'=>true],
                ['name'=>'child_birth_date','label'=>'Data urodzenia','type'=>'date'],
                ['name'=>'child_pesel','label'=>'PESEL','type'=>'text'],
                ['name'=>'child_height','label'=>'Wzrost (cm)','type'=>'number'],
                ['name'=>'child_weight','label'=>'Waga (kg)','type'=>'number'],
                ['name'=>'shirt_size','label'=>'Rozmiar stroju','type'=>'text'],
                ['name'=>'child_club','label'=>'Klub','type'=>'text'],
            ]],
            ['title'=>'Zdrowie, żywienie i szczepienia', 'fields'=>[
                ['name'=>'special_educational_needs','label'=>'Specjalne potrzeby edukacyjne','type'=>'textarea','wide'=>true],
                ['name'=>'medical_notes','label'=>'Uwagi zdrowotne','type'=>'textarea','wide'=>true],
                ['name'=>'dietary_notes','label'=>'Dieta i żywienie','type'=>'textarea','wide'=>true],
                ['name'=>'vaccination_tetanus','label'=>'Szczepienie przeciw tężcowi – rok','type'=>'text'],
                ['name'=>'vaccination_diphtheria','label'=>'Szczepienie przeciw błonicy – rok','type'=>'text'],
                ['name'=>'vaccination_other','label'=>'Inne szczepienia','type'=>'textarea','wide'=>true],
            ]],
            ['title'=>'Informacje dotyczące pobytu', 'fields'=>[
                ['name'=>'stay_contact','label'=>'Kontakt podczas pobytu','type'=>'textarea','wide'=>true],
                ['name'=>'authorized_pickup','label'=>'Osoby upoważnione do odbioru','type'=>'textarea','wide'=>true],
                ['name'=>'camp_notes','label'=>'Dodatkowe informacje dla organizatora','type'=>'textarea','wide'=>true],
            ]],
            ['title'=>'Dane do faktury', 'fields'=>[
                ['name'=>'invoice_requested','label'=>'Faktura','type'=>'checkbox'],
                ['name'=>'invoice_buyer_name','label'=>'Nabywca faktury','type'=>'text'],
                ['name'=>'invoice_street','label'=>'Ulica do faktury','type'=>'text'],
                ['name'=>'invoice_postal_code','label'=>'Kod pocztowy do faktury','type'=>'text'],
                ['name'=>'invoice_city','label'=>'Miejscowość do faktury','type'=>'text'],
                ['name'=>'invoice_nip','label'=>'NIP nabywcy','type'=>'text'],
                ['name'=>'invoice_notes','label'=>'Dodatkowe dane na fakturze','type'=>'textarea','wide'=>true],
            ]],
        ];
    }

    private static function can_verify(object $r): bool {
        return (string)($r->status ?? '') !== 'cancelled'
            && (string)($r->form_status ?? '') === 'complete'
            && empty($r->form_verified_at);
    }

    private static function editing_locked(object $r): bool {
        $agreement_locked = !empty($r->has_final_agreement)
            || in_array((string)($r->agreement_record_status ?? ''), ['pending','parent_signed','accepted'], true);
        $invoice_locked = !empty($r->has_invoice)
            || in_array((string)($r->invoice_status ?? ''), ['generated','sent'], true);
        return $agreement_locked || $invoice_locked;
    }

    public static function render_card_html(object $r): string {
        $html = '<div class="bcs-card-form-root-060" data-bcs-card-form-version="060">'
            .'<div class="bcs-card-form-toolbar-060">'
            .'<div><strong>Pełne dane Formularza Obozowego</strong><span>Dane są pogrupowane tak samo jak w podglądzie na Liście Zgłoszeń.</span></div>';
        if (!self::editing_locked($r)) {
            $html .= '<button type="button" class="button bcs-card-form-edit-060"><span class="dashicons dashicons-edit"></span> Edytuj dane</button>';
        }
        $html .= '</div><div class="bcs-card-form-sections-060">';

        foreach (self::display_sections($r) as $heading => $rows) {
            $html .= '<section class="bcs-card-form-section-060"><h3>'.esc_html($heading).'</h3><div class="bcs-card-form-grid-060">';
            foreach ($rows as $row) {
                $label = (string)($row[0] ?? '');
                $value = trim((string)($row[1] ?? ''));
                $wide = !empty($row[2]);
                $html .= '<div class="bcs-card-form-item-060'.($wide ? ' is-wide' : '').'">'
                    .'<span>'.esc_html($label).'</span><strong>'.($value !== '' ? nl2br(esc_html($value)) : '—').'</strong></div>';
            }
            $html .= '</div></section>';
        }
        $html .= '</div>';

        if (self::can_verify($r)) {
            $id = (int)$r->id;
            $html .= '<section class="bcs-form-verification-inline-060">'
                .'<h3>Potwierdzenie poprawności formularza</h3>'
                .'<p>Po sprawdzeniu wszystkich powyższych grup potwierdź poprawność formularza. System zablokuje edycję w Panelu Rodzica, wyśle informację o akceptacji i przygotuje draft umowy.</p>'
                .'<form method="post" class="bcs-form-verification-form-060">'
                .wp_nonce_field('bcs_crm_'.$id, '_wpnonce', true, false)
                .'<input type="hidden" name="registration_id" value="'.$id.'">'
                .'<input type="hidden" name="return_to" value="card">'
                .'<input type="hidden" name="bcs_crm_action" value="verify_form">'
                .'<button type="submit" class="button button-primary"><span class="dashicons dashicons-yes-alt"></span> Potwierdź poprawność formularza obozowego</button>'
                .'</form></section>';
        } elseif (!empty($r->form_verified_at)) {
            $html .= '<div class="bcs-form-verified-060"><span class="dashicons dashicons-yes-alt"></span><div><strong>Formularz potwierdzony przez Organizatora</strong><span>'.esc_html(BCS_Utils::format_datetime((string)$r->form_verified_at)).'</span></div></div>';
        }

        return $html.'</div>';
    }

    public static function ajax_display(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !wp_verify_nonce($nonce, 'bcs_crm_'.$id)) {
            wp_send_json_error(['message'=>'Sesja wygasła. Odśwież Kartę Zgłoszenia.'], 403);
        }
        $row = self::row($id);
        if (!$row) wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'], 404);
        wp_send_json_success([
            'html'=>self::render_card_html($row),
            'can_verify'=>self::can_verify($row),
            'verified_at'=>(string)($row->form_verified_at ?? ''),
        ]);
    }

    public static function enqueue_card_assets(): void {
        $id = self::card_id();
        if (!$id || !current_user_can('manage_options')) return;
        $row = self::row($id);
        if (!$row) return;

        wp_enqueue_style('bcs-card-form-060', BCS_URL.'assets/css/card-form-060.css', [], BCS_VERSION);
        wp_enqueue_script('bcs-card-form-060', BCS_URL.'assets/js/card-form-060.js', [], BCS_VERSION, true);
        wp_localize_script('bcs-card-form-060', 'BCSCardForm060', [
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'registrationId'=>$id,
            'crmNonce'=>wp_create_nonce('bcs_crm_'.$id),
            'editorNonce'=>wp_create_nonce('bcs_042_camp_form'),
            'displayAction'=>self::DISPLAY_ACTION,
            'getAction'=>'bcs_042_get_camp_form',
            'saveAction'=>'bcs_042_save_camp_form',
            'verifyAction'=>'bcs_list_quick_action_02010',
            'initialHtml'=>self::render_card_html($row),
            'editorGroups'=>self::editor_groups(),
        ]);
    }
}
