<?php
if (!defined('ABSPATH')) exit;

/** Centralny silnik generowania, przechowywania i udostępniania dokumentów. */
final class BCS_Document_Engine {
    public const ARCHITECTURE_VERSION = '1.0';

    public static function init(): void {}
    public static function download_url(int $registration_id, string $document): string { return BCS_Documents::download_url($registration_id, $document); }
    public static function agreement_pdf(int $registration_id, string $version = 'current'): string { return (string) BCS_Documents::agreement_pdf($registration_id, $version); }
    public static function html_document(string $title, string $body): string { return BCS_Documents::html_document($title, $body); }
    public static function uploads_dir(): string { return BCS_Documents::uploads_dir(); }

    public static function __callStatic(string $name, array $arguments): mixed {
        if (!is_callable([BCS_Documents::class, $name])) {
            throw new BadMethodCallException('Nieznana operacja dokumentu: ' . $name);
        }
        return BCS_Documents::$name(...$arguments);
    }
}
