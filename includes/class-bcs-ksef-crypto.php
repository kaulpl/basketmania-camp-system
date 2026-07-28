<?php
if (!defined('ABSPATH')) exit;

/** Kryptografia wymagana przez KSeF API 2.0. */
final class BCS_KSeF_Crypto {
    private const HASH = 'sha256';

    /** @return array{certificate:string,certificateId:string,publicKeyId:string,usage:mixed} */
    public static function select_public_key(array $certificates, string $requiredUsage): array {
        $now = time();
        foreach ($certificates as $certificate) {
            if (!is_array($certificate)) continue;
            $usage = $certificate['usage'] ?? [];
            $usages = is_array($usage) ? $usage : [$usage];
            if (!in_array($requiredUsage, array_map('strval', $usages), true)) continue;
            $from = !empty($certificate['validFrom']) ? strtotime((string)$certificate['validFrom']) : false;
            $to = !empty($certificate['validTo']) ? strtotime((string)$certificate['validTo']) : false;
            if ($from !== false && $from > $now + 300) continue;
            if ($to !== false && $to < $now - 300) continue;
            if (empty($certificate['certificate']) || empty($certificate['publicKeyId'])) continue;
            return [
                'certificate'=>(string)$certificate['certificate'],
                'certificateId'=>(string)($certificate['certificateId'] ?? ''),
                'publicKeyId'=>(string)$certificate['publicKeyId'],
                'usage'=>$usage,
            ];
        }
        throw new RuntimeException('KSeF nie zwrócił aktywnego klucza publicznego dla zastosowania '.$requiredUsage.'.');
    }

    /** RSA-OAEP z SHA-256 i MGF1-SHA-256, zgodnie z KSeF API 2.0. */
    public static function rsa_oaep_sha256_encrypt(string $plain, string $certificate): string {
        if (!function_exists('openssl_public_encrypt')) throw new RuntimeException('Rozszerzenie OpenSSL nie jest dostępne.');
        $publicKey = self::public_key($certificate);
        $details = openssl_pkey_get_details($publicKey);
        if (!is_array($details) || empty($details['bits'])) throw new RuntimeException('Nie udało się odczytać parametrów klucza publicznego KSeF.');
        $k = (int)ceil(((int)$details['bits']) / 8);
        $hLen = 32;
        if (strlen($plain) > $k - 2 * $hLen - 2) throw new RuntimeException('Dane są zbyt długie dla klucza RSA KSeF.');

        $lHash = hash(self::HASH, '', true);
        $padding = str_repeat("\0", $k - strlen($plain) - 2 * $hLen - 2);
        $db = $lHash.$padding."\x01".$plain;
        $seed = random_bytes($hLen);
        $dbMask = self::mgf1($seed, $k - $hLen - 1);
        $maskedDb = $db ^ $dbMask;
        $seedMask = self::mgf1($maskedDb, $hLen);
        $maskedSeed = $seed ^ $seedMask;
        $encoded = "\0".$maskedSeed.$maskedDb;

        $encrypted = '';
        if (!openssl_public_encrypt($encoded, $encrypted, $publicKey, OPENSSL_NO_PADDING)) {
            throw new RuntimeException('Nie udało się zaszyfrować danych kluczem publicznym KSeF.');
        }
        return $encrypted;
    }

    /** @return array{key:string,iv:string} */
    public static function symmetric_material(): array {
        return ['key'=>random_bytes(32), 'iv'=>random_bytes(16)];
    }

    public static function aes_256_cbc_encrypt(string $plain, string $key, string $iv): string {
        if (strlen($key) !== 32 || strlen($iv) !== 16) throw new InvalidArgumentException('Nieprawidłowy klucz lub IV szyfrowania faktury KSeF.');
        $encrypted = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($encrypted)) throw new RuntimeException('Nie udało się zaszyfrować faktury algorytmem AES-256-CBC.');
        return $encrypted;
    }

    public static function sha256_base64(string $content): string {
        return base64_encode(hash('sha256', $content, true));
    }

    private static function mgf1(string $seed, int $length): string {
        $mask = '';
        for ($counter = 0; strlen($mask) < $length; $counter++) {
            $mask .= hash(self::HASH, $seed.pack('N', $counter), true);
        }
        return substr($mask, 0, $length);
    }

    /** @return OpenSSLAsymmetricKey|resource */
    private static function public_key(string $certificate) {
        $compact = preg_replace('/\s+/', '', $certificate) ?: '';
        $decoded = base64_decode($compact, true);
        if (!is_string($decoded) || $decoded === '') throw new RuntimeException('Certyfikat publiczny KSeF ma nieprawidłowy format.');

        foreach (['CERTIFICATE', 'PUBLIC KEY'] as $type) {
            $pem = "-----BEGIN {$type}-----\n".chunk_split(base64_encode($decoded), 64, "\n")."-----END {$type}-----\n";
            $key = openssl_pkey_get_public($pem);
            if ($key !== false) return $key;
        }
        throw new RuntimeException('Nie udało się utworzyć klucza publicznego z certyfikatu KSeF.');
    }
}
