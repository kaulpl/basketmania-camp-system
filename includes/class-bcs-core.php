<?php
if (!defined('ABSPATH')) exit;

/** Rejestr centralnych komponentów architektury 0.14.0. */
final class BCS_Core {
    public static function init(): void {
        BCS_Workflow_Engine::init();
        BCS_Template_Engine::init();
        BCS_Document_Engine::init();
        BCS_Communication_Engine::init();
        update_option('bcs_architecture_version', '0.14.0', false);
    }

    public static function components(): array {
        return [
            'workflow'      => BCS_Workflow_Engine::class,
            'templates'     => BCS_Template_Engine::class,
            'documents'     => BCS_Document_Engine::class,
            'communication' => BCS_Communication_Engine::class,
        ];
    }
}
