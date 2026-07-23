<?php
if (!defined('ABSPATH')) exit;

/** Konfiguracja kanałów i kontrola kompletności szablonów powiadomień workflow. */
final class BCS_Notification_Settings {
    private const OPTION = 'bcs_notification_channels';
    private const TEMPLATE_MIGRATION = 'bcs_notification_templates_migrated_0186';

    public static function init(): void {
        self::ensure_complete_templates();
        add_action('admin_post_bcs_save_notification_settings', [self::class, 'save']);
        add_action('admin_footer', [self::class, 'render_settings_section']);
        add_action('admin_footer', [self::class, 'enhance_templates_module']);
    }

    public static function events(): array {
        return [
            'registration_received' => ['label'=>'Wstępna rejestracja','description'=>'Natychmiast po zapisaniu zgłoszenia: podziękowanie i odnośnik do Formularza Obozowego.','default'=>'email','once'=>true],
            'camp_form_request' => ['label'=>'Ponowienie prośby o Formularz Obozowy','description'=>'Ręczne lub automatyczne przypomnienie, gdy formularz nadal nie został uzupełniony.','default'=>'off','once'=>false],
            'camp_form_verified' => ['label'=>'Formularz Obozowy sprawdzony','description'=>'Informacja o zakończeniu weryfikacji pełnych danych uczestnika.','default'=>'email','once'=>true],
            'agreement_sent' => ['label'=>'Umowa gotowa do podpisania','description'=>'Powiadomienie z dostępem do podpisu umowy kodem SMS.','default'=>'both','once'=>false],
            'agreement_signed' => ['label'=>'Umowa podpisana','description'=>'Potwierdzenie podpisania oraz dane do płatności.','default'=>'email','once'=>true],
            'stripe_link' => ['label'=>'Link do płatności Stripe','description'=>'Indywidualny link do płatności internetowej.','default'=>'email','once'=>false],
            'payment' => ['label'=>'Przypomnienie o płatności','description'=>'Automatyczne lub ręczne przypomnienie o zaległej wpłacie.','default'=>'both','once'=>false],
            'paid' => ['label'=>'Płatność zaksięgowana','description'=>'Potwierdzenie pełnego opłacenia udziału.','default'=>'email','once'=>true],
            'invoice_issued' => ['label'=>'Faktura wystawiona','description'=>'Wiadomość z fakturą i dostępem do dokumentu.','default'=>'email','once'=>true],
            'pre_camp' => ['label'=>'Informacje przed obozem','description'=>'Informacje organizacyjne wysyłane przed rozpoczęciem turnusu.','default'=>'email','once'=>false],
        ];
    }

    private static function sms_defaults(): array {
        return [
            'registration_received'=>'Basketmania Camp: dziekujemy za zgloszenie {{CHILD_NAME}} na {{CAMP_NAME}}. Uzupelnij Formularz Obozowy w Panelu Rodzica. Szczegoly wyslalismy e-mailem.',
            'camp_form_request'=>'Basketmania Camp: prosimy o uzupelnienie Formularza Obozowego dla {{CHILD_NAME}}. Szczegoly i dostep do panelu znajduja sie w e-mailu.',
            'camp_form_verified'=>'Basketmania Camp: Formularz Obozowy uczestnika {{CHILD_NAME}} zostal sprawdzony. Kolejne informacje przeslemy zgodnie z procesem rejestracji.',
            'agreement_sent'=>'Basketmania Camp: umowa {{AGREEMENT_NUMBER}} jest gotowa do podpisu. Otworz Panel Rodzica i potwierdz umowe kodem SMS.',
            'agreement_signed'=>'Basketmania Camp: umowa {{AGREEMENT_NUMBER}} zostala podpisana. Dane do platnosci i potwierdzenie wyslalismy e-mailem.',
            'stripe_link'=>'Basketmania Camp: przygotowalismy indywidualny link do platnosci za {{CAMP_NAME}}. Link zostal wyslany w wiadomosci e-mail.',
            'payment'=>'Basketmania Camp: przypominamy o platnosci za udzial {{CHILD_NAME}} w {{CAMP_NAME}}. Kwota: {{AMOUNT_DUE}} zl. Dane do przelewu wyslalismy e-mailem.',
            'paid'=>'Basketmania Camp: potwierdzamy zaksiegowanie platnosci za udzial {{CHILD_NAME}} w {{CAMP_NAME}}. Dziekujemy.',
            'invoice_issued'=>'Basketmania Camp: wystawiono fakture dotyczaca udzialu {{CHILD_NAME}} w {{CAMP_NAME}}. Dokument jest dostepny w Panelu Rodzica i e-mailu.',
            'pre_camp'=>'Basketmania Camp: przeslalismy informacje organizacyjne przed turnusem {{CAMP_NAME}}. Prosze sprawdzic wiadomosc e-mail.',
        ];
    }

