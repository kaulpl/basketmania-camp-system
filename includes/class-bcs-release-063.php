<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_063 {
    public static function init(): void {}

    /**
     * Formularz można edytować do chwili faktycznego wysłania bieżącej umowy
     * do podpisu. Status procesu `draft_sent` oznacza jedynie, że przygotowano
     * i przekazano rodzicowi wzór/draft, więc nie może blokować edycji.
     *
     * Historyczny albo niespójny status `pending` w rekordzie umowy nie blokuje
     * edycji, gdy bieżący workflow nadal znajduje się na etapie przed wysyłką.
     */
    public static function form_editing_locked(object $registration): bool {
        $workflow_status = (string)($registration->status ?? '');
        if ($workflow_status === 'cancelled') return true;

        $invoice_locked = !empty($registration->has_invoice)
            || in_array((string)($registration->invoice_status ?? ''), ['generated', 'sent'], true);
        if ($invoice_locked) return true;

        $registration_agreement_status = (string)($registration->agreement_status ?? '');
        $agreement_record_status = (string)($registration->agreement_record_status ?? '');

        // Podpis jednej albo obu stron jest zawsze bezwzględną blokadą.
        if (in_array($registration_agreement_status, ['parent_signed', 'accepted'], true)) return true;
        if ($agreement_record_status === 'accepted') return true;

        // Etapy przed właściwym wysłaniem umowy pozostają edytowalne.
        if (in_array($workflow_status, ['new', 'admin_confirmed', 'form_complete', 'draft_sent'], true)) {
            return false;
        }

        // Te etapy oznaczają, że umowę faktycznie wysłano lub proces poszedł dalej.
        if (in_array($workflow_status, [
            'agreement_sent',
            'agreement_parent_signed',
            'awaiting_bank_payment',
            'stripe_link_sent',
            'partially_paid',
            'paid',
        ], true)) {
            return true;
        }

        return $registration_agreement_status === 'pending'
            || $agreement_record_status === 'pending';
    }
}
