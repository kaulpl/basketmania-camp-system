<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_051_Storage {
    private const MIGRATION_OPTION = 'bcs_release_051_logo_storage_cleaned';

    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'cleanup_existing_once'], 4);
        register_shutdown_function([__CLASS__, 'cleanup_after_document_request']);
    }

    private static function logo_url(): string {
        return BCS_URL.'assets/images/logo-basketmania-camp-white.png';
    }

    private static function clean_html(string $html): string {
        if (!str_contains($html, 'data:image/png;base64,')) return $html;
        $url = esc_url(self::logo_url());
        return (string)preg_replace_callback(
            '~(<img\b[^>]*\bsrc=)(["\'])data:image/png;base64,[^"\']+\2~i',
            static fn(array $match): string => $match[1].$match[2].$url.$match[2],
            $html
        );
    }

    public static function cleanup(): void {
        if (!class_exists('BCS_DB')) return;
        global $wpdb;

        $agreements = $wpdb->get_results(
            "SELECT id,html FROM ".BCS_DB::table('agreements')."
             WHERE html LIKE '%data:image/png;base64,%'"
        );
        foreach ($agreements as $agreement) {
            $clean = self::clean_html((string)$agreement->html);
            if ($clean !== (string)$agreement->html) {
                $wpdb->update(
                    BCS_DB::table('agreements'),
                    ['html'=>$clean],
                    ['id'=>(int)$agreement->id]
                );
            }
        }

        $versions = $wpdb->get_results(
            "SELECT id,html FROM ".BCS_DB::table('agreement_versions')."
             WHERE html LIKE '%data:image/png;base64,%'"
        );
        foreach ($versions as $version) {
            $clean = self::clean_html((string)$version->html);
            if ($clean !== (string)$version->html) {
                $wpdb->update(
                    BCS_DB::table('agreement_versions'),
                    ['html'=>$clean],
                    ['id'=>(int)$version->id]
                );
            }
        }
    }

    public static function cleanup_existing_once(): void {
        if (get_option(self::MIGRATION_OPTION)) return;
        self::cleanup();
        update_option(self::MIGRATION_OPTION, 1, false);
    }

    public static function cleanup_after_document_request(): void {
        $action = sanitize_key((string)($_REQUEST['action'] ?? ''));
        $document = sanitize_key((string)($_GET['document'] ?? ''));
        $relevant = in_array($action, [
            'bcs_agreement_view',
            'bcs_verify_otp',
            'bcs_046_organizer_otp_verify',
        ], true) || str_starts_with($document, 'agreement_');
        if ($relevant) self::cleanup();
    }
}