    /** Uzupełnia brakujące pola, ale nigdy nie nadpisuje treści wpisanej przez administratora. */
    public static function ensure_complete_templates(): void {
        if (get_option(self::TEMPLATE_MIGRATION)) return;
        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $defaults = class_exists('BCS_Communications') ? BCS_Communications::default_templates() : [];
        $sms_defaults = self::sms_defaults();
        foreach (self::events() as $key => $event) {
            $default = (array)($defaults[$key] ?? []);
            $template = (array)($saved['emails'][$key] ?? []);
            if (trim((string)($template['name'] ?? '')) === '') $template['name'] = (string)($default['name'] ?? $event['label']);
            if (trim((string)($template['subject'] ?? '')) === '') $template['subject'] = (string)($default['subject'] ?? $event['label']);
            if (trim(wp_strip_all_tags((string)($template['body'] ?? ''))) === '') $template['body'] = (string)($default['body'] ?? '');
            if (trim((string)($template['sms'] ?? '')) === '') $template['sms'] = (string)($sms_defaults[$key] ?? ($default['sms'] ?? ''));
            $saved['emails'][$key] = $template;
        }
        update_option('bcs_content_templates', $saved, false);
        update_option(self::TEMPLATE_MIGRATION, 1, false);
    }

    public static function channels_for(string $event, string $fallback='email'): string {
        $events = self::events();
        $saved = get_option(self::OPTION, []);
        $value = is_array($saved) ? (string)($saved[$event] ?? '') : '';
        if (!in_array($value, ['off','email','sms','both'], true)) $value = (string)($events[$event]['default'] ?? $fallback);
        return $value;
    }

    public static function is_once(string $event): bool { return !empty(self::events()[$event]['once']); }

