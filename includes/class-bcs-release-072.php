<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.72 – fundament integracji z KSeF API 2.0 na środowisku TEST.
 * Wydanie nie wysyła jeszcze faktur do KSeF i nie zmienia obecnego procesu dostarczenia PDF.
 */
final class BCS_Release_072 {
    public static function init(): void {
        BCS_KSeF_Install::maybe_upgrade();
        BCS_KSeF_Admin::init();
    }
}
