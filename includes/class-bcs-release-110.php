<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.10 – czytelny profil nabywcy przed wystawieniem faktury.
 *
 * Billing_* pozostaje źródłem prawdy wprowadzonym w 0.83/0.84. Ten release
 * porządkuje UX: osobny panel Faktura, wybór „Faktura imienna / na firmę”,
 * pola zależne od typu nabywcy i bezpośrednia akcja wystawienia dokumentu.
 * PDF i KSeF nadal korzystają z tego samego snapshotu nabywcy.
 */
final class BCS_Release_110 {
    private const SAVE_ACTION = 'bcs_save_invoice_profile_083';

    public static function init(): void {
        // Zastępujemy UI 0.86 nowym panelem, ale zachowujemy backend 0.83/0.84.
        remove_action('admin_enqueue_scripts', ['BCS_Release_086', 'enqueue_admin_assets'], 1000);
        remove_action('admin_footer', ['BCS_Release_086', 'invoice_profile_template'], 9998);

        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 1010);
        add_action('admin_footer', [__CLASS__, 'render_template'], 10010);
    }

    private static function is_registration_card(): bool {
        return is_admin()
            && current_user_can('manage_options')
            && sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'bcs-registrations'
            && absint($_GET['view'] ?? 0) > 0;
    }

    private static function registration(int $registrationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT r.*, i.id invoice_real_id, i.invoice_number, i.status invoice_real_status '
            .'FROM '.BCS_DB::table('registrations').' r '
            .'LEFT JOIN '.BCS_DB::table('invoices').' i ON i.id=(SELECT i2.id FROM '.BCS_DB::table('invoices').' i2 WHERE i2.registration_id=r.id ORDER BY i2.id DESC LIMIT 1) '
            .'WHERE r.id=%d',
            $registrationId
        )) ?: null;
    }

    private static function parent_street(object $r): string {
        $street = trim((string)($r->parent_street ?? '').' '.(string)($r->parent_house_number ?? ''));
        if ($street !== '') return $street;
        $address = trim((string)($r->parent_address ?? ''));
        if ($address === '') return '';
        $parts = preg_split('/\R+/u', $address) ?: [];
        return trim((string)($parts[0] ?? $address));
    }

    private static function source_label(string $source): string {
        return match ($source) {
            'form_company' => 'Dane firmowe z Formularza Obozowego',
            'form_individual' => 'Dane rodzica / opiekuna',
            'admin_edit' => 'Dane zweryfikowane przez administratora',
            default => 'Profil nabywcy faktury',
        };
    }

    /** @return array<int,string> */
    public static function profile_errors(object $r): array {
        $type = in_array((string)($r->billing_type ?? ''), ['individual','company'], true) ? (string)$r->billing_type : '';
        $name = trim((string)($r->billing_name ?? ''));
        $street = trim((string)($r->billing_street ?? ''));
        $postal = trim((string)($r->billing_postal_code ?? ''));
        $city = trim((string)($r->billing_city ?? ''));
        $nip = preg_replace('/\D+/', '', (string)($r->billing_nip ?? '')) ?: '';
        $errors = [];
        if ($type === '') $errors[] = 'Wybierz rodzaj faktury.';
        if ($name === '') $errors[] = $type === 'company' ? 'Podaj nazwę firmy.' : 'Podaj imię i nazwisko nabywcy.';
        if ($street === '') $errors[] = 'Podaj ulicę i numer.';
        if ($postal === '') $errors[] = 'Podaj kod pocztowy.';
        if ($city === '') $errors[] = 'Podaj miejscowość.';
        if ($type === 'company' && strlen($nip) !== 10) $errors[] = 'Podaj 10-cyfrowy NIP firmy.';
        return $errors;
    }

    public static function enqueue_assets(): void {
        if (!self::is_registration_card()) return;
        $id = absint($_GET['view'] ?? 0);
        BCS_Release_083::initialize_profile($id, false);
        $r = self::registration($id);
        if (!$r || (string)($r->form_status ?? '') !== 'complete') return;

        $companyAvailable = trim((string)($r->invoice_buyer_name ?? '')) !== ''
            || trim((string)($r->invoice_nip ?? '')) !== '';

        wp_enqueue_script('bcs-invoice-profile-110', BCS_URL.'assets/js/invoice-profile-110.js', [], BCS_VERSION, true);
        wp_localize_script('bcs-invoice-profile-110', 'BCSInvoiceProfile110', [
            'registrationId'=>$id,
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'saveAction'=>self::SAVE_ACTION,
            'nonce'=>wp_create_nonce('bcs_invoice_profile_083_'.$id),
            'locked'=>!empty($r->invoice_real_id),
            'parent'=>[
                'type'=>'individual',
                'name'=>trim((string)($r->parent_first_name ?? '').' '.(string)($r->parent_last_name ?? '')),
                'street'=>self::parent_street($r),
                'postal'=>(string)($r->parent_postal_code ?? ''),
                'city'=>(string)($r->parent_city ?? ''),
                'nip'=>'',
                'notes'=>'',
                'description'=>trim((string)($r->invoice_ksef_description ?? $r->billing_ksef_description ?? '')),
            ],
            'company'=>[
                'available'=>$companyAvailable,
                'type'=>'company',
                'name'=>(string)($r->invoice_buyer_name ?? ''),
                'street'=>(string)($r->invoice_street ?? ''),
                'postal'=>(string)($r->invoice_postal_code ?? ''),
                'city'=>(string)($r->invoice_city ?? ''),
                'nip'=>(string)($r->invoice_nip ?? ''),
                'notes'=>(string)($r->invoice_notes ?? ''),
                'description'=>trim((string)($r->invoice_ksef_description ?? $r->billing_ksef_description ?? '')),
            ],
        ]);
    }

    public static function render_template(): void {
        if (!self::is_registration_card()) return;
        $id = absint($_GET['view'] ?? 0);
        BCS_Release_083::initialize_profile($id, false);
        $r = self::registration($id);
        if (!$r || (string)($r->form_status ?? '') !== 'complete') return;

        $locked = !empty($r->invoice_real_id);
        $type = (string)($r->billing_type ?? '') === 'company' ? 'company' : 'individual';
        $errors = self::profile_errors($r);
        $ready = !$errors;
        $invoiceAvailable = !$locked && BCS_Workflow_Engine::invoice_available($id);
        $companyAvailable = trim((string)($r->invoice_buyer_name ?? '')) !== '' || trim((string)($r->invoice_nip ?? '')) !== '';
        ?>
        <template id="bcs-invoice-profile-template-110">
            <section class="bcs-panel bcs-invoice-profile-110" data-bcs-invoice-profile-110>
                <div class="bcs-invoice-head-110">
                    <div>
                        <h2><span class="dashicons dashicons-media-spreadsheet"></span> Faktura</h2>
                        <p>Przed wystawieniem wybierz rodzaj faktury i sprawdź dane nabywcy. Ten sam profil zostanie użyty w PDF oraz w XML FA(3) wysyłanym do KSeF.</p>
                    </div>
                    <span class="bcs-badge <?php echo $locked ? 'status-accepted' : ($ready ? 'status-open' : 'status-draft'); ?>">
                        <?php echo esc_html($locked ? 'Faktura wystawiona' : ($ready ? 'Dane kompletne' : 'Wymaga uzupełnienia')); ?>
                    </span>
                </div>

                <?php if ($locked): ?>
                    <div class="notice notice-info inline"><p><strong>Dane nabywcy są zablokowane.</strong> Faktura <?php echo esc_html((string)($r->invoice_number ?? '')); ?> została już wystawiona. Ewentualne zmiany wymagają procedury korekty, a nie edycji tego profilu.</p></div>
                <?php endif; ?>

                <div class="bcs-invoice-summary-110">
                    <div><span>Rodzaj</span><strong><?php echo esc_html($type === 'company' ? 'Faktura na firmę' : 'Faktura imienna'); ?></strong></div>
                    <div><span><?php echo esc_html($type === 'company' ? 'Nazwa firmy' : 'Imię i nazwisko nabywcy'); ?></span><strong><?php echo esc_html((string)($r->billing_name ?: '—')); ?></strong></div>
                    <?php if ($type === 'company'): ?><div><span>NIP</span><strong><?php echo esc_html((string)($r->billing_nip ?: '—')); ?></strong></div><?php endif; ?>
                    <div><span>Ulica i numer</span><strong><?php echo esc_html((string)($r->billing_street ?: '—')); ?></strong></div>
                    <div><span>Kod pocztowy</span><strong><?php echo esc_html((string)($r->billing_postal_code ?: '—')); ?></strong></div>
                    <div><span>Miejscowość</span><strong><?php echo esc_html((string)($r->billing_city ?: '—')); ?></strong></div>
                    <div class="is-wide"><span>Dodatkowy opis do KSeF</span><strong><?php echo nl2br(esc_html((string)($r->billing_ksef_description ?: '—'))); ?></strong></div>
                </div>

                <?php if (!$locked): ?>
                    <form class="bcs-invoice-form-110" data-bcs-invoice-form-110>
                        <div class="bcs-invoice-kind-110" role="radiogroup" aria-label="Rodzaj faktury">
                            <label class="bcs-invoice-kind-option-110 <?php echo $type === 'individual' ? 'is-selected' : ''; ?>">
                                <input type="radio" name="billing_type" value="individual" <?php checked($type, 'individual'); ?>>
                                <span class="dashicons dashicons-admin-users"></span>
                                <span><strong>Faktura imienna</strong><small>dla osoby fizycznej – bez NIP</small></span>
                            </label>
                            <label class="bcs-invoice-kind-option-110 <?php echo $type === 'company' ? 'is-selected' : ''; ?>">
                                <input type="radio" name="billing_type" value="company" <?php checked($type, 'company'); ?>>
                                <span class="dashicons dashicons-building"></span>
                                <span><strong>Faktura na firmę</strong><small>nazwa firmy i 10-cyfrowy NIP</small></span>
                            </label>
                        </div>

                        <div class="bcs-invoice-shortcuts-110">
                            <button type="button" class="button" data-bcs-fill-parent-110>Użyj danych rodzica</button>
                            <?php if ($companyAvailable): ?><button type="button" class="button" data-bcs-fill-company-110>Użyj danych firmowych z formularza</button><?php endif; ?>
                        </div>

                        <div class="bcs-invoice-editor-grid-110">
                            <label><span data-bcs-name-label-110><?php echo esc_html($type === 'company' ? 'Nazwa firmy' : 'Imię i nazwisko nabywcy'); ?> <em>*</em></span><input name="billing_name" value="<?php echo esc_attr((string)$r->billing_name); ?>" required autocomplete="name"></label>
                            <label data-bcs-nip-row-110 <?php echo $type === 'company' ? '' : 'hidden'; ?>><span>NIP firmy <em>*</em></span><input name="billing_nip" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" value="<?php echo esc_attr((string)$r->billing_nip); ?>"></label>
                            <label><span>Ulica i numer <em>*</em></span><input name="billing_street" value="<?php echo esc_attr((string)$r->billing_street); ?>" required autocomplete="street-address"></label>
                            <label><span>Kod pocztowy <em>*</em></span><input name="billing_postal_code" value="<?php echo esc_attr((string)$r->billing_postal_code); ?>" required autocomplete="postal-code" placeholder="00-000"></label>
                            <label><span>Miejscowość <em>*</em></span><input name="billing_city" value="<?php echo esc_attr((string)$r->billing_city); ?>" required autocomplete="address-level2"></label>
                            <label class="is-wide"><span>Dodatkowy opis do KSeF</span><textarea name="billing_ksef_description" rows="3" maxlength="256"><?php echo esc_textarea((string)$r->billing_ksef_description); ?></textarea><small>Opcjonalnie, np. imię i nazwisko uczestnika. Maksymalnie 256 znaków.</small></label>
                            <label class="is-wide"><span>Uwagi do faktury</span><textarea name="billing_notes" rows="3"><?php echo esc_textarea((string)$r->billing_notes); ?></textarea></label>
                        </div>

                        <div class="bcs-invoice-required-110"><strong>Wymagane pola:</strong> <span data-bcs-required-copy-110><?php echo esc_html($type === 'company' ? 'nazwa firmy, NIP, ulica i numer, kod pocztowy, miejscowość' : 'imię i nazwisko, ulica i numer, kod pocztowy, miejscowość'); ?></span>.</div>
                        <div class="bcs-invoice-form-actions-110"><button type="submit" class="button button-primary">Zapisz dane nabywcy</button></div>
                    </form>

                    <?php if ($errors): ?>
                        <div class="notice notice-warning inline bcs-invoice-errors-110"><p><strong>Przed wystawieniem uzupełnij:</strong> <?php echo esc_html(implode(' ', $errors)); ?></p></div>
                    <?php endif; ?>

                    <div class="bcs-invoice-generate-box-110">
                        <div><strong>Wystawienie faktury</strong><p>Faktura zostanie utworzona z zapisanych powyżej danych. Po wystawieniu profil nabywcy zostanie zablokowany.</p></div>
                        <?php if ($invoiceAvailable && $ready): ?>
                            <form method="post" class="bcs-invoice-generate-110">
                                <?php wp_nonce_field('bcs_crm_'.$id); ?>
                                <input type="hidden" name="registration_id" value="<?php echo (int)$id; ?>">
                                <button class="button button-primary button-hero" name="bcs_crm_action" value="invoice_generate">Wygeneruj fakturę z tymi danymi</button>
                            </form>
                        <?php elseif (!$ready): ?>
                            <span class="button disabled" aria-disabled="true">Najpierw uzupełnij i zapisz dane nabywcy</span>
                        <?php else: ?>
                            <span class="button disabled" aria-disabled="true">Faktura nie jest jeszcze dostępna na tym etapie</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="bcs-invoice-locked-meta-110"><span>Źródło danych: <strong><?php echo esc_html(self::source_label((string)($r->billing_source ?? ''))); ?></strong></span></div>
                <?php endif; ?>
            </section>
        </template>
        <style>
            .bcs-invoice-profile-110{min-width:0;box-sizing:border-box}.bcs-invoice-head-110{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}.bcs-invoice-head-110 h2{display:flex;gap:8px;align-items:center;margin:0 0 5px}.bcs-invoice-head-110 p{margin:0;color:#646970;line-height:1.45}.bcs-invoice-summary-110,.bcs-invoice-editor-grid-110{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.bcs-invoice-summary-110>div{border:1px solid #dcdcde;border-radius:9px;padding:11px;background:#fff;min-width:0}.bcs-invoice-summary-110 span,.bcs-invoice-editor-grid-110 label>span{display:block;font-size:12px;color:#646970;margin-bottom:4px}.bcs-invoice-summary-110 strong{overflow-wrap:anywhere}.bcs-invoice-summary-110 .is-wide,.bcs-invoice-editor-grid-110 .is-wide{grid-column:1/-1}.bcs-invoice-form-110{margin-top:18px;padding-top:16px;border-top:1px solid #dcdcde}.bcs-invoice-kind-110{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}.bcs-invoice-kind-option-110{display:flex;gap:10px;align-items:center;padding:14px;border:1px solid #c3c4c7;border-radius:10px;background:#fff;cursor:pointer;transition:.15s ease}.bcs-invoice-kind-option-110:hover{border-color:#8c8f94}.bcs-invoice-kind-option-110.is-selected{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;background:#f6f9fc}.bcs-invoice-kind-option-110 input{margin:0}.bcs-invoice-kind-option-110>.dashicons{width:26px;height:26px;font-size:26px}.bcs-invoice-kind-option-110 strong,.bcs-invoice-kind-option-110 small{display:block}.bcs-invoice-kind-option-110 small{margin-top:2px;color:#646970}.bcs-invoice-shortcuts-110{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}.bcs-invoice-editor-grid-110 input,.bcs-invoice-editor-grid-110 textarea{width:100%;max-width:100%;box-sizing:border-box}.bcs-invoice-editor-grid-110 em{font-style:normal;color:#b32d2e}.bcs-invoice-required-110{margin-top:12px;padding:10px 12px;background:#f6f7f7;border-radius:8px;color:#50575e}.bcs-invoice-form-actions-110{display:flex;justify-content:flex-end;margin-top:12px}.bcs-invoice-errors-110{margin:14px 0 0!important}.bcs-invoice-generate-box-110{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:16px;padding:14px;border:1px solid #dcdcde;border-radius:10px;background:#f6f7f7}.bcs-invoice-generate-box-110 p{margin:4px 0 0;color:#646970}.bcs-invoice-generate-box-110 form{margin:0;flex:0 0 auto}.bcs-invoice-locked-meta-110{margin-top:12px;color:#646970}.bcs-quick-actions .bcs-invoice-action-hidden-110{display:none!important}
            @media(max-width:1100px){.bcs-invoice-kind-110,.bcs-invoice-summary-110,.bcs-invoice-editor-grid-110{grid-template-columns:1fr}.bcs-invoice-summary-110 .is-wide,.bcs-invoice-editor-grid-110 .is-wide{grid-column:auto}.bcs-invoice-generate-box-110{display:block}.bcs-invoice-generate-box-110 form,.bcs-invoice-generate-box-110>.button{margin-top:12px}.bcs-invoice-head-110{display:block}.bcs-invoice-head-110 .bcs-badge{display:inline-block;margin-top:10px}}
        </style>
        <?php
    }
}
