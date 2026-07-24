<?php
if (!defined('ABSPATH')) exit;

/**
 * Centralny punkt dostępu do reguł procesu zgłoszenia.
 *
 * Warstwa silnika oddziela moduły interfejsu, płatności, umów i CRM od
 * technicznej implementacji workflow. Klasa BCS_Workflow pozostaje
 * wykonawcą operacji i kompatybilną warstwą dla starszych integracji.
 */
final class BCS_Workflow_Engine {
    public const ARCHITECTURE_VERSION = '1.0';

    public static function init(): void {}

    public static function statuses(): array { return BCS_Workflow::statuses(); }
    public static function test_mode_enabled(): bool { return BCS_Workflow::test_mode_enabled(); }
    public static function invoice_available(int $registration_id): bool { return BCS_Workflow::invoice_available($registration_id); }
    public static function refresh_invoice_readiness(int $registration_id): void { BCS_Workflow::refresh_invoice_readiness($registration_id); }
    public static function last_form_verification_result(): array { return BCS_Workflow::last_form_verification_result(); }

    public static function execute(string $action, int $registration_id, array $context = []): bool {
        return match ($action) {
            'confirm_registration' => BCS_Workflow::confirm_registration($registration_id),
            'verify_form'          => BCS_Workflow::verify_form($registration_id),
            'send_agreement'       => BCS_Workflow::send_agreement($registration_id),
            'send_stripe_link'     => BCS_Workflow::send_stripe_link($registration_id),
            'mark_bank_paid'       => BCS_Workflow::mark_bank_paid($registration_id, (string)($context['paid_at'] ?? '')),
            'remind_payment'       => BCS_Workflow::remind_payment($registration_id),
            'generate_invoice'     => BCS_Workflow::generate_invoice($registration_id),
            default                => false,
        };
    }

    public static function mark_bank_paid(int $registration_id, string $paid_at = ''): bool {
        return BCS_Workflow::mark_bank_paid($registration_id, $paid_at);
    }

    public static function __callStatic(string $name, array $arguments): mixed {
        if (!is_callable([BCS_Workflow::class, $name])) {
            throw new BadMethodCallException('Nieznana operacja workflow: ' . $name);
        }
        return BCS_Workflow::$name(...$arguments);
    }
}
