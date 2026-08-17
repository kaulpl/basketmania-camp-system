<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.86 – poprawny komunikat po podpisie rodzica i stabilna zakładka Dane do Faktury.
 *
 * - status agreement_parent_signed w Panelu Rodzica pokazuje etap oczekiwania na podpis Organizatora,
 * - wyłącza szerokie obserwatory DOM dodane w 0.83/0.84 dla Karty Zgłoszenia,
 * - montuje Dane do Faktury jednokrotnie, bez obserwowania document.body,
 * - zachowuje backend i zapis profilu z 0.84.
 */
final class BCS_Release_086 {
    public static function init(): void {
        // 0.83 i 0.84 dodawały niezależne skrypty z MutationObserver(document.body, subtree:true).
        // Zostawiamy ich backend, ale zastępujemy warstwę UI jednym stabilnym komponentem.
        remove_action('admin_footer', ['BCS_Release_083', 'card_invoice_tab'], 9997);
        remove_action('admin_footer', ['BCS_Release_084', 'card_description_ui'], 9999);

        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets'], 1000);
        add_action('admin_footer', [__CLASS__, 'invoice_profile_template'], 9998);

        // Poprawa komunikacji etapu po podpisaniu umowy przez Rodzica.
        add_filter('do_shortcode_tag', [__CLASS__, 'enhance_parent_portal'], 100, 4);
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
            'SELECT r.*, i.id invoice_real_id, i.invoice_number '
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
            'form_individual' => 'Dane imienne rodzica / opiekuna',
            'admin_edit' => 'Zmienione ręcznie przez administratora',
            default => 'Profil danych do faktury',
        };
    }

    public static function enqueue_admin_assets(): void {
        if (!self::is_registration_card()) return;
        $id = absint($_GET['view'] ?? 0);
        BCS_Release_083::initialize_profile($id, false);
        $r = self::registration($id);
        if (!$r || (string)($r->form_status ?? '') !== 'complete') return;

        $companyAvailable = (int)($r->invoice_requested ?? 0) === 1
            && trim((string)($r->invoice_buyer_name ?? '')) !== '';

        wp_enqueue_script('bcs-invoice-profile-086', BCS_URL.'assets/js/invoice-profile-086.js', [], BCS_VERSION, true);
        wp_localize_script('bcs-invoice-profile-086', 'BCSInvoiceProfile086', [
            'registrationId'=>$id,
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'saveAction'=>'bcs_save_invoice_profile_083',
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
                'description'=>trim((string)($r->invoice_ksef_description ?? '')),
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
                'description'=>trim((string)($r->invoice_ksef_description ?? '')),
            ],
        ]);
    }

    public static function invoice_profile_template(): void {
        if (!self::is_registration_card()) return;
        $id = absint($_GET['view'] ?? 0);
        BCS_Release_083::initialize_profile($id, false);
        $r = self::registration($id);
        if (!$r || (string)($r->form_status ?? '') !== 'complete') return;

        $locked = !empty($r->invoice_real_id);
        $type = (string)($r->billing_type ?? '') === 'company' ? 'Firma' : 'Osoba prywatna';
        $companyAvailable = (int)($r->invoice_requested ?? 0) === 1
            && trim((string)($r->invoice_buyer_name ?? '')) !== '';
        ?>
        <template id="bcs-invoice-profile-template-086">
            <section class="bcs-panel bcs-invoice-profile-086" data-bcs-invoice-profile-086 hidden>
                <div class="bcs-invoice-profile-head-086">
                    <div>
                        <h2>Dane do Faktury</h2>
                        <p>To są dane używane do wystawienia PDF faktury i wysyłki do KSeF. Nie zmieniają historycznych danych Formularza Obozowego.</p>
                    </div>
                    <span class="bcs-badge"><?php echo esc_html(self::source_label((string)($r->billing_source ?? ''))); ?></span>
                </div>
                <?php if ($locked): ?>
                    <div class="notice notice-info inline"><p><strong>Dane zablokowane.</strong> Faktura <?php echo esc_html((string)($r->invoice_number ?? '')); ?> została już wystawiona. Zmiana nabywcy wymaga osobnej procedury korekty.</p></div>
                <?php endif; ?>
                <div class="bcs-invoice-profile-view-086" data-bcs-invoice-view-086>
                    <div><span>Typ nabywcy</span><strong><?php echo esc_html($type); ?></strong></div>
                    <div><span>Nazwa / imię i nazwisko</span><strong><?php echo esc_html((string)($r->billing_name ?: '—')); ?></strong></div>
                    <div><span>Ulica i numer</span><strong><?php echo esc_html((string)($r->billing_street ?: '—')); ?></strong></div>
                    <div><span>Kod pocztowy</span><strong><?php echo esc_html((string)($r->billing_postal_code ?: '—')); ?></strong></div>
                    <div><span>Miejscowość</span><strong><?php echo esc_html((string)($r->billing_city ?: '—')); ?></strong></div>
                    <div><span>NIP</span><strong><?php echo esc_html((string)($r->billing_nip ?: '—')); ?></strong></div>
                    <div class="is-wide"><span>Dodatkowy opis do KSeF</span><strong><?php echo nl2br(esc_html((string)($r->billing_ksef_description ?: '—'))); ?></strong></div>
                    <div class="is-wide"><span>Uwagi do faktury</span><strong><?php echo nl2br(esc_html((string)($r->billing_notes ?: '—'))); ?></strong></div>
                </div>
                <?php if (!$locked): ?>
                    <div class="bcs-invoice-profile-actions-086" data-bcs-invoice-actions-086>
                        <button type="button" class="button button-primary" data-bcs-invoice-edit-086><span class="dashicons dashicons-edit"></span> Edytuj dane do faktury</button>
                    </div>
                    <form class="bcs-invoice-profile-form-086" data-bcs-invoice-form-086 hidden>
                        <div class="bcs-invoice-profile-shortcuts-086">
                            <button type="button" class="button" data-bcs-fill-parent-086>Użyj danych rodzica</button>
                            <?php if ($companyAvailable): ?><button type="button" class="button" data-bcs-fill-company-086>Użyj danych firmowych z formularza</button><?php endif; ?>
                        </div>
                        <div class="bcs-invoice-profile-editor-grid-086">
                            <label><span>Typ nabywcy</span><select name="billing_type" required><option value="individual" <?php selected((string)$r->billing_type, 'individual'); ?>>Osoba prywatna</option><option value="company" <?php selected((string)$r->billing_type, 'company'); ?>>Firma</option></select></label>
                            <label><span>Nazwa / imię i nazwisko</span><input name="billing_name" value="<?php echo esc_attr((string)$r->billing_name); ?>" required></label>
                            <label><span>Ulica i numer</span><input name="billing_street" value="<?php echo esc_attr((string)$r->billing_street); ?>" required></label>
                            <label><span>Kod pocztowy</span><input name="billing_postal_code" value="<?php echo esc_attr((string)$r->billing_postal_code); ?>" required></label>
                            <label><span>Miejscowość</span><input name="billing_city" value="<?php echo esc_attr((string)$r->billing_city); ?>" required></label>
                            <label data-bcs-nip-row-086><span>NIP</span><input name="billing_nip" inputmode="numeric" value="<?php echo esc_attr((string)$r->billing_nip); ?>"></label>
                            <label class="is-wide"><span>Dodatkowy opis do KSeF</span><textarea name="billing_ksef_description" rows="3" maxlength="256"><?php echo esc_textarea((string)$r->billing_ksef_description); ?></textarea><small>Np. imię i nazwisko uczestnika obozu. Maks. 256 znaków.</small></label>
                            <label class="is-wide"><span>Uwagi do faktury</span><textarea name="billing_notes" rows="3"><?php echo esc_textarea((string)$r->billing_notes); ?></textarea></label>
                        </div>
                        <div class="bcs-invoice-profile-form-actions-086">
                            <button type="button" class="button" data-bcs-invoice-cancel-086>Anuluj</button>
                            <button type="submit" class="button button-primary">Zapisz dane do faktury</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </template>
        <style>
            .bcs-card-data-tabs-086{display:flex;gap:6px;margin:16px 0 10px;padding:5px;background:#f0f0f1;border-radius:9px;width:max-content;max-width:100%;box-sizing:border-box}
            .bcs-card-data-tabs-086 button{border:0;background:transparent;padding:8px 14px;border-radius:7px;font-weight:600;cursor:pointer;white-space:nowrap}
            .bcs-card-data-tabs-086 button.is-active{background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.12)}
            .bcs-invoice-profile-086{margin-top:0;min-width:0;max-width:100%;box-sizing:border-box}
            .bcs-invoice-profile-head-086{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:16px;min-width:0}
            .bcs-invoice-profile-head-086 h2{margin:0 0 5px}.bcs-invoice-profile-head-086 p{margin:0;color:#646970;max-width:760px}
            .bcs-invoice-profile-view-086,.bcs-invoice-profile-editor-grid-086{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;min-width:0}
            .bcs-invoice-profile-view-086>div{padding:12px;border:1px solid #dcdcde;border-radius:8px;background:#fff;min-width:0;overflow-wrap:anywhere}
            .bcs-invoice-profile-view-086 span,.bcs-invoice-profile-editor-grid-086 label>span{display:block;color:#646970;font-size:12px;margin-bottom:4px}
            .bcs-invoice-profile-view-086 .is-wide,.bcs-invoice-profile-editor-grid-086 .is-wide{grid-column:1/-1}
            .bcs-invoice-profile-editor-grid-086 label{min-width:0}.bcs-invoice-profile-editor-grid-086 input,.bcs-invoice-profile-editor-grid-086 select,.bcs-invoice-profile-editor-grid-086 textarea{width:100%;max-width:100%;box-sizing:border-box}
            .bcs-invoice-profile-actions-086,.bcs-invoice-profile-form-actions-086,.bcs-invoice-profile-shortcuts-086{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.bcs-invoice-profile-form-actions-086{justify-content:flex-end}
            .bcs-invoice-profile-form-086{margin-top:16px;padding-top:16px;border-top:1px solid #dcdcde;max-width:100%;min-width:0;box-sizing:border-box}
            @media(max-width:782px){.bcs-invoice-profile-view-086,.bcs-invoice-profile-editor-grid-086{grid-template-columns:1fr}.bcs-invoice-profile-view-086 .is-wide,.bcs-invoice-profile-editor-grid-086 .is-wide{grid-column:auto}.bcs-invoice-profile-head-086{display:block}.bcs-invoice-profile-head-086 .bcs-badge{display:inline-block;margin-top:8px}.bcs-card-data-tabs-086{width:100%;overflow-x:auto}.bcs-card-data-tabs-086 button{flex:0 0 auto}}
        </style>
        <?php
    }

    /** Czysta transformacja HTML – używana także w teście regresyjnym 0.86. */
    public static function parent_signed_copy(string $html): string {
        $replacements = [
            'agreement_parent_signed' => 'Umowa podpisana przez Rodzica',
            'Formularz zaakceptowany' => 'Umowa podpisana przez Rodzica',
            'Organizator zaakceptował formularz i przygotowuje lub wysłał draft umowy.' => 'Umowa została podpisana przez Rodzica i oczekuje teraz na podpis Organizatora.',
            'Organizator zaakceptował formularz i przekazał wzór umowy.' => 'Umowa została podpisana przez Rodzica i oczekuje teraz na podpis Organizatora.',
            'Draft umowy jest dostępny do wglądu.' => 'Umowa została podpisana przez Ciebie i oczekuje na podpis Organizatora.',
            'Wzór umowy jest dostępny do wglądu.' => 'Umowa została podpisana przez Ciebie i oczekuje na podpis Organizatora.',
            'wzór umowy jest dostępny do wglądu.' => 'Umowa została podpisana przez Ciebie i oczekuje na podpis Organizatora.',
            'Otwórz draft umowy' => 'Otwórz podpisaną umowę',
            'Otwórz wzór umowy' => 'Otwórz podpisaną umowę',
            'Podpisz dokument' => 'Podpis rodzica złożony',
        ];
        return strtr($html, $replacements);
    }

    /**
     * @param mixed $output
     * @param mixed $tag
     * @param mixed $attr
     * @param mixed $m
     * @return mixed
     */
    public static function enhance_parent_portal($output, $tag, $attr, $m) {
        if ($tag !== 'basketmania_portal' || !is_string($output)) return $output;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if ($token === '') return $output;

        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT r.status,r.agreement_status,a.status agreement_real_status FROM '.BCS_DB::table('registrations').' r '
            .'LEFT JOIN '.BCS_DB::table('agreements').' a ON a.id=r.agreement_id WHERE r.public_token=%s LIMIT 1',
            $token
        ));
        if (!$r) return $output;
        $parentSigned = (string)($r->status ?? '') === 'agreement_parent_signed'
            || (string)($r->agreement_status ?? '') === 'parent_signed'
            || (string)($r->agreement_real_status ?? '') === 'parent_signed';
        return $parentSigned ? self::parent_signed_copy($output) : $output;
    }
}
