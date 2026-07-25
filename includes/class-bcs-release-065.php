<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_065 {
    private const MIGRATION_OPTION = 'bcs_release_065_log_duplicates_cleaned';
    private const SCRIPT_HANDLE = 'bcs-shirt-size-select-065';

    public static function init(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_front_assets'], 99);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets'], 99);
        add_action('admin_menu', [__CLASS__, 'replace_logs_page'], 999);
        add_action('admin_init', [__CLASS__, 'migrate_historical_duplicates'], 3);
        register_shutdown_function([__CLASS__, 'cleanup_recent_duplicates']);
    }

    public static function shirt_sizes(): array {
        return [
            '128-134',
            '134-140',
            '140-146',
            '146-152',
            '152-158',
            '158-164',
            'S-164-170',
            'M-170-176',
            'L-176-182',
            'XL-182-188',
            '2XL-188-194',
            '3XL-194-200',
        ];
    }

    private static function enqueue_script(): void {
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            BCS_URL.'assets/js/shirt-size-select-065.js',
            [],
            BCS_VERSION,
            true
        );
        wp_localize_script(self::SCRIPT_HANDLE, 'BCSShirtSizes065', [
            'sizes'=>self::shirt_sizes(),
            'placeholder'=>'Wybierz rozmiar',
        ]);
    }

    public static function enqueue_front_assets(): void {
        self::enqueue_script();
    }

    public static function enqueue_admin_assets(string $hook): void {
        if (strpos($hook, 'bcs-') === false) return;
        self::enqueue_script();
    }

    public static function replace_logs_page(): void {
        remove_submenu_page('bcs-dashboard', 'bcs-logs');
        add_submenu_page(
            'bcs-dashboard',
            'Logi',
            'Logi',
            'manage_options',
            'bcs-logs',
            [__CLASS__, 'logs_page']
        );
    }

    public static function event_labels(): array {
        return [
            'registration_created'=>'Utworzono zgłoszenie',
            'registration_created_from_email'=>'Utworzono zgłoszenie z wiadomości e-mail',
            'registration_admin_confirmed'=>'Administrator potwierdził rejestrację',
            'registration_edit_lock_started'=>'Administrator otworzył Kartę Zgłoszenia i zablokował edycję rodzica',
            'registration_edited_by_admin'=>'Administrator zmienił dane zgłoszenia',
            'registration_cancelled'=>'Anulowano zgłoszenie',
            'registration_deleted'=>'Usunięto zgłoszenie',
            'parent_portal_invite_sent'=>'Wysłano rodzicowi dostęp do Panelu Rodzica',
            'parent_form_save_blocked'=>'Odrzucono zapis Formularza Obozowego z powodu aktywnej blokady',
            'parent_form_completed'=>'Rodzic przesłał Formularz Obozowy',
            'parent_form_updated'=>'Rodzic zaktualizował Formularz Obozowy',
            'camp_form_verified'=>'Organizator potwierdził poprawność Formularza Obozowego',
            'form_verification_failed'=>'Nie udało się potwierdzić Formularza Obozowego',
            'agreement_draft_created'=>'Utworzono draft umowy',
            'agreement_created'=>'Utworzono umowę',
            'agreement_draft_edited'=>'Administrator zmienił treść draftu umowy',
            'agreement_sent_by_admin'=>'Administrator wysłał umowę do podpisu',
            'agreement_signature_reminder_sent'=>'Wysłano przypomnienie o podpisaniu umowy',
            'agreement_opened_for_signature'=>'Rodzic otworzył umowę do podpisu',
            'agreement_accepted'=>'Rodzic podpisał umowę kodem SMS',
            'agreement_document_repaired_050'=>'Naprawiono zapisany dokument umowy',
            'agreement_final_document_failed_050'=>'Nie udało się przygotować finalnego dokumentu umowy',
            'organizer_agreement_otp_sent'=>'Wysłano Organizatorowi kod SMS do podpisania umowy',
            'organizer_agreement_otp_verified'=>'Organizator podpisał umowę kodem SMS',
            'organizer_agreement_otp_failed'=>'Nie udało się potwierdzić podpisu Organizatora',
            'otp_send_requested'=>'Rozpoczęto wysyłkę kodu SMS do podpisania umowy',
            'otp_sent'=>'Wysłano kod SMS do podpisania umowy',
            'otp_send_failed'=>'Nie udało się wysłać kodu SMS do podpisania umowy',
            'otp_send_blocked_admin_lock'=>'Zablokowano wysyłkę kodu SMS z powodu pracy administratora',
            'otp_send_blocked_active_code'=>'Zablokowano wysyłkę nowego kodu SMS, ponieważ poprzedni kod jest nadal aktywny',
            'otp_send_blocked_hourly_limit'=>'Zablokowano wysyłkę kodu SMS z powodu limitu godzinowego',
            'otp_invalid'=>'Wprowadzono nieprawidłowy kod SMS',
            'stripe_link_sent'=>'Wysłano link do płatności Stripe',
            'stripe_link_email_failed'=>'Nie udało się wysłać e-maila z linkiem Stripe',
            'stripe_payment_confirmed'=>'Potwierdzono płatność Stripe',
            'bank_payment_marked_paid'=>'Administrator zaksięgował wpłatę tradycyjną',
            'payment_confirmation_sent'=>'Wysłano potwierdzenie płatności',
            'payment_reminder_sent'=>'Wysłano przypomnienie o płatności',
            'invoice_created'=>'Wygenerowano fakturę',
            'invoice_generated_manually'=>'Administrator uruchomił generowanie faktury',
            'invoice_delivery'=>'Przekazano fakturę rodzicowi',
            'invoice_downloaded_by_parent'=>'Rodzic pobrał fakturę',
            'invoice_deleted'=>'Usunięto fakturę',
            'invoice_duplicate_generation_blocked'=>'Zablokowano ponowne wygenerowanie tej samej faktury',
            'invoice_number_generation_blocked'=>'Nie przydzielono numeru faktury, ponieważ trwa inne generowanie',
            'invoice_create_failed'=>'Nie udało się zapisać faktury',
            'document_downloaded'=>'Pobrano dokument',
            'document_download_denied'=>'Odrzucono próbę pobrania dokumentu',
            'communication_sent'=>'Wysłano wiadomość do rodzica',
            'communication_failed'=>'Nie udało się wysłać wiadomości do rodzica',
            'communication_duplicate_blocked'=>'Pominięto ponowną wysyłkę tej samej wiadomości',
            'communication_disabled_by_settings'=>'Pominięto wiadomość wyłączoną w ustawieniach',
            'communication_email_error'=>'Nie udało się wysłać wiadomości e-mail',
            'communication_sms_error'=>'Nie udało się wysłać wiadomości SMS',
            'email_send_result'=>'Zakończono wysyłkę wiadomości e-mail',
            'email_sent'=>'Wysłano wiadomość e-mail',
            'email_failed'=>'Nie udało się wysłać wiadomości e-mail',
            'sms_sent'=>'Wysłano wiadomość SMS',
            'sms_failed'=>'Nie udało się wysłać wiadomości SMS',
            'mailbox_reply'=>'Wysłano odpowiedź z modułu Poczta',
            'pdf_error'=>'Nie udało się wygenerować dokumentu PDF',
            'auto_agreement_reminder'=>'Automatycznie wysłano przypomnienie o podpisaniu umowy',
            'auto_agreement_reminder_failed'=>'Nie udało się automatycznie wysłać przypomnienia o podpisaniu umowy',
            'auto_agreement_reminder_skipped'=>'Pominięto automatyczne przypomnienie o podpisaniu umowy',
            'auto_payment'=>'Automatycznie wysłano przypomnienie o płatności',
            'auto_payment_failed'=>'Nie udało się automatycznie wysłać przypomnienia o płatności',
            'auto_payment_skipped'=>'Pominięto automatyczne przypomnienie o płatności',
            'auto_pre_camp'=>'Automatycznie wysłano informacje przed obozem',
            'auto_pre_camp_failed'=>'Nie udało się automatycznie wysłać informacji przed obozem',
            'auto_pre_camp_skipped'=>'Pominięto automatyczne informacje przed obozem',
            'auto_reservation'=>'Automatycznie wysłano przypomnienie dotyczące rezerwacji',
        ];
    }

    public static function event_label(string $event, array $data = []): string {
        $labels = self::event_labels();
        if ($event === 'email_send_result') {
            return !empty($data['success'])
                ? 'Wysłano wiadomość e-mail'
                : 'Nie udało się wysłać wiadomości e-mail';
        }
        if ($event === 'communication_sent' && array_key_exists('success', $data) && empty($data['success'])) {
            return 'Nie udało się wysłać wiadomości do rodzica';
        }
        if (isset($labels[$event])) return $labels[$event];

        if (str_starts_with($event, 'crm_')) {
            $crm = substr($event, 4);
            return match ($crm) {
                'invoice' => 'Wygenerowano fakturę',
                'email' => 'Wysłano wiadomość e-mail',
                'payment' => 'Zaksięgowano wpłatę',
                'phone' => 'Odnotowano rozmowę telefoniczną',
                'note' => 'Dodano notatkę do zgłoszenia',
                'task' => 'Dodano zadanie do zgłoszenia',
                'portal_invite' => 'Wysłano dostęp do Panelu Rodzica',
                'registration_cancelled' => 'Anulowano zgłoszenie',
                'agreement_draft_edited' => 'Zmieniono draft umowy',
                default => 'Wykonano działanie w CRM',
            };
        }

        // Nie pokazujemy użytkownikowi surowej, angielskiej nazwy technicznej.
        return 'Zdarzenie systemowe';
    }

    private static function log_data(object $row): array {
        $data = json_decode((string)($row->event_data ?? ''), true);
        return is_array($data) ? $data : [];
    }

    private static function log_family(object $row): string {
        $event = (string)$row->event_type;
        $data = self::log_data($row);
        $subject = mb_strtolower((string)($data['subject'] ?? ''), 'UTF-8');
        $template = (string)($data['template'] ?? '');

        if (in_array($event, ['invoice_created','invoice_generated_manually','crm_invoice'], true)) return 'invoice_generation';
        if ($event === 'invoice_delivery' || $template === 'invoice_issued' || str_contains($subject, 'faktura')) return 'invoice_delivery';
        if ($event === 'parent_portal_invite_sent' || $template === 'camp_form_request') return 'portal_invite';
        if ($event === 'camp_form_verified' || $template === 'camp_form_verified') return 'form_verification';
        if (in_array($event, ['agreement_sent_by_admin','agreement_signature_reminder_sent'], true) || in_array($template, ['agreement_sent','agreement_reminder'], true)) return 'agreement_delivery';
        if ($event === 'stripe_link_sent' || $template === 'stripe_link' || str_contains($subject, 'stripe')) return 'stripe_link';
        if ($event === 'payment_reminder_sent' || $template === 'payment') return 'payment_reminder';
        if ($event === 'payment_confirmation_sent' || $template === 'paid') return 'payment_confirmation';
        if (in_array($event, ['bank_payment_marked_paid','crm_payment'], true)) return 'payment_booking';
        if (in_array($event, ['communication_sent','communication_failed','email_send_result','communication_email_error','crm_email'], true)) return 'email_delivery';
        return $event;
    }

    private static function log_priority(object $row): int {
        $event = (string)$row->event_type;
        if (in_array($event, [
            'invoice_created','invoice_delivery','parent_portal_invite_sent','camp_form_verified',
            'agreement_sent_by_admin','agreement_signature_reminder_sent','stripe_link_sent',
            'payment_reminder_sent','payment_confirmation_sent','bank_payment_marked_paid',
        ], true)) return 100;
        if (in_array($event, ['communication_sent','communication_failed'], true)) return 70;
        if (in_array($event, ['email_send_result','communication_email_error','invoice_generated_manually'], true)) return 30;
        if (str_starts_with($event, 'crm_')) return 10;
        return 60;
    }

    private static function created_timestamp(object $row): int {
        try {
            return (new DateTimeImmutable((string)$row->created_at, BCS_Utils::timezone()))->getTimestamp();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function same_context(object $a, object $b): bool {
        return (int)($a->registration_id ?? 0) === (int)($b->registration_id ?? 0)
            && (int)($a->agreement_id ?? 0) === (int)($b->agreement_id ?? 0);
    }

    private static function deduplication_window(string $family): int {
        return $family === 'invoice_generation' ? 120 : 45;
    }

    public static function split_log_rows(array $rows): array {
        $kept = [];
        $duplicates = [];

        foreach ($rows as $row) {
            if (!is_object($row)) continue;
            $family = self::log_family($row);
            $priority = self::log_priority($row);
            $timestamp = self::created_timestamp($row);
            $matched_index = null;

            foreach ($kept as $index => $existing) {
                if (!self::same_context($row, $existing)) continue;
                if (self::log_family($existing) !== $family) continue;
                $distance = abs($timestamp - self::created_timestamp($existing));
                if ($distance > self::deduplication_window($family)) continue;
                $matched_index = $index;
                break;
            }

            if ($matched_index === null) {
                $kept[] = $row;
                continue;
            }

            $existing = $kept[$matched_index];
            if ($priority > self::log_priority($existing)) {
                $duplicates[] = $existing;
                $kept[$matched_index] = $row;
            } else {
                $duplicates[] = $row;
            }
        }

        usort($kept, static fn(object $a, object $b): int => (int)$b->id <=> (int)$a->id);
        return ['kept'=>$kept, 'duplicates'=>$duplicates];
    }

    public static function deduplicate_log_rows(array $rows): array {
        return self::split_log_rows($rows)['kept'];
    }

    private static function removable_duplicate_events(): array {
        return [
            'email_send_result',
            'communication_email_error',
            'invoice_generated_manually',
            'crm_invoice',
            'crm_email',
            'crm_payment',
        ];
    }

    private static function delete_safe_duplicates(array $rows): int {
        global $wpdb;
        $split = self::split_log_rows($rows);
        $allowed = self::removable_duplicate_events();
        $ids = [];
        foreach ($split['duplicates'] as $row) {
            if (!in_array((string)$row->event_type, $allowed, true)) continue;
            $ids[] = (int)$row->id;
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM ".BCS_DB::table('logs')." WHERE id IN ($placeholders)",
            ...$ids
        ));
    }

    public static function migrate_historical_duplicates(): void {
        if (get_option(self::MIGRATION_OPTION)) return;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM ".BCS_DB::table('logs')." ORDER BY id DESC LIMIT 5000"
        );
        self::delete_safe_duplicates(is_array($rows) ? $rows : []);
        update_option(self::MIGRATION_OPTION, 1, false);
    }

    public static function cleanup_recent_duplicates(): void {
        if (!class_exists('BCS_DB')) return;
        global $wpdb;
        $table = BCS_DB::table('logs');
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 300"
        );
        self::delete_safe_duplicates(is_array($rows) ? $rows : []);
    }

    private static function translated_details(array $data): array {
        $keys = [
            'success'=>'powodzenie',
            'template'=>'szablon',
            'channel'=>'kanał',
            'email_success'=>'wysyłka_e_mail',
            'sms_success'=>'wysyłka_sms',
            'email_error'=>'błąd_e_mail',
            'sms_error'=>'błąd_sms',
            'subject'=>'temat',
            'to'=>'odbiorca',
            'reason'=>'powód',
            'invoice_number'=>'numer_faktury',
            'payment_id'=>'identyfikator_płatności',
            'document'=>'dokument',
            'file'=>'plik',
            'source'=>'źródło',
            'result'=>'wynik',
        ];
        $out = [];
        foreach ($data as $key => $value) {
            $translated = $keys[$key] ?? (str_starts_with((string)$key, '_actor_') ? $key : str_replace('_', ' ', (string)$key));
            $out[$translated] = is_array($value) ? self::translated_details($value) : $value;
        }
        return $out;
    }

    public static function logs_page(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        global $wpdb;
        $category = sanitize_key(wp_unslash($_GET['category'] ?? ''));
        $registration_id = absint($_GET['registration_id'] ?? 0);
        $page_num = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 50;
        $where = ['1=1'];
        $args = [];
        if ($registration_id) {
            $where[] = 'registration_id=%d';
            $args[] = $registration_id;
        }
        $table = BCS_DB::table('logs');
        $query = "SELECT * FROM {$table} WHERE ".implode(' AND ', $where)." ORDER BY id DESC LIMIT 1000";
        $all_rows = $args ? $wpdb->get_results($wpdb->prepare($query, ...$args)) : $wpdb->get_results($query);
        $all_rows = self::deduplicate_log_rows(is_array($all_rows) ? $all_rows : []);

        $filtered = [];
        foreach ($all_rows as $row) {
            $data = self::log_data($row);
            $meta = BCS_Utils::event_category_meta((string)$row->event_type, $data);
            if ($category === '' || $meta['key'] === $category) $filtered[] = $row;
        }
        $total = count($filtered);
        $rows = array_slice($filtered, ($page_num - 1) * $per_page, $per_page);

        echo '<div class="wrap bcs-admin"><div class="bcs-page-head"><div><h1>Logi systemowe</h1><p>Rzeczywista historia działań bez technicznych duplikatów i angielskich nazw zdarzeń.</p></div><span class="bcs-count">'.number_format_i18n($total).' wpisów</span></div>';
        echo '<section class="bcs-panel"><form method="get" class="bcs-log-filters"><input type="hidden" name="page" value="bcs-logs"><label><span>Kategoria</span><select name="category"><option value="">Wszystkie</option>';
        foreach (BCS_Utils::event_categories() as $key => $meta) {
            echo '<option value="'.esc_attr($key).'" '.selected($category, $key, false).'>'.esc_html($meta['label']).'</option>';
        }
        echo '</select></label><label><span>ID zgłoszenia</span><input type="number" min="1" name="registration_id" value="'.($registration_id ?: '').'"></label><button class="button button-primary">Filtruj</button><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-logs')).'">Wyczyść</a></form></section>';
        echo '<section class="bcs-panel"><div class="bcs-table-wrap"><table class="widefat striped"><thead><tr><th>Data</th><th>Zdarzenie</th><th>Wykonawca</th><th>Zgłoszenie</th><th>Szczegóły</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="5">Brak logów dla wybranych filtrów.</td></tr>';

        foreach ($rows as $row) {
            $data = self::log_data($row);
            $actor = BCS_Utils::infer_actor_type((string)$row->event_type, $data);
            $actor_label = BCS_Utils::actor_label($actor);
            if ($actor === 'administrator' && !empty($data['_actor_display_name'])) {
                $actor_label = (string)$data['_actor_display_name'].(!empty($data['_actor_login']) ? ' ('.(string)$data['_actor_login'].')' : '');
            }
            $actor_class = 'bcs-log-actor-'.sanitize_html_class($actor);
            $meta = BCS_Utils::event_category_meta((string)$row->event_type, $data);
            $is_error = $meta['key'] === 'system_error';
            $label = self::event_label((string)$row->event_type, $data);
            $details = wp_json_encode(self::translated_details($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            echo '<tr'.($is_error ? ' class="bcs-log-error"' : '').'><td>'.esc_html(BCS_Utils::format_datetime((string)$row->created_at)).'</td><td><div class="bcs-log-event"><span class="bcs-log-category-icon bcs-log-category-'.esc_attr($meta['key']).' dashicons dashicons-'.esc_attr($meta['icon']).'" title="'.esc_attr($meta['label']).'"></span><div><strong>'.esc_html($label).'</strong><br><small class="bcs-log-category-label">'.esc_html($meta['label']).'</small></div></div></td><td><span class="bcs-log-actor '.esc_attr($actor_class).'">'.esc_html($actor_label).'</span></td><td>'.($row->registration_id ? '<a href="'.esc_url(admin_url('admin.php?page=bcs-registrations&view='.(int)$row->registration_id)).'">#'.(int)$row->registration_id.'</a>' : '—').'</td><td><button type="button" class="button bcs-log-details" data-title="'.esc_attr($label).'" data-details="'.esc_attr((string)$details).'" aria-label="Pokaż szczegóły"><span class="dashicons dashicons-visibility"></span><span>Szczegóły</span></button></td></tr>';
        }

        echo '</tbody></table></div></section>';
        $pages = (int)ceil($total / $per_page);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">'.paginate_links([
                'base'=>add_query_arg('paged', '%#%'),
                'format'=>'',
                'current'=>$page_num,
                'total'=>$pages,
            ]).'</div></div>';
        }
        echo '<div id="bcs-log-modal" class="bcs-log-modal" hidden><div class="bcs-log-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-log-modal-title"><button type="button" class="bcs-log-modal__close" aria-label="Zamknij"><span class="dashicons dashicons-no-alt"></span></button><h2 id="bcs-log-modal-title">Szczegóły zdarzenia</h2><pre class="bcs-log-data"></pre></div></div></div>';
    }
}
