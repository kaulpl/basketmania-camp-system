<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.80 – spójność danych nabywcy faktury lokalnej i KSeF.
 *
 * Logika wykonawcza znajduje się w BCS_Invoices, BCS_KSeF_FA3 oraz
 * BCS_KSeF_Invoice_Flow. Klasa wydania pozostaje znacznikiem wersji
 * i miejscem na ewentualne migracje kompatybilnościowe.
 */
final class BCS_Release_080 {
    public static function init(): void {}
}
