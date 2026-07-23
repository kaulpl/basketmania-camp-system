<?php
if (!defined('ABSPATH')) exit;

class BCS_Templates {
    public static function init(): void {
        // Menu rejestrowane centralnie w BCS_Admin.
        add_action('admin_init', [__CLASS__, 'save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        self::migrate_0131_templates();
        self::migrate_0132_templates();
        self::migrate_0152_templates();
        self::migrate_0208_templates();
    }


    private static function migrate_0131_templates(): void {
        if (get_option('bcs_templates_migrated_0131')) return;
        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $defaults = BCS_Communication_Engine::default_templates();
        foreach (['agreement_sent','agreement_signed','payment','paid'] as $key) {
            $saved['emails'][$key] = $defaults[$key];
        }
        update_option('bcs_content_templates', $saved, false);
        update_option('bcs_templates_migrated_0131', 1, false);
    }


    private static function migrate_0132_templates(): void {
        if (get_option('bcs_templates_migrated_0132')) return;
        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $defaults = self::defaults();
        $old_pre_camp = (string)($saved['emails']['pre_camp']['body'] ?? '');
        if (empty($saved['emails']['pre_camp']) || str_contains($old_pre_camp, '{{PRE_CAMP_INFO}}')) {
            $saved['emails']['pre_camp'] = $defaults['emails']['pre_camp'];
        }
        if (empty($saved['emails']['payment']['sms'])) {
            $saved['emails']['payment']['sms'] = $defaults['emails']['payment']['sms'];
        }
        if (empty($saved['documents']['regulations'])) $saved['documents']['regulations'] = $defaults['documents']['regulations'];
        update_option('bcs_content_templates', $saved, false);
        update_option('bcs_templates_migrated_0132', 1, false);
    }


    private static function migrate_0152_templates(): void {
        if (get_option('bcs_templates_migrated_0152')) return;
        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $defaults = BCS_Communication_Engine::default_templates();
        // Celowo aktualizujemy ten szablon: od 0.15.2 po podpisaniu umowy wysyłany jest jeden kompletny e-mail.
        $saved['emails']['agreement_signed'] = $defaults['agreement_signed'];
        update_option('bcs_content_templates', $saved, false);
        update_option('bcs_templates_migrated_0152', 1, false);
    }

    private static function migrate_0208_templates(): void {
        if (get_option('bcs_templates_migrated_0208')) return;
        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $body = (string)($saved['emails']['stripe_link']['body'] ?? '');
        if ($body === '' || preg_match('/<a\b(?![^>]*\bstyle=)[^>]*href=["\']\{\{STRIPE_URL\}\}/i', $body)) {
            $defaults = BCS_Communication_Engine::default_templates();
            $saved['emails']['stripe_link'] = $defaults['stripe_link'];
            update_option('bcs_content_templates', $saved, false);
        }
        update_option('bcs_templates_migrated_0208', 1, false);
    }

    public static function menu(): void {
        add_submenu_page('bcs-dashboard', 'Szablony wiadomości i dokumentów', 'Szablony', 'manage_options', 'bcs-templates', [__CLASS__, 'page']);
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'bcs-') === false) return;
        wp_enqueue_editor();
        wp_enqueue_media();
    }

