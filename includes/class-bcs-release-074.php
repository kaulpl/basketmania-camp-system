<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.74 – uporządkowany moduł Organizatorzy.
 *
 * Naprawia konflikt callbacków ekranu: wcześniejszy renderer BCS_Admin,
 * renderer 0.73 i dynamiczny panel KSeF 0.72 mogły działać jednocześnie.
 * Edytor 0.74 jest jednym formularzem z jednym przyciskiem zapisu.
 */
final class BCS_Release_074 {
    public static function init(): void {
        // Panel KSeF jest renderowany bezpośrednio w jednym formularzu 0.74.
        remove_action('admin_footer', ['BCS_KSeF_Admin', 'inject_organizer_panel'], 20);
        remove_action('admin_footer', ['BCS_Release_073', 'ksef_token_help'], 40);

        // Uruchom po zarejestrowaniu menu przez rdzeń oraz wersję 0.73.
        add_action('admin_menu', [__CLASS__, 'replace_page_callback'], 9999);
    }

    public static function replace_page_callback(): void {
        $hook = get_plugin_page_hookname('bcs-organizers', 'bcs-dashboard');
        if (!$hook) return;

        remove_action($hook, ['BCS_Admin', 'organizers']);
        remove_action($hook, ['BCS_Release_073', 'page']);
        remove_action($hook, [__CLASS__, 'page']);
        add_action($hook, [__CLASS__, 'page']);
    }

