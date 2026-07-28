<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_073 {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'replace_organizers_page'], 99);
    }

    public static function replace_organizers_page(): void {
        remove_submenu_page('bcs-dashboard', 'bcs-organizers');
        add_submenu_page('bcs-dashboard', 'Organizatorzy', 'Organizatorzy', 'manage_options', 'bcs-organizers', [__CLASS__, 'page']);
    }

    private static function organizer(int $id): ?object {
        global $wpdb;
        return $id > 0 ? ($wpdb->get_row($wpdb->prepare('SELECT * FROM '.BCS_DB::table('organizers').' WHERE id=%d', $id)) ?: null) : null;
    }

    private static function notice(): void {
        if (!empty($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Zapisano ustawienia Organizatora.</p></div>';
        if (!empty($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Organizator został usunięty.</p></div>';
        $error = sanitize_key(wp_unslash($_GET['error'] ?? ''));
        if ($error === 'organizer_has_camps') echo '<div class="notice notice-error is-dismissible"><p>Nie można usunąć Organizatora, ponieważ ma przypisane turnusy.</p></div>';
        if ($error === 'not_found') echo '<div class="notice notice-error is-dismissible"><p>Nie znaleziono wskazanego Organizatora.</p></div>';
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        self::notice();
        $editId = absint($_GET['edit'] ?? 0);
        $new = isset($_GET['new']);
        if ($editId || $new) {
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
        $rows = $wpdb->get_results('SELECT o.*, COUNT(c.id) camps_count FROM '.BCS_DB::table('organizers').' o LEFT JOIN '.BCS_DB::table('camps').' c ON c.organizer_id=o.id GROUP BY o.id ORDER BY o.name');
        echo '<div class="wrap bcs-admin bcs-organizers-list-073"><div class="bcs-page-head"><div><h1>Organizatorzy</h1><p>Zarządzaj podmiotami, rachunkami bankowymi, Stripe i KSeF.</p></div><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bcs-organizers&new=1')).'">Dodaj organizatora</a></div><div class="bcs-list-grid">';
        foreach ($rows as $row) {
            $ksefEnabled = (int)($row->ksef_enabled ?? 0) === 1;
            echo '<article class="bcs-list-card"><div class="bcs-card-top"><div><div class="bcs-card-labels"><span class="bcs-badge '.((int)$row->stripe_enabled ? 'status-open' : 'status-draft').'">'.((int)$row->stripe_enabled ? 'Stripe aktywny' : 'Stripe wyłączony').'</span><span class="bcs-badge '.($ksefEnabled ? 'status-open' : 'status-draft').'">'.($ksefEnabled ? 'KSeF TEST aktywny' : 'KSeF wyłączony').'</span><span class="bcs-id">#'.(int)$row->id.'</span></div><h2>'.esc_html($row->name).'</h2><p>'.esc_html($row->legal_form ?: 'Brak formy prawnej').'</p></div><strong class="bcs-count">'.(int)$row->camps_count.' turn.</strong></div><dl><div><dt>NIP</dt><dd>'.esc_html($row->nip ?: '—').'</dd></div><div><dt>Rachunek</dt><dd>'.esc_html($row->bank_account ?: '—').'</dd></div><div><dt>E-mail</dt><dd>'.esc_html($row->email ?: '—').'</dd></div></dl><div class="bcs-card-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-organizers&edit='.(int)$row->id)).'">Edytuj</a><form method="post" class="bcs-inline-delete" data-confirm="Usunąć organizatora? Tej operacji nie można cofnąć.">';
            wp_nonce_field('bcs_delete_organizer_'.$row->id);
            echo '<input type="hidden" name="organizer_id" value="'.(int)$row->id.'"><button class="button button-link-delete" name="bcs_delete_organizer" value="1" '.((int)$row->camps_count ? 'disabled title="Najpierw usuń lub przenieś turnusy"' : '').'>Usuń</button></form></div></article>';
        }
        if (!$rows) echo '<div class="bcs-empty">Brak Organizatorów. Dodaj pierwszy podmiot.</div>';
        echo '</div></div>';
    }

    private static function editor(?object $organizer): void {
        $id = (int)($organizer->id ?? 0);
        $value = static fn(string $field, string $default = ''): string => esc_attr((string)($organizer->{$field} ?? $default));
        $title = $organizer ? 'Ustawienia Organizatora: '.$organizer->name : 'Nowy Organizator';
        echo '<div class="wrap bcs-admin bcs-organizer-editor-073"><div class="bcs-page-head"><div><a class="bcs-back-link-073" href="'.esc_url(admin_url('admin.php?page=bcs-organizers')).'">← Wróć do listy Organizatorów</a><h1>'.esc_html($title).'</h1><p>Dane podmiotu, rozliczenia, Stripe oraz integracja KSeF.</p></div><button type="submit" form="bcs-organizer-form-073" class="button button-primary">Zapisz</button></div><section class="bcs-panel bcs-organizer-single-panel-073"><form method="post" id="bcs-organizer-form-073">';
        wp_nonce_field('bcs_save_organizer');
        echo '<input type="hidden" name="organizer_id" value="'.$id.'"><div class="bcs-subpanel"><h2>Dane Organizatora</h2><div class="bcs-form-grid">';
        $fields = [
            'org_name'=>['Pełna nazwa','name','text'],'legal_form'=>['Forma prawna','legal_form','text'],'nip'=>['NIP','nip','text'],'regon'=>['REGON','regon','text'],'krs'=>['KRS','krs','text'],'representative'=>['Osoba reprezentująca','representative','text'],'org_email'=>['E-mail','email','email'],'org_phone'=>['Telefon','phone','text'],'bank_name'=>['Nazwa banku','bank_name','text'],'bank_account'=>['Numer rachunku','bank_account','text'],'transfer_title_template'=>['Szablon tytułu przelewu','transfer_title_template','text'],'invoice_prefix'=>['Prefiks organizatora','invoice_prefix','text']
        ];
        foreach ($fields as $name => $field) {
            $default = $name === 'transfer_title_template' ? 'Umowa {{AGREEMENT_NUMBER}} – {{CHILD_NAME}}' : '';
            $required = in_array($name, ['org_name','bank_account','invoice_prefix'], true) ? ' required' : '';
            $extra = $name === 'invoice_prefix' ? ' maxlength="40" pattern="[A-Za-z0-9_-]+" placeholder="np. BMC"' : '';
            echo '<label><span>'.esc_html($field[0]).'</span><input type="'.esc_attr($field[2]).'" name="'.esc_attr($name).'" value="'.$value($field[1], $default).'"'.$required.$extra.'></label>';
        }
        echo '<p class="description bcs-span-2">Prefiks jest używany w numerach umów i faktur: <code>[prefiks dokumentu]/[prefiks organizatora]/[rok]/[numer]</code>.</p><label class="bcs-span-2"><span>Adres siedziby</span><textarea rows="3" name="org_address" required>'.esc_textarea((string)($organizer->address ?? '')).'</textarea></label></div></div><div class="bcs-subpanel"><h2>Stripe</h2><div class="bcs-form-grid"><label class="bcs-checkbox"><input type="checkbox" name="stripe_enabled" value="1" '.checked((int)($organizer->stripe_enabled ?? 0), 1, false).'><span>Włącz Stripe dla Organizatora</span></label><label><span>Tryb</span><select name="stripe_mode"><option value="test" '.selected((string)($organizer->stripe_mode ?? 'test'), 'test', false).'>Testowy</option><option value="live" '.selected((string)($organizer->stripe_mode ?? 'test'), 'live', false).'>Produkcyjny</option></select></label>';
        foreach (['stripe_test_secret_key'=>'Testowy klucz tajny','stripe_test_webhook_secret'=>'Testowy sekret webhooka','stripe_live_secret_key'=>'Produkcyjny klucz tajny','stripe_live_webhook_secret'=>'Produkcyjny sekret webhooka'] as $name => $label) echo '<label><span>'.esc_html($label).'</span><input type="password" autocomplete="new-password" name="'.esc_attr($name).'" placeholder="'.(!empty($organizer->{$name}) ? 'Klucz zapisany — pozostaw puste' : '').'"></label>';
        echo '</div>';
        if ($id) echo '<p><strong>Webhook:</strong> <code>'.esc_html(rest_url('bcs/v1/stripe-webhook/'.$id)).'</code></p>';
        echo '</div>';
        if (!$id) echo '<div class="notice notice-info inline"><p>Najpierw zapisz nowego Organizatora. Po pierwszym zapisie pojawi się sekcja konfiguracji KSeF TEST.</p></div>';
        echo '<div class="bcs-form-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-organizers')).'">Anuluj</a><button class="button button-primary button-hero" name="bcs_save_organizer" value="1">Zapisz</button></div></form></section></div><style>.bcs-organizer-editor-073{max-width:1180px}.bcs-back-link-073{display:inline-block;margin-bottom:8px;text-decoration:none}.bcs-organizer-single-panel-073{margin-top:18px}.bcs-organizer-single-panel-073 .bcs-subpanel{margin-bottom:18px}.bcs-organizer-editor-073 .bcs-page-head{align-items:flex-start}.bcs-organizer-editor-073 .bcs-page-head>button{margin-top:6px;min-width:120px}</style>';
    }
}