    public static function defaults(): array {
        return [
            'ui' => [
                'signup_title'=>'Zapisz dziecko na turnus', 'portal_title'=>'Panel rodzica',
                'agreement_confirm_title'=>'Potwierdzenie umowy', 'send_code_button'=>'Wyślij kod SMS',
                'confirm_button'=>'Potwierdzam umowę i zobowiązuję się do zapłaty {{TOTAL_AMOUNT}}',
                'pay_button'=>'Przejdź do płatności', 'download_package_button'=>'Pobierz pakiet dokumentów',
                'camp_unavailable'=>'Turnus jest niedostępny.', 'camp_full'=>'Brak wolnych miejsc.',
                'invalid_link'=>'Nieprawidłowy link.', 'agreement_already_accepted'=>'Umowa jest już potwierdzona.',
                'otp_limit'=>'Osiągnięto limit wysyłek. Spróbuj później.', 'otp_sent'=>'Kod został wysłany na numer {{PHONE}}.',
                'otp_first'=>'Najpierw wyślij kod SMS.', 'otp_expired'=>'Kod wygasł. Wyślij nowy.',
                'otp_attempts'=>'Przekroczono liczbę prób. Wyślij nowy kod.', 'otp_invalid'=>'Kod jest nieprawidłowy.',
                'agreement_success'=>'Umowa została skutecznie potwierdzona.', 'access_denied'=>'Brak dostępu.',
            ],
            'emails' => BCS_Communication_Engine::default_templates(),
            'documents' => [
                'agreement' => BCS_Agreements::default_template(),
                'regulations' => '<h1>Regulamin obozu Basketmania Camp</h1><p><strong>Turnus:</strong> {{CAMP_NAME}}</p><p><strong>Termin:</strong> {{CAMP_DATES}}</p><p><strong>Miejsce:</strong> {{CAMP_LOCATION}}</p><h2>1. Postanowienia ogólne</h2><p>Treść regulaminu należy uzupełnić i zatwierdzić w module Szablony przed wysyłką informacji organizacyjnych.</p><h2>2. Organizator</h2><p>{{ORGANIZER_NAME}}<br>{{ORGANIZER_ADDRESS}}<br>NIP: {{ORGANIZER_NIP}}</p>',
                'invoice' => '<div class="invoice-sheet"><table class="invoice-head"><tr><td class="invoice-logo"><img src="{{LOGO_DATA_URI}}" alt="Basketmania Camp"></td><td class="invoice-meta"><div><span>Miejsce wystawienia</span><strong>{{ISSUE_PLACE}}</strong></div><div><span>Data wystawienia</span><strong>{{ISSUE_DATE}}</strong></div><div><span>Data sprzedaży</span><strong>{{SALE_DATE}}</strong></div></td></tr></table><table class="invoice-parties"><tr><td><div class="invoice-bar">Sprzedawca</div><strong>{{ORGANIZER_NAME}}</strong><br>{{ORGANIZER_ADDRESS}}<br>NIP: {{ORGANIZER_NIP}}<br>{{ORGANIZER_EMAIL}}<br>{{ORGANIZER_PHONE}}</td><td><div class="invoice-bar">Nabywca</div><strong>{{BUYER_NAME}}</strong><br>{{BUYER_ADDRESS}}</td></tr></table><h1 class="invoice-title">Faktura {{INVOICE_NUMBER}}</h1><table class="invoice-items"><thead><tr><th>Lp.</th><th>Nazwa towaru lub usługi</th><th>Jm.</th><th>Ilość</th><th>Cena</th><th>Wartość</th></tr></thead><tbody><tr><td>1</td><td>Udział w {{CAMP_NAME}} ({{CAMP_DATES}})</td><td>usł.</td><td>1</td><td>{{GROSS_AMOUNT}}</td><td>{{GROSS_AMOUNT}}</td></tr><tr class="invoice-total"><td colspan="5">Razem</td><td>{{GROSS_AMOUNT}}</td></tr></tbody></table><table class="invoice-payment"><tr><td><strong>Zapłacono&nbsp;&nbsp; {{GROSS_AMOUNT}}</strong><br>Data płatności: {{PAYMENT_DATE}}<hr>Sposób płatności&nbsp;&nbsp; {{PAYMENT_METHOD}}</td><td><strong>Do zapłaty&nbsp;&nbsp; {{AMOUNT_DUE}}</strong><hr>Słownie: zapłacono w całości</td></tr></table>{{EXEMPTION_NOTE}}<div class="invoice-note"><strong>Uwagi:</strong><br>Dokument wygenerowany elektronicznie przez system Basketmania Camp. Numer rachunku organizatora: {{BANK_ACCOUNT}}</div><table class="invoice-signatures"><tr><td><div class="invoice-bar">Wystawił(a):</div><div class="signature-space"></div><small>Osoba upoważniona do wystawienia</small></td><td><div class="invoice-bar">Odebrał(a):</div><div class="signature-space"></div><small>Osoba upoważniona do odbioru</small></td></tr></table></div>',
                                'receipt' => '<h1>Rachunek {{RECEIPT_NUMBER}}</h1><p><strong>Wystawca:</strong><br>{{ORGANIZER_NAME}}<br>{{ORGANIZER_ADDRESS}}<br>NIP: {{ORGANIZER_NIP}}</p><p><strong>Nabywca:</strong><br>{{BUYER_NAME}}<br>{{BUYER_ADDRESS}}</p><table><tr><th>Usługa</th><th>Kwota</th></tr><tr><td>Udział w {{CAMP_NAME}} ({{CAMP_DATES}})</td><td>{{GROSS_AMOUNT}}</td></tr></table><p>Zapłacono dnia {{SALE_DATE}}.</p>',
                'confirmation' => '<h1>Potwierdzenie uczestnictwa</h1><p>Potwierdzamy przyjęcie <strong>{{CHILD_NAME}}</strong> na turnus <strong>{{CAMP_NAME}}</strong>.</p><p>Termin: {{CAMP_DATES}}<br>Miejsce: {{CAMP_LOCATION}}<br>Umowa: {{AGREEMENT_NUMBER}}</p>',
                'agreement_proof' => '<div class="proof"><h2>Potwierdzenie zawarcia umowy</h2><p><strong>Status:</strong> potwierdzona kodem SMS</p><p><strong>Data:</strong> {{ACCEPTED_AT}}</p><p><strong>Telefon:</strong> {{PHONE_MASKED}}</p><p><strong>Identyfikator SMS:</strong> {{SMS_ID}}</p><p><strong>Skrót SHA-256 dokumentu:</strong><br><code>{{DOCUMENT_HASH}}</code></p></div>',
            ],
        ];
    }

