<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.87 – Dane do Faktury jako natywna sekcja rozwijana Karty Zgłoszenia.
 *
 * Rezygnujemy z zakładek JS z 0.86. Sekcja jest dostępna dla każdego zgłoszenia,
 * także gdy rodzic nie podał danych firmowych. W takim przypadku profil startuje
 * od danych imiennych rodzica/opiekuna. Rozwijanie sekcji i edycji korzysta z
 * natywnego HTML <details>, a zapis odbywa się zwykłym admin-post.
 */
final class BCS_Release_087 {
    private const SAVE_ACTION = 'bcs_save_invoice_profile_087';

    public static function init(): void {
        // 0.87 zastępuje w całości warstwę zakładek 0.86, ale zostawia jego poprawkę
        // komunikatu po podpisie Rodzica oraz cały backend faktur/KSeF 0.83/0.84.
        remove_action('admin_enqueue_scripts', ['BCS_Release_086', 'enqueue_admin_assets'], 1000);
        remove_action('admin_footer', ['BCS_Release_086', 'invoice_profile_template'], 9998);

        add_action('admin_footer', [__CLASS__, 'render_invoice_accordion'], 9998);
        add_action('admin_post_'.self::SAVE_ACTION, [__CLASS__, 'save_profile']);
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

    private static function clean_description(string $value): string {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        if (function_exists('mb_substr')) return mb_substr($value, 0, 256, 'UTF-8');
        return substr($value, 0, 256);
    }

    /**
     * Profil jest zapewniany dla KAŻDEGO zgłoszenia. Brak danych firmowych oznacza
     * profil imienny rodzica/opiekuna – niezależnie od invoice_requested.
     */
    public static function ensure_profile(int $registrationId): ?object {
        $r = self::registration($registrationId);
        if (!$r) return null;

        $initialized = trim((string)($r->billing_initialized_at ?? '')) !== '';
        $hasName = trim((string)($r->billing_name ?? '')) !== '';
        if ($initialized && $hasName) return $r;

        $company = (int)($r->invoice_requested ?? 0) === 1
            && trim((string)($r->invoice_buyer_name ?? '')) !== ''
            && trim((string)($r->invoice_nip ?? '')) !== '';
        $description = self::clean_description((string)($r->billing_ksef_description ?? ''));
        if ($description === '') $description = self::clean_description((string)($r->invoice_ksef_description ?? ''));
        if ($description === '') {
            $description = self::clean_description(trim((string)($r->child_first_name ?? '').' '.(string)($r->child_last_name ?? '')));
        }
        $now = BCS_Utils::now();
        $data = $company ? [
            'billing_type'=>'company',
            'billing_name'=>trim((string)($r->invoice_buyer_name ?? '')),
            'billing_street'=>trim((string)($r->invoice_street ?? '')),
            'billing_postal_code'=>trim((string)($r->invoice_postal_code ?? '')),
            'billing_city'=>trim((string)($r->invoice_city ?? '')),
            'billing_nip'=>preg_replace('/\D+/', '', (string)($r->invoice_nip ?? '')) ?: '',
            'billing_notes'=>trim((string)($r->invoice_notes ?? '')),
            'billing_source'=>'form_company',
        ] : [
            'billing_type'=>'individual',
            'billing_name'=>trim((string)($r->parent_first_name ?? '').' '.(string)($r->parent_last_name ?? '')),
            'billing_street'=>self::parent_street($r),
            'billing_postal_code'=>trim((string)($r->parent_postal_code ?? '')),
            'billing_city'=>trim((string)($r->parent_city ?? '')),
            'billing_nip'=>'',
            'billing_notes'=>'',
            'billing_source'=>'form_individual',
        ];
        $data['billing_ksef_description'] = $description;
        $data['billing_initialized_at'] = $now;
        $data['billing_updated_at'] = $now;

        global $wpdb;
        $wpdb->update(BCS_DB::table('registrations'), $data, ['id'=>$registrationId]);
        BCS_Utils::log('invoice_profile_initialized_087', [
            'billing_type'=>$data['billing_type'],
            'billing_source'=>$data['billing_source'],
            'available_without_company_data'=>true,
        ], $registrationId, null);
        return self::registration($registrationId);
    }

    private static function source_label(string $source): string {
        return match ($source) {
            'form_company' => 'Dane firmowe z Formularza Obozowego',
            'form_individual' => 'Dane imienne rodzica / opiekuna',
            'admin_edit' => 'Zmienione ręcznie przez administratora',
            default => 'Dane imienne rodzica / opiekuna',
        };
    }

    private static function redirect_notice(int $id, bool $ok, string $message): void {
        set_transient('bcs_invoice_087_notice_'.get_current_user_id().'_'.$id, [
            'ok'=>$ok,
            'message'=>$message,
        ], 60);
        $url = add_query_arg(['page'=>'bcs-registrations','view'=>$id], admin_url('admin.php'));
        wp_safe_redirect($url.'#bcs-invoice-data-087');
        exit;
    }

    public static function save_profile(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', 403);
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id) wp_die('Brak identyfikatora zgłoszenia.', 400);
        check_admin_referer('bcs_invoice_profile_087_'.$id);

        $r = self::ensure_profile($id);
        if (!$r) self::redirect_notice($id, false, 'Nie znaleziono zgłoszenia.');
        if (!empty($r->invoice_real_id)) {
            self::redirect_notice($id, false, 'Dane do faktury są zablokowane, ponieważ faktura została już wystawiona. Zmiana nabywcy wymaga korekty.');
        }

        $type = sanitize_key(wp_unslash($_POST['billing_type'] ?? ''));
        if (!in_array($type, ['individual','company'], true)) $type = 'individual';
        $name = trim(sanitize_text_field(wp_unslash($_POST['billing_name'] ?? '')));
        $street = trim(sanitize_text_field(wp_unslash($_POST['billing_street'] ?? '')));
        $postal = trim(sanitize_text_field(wp_unslash($_POST['billing_postal_code'] ?? '')));
        $city = trim(sanitize_text_field(wp_unslash($_POST['billing_city'] ?? '')));
        $nip = preg_replace('/\D+/', '', sanitize_text_field(wp_unslash($_POST['billing_nip'] ?? ''))) ?: '';
        $notes = trim(sanitize_textarea_field(wp_unslash($_POST['billing_notes'] ?? '')));
        $description = self::clean_description(sanitize_textarea_field(wp_unslash($_POST['billing_ksef_description'] ?? '')));

        $errors = [];
        if ($name === '') $errors[] = 'Podaj nazwę firmy albo imię i nazwisko.';
        if ($street === '') $errors[] = 'Podaj ulicę i numer.';
        if ($postal === '') $errors[] = 'Podaj kod pocztowy.';
        if ($city === '') $errors[] = 'Podaj miejscowość.';
        if ($type === 'company' && strlen($nip) !== 10) $errors[] = 'Dla firmy podaj 10-cyfrowy NIP.';
        if ($errors) self::redirect_notice($id, false, implode(' ', $errors));

        $now = BCS_Utils::now();
        global $wpdb;
        $updated = $wpdb->update(BCS_DB::table('registrations'), [
            'billing_type'=>$type,
            'billing_name'=>$name,
            'billing_street'=>$street,
            'billing_postal_code'=>$postal,
            'billing_city'=>$city,
            'billing_nip'=>$type === 'company' ? $nip : '',
            'billing_notes'=>$notes,
            'billing_ksef_description'=>$description,
            'billing_source'=>'admin_edit',
            'billing_initialized_at'=>trim((string)($r->billing_initialized_at ?? '')) !== '' ? (string)$r->billing_initialized_at : $now,
            'billing_updated_at'=>$now,
            'updated_at'=>$now,
        ], ['id'=>$id]);
        if ($updated === false) self::redirect_notice($id, false, 'Nie udało się zapisać danych do faktury.');

        BCS_Utils::log('invoice_profile_updated_087', [
            'billing_type'=>$type,
            'ksef_description_present'=>$description !== '',
        ], $id, null);
        self::redirect_notice($id, true, 'Dane do faktury zostały zapisane.');
    }

