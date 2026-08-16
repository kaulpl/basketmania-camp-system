<?php
if (!defined('ABSPATH')) exit;

/** Szyfrowanie tokenów KSeF poza mechanizmem zwykłych opcji WordPressa. */
final class BCS_KSeF_Secret {
    private static function key(): string {
        $material = BCS_KSeF_Config::master_key_material();
        if ($material === '') throw new RuntimeException('Brak BCS_KSEF_SECRET_KEY w konfiguracji serwera.');
        if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('Rozszerzenie Sodium nie jest dostępne.');

        $decoded = base64_decode($material, true);
        if (is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return $decoded;
        return hash('sha256', $material, true);
    }

    /** @return array{ciphertext:string,nonce:string} */
    public static function encrypt(string $plain): array {
        if ($plain === '') return ['ciphertext' => '', 'nonce' => ''];
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plain, $nonce, self::key());
        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
        ];
    }

    public static function decrypt(string $ciphertext, string $nonce): string {
        if ($ciphertext === '' || $nonce === '') return '';
        $decodedCiphertext = base64_decode($ciphertext, true);
        $decodedNonce = base64_decode($nonce, true);
        if (!is_string($decodedCiphertext) || !is_string($decodedNonce)) {
            throw new RuntimeException('Zapisany sekret KSeF ma nieprawidłowy format.');
        }
        $plain = sodium_crypto_secretbox_open($decodedCiphertext, $decodedNonce, self::key());
        if (!is_string($plain)) throw new RuntimeException('Nie udało się odszyfrować tokenu KSeF.');
        return $plain;
    }

    public static function configured(object $organizer, string $environment = 'test'): bool {
        $environment = BCS_KSeF_Config::allowed_environment($environment);
        if ($environment === 'production') {
            return trim((string)($organizer->ksef_production_token_ciphertext ?? '')) !== ''
                && trim((string)($organizer->ksef_production_token_nonce ?? '')) !== '';
        }
        return trim((string)($organizer->ksef_token_ciphertext ?? '')) !== ''
            && trim((string)($organizer->ksef_token_nonce ?? '')) !== '';
    }

    public static function decrypt_for_environment(object $organizer, string $environment): string {
        $environment = BCS_KSeF_Config::allowed_environment($environment);
        if ($environment === 'production') {
            return self::decrypt(
                (string)($organizer->ksef_production_token_ciphertext ?? ''),
                (string)($organizer->ksef_production_token_nonce ?? '')
            );
        }
        return self::decrypt(
            (string)($organizer->ksef_token_ciphertext ?? ''),
            (string)($organizer->ksef_token_nonce ?? '')
        );
    }
}