    private static function organizer(int $id): ?object {
        global $wpdb;
        if ($id < 1) return null;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.BCS_DB::table('organizers').' WHERE id=%d',
            $id
        )) ?: null;
    }

    private static function notices(): void {
        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Ustawienia Organizatora zostały zapisane.</p></div>';
        }
        if (!empty($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Organizator został usunięty.</p></div>';
        }
        $error = sanitize_key(wp_unslash($_GET['error'] ?? ''));
        $messages = [
            'organizer_has_camps' => 'Nie można usunąć Organizatora, ponieważ ma przypisane turnusy.',
            'not_found' => 'Nie znaleziono wskazanego Organizatora.',
        ];
        if ($error && isset($messages[$error])) {
            echo '<div class="notice notice-error is-dismissible"><p>'.esc_html($messages[$error]).'</p></div>';
        }
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        self::notices();

        $editId = absint($_GET['edit'] ?? 0);
        $isNew = isset($_GET['new']);
        if ($editId || $isNew) {
            $organizer = $editId ? self::organizer($editId) : null;
            if ($editId && !$organizer) {
                wp_safe_redirect(add_query_arg(['page'=>'bcs-organizers', 'error'=>'not_found'], admin_url('admin.php')));
                exit;
            }
            self::editor($organizer);
            return;
        }

        self::list_page();
    }

    private static function list_page(): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT o.*, COUNT(c.id) camps_count '
            .'FROM '.BCS_DB::table('organizers').' o '
            .'LEFT JOIN '.BCS_DB::table('camps').' c ON c.organizer_id=o.id '
            .'GROUP BY o.id ORDER BY o.name'
        );

        echo '<div class="wrap bcs-admin bcs-organizers-074">';
        echo '<div class="bcs-page-head"><div><h1>Organizatorzy</h1><p>Podmioty odpowiedzialne za turnusy, rozliczenia, Stripe i KSeF.</p></div>';
        echo '<a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bcs-organizers&new=1')).'">Dodaj Organizatora</a></div>';
        echo '<div class="bcs-list-grid">';

        foreach ($rows as $row) {
            $stripe = (int)($row->stripe_enabled ?? 0) === 1;
            $ksef = (int)($row->ksef_enabled ?? 0) === 1;
            echo '<article class="bcs-list-card">';
            echo '<div class="bcs-card-top"><div><div class="bcs-card-labels">';
            echo '<span class="bcs-badge '.($stripe ? 'status-open' : 'status-draft').'">'.($stripe ? 'Stripe aktywny' : 'Stripe wyłączony').'</span>';
            echo '<span class="bcs-badge '.($ksef ? 'status-open' : 'status-draft').'">'.($ksef ? 'KSeF TEST aktywny' : 'KSeF wyłączony').'</span>';
            echo '<span class="bcs-id">#'.(int)$row->id.'</span></div>';
            echo '<h2>'.esc_html((string)$row->name).'</h2><p>'.esc_html((string)($row->legal_form ?: 'Brak formy prawnej')).'</p></div>';
            echo '<strong class="bcs-count">'.(int)$row->camps_count.' turn.</strong></div>';
            echo '<dl><div><dt>NIP</dt><dd>'.esc_html((string)($row->nip ?: '—')).'</dd></div>';
            echo '<div><dt>Rachunek</dt><dd>'.esc_html((string)($row->bank_account ?: '—')).'</dd></div>';
            echo '<div><dt>E-mail</dt><dd>'.esc_html((string)($row->email ?: '—')).'</dd></div></dl>';
            echo '<div class="bcs-card-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-organizers&edit='.(int)$row->id)).'">Edytuj</a>';
            echo '<form method="post" class="bcs-inline-delete" data-confirm="Usunąć Organizatora? Tej operacji nie można cofnąć.">';
            wp_nonce_field('bcs_delete_organizer_'.$row->id);
            echo '<input type="hidden" name="organizer_id" value="'.(int)$row->id.'">';
            echo '<button class="button button-link-delete" name="bcs_delete_organizer" value="1" '.((int)$row->camps_count ? 'disabled title="Najpierw usuń lub przenieś turnusy"' : '').'>Usuń</button></form></div>';
            echo '</article>';
        }

        if (!$rows) echo '<div class="bcs-empty">Nie dodano jeszcze żadnego Organizatora.</div>';
        echo '</div></div>';
    }

    private static function editor(?object $organizer): void {
        $id = (int)($organizer->id ?? 0);
        $isExisting = $id > 0;
        $value = static fn(string $field, string $default = ''): string => esc_attr((string)($organizer->{$field} ?? $default));
        $title = $isExisting ? 'Organizator: '.(string)$organizer->name : 'Nowy Organizator';
        $tokenConfigured = $isExisting && BCS_KSeF_Secret::configured($organizer);
        $masterKeyReady = BCS_KSeF_Config::master_key_available();
        $ksefNonce = $isExisting ? wp_create_nonce('bcs_ksef_organizer_'.$id) : '';

        echo '<div class="wrap bcs-admin bcs-organizer-editor-074">';
        echo '<div class="bcs-page-head"><div><a class="bcs-back-link-074" href="'.esc_url(admin_url('admin.php?page=bcs-organizers')).'">← Organizatorzy</a>';
        echo '<h1>'.esc_html($title).'</h1><p>Wszystkie ustawienia podmiotu znajdują się w jednym formularzu.</p></div></div>';

        echo '<form method="post" id="bcs-organizer-form-074" class="bcs-organizer-form-074">';
        wp_nonce_field('bcs_save_organizer');
        echo '<input type="hidden" name="organizer_id" value="'.$id.'">';
        if ($isExisting) echo '<input type="hidden" name="bcs_ksef_panel_present" value="1">';

        echo '<details class="bcs-settings-accordion bcs-organizer-section-074" open><summary><span><span class="dashicons dashicons-building"></span><strong>Dane Organizatora</strong></span><span class="bcs-settings-summary">Dane prawne i kontaktowe</span></summary><div class="bcs-settings-accordion-body"><div class="bcs-form-grid">';
        self::input('org_name', 'Pełna nazwa', $value('name'), 'text', true);
        self::input('legal_form', 'Forma prawna', $value('legal_form'));
        self::input('nip', 'NIP', $value('nip'));
        self::input('regon', 'REGON', $value('regon'));
        self::input('krs', 'KRS', $value('krs'));
        self::input('representative', 'Osoba reprezentująca', $value('representative'));
        self::input('org_email', 'E-mail', $value('email'), 'email');
        self::input('org_phone', 'Telefon', $value('phone'));
        echo '<label class="bcs-span-2"><span>Adres siedziby</span><textarea rows="3" name="org_address" required>'.esc_textarea((string)($organizer->address ?? '')).'</textarea></label>';
        echo '</div></div></details>';

        echo '<details class="bcs-settings-accordion bcs-organizer-section-074" open><summary><span><span class="dashicons dashicons-money-alt"></span><strong>Rozliczenia i dokumenty</strong></span><span class="bcs-settings-summary">Rachunek, przelewy i numeracja</span></summary><div class="bcs-settings-accordion-body"><div class="bcs-form-grid">';
        self::input('bank_name', 'Nazwa banku', $value('bank_name'));
        self::input('bank_account', 'Numer rachunku', $value('bank_account'), 'text', true);
        self::input('invoice_prefix', 'Prefiks Organizatora', $value('invoice_prefix'), 'text', true, 'np. BMC');
        self::input('transfer_title_template', 'Szablon tytułu przelewu', $value('transfer_title_template', 'Umowa {{AGREEMENT_NUMBER}} – {{CHILD_NAME}}'));
        echo '<p class="description bcs-span-2">Prefiks jest używany w numerach umów i faktur. Dozwolone są litery, cyfry, myślnik i podkreślenie.</p>';
        echo '</div></div></details>';

        echo '<details class="bcs-settings-accordion bcs-organizer-section-074"><summary><span><span class="dashicons dashicons-cart"></span><strong>Stripe</strong></span><span class="bcs-settings-summary">Płatności internetowe</span></summary><div class="bcs-settings-accordion-body"><div class="bcs-form-grid">';
        echo '<label class="bcs-checkbox bcs-span-2"><input type="checkbox" name="stripe_enabled" value="1" '.checked((int)($organizer->stripe_enabled ?? 0), 1, false).'><span>Włącz Stripe dla Organizatora</span></label>';
        echo '<label><span>Tryb Stripe</span><select name="stripe_mode"><option value="test" '.selected((string)($organizer->stripe_mode ?? 'test'), 'test', false).'>Testowy</option><option value="live" '.selected((string)($organizer->stripe_mode ?? 'test'), 'live', false).'>Produkcyjny</option></select></label>';
        echo '<div></div>';
        self::secret_input('stripe_test_secret_key', 'Testowy klucz tajny', !empty($organizer->stripe_test_secret_key));
        self::secret_input('stripe_test_webhook_secret', 'Testowy sekret webhooka', !empty($organizer->stripe_test_webhook_secret));
        self::secret_input('stripe_live_secret_key', 'Produkcyjny klucz tajny', !empty($organizer->stripe_live_secret_key));
        self::secret_input('stripe_live_webhook_secret', 'Produkcyjny sekret webhooka', !empty($organizer->stripe_live_webhook_secret));
        echo '</div>';
        if ($isExisting) echo '<p><strong>Adres webhooka:</strong> <code>'.esc_html(rest_url('bcs/v1/stripe-webhook/'.$id)).'</code></p>';
        echo '</div></details>';

        echo '<details class="bcs-settings-accordion bcs-organizer-section-074" '.($isExisting ? '' : 'open').'><summary><span><span class="dashicons dashicons-media-code"></span><strong>KSeF API 2.0 – TEST</strong></span><span class="bcs-settings-summary">Faktury ustrukturyzowane</span></summary><div class="bcs-settings-accordion-body">';
        if (!$isExisting) {
            echo '<div class="notice notice-info inline"><p>Najpierw zapisz nowego Organizatora. Konfiguracja KSeF pojawi się po utworzeniu rekordu podmiotu.</p></div>';
        } else {
            echo '<div class="bcs-form-grid">';
            echo '<label class="bcs-checkbox bcs-span-2"><input type="checkbox" name="ksef_enabled" value="1" '.checked((int)($organizer->ksef_enabled ?? 0), 1, false).'><span>Włącz przygotowanie KSeF dla Organizatora</span></label>';
            echo '<label><span>Środowisko</span><select disabled><option selected>TEST – api-test.ksef.mf.gov.pl</option></select><input type="hidden" name="ksef_environment" value="test"></label>';
            self::input('ksef_context_nip', 'NIP kontekstu TEST', $value('ksef_context_nip', (string)($organizer->nip ?? '')), 'text', false, '10 cyfr');
            self::input('ksef_country_code', 'Kod kraju', $value('ksef_country_code', 'PL'));
            self::input('ksef_address_l1', 'Adres KSeF – linia 1', $value('ksef_address_l1'), 'text', false, 'ulica i numer');
            self::input('ksef_address_l2', 'Adres KSeF – linia 2', $value('ksef_address_l2'), 'text', false, 'kod pocztowy i miejscowość');
            echo '<label class="bcs-span-2"><span>Token KSeF TEST</span><input type="password" autocomplete="new-password" name="ksef_token" placeholder="'.esc_attr($tokenConfigured ? 'Token zapisany — pozostaw puste, aby zachować' : 'Wklej token wygenerowany w Aplikacji Podatnika KSeF TEST').'"></label>';
            echo '<label class="bcs-checkbox"><input type="checkbox" name="ksef_anonymize_test" value="1" '.checked((int)($organizer->ksef_anonymize_test ?? 1), 1, false).'><span>Anonimizuj dane w XML TEST</span></label>';
            if ($tokenConfigured) echo '<label class="bcs-checkbox"><input type="checkbox" name="ksef_remove_token" value="1"><span>Usuń zapisany token</span></label>';
            echo '</div>';

            echo '<div class="bcs-ksef-token-help-074"><strong>Token testowy</strong><p>Wklej token wygenerowany w <a href="https://ap-test.ksef.mf.gov.pl/" target="_blank" rel="noopener noreferrer">Aplikacji Podatnika KSeF 2.0 – TEST</a>, w tym samym kontekście NIP. Wymagane uprawnienie: <code>InvoiceWrite</code>; zalecane również <code>InvoiceRead</code>.</p></div>';
            echo '<div class="bcs-ksef-security-074 '.($masterKeyReady ? 'is-ok' : 'is-error').'"><strong>'.($masterKeyReady ? '✓ Klucz szyfrujący serwera jest dostępny.' : '✕ Brak klucza BCS_KSEF_SECRET_KEY w wp-config.php.').'</strong><p>Token: '.($tokenConfigured ? 'zapisany w formie zaszyfrowanej' : 'nie skonfigurowano').'.</p></div>';
            echo '<p><button type="button" class="button bcs-ksef-test-074" data-organizer="'.$id.'" data-nonce="'.esc_attr($ksefNonce).'">Sprawdź połączenie z KSeF API TEST</button> <span class="bcs-ksef-test-result-074"></span></p>';
            if (!empty($organizer->ksef_last_test_at)) echo '<p class="description"><strong>Ostatni test:</strong> '.esc_html(BCS_Utils::format_datetime((string)$organizer->ksef_last_test_at)).' – '.esc_html((string)$organizer->ksef_last_test_message).'</p>';
        }
        echo '</div></details>';

        echo '<div class="bcs-organizer-actions-074"><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-organizers')).'">Anuluj</a><button type="submit" class="button button-primary button-hero" name="bcs_save_organizer" value="1">'.($isExisting ? 'Zapisz ustawienia' : 'Dodaj Organizatora').'</button></div>';
        echo '</form></div>';

        self::assets();
    }

    private static function input(string $name, string $label, string $value, string $type = 'text', bool $required = false, string $placeholder = ''): void {
        $extra = $name === 'invoice_prefix' ? ' maxlength="40" pattern="[A-Za-z0-9_-]+"' : '';
        echo '<label><span>'.esc_html($label).'</span><input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.$value.'"'.($required ? ' required' : '').($placeholder !== '' ? ' placeholder="'.esc_attr($placeholder).'"' : '').$extra.'></label>';
    }

    private static function secret_input(string $name, string $label, bool $configured): void {
        echo '<label><span>'.esc_html($label).'</span><input type="password" autocomplete="new-password" name="'.esc_attr($name).'" placeholder="'.esc_attr($configured ? 'Klucz zapisany — pozostaw puste' : '').'"></label>';
    }

    private static function assets(): void {
        ?>
        <style>
            .bcs-organizer-editor-074{max-width:1180px}
            .bcs-back-link-074{display:inline-block;margin-bottom:8px;text-decoration:none}
            .bcs-organizer-form-074{margin-top:18px}
            .bcs-organizer-section-074{margin-bottom:14px}
            .bcs-organizer-actions-074{position:sticky;bottom:0;z-index:20;display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding:14px 18px;border-top:1px solid #e2e8f0;background:rgba(255,255,255,.96);box-shadow:0 -6px 18px rgba(15,23,42,.08);backdrop-filter:blur(8px)}
            .bcs-ksef-token-help-074{margin:14px 0;padding:14px 16px;border:1px solid #fdba74;border-left:4px solid #f97316;border-radius:8px;background:#fff7ed}
            .bcs-ksef-token-help-074 p{margin:6px 0 0}
            .bcs-ksef-security-074{margin:14px 0;padding:12px 14px;border-left:4px solid #dba617;background:#fff8e5}
            .bcs-ksef-security-074.is-ok{border-color:#2e8b57;background:#eefaf3}
            .bcs-ksef-security-074.is-error{border-color:#c0392b;background:#fff1f0}
            .bcs-ksef-security-074 p{margin:5px 0 0}
            .bcs-ksef-test-result-074{margin-left:8px;font-weight:600}
            .bcs-ksef-test-result-074.is-ok{color:#22713e}.bcs-ksef-test-result-074.is-error{color:#b42318}
            @media(max-width:782px){.bcs-organizer-actions-074{position:static}.bcs-organizer-actions-074 .button{flex:1;text-align:center}}
        </style>
        <script>
        (() => {
            const button = document.querySelector('.bcs-ksef-test-074');
            if (!button) return;
            button.addEventListener('click', async () => {
                const result = document.querySelector('.bcs-ksef-test-result-074');
                button.disabled = true;
                result.className = 'bcs-ksef-test-result-074';
                result.textContent = 'Łączenie…';
                const data = new URLSearchParams({action:'bcs_ksef_test_connection_072', organizer_id:button.dataset.organizer, nonce:button.dataset.nonce});
                try {
                    const response = await fetch(window.ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:data.toString()});
                    const json = await response.json();
                    const ok = Boolean(json.success);
                    result.classList.add(ok ? 'is-ok' : 'is-error');
                    result.textContent = json.data?.message || (ok ? 'Połączono.' : 'Błąd połączenia.');
                } catch (error) {
                    result.classList.add('is-error');
                    result.textContent = 'Nie udało się odczytać odpowiedzi KSeF.';
                } finally {
                    button.disabled = false;
                }
            });
        })();
        </script>
        <?php
    }
}