    public static function render_invoice_accordion(): void {
        if (!self::is_registration_card()) return;
        $id = absint($_GET['view'] ?? 0);
        $r = self::ensure_profile($id);
        if (!$r) return;

        $locked = !empty($r->invoice_real_id);
        $typeLabel = (string)($r->billing_type ?? '') === 'company' ? 'Firma' : 'Osoba prywatna';
        $notice = get_transient('bcs_invoice_087_notice_'.get_current_user_id().'_'.$id);
        if (is_array($notice)) delete_transient('bcs_invoice_087_notice_'.get_current_user_id().'_'.$id);
        $open = is_array($notice) || (string)wp_unslash($_SERVER['REQUEST_URI'] ?? '') !== '' && str_contains((string)wp_unslash($_SERVER['REQUEST_URI'] ?? ''), '#bcs-invoice-data-087');
        ?>
        <section id="bcs-invoice-data-087" class="bcs-panel bcs-accordion-panel bcs-invoice-profile-087">
            <details <?php echo $open ? 'open' : ''; ?>>
                <summary>
                    <span><span class="dashicons dashicons-money-alt"></span><strong>Dane do Faktury</strong></span>
                    <span class="bcs-accordion-statuses"><span class="bcs-badge"><?php echo esc_html(self::source_label((string)($r->billing_source ?? ''))); ?></span><span class="bcs-accordion-hint">Rozwiń dane</span></span>
                </summary>
                <div class="bcs-accordion-content">
                    <p class="bcs-muted">Dane używane do wystawienia faktury PDF i wysyłki do KSeF. Sekcja jest dostępna zawsze – brak danych firmowych oznacza fakturę na dane imienne rodzica / opiekuna.</p>
                    <?php if (is_array($notice)): ?>
                        <div class="notice <?php echo !empty($notice['ok']) ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html((string)($notice['message'] ?? '')); ?></p></div>
                    <?php endif; ?>
                    <?php if ($locked): ?>
                        <div class="notice notice-info inline"><p><strong>Dane zablokowane.</strong> Faktura <?php echo esc_html((string)($r->invoice_number ?? '')); ?> została już wystawiona. Zmiana nabywcy wymaga osobnej procedury korekty.</p></div>
                    <?php endif; ?>
                    <div class="bcs-detail-grid bcs-form-preview-grid bcs-invoice-preview-087">
                        <div class="bcs-detail-item"><span>Typ nabywcy</span><strong><?php echo esc_html($typeLabel); ?></strong></div>
                        <div class="bcs-detail-item"><span>Nazwa / imię i nazwisko</span><strong><?php echo esc_html((string)($r->billing_name ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item"><span>Ulica i numer</span><strong><?php echo esc_html((string)($r->billing_street ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item"><span>Kod pocztowy</span><strong><?php echo esc_html((string)($r->billing_postal_code ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item"><span>Miejscowość</span><strong><?php echo esc_html((string)($r->billing_city ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item"><span>NIP</span><strong><?php echo esc_html((string)($r->billing_nip ?: '—')); ?></strong></div>
                        <div class="bcs-detail-item bcs-detail-wide"><span>Dodatkowy opis do KSeF</span><strong><?php echo nl2br(esc_html((string)($r->billing_ksef_description ?: '—'))); ?></strong></div>
                        <div class="bcs-detail-item bcs-detail-wide"><span>Uwagi do faktury</span><strong><?php echo nl2br(esc_html((string)($r->billing_notes ?: '—'))); ?></strong></div>
                    </div>
                    <?php if (!$locked): ?>
                        <details class="bcs-document-stage is-ready bcs-invoice-editor-087">
                            <summary>
                                <span><span class="dashicons dashicons-edit"></span><span><strong>Edytuj dane do faktury</strong><small>Zmień nabywcę pomiędzy osobą prywatną i firmą lub popraw dane przed wystawieniem faktury.</small></span></span>
                                <span class="bcs-stage-status">Rozwiń edycję</span>
                            </summary>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bcs-invoice-form-087">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                                <input type="hidden" name="registration_id" value="<?php echo (int)$id; ?>">
                                <?php wp_nonce_field('bcs_invoice_profile_087_'.$id); ?>
                                <div class="bcs-invoice-grid-087">
                                    <label><span>Typ nabywcy</span><select name="billing_type" required><option value="individual" <?php selected((string)$r->billing_type, 'individual'); ?>>Osoba prywatna</option><option value="company" <?php selected((string)$r->billing_type, 'company'); ?>>Firma</option></select></label>
                                    <label><span>Nazwa / imię i nazwisko</span><input name="billing_name" value="<?php echo esc_attr((string)$r->billing_name); ?>" required></label>
                                    <label><span>Ulica i numer</span><input name="billing_street" value="<?php echo esc_attr((string)$r->billing_street); ?>" required></label>
                                    <label><span>Kod pocztowy</span><input name="billing_postal_code" value="<?php echo esc_attr((string)$r->billing_postal_code); ?>" required></label>
                                    <label><span>Miejscowość</span><input name="billing_city" value="<?php echo esc_attr((string)$r->billing_city); ?>" required></label>
                                    <label><span>NIP firmy</span><input name="billing_nip" inputmode="numeric" value="<?php echo esc_attr((string)$r->billing_nip); ?>"><small>Wymagany tylko dla firmy – dokładnie 10 cyfr.</small></label>
                                    <label class="is-wide"><span>Dodatkowy opis do KSeF</span><textarea name="billing_ksef_description" rows="3" maxlength="256"><?php echo esc_textarea((string)$r->billing_ksef_description); ?></textarea><small>Np. imię i nazwisko uczestnika obozu. Maks. 256 znaków.</small></label>
                                    <label class="is-wide"><span>Uwagi do faktury</span><textarea name="billing_notes" rows="3"><?php echo esc_textarea((string)$r->billing_notes); ?></textarea></label>
                                </div>
                                <p><button type="submit" class="button button-primary"><span class="dashicons dashicons-saved"></span> Zapisz dane do faktury</button></p>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </details>
        </section>
        <style>
            .bcs-invoice-profile-087{min-width:0;max-width:100%;box-sizing:border-box}
            .bcs-invoice-profile-087 .bcs-accordion-statuses{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
            .bcs-invoice-preview-087>*{min-width:0;overflow-wrap:anywhere}
            .bcs-invoice-editor-087{margin-top:16px}
            .bcs-invoice-form-087{padding:16px;max-width:100%;box-sizing:border-box}
            .bcs-invoice-grid-087{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;min-width:0}
            .bcs-invoice-grid-087 label{min-width:0}.bcs-invoice-grid-087 label>span{display:block;font-weight:600;margin-bottom:5px}
            .bcs-invoice-grid-087 input,.bcs-invoice-grid-087 select,.bcs-invoice-grid-087 textarea{width:100%;max-width:100%;box-sizing:border-box}
            .bcs-invoice-grid-087 .is-wide{grid-column:1/-1}.bcs-invoice-grid-087 small{display:block;margin-top:4px;color:#646970}
            @media(max-width:782px){.bcs-invoice-grid-087{grid-template-columns:1fr}.bcs-invoice-grid-087 .is-wide{grid-column:auto}.bcs-invoice-profile-087 .bcs-accordion-statuses{justify-content:flex-start}}
        </style>
        <script>
        (()=>{
            const section=document.getElementById('bcs-invoice-data-087');if(!section)return;
            const panels=[...document.querySelectorAll('.bcs-accordion-panel')].filter(panel=>panel!==section);
            const formPanel=panels.find(panel=>/Dane z formularza/i.test(panel.querySelector('summary strong')?.textContent||''));
            if(formPanel)formPanel.insertAdjacentElement('afterend',section);
        })();
        </script>
        <?php
    }
}
