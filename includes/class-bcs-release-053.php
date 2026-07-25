<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_053 {
    public static function init(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_parent_autofill'], 30);
    }

    private static function test_mode_enabled(): bool {
        if (class_exists('BCS_Workflow_Engine')) {
            return BCS_Workflow_Engine::test_mode_enabled();
        }
        if (class_exists('BCS_Workflow')) {
            return BCS_Workflow::test_mode_enabled();
        }
        $settings = get_option('bcs_settings', []);
        return !array_key_exists('test_workflow_mode', $settings)
            || !empty($settings['test_workflow_mode']);
    }

    public static function enqueue_parent_autofill(): void {
        if (!self::test_mode_enabled()) return;

        wp_enqueue_script(
            'bcs-parent-test-autofill-053',
            BCS_URL . 'assets/js/parent-test-autofill-053.js',
            [],
            BCS_VERSION,
            true
        );
        wp_localize_script('bcs-parent-test-autofill-053', 'BCSTestAutofill053', [
            'enabled' => true,
            'buttonLabel' => 'Wypełnij losowymi danymi',
            'heading' => 'Tryb testowy',
            'description' => 'System uzupełni formularz przykładowymi danymi. E-mail i Telefon I pozostaną bez zmian.',
            'success' => 'Formularz został wypełniony losowymi danymi. E-mail i Telefon I pozostawiono bez zmian.',
            'missingPrefix' => 'Uzupełnij ręcznie: ',
        ]);
    }
}
