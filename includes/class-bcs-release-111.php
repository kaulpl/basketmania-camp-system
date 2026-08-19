<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.11 – obowiązkowa anonimizacja danych nabywcy przed wysyłką do KSeF TEST.
 *
 * Zasady:
 * - TEST zawsze używa fikcyjnego Podmiot2, niezależnie od rodzaju faktury,
 * - administrator nie może wyłączyć ochrony TEST,
 * - PRODUKCJA pozostaje niezanonimizowana i korzysta z rzeczywistych danych faktury,
 * - historyczna ręczna ścieżka „Wyślij do KSeF TEST” regeneruje XML i wykonuje
 *   dodatkową kontrolę tuż przed przekazaniem dokumentu do serwisu wysyłkowego.
 */
final class BCS_Release_111 {
    private const DB_OPTION = 'bcs_release_111_anonymization_guard';

    public static function init(): void {
        self::enforce_test_setting();
        add_action('admin_init', [__CLASS__, 'force_test_setting_on_save'], 0);
        add_action('admin_footer', [__CLASS__, 'render_anonymization_notice'], 20000);

        // Historyczny panel 0.75 potrafi wysłać istniejący plik XML. Przejmujemy
        // wyłącznie tę akcję, aby stary niezanonimizowany XML nie mógł zostać użyty.
        remove_action('wp_ajax_bcs_ksef_send_075', ['BCS_Release_075', 'ajax_send']);
        add_action('wp_ajax_bcs_ksef_send_075', [__CLASS__, 'ajax_send_075_guarded']);
    }

    /** Wymusza ochronę dla wszystkich Organizatorów pracujących w TEST. */
    public static function enforce_test_setting(): void {
        global $wpdb;
        $table = BCS_DB::table('organizers');
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'ksef_anonymize_test'));
        if ($exists === null) return;
        $wpdb->query("UPDATE {$table} SET ksef_anonymize_test=1 WHERE ksef_environment='test' AND (ksef_anonymize_test IS NULL OR ksef_anonymize_test<>1)");
        if ((string)get_option(self::DB_OPTION, '') !== '1.11') update_option(self::DB_OPTION, '1.11', false);
    }

    /**
     * Starsze formularze zapisują checkbox ksef_anonymize_test. Pole zostaje dla
     * kompatybilności bazy, ale w środowisku TEST nie jest już decyzją użytkownika.
     */
    public static function force_test_setting_on_save(): void {
        if (!is_admin() || empty($_POST['bcs_save_organizer']) || empty($_POST['bcs_ksef_panel_present'])) return;
        $environment = BCS_KSeF_Config::allowed_environment(sanitize_key(wp_unslash($_POST['ksef_environment'] ?? 'test')));
        if ($environment === 'test') $_POST['ksef_anonymize_test'] = '1';
    }

    /** Informacja w konfiguracji Organizatora – bez możliwości wyłączenia TEST. */
    public static function render_anonymization_notice(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-organizers') return;
        ?>
        <script>
        (()=>{
            const apply=()=>{
                const checkbox=document.querySelector('input[name="ksef_anonymize_test"]');
                if(!checkbox)return;
                checkbox.checked=true;
                checkbox.disabled=true;
                const label=checkbox.closest('label');
                const text=label?.querySelector('span');
                if(text)text.textContent='Anonimizacja danych faktury w KSeF TEST – zawsze włączona';
                const panel=checkbox.closest('.bcs-subpanel,details,form');
                if(panel && !panel.querySelector('.bcs-ksef-anonymization-111')){
                    const note=document.createElement('div');
                    note.className='notice notice-info inline bcs-ksef-anonymization-111';
                    note.innerHTML='<p><strong>Ochrona danych KSeF:</strong> w środowisku TEST nazwa, NIP i adres nabywcy są zawsze zastępowane danymi fikcyjnymi. Dotyczy to również faktury na firmę. W PRODUKCJI używane są rzeczywiste dane zapisane w profilu faktury.</p>';
                    label?.insertAdjacentElement('afterend',note);
                }
            };
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',apply,{once:true});else apply();
            window.setTimeout(apply,250);window.setTimeout(apply,800);
        })();
        </script>
        <?php
    }

    /**
     * Twarda ochrona historycznej ręcznej wysyłki 0.75. Zawsze regenerujemy XML
     * TEST, a następnie sprawdzamy jego Podmiot2 przed wywołaniem Service::send().
     */
    public static function ajax_send_075_guarded(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $invoiceId = absint($_POST['invoice_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$invoiceId || !wp_verify_nonce($nonce, 'bcs_ksef_invoice_075_'.$invoiceId)) {
            wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę.'], 403);
        }

        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare(
            'SELECT i.*,o.ksef_environment,o.ksef_anonymize_test FROM '.BCS_DB::table('invoices').' i '
            .'JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id WHERE i.id=%d',
            $invoiceId
        ));
        if (!$invoice) wp_send_json_error(['message'=>'Nie znaleziono faktury.'], 404);

        $environment = BCS_KSeF_Config::allowed_environment((string)($invoice->ksef_environment ?? 'test'));
        if ($environment === 'test') {
            $wpdb->update(BCS_DB::table('organizers'), ['ksef_anonymize_test'=>1], ['id'=>(int)$invoice->organizer_id]);
            $prepared = BCS_KSeF_FA3::prepare_and_save($invoiceId);
            if (empty($prepared['success'])) {
                wp_send_json_error(['message'=>(string)($prepared['message'] ?? 'Nie udało się bezpiecznie przygotować XML KSeF TEST.')], 422);
            }
            $fresh = $wpdb->get_row($wpdb->prepare('SELECT ksef_xml_path FROM '.BCS_DB::table('invoices').' WHERE id=%d', $invoiceId));
            $path = (string)($fresh->ksef_xml_path ?? '');
            $xml = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
            if (!BCS_Release_082::test_buyer_is_anonymized($xml)) {
                $message = 'Wysyłka została zablokowana: XML KSeF TEST nie przeszedł obowiązkowej kontroli anonimizacji nabywcy.';
                BCS_KSeF_FA3::operation($invoiceId, (int)$invoice->organizer_id, 'Kontrola anonimizacji przed wysyłką KSeF TEST', 'error', null, [
                    'environment'=>'test',
                    'guard_version'=>'1.11',
                ], 'TEST_ANONYMIZATION_GUARD_111', $message);
                wp_send_json_error(['message'=>$message], 422);
            }
            BCS_KSeF_FA3::operation($invoiceId, (int)$invoice->organizer_id, 'Kontrola anonimizacji przed wysyłką KSeF TEST', 'success', null, [
                'environment'=>'test',
                'guard_version'=>'1.11',
            ]);
        }

        $result = BCS_KSeF_Service::send($invoiceId);
        if (!empty($result['success'])) wp_send_json_success($result);
        wp_send_json_error($result, 422);
    }
}
