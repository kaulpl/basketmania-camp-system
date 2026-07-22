<?php
if (!defined('ABSPATH')) exit;

/** Centralny silnik komunikacji e-mail i SMS. */
final class BCS_Communication_Engine {
    public const ARCHITECTURE_VERSION = '1.1';

    public static function init(): void {}

    public static function send_to_registration(int $registration_id, string $template_key, string $channels = 'email', string $subject = '', string $body = '', bool $with_package = false): bool {
        if (class_exists('BCS_Notification_Settings')) {
            if (BCS_Notification_Settings::already_sent($registration_id, $template_key)) {
                BCS_Utils::log('communication_duplicate_blocked', [
                    'template' => $template_key,
                    'reason' => 'Powiadomienie jednorazowe zostało już skutecznie wysłane.',
                ], $registration_id, null);
                return true;
            }

            $configured = BCS_Notification_Settings::channels_for($template_key, $channels);
            if ($configured === 'off') {
                BCS_Utils::log('communication_disabled_by_settings', [
                    'template' => $template_key,
                    'requested_channel' => $channels,
                ], $registration_id, null);
                return true;
            }
            $channels = $configured;
        }

        return BCS_Communications::send_to_registration($registration_id, $template_key, $channels, $subject, $body, $with_package);
    }

    public static function registration_context(int $registration_id): ?array { return BCS_Communications::registration_context($registration_id); }
    public static function templates(): array { return BCS_Communications::templates(); }
    public static function default_templates(): array { return BCS_Communications::default_templates(); }
    public static function last_send_result(): array { return BCS_Communications::last_send_result(); }

    public static function __callStatic(string $name, array $arguments): mixed {
        if (!is_callable([BCS_Communications::class, $name])) {
            throw new BadMethodCallException('Nieznana operacja komunikacji: ' . $name);
        }
        return BCS_Communications::$name(...$arguments);
    }
}