    public static function all(): array { return array_replace_recursive(self::defaults(), get_option('bcs_content_templates', [])); }
    public static function get(string $group, string $key, string $fallback=''): string { $all=self::all(); return (string)($all[$group][$key] ?? $fallback); }
    public static function render(string $content, array $vars): string { return strtr($content, $vars); }

    private static function labels(): array {
        return [
            'ui'=>[
                'signup_title'=>'Tytuł formularza zapisów','portal_title'=>'Tytuł panelu rodzica','agreement_confirm_title'=>'Tytuł potwierdzenia umowy','send_code_button'=>'Przycisk wysłania kodu SMS','confirm_button'=>'Przycisk akceptacji umowy','pay_button'=>'Przycisk płatności','download_package_button'=>'Przycisk pobrania dokumentów','camp_unavailable'=>'Turnus niedostępny','camp_full'=>'Brak wolnych miejsc','invalid_link'=>'Nieprawidłowy link','agreement_already_accepted'=>'Umowa już zaakceptowana','otp_limit'=>'Limit wysyłek SMS','otp_sent'=>'Kod SMS wysłany','otp_first'=>'Kod nie został wysłany','otp_expired'=>'Kod wygasł','otp_attempts'=>'Przekroczono liczbę prób','otp_invalid'=>'Nieprawidłowy kod','agreement_success'=>'Umowa potwierdzona','access_denied'=>'Brak dostępu',
            ],
            'documents'=>[
                'agreement'=>'Wzór umowy','regulations'=>'Regulamin obozu','invoice'=>'Wzór faktury','receipt'=>'Wzór rachunku','confirmation'=>'Potwierdzenie uczestnictwa','agreement_proof'=>'Sekcja dowodowa SMS',
            ],
        ];
    }

    private static function catalog(): array {
        $t=self::all(); $labels=self::labels(); $out=[];
        // Komunikaty systemowe pozostają na stałe w kodzie i nie są edytowalne.
        foreach($t['emails'] as $key=>$tpl) $out['emails:'.$key]=['group'=>'emails','key'=>$key,'name'=>(string)($tpl['name'] ?? ucwords(str_replace('_',' ',$key))),'description'=>($key==='pre_camp'?'Wiadomość wysyłana 7 dni przed obozem z regulaminem PDF w załączniku.':'Szablon wiadomości e-mail i SMS.'),'type'=>'email'];
        foreach($t['documents'] as $key=>$value) $out['documents:'.$key]=['group'=>'documents','key'=>$key,'name'=>$labels['documents'][$key] ?? ucwords(str_replace('_',' ',$key)),'description'=>'Dokument generowany przez system i eksportowany do PDF.','type'=>'document'];
        return $out;
    }

