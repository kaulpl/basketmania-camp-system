<?php
if (!defined('ABSPATH')) exit;

/** Jawna konfiguracja środowisk KSeF API 2.0. */
final class BCS_KSeF_Config {
    public const API_VERSION = '2.7.0';
    public const TEST_BASE_URL = 'https://api-test.ksef.mf.gov.pl/v2';
    public const DEMO_BASE_URL = 'https://api-demo.ksef.mf.gov.pl/v2';
    public const PRODUCTION_BASE_URL = 'https://api.ksef.mf.gov.pl/v2';

    public const FA3_NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';
    public const FA3_SCHEMA_VERSION = '1-0E';
    public const FA3_SYSTEM_CODE = 'FA (3)';
    public const FA3_VARIANT = '3';
    public const FA3_XSD_SOURCE = 'https://crd.gov.pl/wzor/2025/06/25/13775/schemat.xsd';

    public static function environments(): array {
        return [
            'test' => ['label' => 'TEST', 'base_url' => self::TEST_BASE_URL],
            'demo' => ['label' => 'DEMO', 'base_url' => self::DEMO_BASE_URL],
            'production' => ['label' => 'PRODUKCJA', 'base_url' => self::PRODUCTION_BASE_URL],
        ];
    }

    /** Operacyjnie obsługujemy TEST i PRODUKCJĘ. DEMO pozostaje zarezerwowane. */
    public static function allowed_environment(string $environment): string {
        return $environment === 'production' ? 'production' : 'test';
    }

    public static function base_url(string $environment = 'test'): string {
        $environment = self::allowed_environment($environment);
        return (string)self::environments()[$environment]['base_url'];
    }

    public static function label(string $environment): string {
        $environment = self::allowed_environment($environment);
        return (string)self::environments()[$environment]['label'];
    }

    public static function master_key_material(): string {
        if (defined('BCS_KSEF_SECRET_KEY') && is_string(BCS_KSEF_SECRET_KEY)) {
            return trim(BCS_KSEF_SECRET_KEY);
        }
        $environment = getenv('BCS_KSEF_SECRET_KEY');
        return is_string($environment) ? trim($environment) : '';
    }

    public static function master_key_available(): bool {
        return self::master_key_material() !== '' && function_exists('sodium_crypto_secretbox');
    }

    public static function master_key_help(): string {
        return "define('BCS_KSEF_SECRET_KEY', 'TU_WSTAW_DLUGI_LOSOWY_SEKRET');";
    }
}
