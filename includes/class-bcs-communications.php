<?php
if (!defined('ABSPATH')) exit;

class BCS_Communications {
    private static array $last_send_result = [];

    public static function last_send_result(): array { return self::$last_send_result; }
    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'handle_admin_actions']);
        add_action('bcs_daily_communications', [__CLASS__, 'run_automations']);
        if (!wp_next_scheduled('bcs_daily_communications')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'bcs_daily_communications');
        }
    }

    public static function menu(): void {
        add_submenu_page('bcs-dashboard', 'Komunikacja', 'Komunikacja', 'manage_options', 'bcs-communications', [__CLASS__, 'page']);
    }

    public static function default_templates(): array {
        return [
            'registration_received' => [
                'name'=>'Przyjęcie pierwszego zgłoszenia',
                'subject'=>'Otrzymaliśmy zgłoszenie – {{CAMP_NAME}}',
                'body'=>'Dzień dobry {{PARENT_NAME}},<br><br>dziękujemy za wstępną rejestrację uczestnika <strong>{{CHILD_NAME}}</strong> na turnus <strong>{{CAMP_NAME}}</strong>.<br><br>Czekamy teraz na wypełnienie pełnego Formularza Obozowego. Formularz jest już otwarty w Panelu Rodzica i można go uzupełnić od razu.<br><br><a href="{{PORTAL_URL}}" style="display:inline-block;background:#f97316;color:#ffffff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:700">Wypełnij Formularz Obozowy</a><br><br>Termin: {{CAMP_DATES}}<br>Miejsce: {{CAMP_LOCATION}}<br><br>Pozdrawiamy<br>Basketmania Camp',
                'sms'=>'',
            ],
            'camp_form_request' => [
                'name'=>'Uzupełnienie formularza obozowego',
                'subject'=>'Uzupełnij formularz obozowy – {{CAMP_NAME}}',
                'body'=>'Dzień dobry {{PARENT_NAME}},<br><br>rejestracja {{CHILD_NAME}} na turnus <strong>{{CAMP_NAME}}</strong> została potwierdzona.<br><br>Prosimy o uzupełnienie formularza obozowego w panelu rodzica. Formularz zawiera dane niezbędne do przygotowania dokumentów i udziału dziecka w obozie.<br><br><a href="{{PORTAL_URL}}" style="display:inline-block;background:#f97316;color:#ffffff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:700">Uzupełnij formularz obozowy</a><br><br>Termin turnusu: {{CAMP_DATES}}<br>Miejsce: {{CAMP_LOCATION}}<br><br>Pozdrawiamy<br>Basketmania Camp',
                'sms'=>'Basketmania Camp: rejestracja {{CHILD_NAME}} zostala potwierdzona. Formularz obozowy uzupelnisz w panelu rodzica. Wiecej informacji w wiadomosci e-mail.',
            ],
            'camp_form_verified' => [
                'name'=>'Potwierdzenie poprawności formularza obozowego',
                'subject'=>'Formularz obozowy został potwierdzony – {{CAMP_NAME}}',
                'body'=>'Dzień dobry {{PARENT_NAME}},<br><br>Informujemy, że dane w formularzu obozowym uczestnika <strong>{{CHILD_NAME}}</strong> zostały sprawdzone i potwierdzone przez Organizatora Basketmania Camp.<br><br>Wzór umowy jest już dostępny w panelu rodzica. Kolejne informacje dotyczące podpisania umowy otrzymają Państwo w następnym etapie.<br><br><a href="{{PORTAL_URL}}" style="display:inline-block;background:#f97316;color:#ffffff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:700">Otwórz panel rodzica</a><br><br>Turnus: {{CAMP_NAME}}<br>Termin: {{CAMP_DATES}}<br>Miejsce: {{CAMP_LOCATION}}',
                'sms'=>'Basketmania Camp: formularz obozowy {{CHILD_NAME}} został potwierdzony przez Organizatora.',
            ],
            'draft_agreement' => [
                'name'=>'Potwierdzenie rejestracji i draft umowy',
                'subject'=>'Rejestracja {{CHILD_NAME}} została potwierdzona',
                'body'=>'Dzień dobry {{PARENT_NAME}},<br><br>potwierdzamy rejestrację {{CHILD_NAME}} na {{CAMP_NAME}}. W panelu rodzica dostępny jest draft umowy z danymi, bez daty zawarcia. Właściwa umowa zostanie udostępniona do akceptacji od {{AGREEMENT_AVAILABLE_FROM}}.<br><br>{{PORTAL_URL}}',
                'sms'=>'',
            ],
            'agreement_sent' => [
                'name'=>'Właściwa umowa do akceptacji',
                'subject'=>'Umowa {{AGREEMENT_NUMBER}} do akceptacji',
                'body'=>'Dzień dobry {{PARENT_NAME}},<br><br>umowa dotycząca udziału {{CHILD_NAME}} w {{CAMP_NAME}} jest gotowa do akceptacji kodem SMS.<br><br><a href="{{PORTAL_URL}}" style="display:inline-block;background:#f97316;color:#ffffff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:700">Podpisz umowę w Panelu Rodzica</a>',
                'sms'=>'Basketmania Camp: umowa {{AGREEMENT_NUMBER}} jest gotowa do podpisu. Otworz panel rodzica, przeczytaj umowe i potwierdz podpis kodem SMS.',
            ],
            'agreement_signed' => [
                'name'=>'Podpisanie umowy i dane do płatności',
                'subject'=>'Umowa {{AGREEMENT_NUMBER}} została podpisana – dziękujemy za zaufanie',
                'body'=>'Dzień dobry {{PARENT_NAME}},<br><br>dziękujemy za podpisanie umowy dotyczącej udziału <strong>{{CHILD_NAME}}</strong> w turnusie <strong>{{CAMP_NAME}}</strong>. Dziękujemy za zaufanie i cieszymy się, że dołączacie do Basketmania Camp.<br><br><a href="{{SIGNED_AGREEMENT_URL}}" style="display:inline-block;background:#f97316;color:#ffffff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:700">Pobierz podpisaną umowę</a><br><br><strong>Warunki płatności</strong><br>Zgodnie z zawartą umową prosimy o wykonanie przelewu w terminie do <strong>{{PAYMENT_DUE_DATE}}</strong>.<br><br>Kwota do zapłaty: <strong>{{AMOUNT_DUE}} zł</strong><br><br><strong>Dane odbiorcy przelewu:</strong><br>Pełna nazwa: {{ORGANIZER_NAME}}<br>Adres siedziby: {{ORGANIZER_ADDRESS}}<br>NIP: {{ORGANIZER_NIP}}<br>Nazwa banku: {{BANK_NAME}}<br>Numer konta: <strong>{{BANK_ACCOUNT}}</strong><br>Tytuł przelewu: <strong>{{TRANSFER_TITLE}}</strong><br><br>Prosimy o wykonanie przelewu w terminie wskazanym powyżej. Po zaksięgowaniu wpłaty otrzymają Państwo potwierdzenie.<br><br>Pozdrawiamy<br>Zespół Basketmania Camp',
                'sms'=>'',
            ],
            'stripe_link' => [
                'name'=>'Indywidualny link Stripe',
                'subject'=>'Link do płatności online za {{CAMP_NAME}}',
                'body'=>'Dzień dobry {{PARENT_NAME}},<br><br>zgodnie z prośbą przesyłamy indywidualny link do płatności online za udział <strong>{{CHILD_NAME}}</strong> w <strong>{{CAMP_NAME}}</strong>.<br><br><a href="{{STRIPE_URL}}" style="display:inline-block;background:#f97316;color:#ffffff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:700">Zapłać online przez Stripe</a><br><br>Kwota: <strong>{{AMOUNT_DUE}} zł</strong>.<br><br>Pozdrawiamy<br>Basketmania Camp',
                'sms'=>'',
            ],
            'reservation' => [
                'name' => 'Potwierdzenie rezerwacji',
                'subject' => 'Potwierdź rezerwację {{CAMP_NAME}}',
                'body' => "Dzień dobry {{PARENT_NAME}},\n\nzgłoszenie {{CHILD_NAME}} na {{CAMP_NAME}} zostało zapisane. Aby potwierdzić rezerwację, otwórz panel rodzica i potwierdź umowę kodem SMS:\n{{PORTAL_URL}}\n\nBasketmania Camp",
                'sms' => '',
            ],
            'payment' => [
                'name' => 'Przypomnienie o płatności',
                'subject' => 'Przypomnienie o płatności za {{CAMP_NAME}}',
                'body' => 'Dzień dobry {{PARENT_NAME}},<br><br>przypominamy, że zgodnie z podpisaną umową oczekujemy na wpłatę kwoty <strong>{{AMOUNT_DUE}} zł</strong> za udział {{CHILD_NAME}} w {{CAMP_NAME}}.<br><br>Termin płatności: {{PAYMENT_DUE_DATE}}<br><br><strong>Dane odbiorcy przelewu:</strong><br>Pełna nazwa: {{ORGANIZER_NAME}}<br>Adres siedziby: {{ORGANIZER_ADDRESS}}<br>NIP: {{ORGANIZER_NIP}}<br>Nazwa banku: {{BANK_NAME}}<br>Numer konta: <strong>{{BANK_ACCOUNT}}</strong>.<br><br>Pozdrawiamy<br>Basketmania Camp',
                'sms' => 'Basketmania Camp: przypominamy o platnosci za udzial {{CHILD_NAME}} w {{CAMP_NAME}}. Kwota do zaplaty: {{AMOUNT_DUE}} zl. Dane do przelewu wyslalismy e-mailem.',
            ],
            'pre_camp' => [
                'name' => 'Informacje przed rozpoczęciem obozu — 7 dni',
                'subject' => 'Informacje przed {{CAMP_NAME}}',
                'body' => 'Dzień dobry {{PARENT_NAME}},<br><br>przesyłamy najważniejsze informacje organizacyjne przed turnusem <strong>{{CAMP_NAME}}</strong>.<br><br>Termin: {{CAMP_DATES}}<br>Miejsce: {{CAMP_LOCATION}}<br>Godzina przyjazdu: {{GODZINA_PRZYJAZDU}}<br><br><strong>Co należy zabrać:</strong><br>{{LISTA_RZECZY}}<br><br><strong>Kontakt:</strong> {{KONTAKT}}<br><br>W załączniku przesyłamy Regulamin Obozu. Prosimy o zapoznanie się z dokumentem przed rozpoczęciem turnusu.<br><br>Pozdrawiamy<br>Basketmania Camp',
                'sms' => '',
            ],
            'invoice_issued' => [
                'name' => 'Wystawienie faktury',
                'subject' => 'Faktura {{INVOICE_NUMBER}} – {{CAMP_NAME}}',
                'body' => 'Dzień dobry {{PARENT_NAME}},<br><br>została wygenerowana faktura <strong>{{INVOICE_NUMBER}}</strong> dotycząca udziału {{CHILD_NAME}} w {{CAMP_NAME}} na kwotę <strong>{{INVOICE_AMOUNT}} zł</strong>.<br><br>Dokument PDF znajduje się w załączniku oraz jest dostępny w Panelu Rodzica.<br><br><a href="{{INVOICE_URL}}" style="display:inline-block;background:#f57618;color:#fff;padding:13px 20px;border-radius:9px;text-decoration:none;font-weight:700">Pobierz fakturę</a><br><br>Pozdrawiamy<br>Basketmania Camp',
                'sms' => 'Zostala wygenerowana faktura do zgloszenia {{CHILD_NAME}} na {{CAMP_NAME}}. Prosze sprawdzic skrzynke pocztowa.',
            ],
            'paid' => [
                'name' => 'Potwierdzenie opłacenia',
                'subject' => 'Udział w {{CAMP_NAME}} został opłacony',
                'body' => "Dzień dobry {{PARENT_NAME}},\n\npotwierdzamy opłacenie udziału {{CHILD_NAME}} w {{CAMP_NAME}}. Pakiet dokumentów został dołączony do wiadomości i jest dostępny w panelu rodzica:\n{{PORTAL_URL}}\n\nBasketmania Camp",
                'sms' => '',
            ],
        ];
    }

    public static function templates(): array {
        if (class_exists('BCS_Templates')) {
            $all = BCS_Template_Engine::all();
            return (array)($all['emails'] ?? self::default_templates());
        }
        $saved = get_option('bcs_message_templates', []);
        return array_replace_recursive(self::default_templates(), is_array($saved) ? $saved : []);
    }

    public static function handle_admin_actions(): void {
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['bcs_save_templates'])) {
            check_admin_referer('bcs_save_templates');
            $defaults = self::default_templates(); $out = [];
            foreach ($defaults as $key => $template) {
                $out[$key] = [
                    'name' => $template['name'],
                    'subject' => sanitize_text_field(wp_unslash($_POST['tpl'][$key]['subject'] ?? $template['subject'])),
                    'body' => wp_kses_post(wp_unslash($_POST['tpl'][$key]['body'] ?? $template['body'])),
                    'sms' => BCS_SMS::strip_links(BCS_SMS::to_ascii(sanitize_textarea_field(wp_unslash($_POST['tpl'][$key]['sms'] ?? $template['sms'])))),
                ];
            }
            update_option('bcs_message_templates', $out);
            wp_safe_redirect(admin_url('admin.php?page=bcs-communications&saved=1')); exit;
        }
        if (isset($_POST['bcs_send_message'])) {
            check_admin_referer('bcs_send_message');
            $ids = array_values(array_filter(array_map('absint', (array)($_POST['registration_ids'] ?? []))));
            $channel = in_array($_POST['channel'] ?? '', ['email','sms','both'], true) ? $_POST['channel'] : 'email';
            $template_key = sanitize_key($_POST['template_key'] ?? 'reservation');
            $custom_subject = sanitize_text_field(wp_unslash($_POST['custom_subject'] ?? ''));
            $custom_body = wp_kses_post(wp_unslash($_POST['custom_body'] ?? ''));
            $sent = 0; $failed = 0;
            foreach ($ids as $id) {
                $result = self::send_to_registration($id, $template_key, $channel, $custom_subject, $custom_body, false);
                $result ? $sent++ : $failed++;
            }
            wp_safe_redirect(add_query_arg(['page'=>'bcs-communications','sent'=>$sent,'failed'=>$failed], admin_url('admin.php'))); exit;
        }
    }

    public static function registration_context(int $registration_id): ?array {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, c.name camp_name, c.start_date, c.end_date, c.location, a.agreement_number, o.name organizer_name,o.address organizer_address,o.nip organizer_nip,o.bank_account,o.bank_name,o.transfer_title_template
             FROM ".BCS_DB::table('registrations')." r
             JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
             LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
             LEFT JOIN ".BCS_DB::table('organizers')." o ON o.id=c.organizer_id WHERE r.id=%d", $registration_id));
        if (!$r) return null;
        $portal_page = get_page_by_path('panel-rodzica');
        $portal = add_query_arg(['token'=>$r->public_token,'edit'=>'camp'], $portal_page ? get_permalink($portal_page) : home_url('/panel-rodzica/'));
        return [
            'row' => $r,
            'vars' => [
                '{{PARENT_NAME}}' => trim($r->parent_first_name.' '.$r->parent_last_name),
                '{{CHILD_NAME}}' => trim($r->child_first_name.' '.$r->child_last_name),
                '{{CAMP_NAME}}' => $r->camp_name,
                '{{CAMP_DATES}}' => trim($r->start_date.' – '.$r->end_date),
                '{{CAMP_LOCATION}}' => $r->location,
                '{{DATA_OD}}' => $r->start_date ? wp_date('d.m.Y', strtotime($r->start_date)) : '',
                '{{DATA_DO}}' => $r->end_date ? wp_date('d.m.Y', strtotime($r->end_date)) : '',
                '{{GODZINA_PRZYJAZDU}}' => 'zgodnie z informacją Organizatora',
                '{{LISTA_RZECZY}}' => 'strój sportowy, obuwie na halę, rzeczy osobiste i dokumenty wskazane przez Organizatora',
                '{{KONTAKT}}' => trim((string)$r->organizer_name),
                '{{PORTAL_URL}}' => $portal,
                '{{AMOUNT_DUE}}' => number_format(max(0, (float)$r->total_amount - (float)$r->paid_amount), 2, ',', ' '),
                '{{TOTAL_AMOUNT}}' => number_format((float)$r->total_amount, 2, ',', ' '),
                '{{AGREEMENT_NUMBER}}' => (string)$r->agreement_number,
                '{{ORGANIZER_NAME}}' => (string)$r->organizer_name,
                '{{ORGANIZER_ADDRESS}}' => nl2br(esc_html((string)$r->organizer_address)),
                '{{ORGANIZER_NIP}}' => (string)$r->organizer_nip,
                '{{BANK_ACCOUNT}}'=>BCS_Utils::format_bank_account((string)$r->bank_account),
                '{{BANK_NAME}}'=>(string)$r->bank_name,
                '{{PAYMENT_DUE_DATE}}'=>$r->payment_due_date?wp_date('d.m.Y',strtotime($r->payment_due_date)):'',
                '{{AGREEMENT_AVAILABLE_FROM}}'=>$r->agreement_available_from?wp_date('d.m.Y',strtotime($r->agreement_available_from)):'',
                '{{STRIPE_URL}}'=>'',
                '{{SIGNED_AGREEMENT_URL}}'=>BCS_Document_Engine::download_url((int)$r->id,'agreement_signed'),
                '{{INVOICE_URL}}'=>BCS_Document_Engine::download_url((int)$r->id,'invoice'),
                '{{TRANSFER_TITLE}}'=>self::transfer_title($r),
            ],
        ];
    }

    private static function transfer_title(object $r): string {
        $template=trim((string)($r->transfer_title_template ?? ''));
        if($template==='') $template='Basketmania Camp - {{CHILD_NAME}} - {{CAMP_NAME}}';
        return strtr($template,[
            '{{CHILD_NAME}}'=>trim((string)$r->child_first_name.' '.(string)$r->child_last_name),
            '{{PARENT_NAME}}'=>trim((string)$r->parent_first_name.' '.(string)$r->parent_last_name),
            '{{CAMP_NAME}}'=>(string)$r->camp_name,
            '{{AGREEMENT_NUMBER}}'=>(string)$r->agreement_number,
            '{{REGISTRATION_ID}}'=>(string)$r->id,
        ]);
    }

    public static function render(string $text, array $vars): string { return strtr($text, $vars); }

    public static function send_to_registration(int $registration_id, string $template_key, string $channel='email', string $custom_subject='', string $custom_body='', bool $with_package=false): bool {
        $context = self::registration_context($registration_id);
        $templates = self::templates();
        if (!$context || empty($templates[$template_key])) return false;
        $r = $context['row']; $tpl = $templates[$template_key]; $vars = $context['vars'];
        $subject = self::render($custom_subject !== '' ? $custom_subject : $tpl['subject'], $vars);
        $body = self::render($custom_body !== '' ? $custom_body : $tpl['body'], $vars);
        if ($template_key === 'payment' && stripos(wp_strip_all_tags($body), (string)$r->organizer_nip) === false) {
            $body .= '<br><br><strong>Dane odbiorcy przelewu:</strong><br>Pełna nazwa: '.esc_html((string)$r->organizer_name).'<br>Adres siedziby: '.nl2br(esc_html((string)$r->organizer_address)).'<br>NIP: '.esc_html((string)$r->organizer_nip).'<br>Nazwa banku: '.esc_html((string)$r->bank_name).'<br>Numer konta: <strong>'.esc_html(BCS_Utils::format_bank_account((string)$r->bank_account)).'</strong>.';
        }
        $sms = self::render($tpl['sms'], $vars);
        // SMS-y nie zawierają linków. Pełne informacje i odnośniki pozostają w wiadomości e-mail.
        $sms = BCS_SMS::strip_links($sms);
        if ($sms === '') $sms = 'Basketmania Camp: masz nowa wiadomosc dotyczaca zgloszenia. Wiecej informacji w wiadomosci e-mail.';
        $ok = true; $email_ok = null; $sms_ok = null; $attachments = [];
        if ($with_package) $attachments = BCS_Document_Engine::build_package_attachments($registration_id);
        if ($template_key === 'pre_camp') {
            $regulations = BCS_Document_Engine::regulations_pdf($registration_id);
            if ($regulations && file_exists($regulations)) $attachments[] = $regulations;
        }
        if (in_array($channel, ['email','both'], true)) {
            $email_ok = BCS_Mailer::send($r->parent_email, $subject, $body, [], $attachments, $registration_id);
            $ok = $ok && $email_ok;
        }
        if (in_array($channel, ['sms','both'], true)) {
            $result = BCS_SMS::send($r->parent_phone, $sms);
            $sms_ok = !empty($result['success']); $ok = $ok && $sms_ok;
        }
        self::$last_send_result = [
            'registration_id'=>$registration_id,
            'template'=>$template_key,
            'channel'=>$channel,
            'success'=>$ok,
            'email'=>$email_ok,
            'email_error'=>$email_ok===false?BCS_Mailer::last_error():'',
            'email_transport'=>BCS_Mailer::transport_label(),
            'sms'=>$sms_ok,
            'sms_provider'=>BCS_SMS::provider_label(),
            'sms_error'=>$sms_ok===false?(string)(get_option('bcs_last_sms_result',[])['error']??''):'',
        ];
        self::record($registration_id, $template_key, $channel, $subject, $body, $email_ok, $sms_ok, $ok ? 'sent' : 'failed');
        BCS_Utils::log('communication_sent', ['template'=>$template_key,'channel'=>$channel,'success'=>$ok,'email_success'=>$email_ok,'email_error'=>$email_ok===false?BCS_Mailer::last_error():'','sms_success'=>$sms_ok,'sms_provider'=>BCS_SMS::provider_label(),'sms_error'=>$sms_ok===false?(string)(get_option('bcs_last_sms_result',[])['error']??''):'' ], $registration_id, (int)$r->agreement_id);
        return $ok;
    }

    private static function record(int $registration_id, string $template_key, string $channel, string $subject, string $body, ?bool $email_ok, ?bool $sms_ok, string $status): void {
        global $wpdb;
        $wpdb->insert(BCS_DB::table('messages'), [
            'registration_id'=>$registration_id, 'template_key'=>$template_key, 'channel'=>$channel,
            'subject'=>$subject, 'body'=>$body, 'email_status'=>$email_ok === null ? null : ($email_ok?'sent':'failed'),
            'sms_status'=>$sms_ok === null ? null : ($sms_ok?'sent':'failed'), 'status'=>$status,
            'sent_by'=>get_current_user_id() ?: null, 'created_at'=>BCS_Utils::now(),
        ]);
    }

    public static function run_automations(): void {
        global $wpdb;
        $settings = get_option('bcs_settings', []);
        if (empty($settings['automations_enabled'])) return;
        $rows = $wpdb->get_results("SELECT r.id, r.agreement_status, r.total_amount, r.paid_amount, r.created_at, c.start_date
            FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
            WHERE r.status NOT IN ('cancelled')");
        $agreement_after = max(1, absint($settings['agreement_reminder_days'] ?? 1));
        $payment_after = max(1, absint($settings['payment_reminder_days'] ?? 2));
        $pre_days = max(1, absint($settings['pre_camp_days'] ?? 7));
        foreach ($rows as $r) {
            $age_days = floor((time() - strtotime($r->created_at.' UTC')) / DAY_IN_SECONDS);
            if ($r->agreement_status !== 'accepted' && $age_days >= $agreement_after) self::send_once((int)$r->id, 'auto_reservation', 'reservation');
            if ($r->agreement_status === 'accepted' && (float)$r->paid_amount < (float)$r->total_amount && $age_days >= $payment_after) self::send_once((int)$r->id, 'auto_payment', 'payment');
            $days_to = (int)ceil((strtotime($r->start_date.' 00:00:00') - BCS_Utils::timestamp()) / DAY_IN_SECONDS);
            if ($days_to >= 0 && $days_to <= $pre_days && (float)$r->paid_amount >= (float)$r->total_amount) self::send_once((int)$r->id, 'auto_pre_camp', 'pre_camp');
        }
    }

    private static function send_once(int $registration_id, string $event, string $template): void {
        global $wpdb;
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".BCS_DB::table('logs')." WHERE registration_id=%d AND event_type=%s", $registration_id, $event));
        if ($exists) return;
        $settings = get_option('bcs_settings', []);
        $channel = in_array($settings['automation_channel'] ?? '', ['email','sms','both'], true) ? $settings['automation_channel'] : 'email';
        if (self::send_to_registration($registration_id, $template, $channel)) BCS_Utils::log($event, [], $registration_id, null);
    }

    public static function page(): void {
        global $wpdb;
        $filter = sanitize_key($_GET['status_filter'] ?? 'all');
        $camp_id = absint($_GET['camp_id'] ?? 0);
        $where = ["r.status NOT IN ('cancelled')"]; $args = [];
        if ($filter === 'paid') $where[] = 'r.paid_amount >= r.total_amount';
        if ($filter === 'payment_due') $where[] = "r.agreement_status='accepted' AND r.paid_amount < r.total_amount";
        if ($filter === 'agreement_pending') $where[] = "r.agreement_status<>'accepted'";
        if ($camp_id) { $where[] = 'r.camp_id=%d'; $args[] = $camp_id; }
        $sql = "SELECT r.*, c.name camp_name, a.agreement_number FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE ".implode(' AND ', $where)." ORDER BY r.created_at DESC";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
        $camps = $wpdb->get_results("SELECT id,name FROM ".BCS_DB::table('camps')." ORDER BY start_date DESC");
        $templates = self::templates();
        echo '<div class="wrap"><h1>Komunikacja z rodzicami</h1>';
        if (isset($_GET['sent'])) echo '<div class="notice notice-success"><p>Wysłano: '.absint($_GET['sent']).'. Błędy: '.absint($_GET['failed'] ?? 0).'.</p></div>';
        echo '<form method="get"><input type="hidden" name="page" value="bcs-communications"><select name="status_filter">';
        foreach (['all'=>'Wszyscy','paid'=>'Opłaceni','payment_due'=>'Po umowie, przed płatnością','agreement_pending'=>'Przed zatwierdzeniem umowy'] as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($filter,$k,false).'>'.esc_html($v).'</option>';
        echo '</select> <select name="camp_id"><option value="0">Wszystkie turnusy</option>'; foreach($camps as $c) echo '<option value="'.$c->id.'" '.selected($camp_id,$c->id,false).'>'.esc_html($c->name).'</option>'; echo '</select> <button class="button">Filtruj</button></form><hr>';
        echo '<form method="post">'; wp_nonce_field('bcs_send_message');
        echo '<p><select name="template_key">'; foreach($templates as $k=>$tpl) echo '<option value="'.esc_attr($k).'">'.esc_html($tpl['name']).'</option>'; echo '</select> <select name="channel"><option value="email">E-mail</option><option value="sms">SMS</option><option value="both">E-mail + SMS</option></select></p>';
        echo '<p><input class="large-text" name="custom_subject" placeholder="Opcjonalny własny temat e-mail"></p><div class="bcs-editor-block"><h3>Opcjonalna własna treść e-mail</h3><p class="description">Pozostaw puste, aby użyć wybranego szablonu.</p>';
        wp_editor('', 'bcs_custom_message_body', ['textarea_name'=>'custom_body','textarea_rows'=>10,'media_buttons'=>true,'teeny'=>false,'quicktags'=>true,'tinymce'=>['toolbar1'=>'formatselect,bold,italic,underline,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo','toolbar2'=>'forecolor,backcolor,pastetext,removeformat,charmap,outdent,indent,hr,fullscreen']]);
        echo '</div>';
        echo '<table class="widefat striped"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll(\'.bcs-recipient\').forEach(x=>x.checked=this.checked)"></th><th>Rodzic / dziecko</th><th>Turnus</th><th>Umowa</th><th>Płatność</th></tr></thead><tbody>';
        foreach($rows as $r) echo '<tr><td><input class="bcs-recipient" type="checkbox" name="registration_ids[]" value="'.$r->id.'"></td><td>'.esc_html($r->parent_first_name.' '.$r->parent_last_name).'<br><small>'.esc_html($r->parent_email.' / '.$r->parent_phone).'</small><br>'.esc_html($r->child_first_name.' '.$r->child_last_name).'</td><td>'.esc_html($r->camp_name).'</td><td>'.esc_html($r->agreement_status).'</td><td>'.esc_html(number_format((float)$r->paid_amount,2,',',' ').' / '.number_format((float)$r->total_amount,2,',',' ')).' zł</td></tr>';
        echo '</tbody></table><p><button class="button button-primary" name="bcs_send_message" value="1">Wyślij do zaznaczonych</button></p></form>';
        echo '<hr><h2>Szablony wiadomości</h2><form method="post">'; wp_nonce_field('bcs_save_templates');
        foreach($templates as $k=>$tpl) {
            echo '<section class="bcs-panel bcs-template-editor"><h3>'.esc_html($tpl['name']).'</h3><p><label><strong>Temat e-mail</strong><input class="large-text" name="tpl['.$k.'][subject]" value="'.esc_attr($tpl['subject']).'"></label></p><h4>Treść e-mail</h4>';
            wp_editor((string)$tpl['body'], 'bcs_tpl_body_'.$k, ['textarea_name'=>'tpl['.$k.'][body]','textarea_rows'=>12,'media_buttons'=>true,'teeny'=>false,'quicktags'=>true,'tinymce'=>['toolbar1'=>'formatselect,bold,italic,underline,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo','toolbar2'=>'forecolor,backcolor,pastetext,removeformat,charmap,outdent,indent,hr,fullscreen']]);
            echo '<h4>Treść SMS</h4><textarea class="large-text" rows="3" name="tpl['.$k.'][sms]">'.esc_textarea($tpl['sms']).'</textarea><p class="description">SMS pozostaje tekstem prostym; linki i polskie znaki są usuwane przed wysyłką.</p></section>';
        }
        echo '<p>Zmienne: <code>{{PARENT_NAME}}</code>, <code>{{CHILD_NAME}}</code>, <code>{{CAMP_NAME}}</code>, <code>{{CAMP_DATES}}</code>, <code>{{PORTAL_URL}}</code>, <code>{{AMOUNT_DUE}}</code>, <code>{{PRE_CAMP_INFO}}</code>.</p><button class="button" name="bcs_save_templates" value="1">Zapisz szablony</button></form></div>';
    }
}