    public static function save(): void {
        if (!current_user_can('manage_options') || !isset($_POST['bcs_save_single_template'])) return;
        check_admin_referer('bcs_save_single_template');
        $id=sanitize_text_field(wp_unslash($_POST['template_id'] ?? ''));
        $catalog=self::catalog(); if(!isset($catalog[$id])) return;
        $item=$catalog[$id]; $all=get_option('bcs_content_templates',[]);
        if($item['group']==='emails') {
            $sms = sanitize_textarea_field(wp_unslash($_POST['template_sms'] ?? ''));
            if (class_exists('BCS_SMS')) $sms = BCS_SMS::strip_links(BCS_SMS::to_ascii($sms));
            $all['emails'][$item['key']]=[
                'name'=>sanitize_text_field(wp_unslash($_POST['template_name'] ?? $item['name'])),
                'subject'=>sanitize_text_field(wp_unslash($_POST['template_subject'] ?? '')),
                'body'=>wp_kses_post(wp_unslash($_POST['template_body'] ?? '')),
                'sms'=>$sms,
            ];
        } else {
            $all['documents'][$item['key']]=wp_kses_post(wp_unslash($_POST['template_value'] ?? ''));
        }
        update_option('bcs_content_templates',$all,false);
        wp_safe_redirect(admin_url('admin.php?page=bcs-templates&edit='.rawurlencode($id).'&saved=1')); exit;
    }

    private static function editor(string $id, string $name, string $content, int $rows=18): void {
        wp_editor($content,$id,[
            'textarea_name'=>$name,
            'textarea_rows'=>$rows,
            'media_buttons'=>true,
            'teeny'=>false,
            'quicktags'=>true,
            'tinymce'=>[
                'toolbar1'=>'formatselect,bold,italic,underline,strikethrough,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_more,undo,redo',
                'toolbar2'=>'forecolor,backcolor,pastetext,removeformat,charmap,outdent,indent,hr,fullscreen,wp_help',
            ],
        ]);
    }

    private static function group_label(string $group): string { return ['ui'=>'Komunikaty systemowe','emails'=>'E-mail i SMS','documents'=>'Dokumenty PDF'][$group] ?? $group; }
    private static function icon(string $type): string { return ['ui'=>'dashicons-format-chat','email'=>'dashicons-email-alt','document'=>'dashicons-media-document'][$type] ?? 'dashicons-admin-generic'; }

