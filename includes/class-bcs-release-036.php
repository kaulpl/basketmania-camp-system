<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_036 {
    public static function init(): void {
        // Historyczny skrypt z wersji 0.29 rejestrował listener w fazie capture
        // z priorytetem 1 i przejmował kliknięcie na liście przed nowym modalem.
        remove_action('admin_footer', ['BCS_Release_029_Gate', 'list_button_script'], 1);

        // Zostaje wyłącznie wspólny modal OTP używany przez kartę i listę.
        remove_action('admin_footer', ['BCS_Release_030', 'admin_footer']);
    }
}