    public static function already_sent(int $registration_id, string $event): bool {
        if (!self::is_once($event)) return false;
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM ".BCS_DB::table('messages')." WHERE registration_id=%d AND template_key=%s AND status='sent' LIMIT 1",
            $registration_id, $event
        ));
    }

    private static function template_state(string $key, array $templates): array {
        $tpl = (array)($templates[$key] ?? []);
        $email = trim((string)($tpl['subject'] ?? '')) !== '' && trim(wp_strip_all_tags((string)($tpl['body'] ?? ''))) !== '';
        $sms = trim((string)($tpl['sms'] ?? '')) !== '';
        return ['email'=>$email, 'sms'=>$sms, 'complete'=>$email && $sms];
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer('bcs_save_notification_settings');
        $input = (array)($_POST['channels'] ?? []); $out = [];
        $templates = class_exists('BCS_Template_Engine') ? (array)(BCS_Template_Engine::all()['emails'] ?? []) : [];
        $errors = [];
        foreach (self::events() as $key => $event) {
            $value = sanitize_key((string)($input[$key] ?? $event['default']));
            $value = in_array($value, ['off','email','sms','both'], true) ? $value : $event['default'];
            $state = self::template_state($key, $templates);
            if (in_array($value, ['email','both'], true) && !$state['email']) $errors[] = $event['label'].' — brak kompletnego szablonu e-mail.';
            if (in_array($value, ['sms','both'], true) && !$state['sms']) $errors[] = $event['label'].' — brak treści SMS.';
            $out[$key] = $value;
        }
        if ($errors) {
            set_transient('bcs_notification_errors_'.get_current_user_id(), $errors, 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg(['page'=>'bcs-settings','notifications_error'=>1], admin_url('admin.php'))); exit;
        }
        update_option(self::OPTION, $out, false);
        wp_safe_redirect(add_query_arg(['page'=>'bcs-settings','notifications_saved'=>1], admin_url('admin.php'))); exit;
    }

    public static function render_settings_section(): void {
        if (!current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-settings') return;
        $saved = get_option(self::OPTION, []);
        ?>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
            const wrap=document.querySelector('.wrap.bcs-admin');if(!wrap||document.querySelector('.bcs-notification-settings'))return;
            const section=document.createElement('details');section.className='bcs-settings-accordion bcs-settings-section-0200 bcs-notification-settings';section.style.marginTop='20px';
            section.innerHTML='<summary><span><span class="dashicons dashicons-bell"></span><strong>Powiadomienia SMS/EMAIL</strong></span><span class="bcs-settings-summary">Kanały wysyłki dla etapów procesu</span></summary><div class="bcs-settings-accordion-body">'+<?php echo wp_json_encode(self::settings_html(is_array($saved)?$saved:[])); ?>+'</div>';
            const email=[...wrap.querySelectorAll('details')].find(function(item){return /^E-?MAIL$/i.test((item.querySelector('summary strong')?.textContent||'').trim());});
            const settingsPanel=email?.closest('.bcs-panel');
            if(settingsPanel)settingsPanel.after(section);else wrap.appendChild(section);
        });
        </script>
        <?php
    }

    private static function settings_html(array $saved): string {
        $templates = class_exists('BCS_Template_Engine') ? BCS_Template_Engine::all() : [];
        $email_templates = (array)($templates['emails'] ?? []);
        $html='<div class="bcs-panel-head"><div><h2>Ustawienia kanałów powiadomień</h2><p>Ustawienia określają kanał wysyłki. Moduł Szablony zawsze przechowuje osobno gotową treść e-mail i SMS.</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-templates')).'">Otwórz wszystkie szablony</a></div>';
        if(isset($_GET['notifications_saved']))$html.='<div class="notice notice-success inline"><p>Ustawienia powiadomień zostały zapisane.</p></div>';
        if(isset($_GET['notifications_error'])){
            $errors=get_transient('bcs_notification_errors_'.get_current_user_id());delete_transient('bcs_notification_errors_'.get_current_user_id());
            if(is_array($errors))$html.='<div class="notice notice-error inline"><p><strong>Nie zapisano ustawień:</strong></p><ul><li>'.implode('</li><li>',array_map('esc_html',$errors)).'</li></ul></div>';
        }
        $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bcs_save_notification_settings">'.wp_nonce_field('bcs_save_notification_settings','_wpnonce',true,false);
        $html.='<table class="widefat striped"><thead><tr><th>Etap procesu</th><th>Kiedy jest wysyłane</th><th>Komplet szablonów</th><th>Kanał</th></tr></thead><tbody>';
        $options=['off'=>'Nie wysyłaj','email'=>'E-mail','sms'=>'SMS','both'=>'E-mail i SMS'];
        foreach(self::events() as $key=>$event){
            $current=(string)($saved[$key]??$event['default']);$state=self::template_state($key,$email_templates);
            $edit=admin_url('admin.php?page=bcs-templates&edit='.rawurlencode('emails:'.$key));
            $status=$state['complete']?'<span style="color:#16803c;font-weight:700">✓ E-mail i SMS gotowe</span>':'<span style="color:#b32d2e;font-weight:700">✕ '.(!$state['email']?'Brak e-maila':'Brak SMS').'</span>';
            $html.='<tr><td><strong>'.esc_html($event['label']).'</strong>'.(!empty($event['once'])?'<br><small>Jednorazowe — duplikaty są blokowane.</small>':'').'</td><td>'.esc_html($event['description']).'</td>';
            $html.='<td>'.$status.'<br><a href="'.esc_url($edit).'">Edytuj e-mail i SMS</a></td><td><select name="channels['.esc_attr($key).']">';
            foreach($options as $value=>$label)$html.='<option value="'.esc_attr($value).'" '.selected($current,$value,false).'>'.esc_html($label).'</option>';
            $html.='</select></td></tr>';
        }
        $html.='</tbody></table><p class="description">Włączenie kanału jest możliwe tylko wtedy, gdy jego szablon jest kompletny. Oba warianty pozostają zawsze edytowalne w module Szablony.</p><p><button class="button button-primary button-hero">Zapisz ustawienia powiadomień</button></p></form>';
        return $html;
    }

    /** Ujawnia edycję SMS dla każdego szablonu workflow i pokazuje jego kompletność na liście. */
    public static function enhance_templates_module(): void {
        if (!current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-templates') return;
        $all = class_exists('BCS_Template_Engine') ? BCS_Template_Engine::all() : [];
        $templates = (array)($all['emails'] ?? []); $states = []; $sms = [];
        foreach (self::events() as $key=>$event) { $states[strtoupper($key)] = self::template_state($key,$templates); $sms[$key]=(string)($templates[$key]['sms']??''); }
        $edit = sanitize_text_field(wp_unslash($_GET['edit'] ?? ''));
        ?>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
            const states=<?php echo wp_json_encode($states); ?>;
            document.querySelectorAll('.bcs-template-card').forEach(function(card){
                const id=(card.querySelector('.bcs-id')?.textContent||'').replace('#','').trim();if(!states[id])return;
                const badge=document.createElement('p');badge.style.fontWeight='700';badge.style.margin='8px 0 0';
                badge.style.color=states[id].complete?'#16803c':'#b32d2e';badge.textContent=states[id].complete?'✓ E-mail i SMS kompletne':(!states[id].email?'✕ Brak kompletnego e-maila':'✕ Brak treści SMS');
                card.querySelector('.bcs-template-content')?.appendChild(badge);
            });
            <?php if (str_starts_with($edit,'emails:')): $key=substr($edit,7); ?>
            const form=document.querySelector('.bcs-template-editor form');if(!form)return;
            let smsField=form.querySelector('textarea[name="template_sms"]');
            if(!smsField){
                const hidden=form.querySelector('input[name="template_sms"]');if(hidden)hidden.remove();
                const actions=form.querySelector('.bcs-template-help');const block=document.createElement('div');block.className='bcs-editor-block';
                block.innerHTML='<h2>Treść SMS</h2><textarea class="large-text" rows="5" name="template_sms"></textarea><p class="description">Szablon SMS jest zawsze edytowalny, niezależnie od aktualnego ustawienia kanału. Polskie znaki są zamieniane na ASCII, a linki usuwane podczas zapisu.</p>';
                actions?.parentNode.insertBefore(block,actions);smsField=block.querySelector('textarea');
            }
            if(smsField && smsField.value==='')smsField.value=<?php echo wp_json_encode((string)($sms[$key]??'')); ?>;
            <?php endif; ?>
        });
        </script>
        <?php
    }
}
