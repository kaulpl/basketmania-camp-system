<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_058 {
    private const PREVIEW_ACTION = 'bcs_058_form_preview';

    public static function init(): void {
        add_action('wp_ajax_'.self::PREVIEW_ACTION, [__CLASS__, 'ajax_form_preview']);
        // Musi wykonać się przed 0.42, które zapamiętuje zawartość rozwijanej sekcji.
        add_action('admin_footer', [__CLASS__, 'admin_footer'], 5);
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

    private static function preview_html(object $r): string {
        $html = '<div class="bcs-form-review-sections-058">';
        foreach (self::sections($r) as $heading => $rows) {
            $html .= '<section class="bcs-form-review-section-058"><h3>'.esc_html($heading).'</h3><div class="bcs-form-review-grid-058">';
            foreach ($rows as $row) {
                $label = (string)($row[0] ?? '');
                $value = trim((string)($row[1] ?? ''));
                $wide = !empty($row[2]);
                $html .= '<div class="bcs-form-review-item-058'.($wide ? ' is-wide' : '').'">'
                    .'<span>'.esc_html($label).'</span><strong>'.($value !== '' ? nl2br(esc_html($value)) : '—').'</strong></div>';
            }
            $html .= '</div></section>';
        }
        return $html.'</div>';
    }

    public static function ajax_form_preview(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message'=>'Brak uprawnień do podglądu formularza.'], 403);
        }
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$registration_id || !wp_verify_nonce($nonce, 'bcs_crm_'.$registration_id)) {
            wp_send_json_error(['message'=>'Sesja wygasła. Odśwież listę zgłoszeń i spróbuj ponownie.'], 403);
        }
        $row = self::row($registration_id);
        if (!$row) wp_send_json_error(['message'=>'Nie znaleziono zgłoszenia.'], 404);

        $can_verify = (string)$row->status !== 'cancelled'
            && (string)($row->form_status ?? '') === 'complete'
            && empty($row->form_verified_at);
        wp_send_json_success([
            'registration_id'=>$registration_id,
            'title'=>'Formularz obozowy — '.trim((string)$row->child_first_name.' '.(string)$row->child_last_name),
            'html'=>self::preview_html($row),
            'can_verify'=>$can_verify,
            'message'=>$can_verify
                ? 'Sprawdź wszystkie dane. Potwierdzenie zablokuje edycję formularza w Panelu Rodzica i uruchomi przygotowanie draftu umowy.'
                : 'Formularz został już potwierdzony albo nie jest gotowy do weryfikacji.',
        ]);
    }

    public static function admin_footer(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $page = sanitize_key((string)($_GET['page'] ?? ''));
        if ($page !== 'bcs-registrations') return;
        $config = [
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'previewAction'=>self::PREVIEW_ACTION,
            'verifyAction'=>'bcs_list_quick_action_02010',
            'view'=>absint($_GET['view'] ?? 0),
        ];
        ?>
        <style id="bcs-form-review-style-058">
            .bcs-form-verification-inline-058{margin:20px 0 0;padding:18px 0 2px;border-top:1px solid #dfe4ea;background:transparent;box-shadow:none}
            .bcs-form-verification-inline-058 h2{margin-top:0}.bcs-form-verification-inline-058 form{display:flex;justify-content:flex-end;margin-top:14px}
            #bcs-form-review-modal-058 .bcs-contact-modal__dialog{width:min(980px,calc(100vw - 36px));max-height:92vh;display:flex;flex-direction:column;padding:0;overflow:hidden}
            .bcs-form-review-head-058{padding:22px 26px 16px;border-bottom:1px solid #e5e7eb}.bcs-form-review-head-058 h2{margin:0 40px 6px 0}.bcs-form-review-head-058 p{margin:0;color:#5b6472}
            .bcs-form-review-scroll-058{padding:20px 26px;overflow:auto}.bcs-form-review-footer-058{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 26px;border-top:1px solid #e5e7eb;background:#f8fafc}
            .bcs-form-review-result-058{margin:0;color:#475569}.bcs-form-review-result-058.is-success{color:#166534;font-weight:700}.bcs-form-review-result-058.is-error{color:#b91c1c;font-weight:700}.bcs-form-review-actions-058{display:flex;gap:10px;flex:0 0 auto}
            .bcs-form-review-section-058{margin:0 0 20px}.bcs-form-review-section-058:last-child{margin-bottom:0}.bcs-form-review-section-058 h3{margin:0 0 10px;padding-bottom:8px;border-bottom:2px solid #f97316;color:#172033}
            .bcs-form-review-grid-058{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 14px}.bcs-form-review-item-058{padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;background:#fff}.bcs-form-review-item-058.is-wide{grid-column:1/-1}
            .bcs-form-review-item-058 span{display:block;margin-bottom:4px;font-size:12px;color:#64748b}.bcs-form-review-item-058 strong{display:block;line-height:1.45;color:#172033;word-break:break-word}.bcs-form-review-loading-058{padding:36px;text-align:center;color:#64748b}
            @media(max-width:700px){.bcs-form-review-grid-058{grid-template-columns:1fr}.bcs-form-review-item-058.is-wide{grid-column:auto}.bcs-form-review-footer-058{align-items:stretch;flex-direction:column}.bcs-form-review-actions-058{justify-content:flex-end}}
        </style>
        <div id="bcs-form-review-modal-058" class="bcs-contact-modal" hidden>
            <div class="bcs-contact-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-form-review-title-058">
                <button type="button" class="bcs-contact-modal__close" data-bcs-form-review-close-058 aria-label="Zamknij">×</button>
                <div class="bcs-form-review-head-058"><h2 id="bcs-form-review-title-058">Formularz obozowy</h2><p data-bcs-form-review-description-058>Sprawdź komplet danych przed potwierdzeniem poprawności formularza.</p></div>
                <div class="bcs-form-review-scroll-058" data-bcs-form-review-content-058><div class="bcs-form-review-loading-058">Pobieranie formularza…</div></div>
                <div class="bcs-form-review-footer-058"><p class="bcs-form-review-result-058" data-bcs-form-review-result-058></p><div class="bcs-form-review-actions-058"><button type="button" class="button" data-bcs-form-review-close-058>Zamknij</button><button type="button" class="button button-primary" data-bcs-form-review-confirm-058 disabled>Potwierdź poprawność formularza</button></div></div>
            </div>
        </div>
        <script id="bcs-form-review-script-058">
        (() => {
            'use strict';
            const cfg = <?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const modal = document.getElementById('bcs-form-review-modal-058');
            if (!modal) return;
            const content = modal.querySelector('[data-bcs-form-review-content-058]');
            const title = modal.querySelector('#bcs-form-review-title-058');
            const description = modal.querySelector('[data-bcs-form-review-description-058]');
            const result = modal.querySelector('[data-bcs-form-review-result-058]');
            const confirmButton = modal.querySelector('[data-bcs-form-review-confirm-058]');
            let current = null, verifying = false;

            const popup = (message, ok) => { if (typeof window.bcsPopup0190 === 'function') window.bcsPopup0190(message, ok); };
            const closeModal = () => { if (verifying) return; modal.hidden = true; document.body.classList.remove('bcs-modal-open'); current = null; };
            const openShell = () => {
                modal.hidden = false; document.body.classList.add('bcs-modal-open');
                title.textContent = 'Formularz obozowy'; description.textContent = 'Sprawdź komplet danych przed potwierdzeniem poprawności formularza.';
                content.innerHTML = '<div class="bcs-form-review-loading-058">Pobieranie formularza…</div>';
                result.textContent = ''; result.className = 'bcs-form-review-result-058';
                confirmButton.hidden = false; confirmButton.disabled = true; confirmButton.textContent = 'Potwierdź poprawność formularza';
            };
            const post = async (data) => {
                const response = await fetch(cfg.ajaxUrl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams(data)});
                const text = await response.text(); let json;
                try { json = JSON.parse(text); } catch (error) { throw new Error('Serwer zwrócił nieprawidłową odpowiedź. Odśwież stronę i spróbuj ponownie.'); }
                if (!response.ok || !json.success) throw new Error(json?.data?.message || 'Nie udało się wykonać działania.');
                return json.data || {};
            };
            const applyRowState = (row, data) => {
                if (!row) return;
                const status = row.querySelector('[data-bcs-col="status"]'), payment = row.querySelector('[data-bcs-col="payment"]'), progress = row.querySelector('[data-bcs-col="progress"]'), actions = row.querySelector('[data-bcs-col="actions"]');
                if (status) status.innerHTML = '<span class="bcs-badge '+String(data.status_class || '')+'">'+String(data.status_label || '')+'</span><br><small>'+String(data.agreement_number || 'Bez umowy')+'</small>';
                if (payment) payment.innerHTML = String(data.payment_html || ''); if (progress) progress.innerHTML = String(data.progress_html || ''); if (actions) actions.innerHTML = String(data.quick_html || '<span class="bcs-muted">Brak wymaganej akcji</span>');
                row.dataset.status = String(data.status || ''); row.dataset.stage = String(data.status_label || '').toLocaleLowerCase('pl-PL'); row.dataset.paid = String(data.paid || 0); row.dataset.updated = String(data.updated_at || ''); row.dataset.requires = data.requires_action ? '1' : '0';
                row.classList.toggle('bcs-requires-action', !!data.requires_action); row.classList.toggle('bcs-registration-complete', !!data.complete);
                const idCell = row.cells[0]; let marker = idCell?.querySelector('.bcs-row-action-marker');
                if (data.requires_action && !marker && idCell) { marker = document.createElement('span'); marker.className = 'bcs-row-action-marker'; marker.title = 'To zgłoszenie wymaga działania administratora'; marker.textContent = 'Wymaga akcji'; idCell.appendChild(marker); }
                else if (!data.requires_action && marker) marker.remove();
                row.classList.add('bcs-ajax-updated-02013'); window.setTimeout(() => row.classList.remove('bcs-ajax-updated-02013'), 1600); enhanceListButtons(row);
            };
            const openReview = async (button) => {
                const form = button.closest('form'), row = button.closest('tr[data-id]');
                const registrationId = form?.querySelector('[name="registration_id"]')?.value || row?.dataset.id || '', nonce = form?.querySelector('[name="_wpnonce"]')?.value || '';
                if (!registrationId || !nonce) { popup('Brakuje danych zgłoszenia albo sesja wygasła.', false); return; }
                openShell(); current = {registrationId, nonce, row};
                try {
                    const data = await post({action:cfg.previewAction,registration_id:registrationId,nonce});
                    if (!current || current.registrationId !== registrationId) return;
                    title.textContent = data.title || 'Formularz obozowy'; description.textContent = data.message || 'Sprawdź wszystkie dane przed potwierdzeniem.'; content.innerHTML = data.html || '<p>Brak danych formularza.</p>'; confirmButton.disabled = !data.can_verify; confirmButton.hidden = !data.can_verify;
                } catch (error) { content.innerHTML = '<div class="notice notice-error inline"><p>'+String(error.message || 'Nie udało się pobrać formularza.')+'</p></div>'; result.textContent = error.message || 'Nie udało się pobrać formularza.'; result.className = 'bcs-form-review-result-058 is-error'; }
            };
            const verifyCurrent = async () => {
                if (!current || verifying) return; verifying = true; confirmButton.disabled = true; confirmButton.textContent = 'Potwierdzanie…'; result.textContent = 'Trwa potwierdzanie formularza i przygotowanie draftu umowy.'; result.className = 'bcs-form-review-result-058';
                try {
                    const data = await post({action:cfg.verifyAction,registration_id:current.registrationId,quick_action:'verify_form',nonce:current.nonce});
                    applyRowState(current.row, data); result.textContent = data.message || 'Formularz obozowy został zaakceptowany.'; result.className = 'bcs-form-review-result-058 is-success'; confirmButton.hidden = true; popup(result.textContent, true);
                } catch (error) { result.textContent = error.message || 'Nie udało się potwierdzić formularza.'; result.className = 'bcs-form-review-result-058 is-error'; confirmButton.disabled = false; confirmButton.textContent = 'Spróbuj ponownie'; popup(result.textContent, false); }
                finally { verifying = false; }
            };
            const moveCardVerification = () => {
                if (cfg.view <= 0) return;
                const verification = document.querySelector('.bcs-crm-layout section.bcs-form-verification'); if (!verification) return;
                const panel = Array.from(document.querySelectorAll('.bcs-crm-layout .bcs-accordion-panel')).find(item => /Dane z formularza (?:zgłoszeniowego\s*[–-]\s*)?obozowego/i.test(item.querySelector('summary strong')?.textContent || ''));
                const target = panel?.querySelector('.bcs-accordion-content'); if (!target) return;
                const form = verification.querySelector('form');
                if (form && !form.querySelector('input[name="bcs_crm_action"]')) { const action = document.createElement('input'); action.type = 'hidden'; action.name = 'bcs_crm_action'; action.value = 'verify_form'; form.appendChild(action); }
                const button = form?.querySelector('button[name="bcs_crm_action"],button[type="submit"],button:not([type])');
                if (button && button.dataset.bcsVerifyPrepared058 !== '1') { button.type = 'submit'; button.textContent = 'Potwierdź poprawność formularza obozowego'; button.dataset.bcsVerifyPrepared058 = '1'; }
                if (!verification.classList.contains('bcs-form-verification-inline-058')) verification.classList.add('bcs-form-verification-inline-058');
                if (!target.contains(verification)) target.appendChild(verification);
            };
            const enhanceListButtons = (root = document) => {
                if (cfg.view > 0) return;
                root.querySelectorAll?.('form.bcs-list-action').forEach(form => {
                    const button = form.querySelector('button[name="bcs_crm_action"][value="verify_form"]');
                    if (!button || button.dataset.bcsFormReview058 === '1') return;
                    button.type = 'button'; button.dataset.bcsFormReview058 = '1'; button.classList.add('bcs-form-review-open-058'); button.textContent = 'Potwierdź formularz';
                });
            };

            moveCardVerification(); enhanceListButtons();
            document.addEventListener('click', event => {
                const reviewButton = event.target.closest('.bcs-form-review-open-058');
                if (reviewButton) { event.preventDefault(); event.stopImmediatePropagation(); openReview(reviewButton); return; }
                if (event.target.closest('[data-bcs-form-review-close-058]') || event.target === modal) closeModal();
            }, true);
            confirmButton.addEventListener('click', verifyCurrent);
            document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) closeModal(); });
            new MutationObserver(() => { moveCardVerification(); enhanceListButtons(); }).observe(document.body, {childList:true,subtree:true});
        })();
        </script>
        <?php
    }
}
