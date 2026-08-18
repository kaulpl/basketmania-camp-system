<?php
if (!defined('ABSPATH')) exit;

/**
 * 1.04 – twarde wymuszenie jednego, throttlowanego workera mailingu.
 *
 * 0.98 miał minutowy pump, który wywoływał BCS_Release_097::run_queue()
 * bezpośrednio. Omijało to przejęcie hooka z 0.99/1.00 i pozwalało staremu
 * workerowi wysłać do 20 wiadomości w jednym przebiegu. 1.04 kieruje każdy
 * trigger do jednego workera 1.00 i chroni go blokadą współbieżności.
 */
final class BCS_Release_104 {
    private const QUEUE_HOOK = 'bcs_marketing_queue_097';
    private const PUMP_HOOK = 'bcs_marketing_queue_pump_098';
    private const LOCK_OPTION = 'bcs_marketing_queue_lock_104';
    private const LOCK_TTL = 180;

    public static function init(): void {
        // Usuń historyczne ścieżki wysyłki. Szczególnie ważny jest pump 0.98,
        // który wywoływał stary batch-worker 0.97 bezpośrednio.
        remove_action(self::PUMP_HOOK, [BCS_Release_098::class, 'pump_queue']);
        remove_action(self::QUEUE_HOOK, [BCS_Release_097::class, 'run_queue']);
        remove_action(self::QUEUE_HOOK, [BCS_Release_099::class, 'run_queue'], 20);
        remove_action(self::QUEUE_HOOK, [BCS_Release_100::class, 'run_queue'], 30);

        // Zarówno event pojedynczy kampanii, jak i minutowy pump trafiają odtąd
        // do tej samej, blokowanej ścieżki.
        add_action(self::QUEUE_HOOK, [__CLASS__, 'run_queue_guarded'], 40);
        add_action(self::PUMP_HOOK, [__CLASS__, 'pump_queue'], 40);
    }

    public static function pump_queue(): void {
        do_action(self::QUEUE_HOOK);
    }

    public static function run_queue_guarded(): void {
        if (!self::acquire_lock()) return;
        try {
            // 1.00 zawiera właściwe reguły: globalny limit dzienny, okno
            // godzinowe, NEXT_SEND_OPTION i LIMIT 1 odbiorcy na przebieg.
            BCS_Release_100::run_queue();
        } finally {
            self::release_lock();
        }
    }

    private static function acquire_lock(): bool {
        $now = time();
        $expires = (int)get_option(self::LOCK_OPTION, 0);
        if ($expires > 0 && $expires <= $now) {
            delete_option(self::LOCK_OPTION);
        }

        // option_name jest unikalne w wp_options, więc add_option() pełni rolę
        // atomowego locka także wtedy, gdy dwa wywołania WP-Cron wystartują naraz.
        return add_option(self::LOCK_OPTION, $now + self::LOCK_TTL, '', 'no');
    }

    private static function release_lock(): void {
        delete_option(self::LOCK_OPTION);
    }
}