    public static function page(): void {
        $edit=sanitize_text_field(wp_unslash($_GET['edit'] ?? ''));
        if($edit) { self::edit_page($edit); return; }
        $catalog=self::catalog();
        echo '<div class="wrap bcs-admin bcs-template-library"><div class="bcs-page-head"><div><h1>Szablony wiadomości i dokumentów</h1><p>Edytuj szablony e-mail, SMS i dokumentów PDF. Komunikaty systemowe pozostają stałe.</p></div><span class="bcs-count">'.count($catalog).' szabl.</span></div>';
        foreach(['emails','documents'] as $group){
            echo '<section class="bcs-template-section"><div class="bcs-section-heading"><h2>'.esc_html(self::group_label($group)).'</h2><span>'.count(array_filter($catalog,static fn($x)=>$x['group']===$group)).'</span></div><div class="bcs-template-grid">';
            foreach($catalog as $id=>$item){ if($item['group']!==$group) continue;
                echo '<article class="bcs-template-card"><div class="bcs-template-icon"><span class="dashicons '.esc_attr(self::icon($item['type'])).'"></span></div><div class="bcs-template-content"><span class="bcs-id">#'.esc_html(strtoupper($item['key'])).'</span><h3>'.esc_html($item['name']).'</h3><p>'.esc_html($item['description']).'</p></div><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bcs-templates&edit='.rawurlencode($id))).'">Edytuj</a></article>';
            }
            echo '</div></section>';
        }
        echo '</div>';
    }

    private static function edit_page(string $id): void {
        $catalog=self::catalog(); if(!isset($catalog[$id])) { echo '<div class="wrap"><div class="notice notice-error"><p>Nie znaleziono szablonu.</p></div></div>'; return; }
        $item=$catalog[$id]; $t=self::all();
        echo '<div class="wrap bcs-admin bcs-template-editor"><div class="bcs-page-head"><div><a class="bcs-back" href="'.esc_url(admin_url('admin.php?page=bcs-templates')).'">← Wróć do listy szablonów</a><span class="bcs-id">#'.esc_html(strtoupper($item['key'])).'</span><h1>'.esc_html($item['name']).'</h1><p>'.esc_html($item['description']).'</p></div><span class="bcs-badge status-open">'.esc_html(self::group_label($item['group'])).'</span></div>';
        if(isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Szablon został zapisany.</p></div>';
        echo '<section class="bcs-panel"><form method="post">'; wp_nonce_field('bcs_save_single_template'); echo '<input type="hidden" name="template_id" value="'.esc_attr($id).'">';
        if($item['group']==='emails') {
            $tpl=$t['emails'][$item['key']];
            $sms_allowed=in_array($item['key'],['camp_form_request','camp_form_verified','agreement_sent','payment','invoice_issued'],true);
            echo '<div class="bcs-form-grid"><label class="bcs-span-2"><span>Nazwa wewnętrzna szablonu</span><input type="text" name="template_name" value="'.esc_attr($tpl['name'] ?? $item['name']).'"></label><label class="bcs-span-2"><span>Temat wiadomości e-mail</span><input type="text" name="template_subject" value="'.esc_attr($tpl['subject'] ?? '').'"></label></div><div class="bcs-editor-block"><h2>Treść wiadomości e-mail</h2>'; self::editor('bcs_single_email_body','template_body',(string)($tpl['body'] ?? ''),16); echo '</div>';
            if($sms_allowed) echo '<div class="bcs-editor-block"><h2>Treść SMS</h2><textarea class="large-text" rows="5" name="template_sms">'.esc_textarea($tpl['sms'] ?? '').'</textarea><p class="description">SMS jest wysyłany po działaniu administratora. Polskie znaki są zamieniane na ASCII, a linki usuwane.</p></div>';
            else echo '<input type="hidden" name="template_sms" value=""><div class="bcs-editor-block"><h2>SMS</h2><p class="description">Dla tego etapu system wysyła wyłącznie wiadomość e-mail.</p></div>';
        } else {
            echo '<div class="bcs-editor-block"><h2>Treść dokumentu</h2>'; self::editor('bcs_single_document','template_value',(string)$t['documents'][$item['key']],24); echo '</div>';
        }
        echo '<div class="bcs-template-help"><strong>Dostępne zmienne:</strong> <code>{{PARENT_NAME}}</code> <code>{{CHILD_NAME}}</code> <code>{{CAMP_NAME}}</code> <code>{{CAMP_DATES}}</code> <code>{{CAMP_LOCATION}}</code> <code>{{TOTAL_AMOUNT}}</code> <code>{{ORGANIZER_NAME}}</code> <code>{{AGREEMENT_NUMBER}}</code> <code>{{INVOICE_NUMBER}}</code> <code>{{PORTAL_URL}}</code> <code>{{DATA_OD}}</code> <code>{{DATA_DO}}</code> <code>{{GODZINA_PRZYJAZDU}}</code> <code>{{LISTA_RZECZY}}</code> <code>{{KONTAKT}}</code></div><div class="bcs-form-actions"><button class="button button-primary button-hero" name="bcs_save_single_template" value="1">Zapisz szablon</button><a class="button button-hero" href="'.esc_url(admin_url('admin.php?page=bcs-templates')).'">Anuluj</a></div></form></section></div>';
    }
}
