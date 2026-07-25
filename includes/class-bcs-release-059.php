<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_059 {
    private const CARD_ACTION = 'bcs_059_card_form';

    public static function init(): void {
        add_action('wp_ajax_'.self::CARD_ACTION, [__CLASS__, 'ajax_card_form']);
        // Wykonujemy przed 0.58 i 0.42: 0.42 zapamiętuje zawartość sekcji do trybu edycji.
        add_action('admin_footer', [__CLASS__, 'admin_footer'], 4);
    }

    private static function row(int $registration_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,c.name camp_name,c.start_date,c.end_date,c.location
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             WHERE r.id=%d LIMIT 1",
            $registration_id
        )) ?: null;
    }

    private static function sections(object $r): array {
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

    private static function grouped_html(object $r): string {
        $html = '<div class="bcs-card-form-sections-059">';
        foreach (self::sections($r) as $heading => $rows) {
            $html .= '<section class="bcs-card-form-section-059"><h3>'.esc_html($heading).'</h3><div class="bcs-card-form-grid-059">';
            foreach ($rows as $row) {
                $label = (string)($row[0] ?? '');
                $value = trim((string)($row[1] ?? ''));
                $wide = !empty($row[2]);
                $html .= '<div class="bcs-card-form-item-059'.($wide ? ' is-wide' : '').'">'
                    .'<span>'.esc_html($label).'</span><strong>'.($value !== '' ? nl2br(esc_html($value)) : '—').'</strong></div>';
            }
            $html .= '</div></section>';
        }
        return $html.'</div>';
    }

    private static function can_verify(object $r): bool {
        return (string)($r->status ?? '') !== 'cancelled'
            && (string)($r->form_status ?? '') === 'complete'
            && empty($r->form_verified_at);
    }

    private static function verification_html(object $r): string {
        if (!self::can_verify($r)) return '';
        $id = (int)$r->id;
        return '<section class="bcs-form-verification-inline-059" data-bcs-form-verification-059>'
            .'<h3>Potwierdzenie poprawności formularza</h3>'
            .'<p>Po sprawdzeniu wszystkich grup danych potwierdź formularz. System zablokuje jego edycję w Panelu Rodzica, wyśle informację o akceptacji i przygotuje draft umowy.</p>'
            .'<form method="post" class="bcs-form-verification-form-059">'
            .wp_nonce_field('bcs_crm_'.$id, '_wpnonce', true, false)
            .'<input type="hidden" name="registration_id" value="'.$id.'">'
            .'<input type="hidden" name="return_to" value="card">'
            .'<input type="hidden" name="bcs_crm_action" value="verify_form">'
            .'<button type="submit" class="button button-primary"><span class="dashicons dashicons-yes-alt"></span> Potwierdź poprawność formularza obozowego</button>'
            .'</form></section>';
    }

    public static function render_card_html(object $r): string {
        return self::grouped_html($r).self::verification_html($r);
    }

    private static function payload(object $r): array {
        return [
            'registration_id'=>(int)$r->id,
            'html'=>self::render_card_html($r),
            'can_verify'=>self::can_verify($r),
        ];
    }

    public static function ajax_card_form(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message'=>'Brak uprawnień do podglądu formularza.'], 403);
        }
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$registration_id || !wp_verify_nonce($nonce, 'bcs_crm_'.$registration_id)) {
            wp_send_json_error(['message'=>'Sesja wygasła. Odśwież Kartę Zgłoszenia.'], 403);
        }
        $row = self::row($registration_id);
        if (!$row) wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'], 404);
        wp_send_json_success(self::payload($row));
    }

    public static function admin_footer(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (sanitize_key((string)($_GET['page'] ?? '')) !== 'bcs-registrations') return;
        $registration_id = absint($_GET['view'] ?? 0);
        if (!$registration_id) return;
        $row = self::row($registration_id);
        if (!$row) return;

        $config = [
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'action'=>self::CARD_ACTION,
            'registrationId'=>$registration_id,
            'nonce'=>wp_create_nonce('bcs_crm_'.$registration_id),
            'initialHtml'=>self::render_card_html($row),
        ];
        ?>
        <style id="bcs-card-form-style-059">
            .bcs-card-form-sections-059{display:flex;flex-direction:column;gap:22px}
            .bcs-card-form-section-059{margin:0}.bcs-card-form-section-059 h3{margin:0 0 11px;padding:0 0 8px;border-bottom:2px solid #f97316;color:#172033;font-size:16px}
            .bcs-card-form-grid-059{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 14px}
            .bcs-card-form-item-059{padding:11px 13px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;min-width:0}
            .bcs-card-form-item-059.is-wide{grid-column:1/-1}.bcs-card-form-item-059 span{display:block;margin-bottom:4px;font-size:12px;color:#64748b}
            .bcs-card-form-item-059 strong{display:block;color:#172033;line-height:1.45;overflow-wrap:anywhere}
            .bcs-form-verification-inline-059{margin:24px 0 0;padding:20px;border:1px solid #fed7aa;border-radius:10px;background:#fff7ed}
            .bcs-form-verification-inline-059 h3{margin:0 0 8px;color:#9a3412}.bcs-form-verification-inline-059 p{margin:0;color:#7c2d12;line-height:1.5}
            .bcs-form-verification-inline-059 form{display:flex;justify-content:flex-end;margin-top:16px}
            @media(max-width:700px){.bcs-card-form-grid-059{grid-template-columns:1fr}.bcs-card-form-item-059.is-wide{grid-column:auto}.bcs-form-verification-inline-059 form{justify-content:stretch}.bcs-form-verification-inline-059 button{width:100%;justify-content:center}}
        </style>
        <script id="bcs-card-form-script-059">
        (() => {
            'use strict';
            const cfg = <?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            let refreshing = false, refreshTimer = 0;

            const findPanel = () => Array.from(document.querySelectorAll('.bcs-crm-layout .bcs-accordion-panel')).find((panel) => {
                const title = panel.querySelector('summary strong')?.textContent || '';
                return /Dane z formularza/i.test(title);
            });

            const apply = (html) => {
                const panel = findPanel();
                const content = panel?.querySelector('.bcs-accordion-content');
                if (!content) return false;
                const title = panel.querySelector('summary strong');
                if (title) title.textContent = 'Dane z formularza obozowego';
                content.innerHTML = html || '<p class="bcs-muted">Brak danych formularza.</p>';
                content.dataset.bcsGrouped059 = '1';
                document.querySelectorAll('.bcs-crm-layout section.bcs-form-verification:not(.bcs-form-verification-inline-059)').forEach((section) => section.remove());
                return true;
            };

            const refresh = async () => {
                if (refreshing) return;
                refreshing = true;
                try {
                    const response = await fetch(cfg.ajaxUrl, {
                        method:'POST', credentials:'same-origin',
                        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
                        body:new URLSearchParams({action:cfg.action,registration_id:String(cfg.registrationId),nonce:cfg.nonce})
                    });
                    const text = await response.text();
                    let json;
                    try { json = JSON.parse(text); } catch (error) { throw new Error('Nieprawidłowa odpowiedź serwera.'); }
                    if (!response.ok || !json.success) throw new Error(json?.data?.message || 'Nie udało się odświeżyć formularza.');
                    apply(json.data?.html || '');
                } catch (error) {
                    console.error('BCS 0.59:', error);
                } finally {
                    refreshing = false;
                }
            };

            const ensure = () => {
                const content = findPanel()?.querySelector('.bcs-accordion-content');
                if (!content || content.dataset.bcsGrouped059 === '1') return;
                window.clearTimeout(refreshTimer);
                refreshTimer = window.setTimeout(refresh, 40);
            };

            apply(cfg.initialHtml);
            new MutationObserver(ensure).observe(document.body, {childList:true,subtree:true});
        })();
        </script>
        <?php
    }
}
