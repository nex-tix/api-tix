<?php

namespace App\Services;

use RuntimeException;

class EncryptionService
{
    private const CIPHER = 'AES-256-CBC';
    private const IV_LENGTH = 16;

    private static ?string $key = null;

    private static function getKey(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $secretKey = env('SECRET_KEY', '');
        if ($secretKey === '') {
            throw new RuntimeException('SECRET_KEY tidak dikonfigurasi di .env');
        }

        $keyBytes = substr($secretKey, 0, 32);
        $keyBytes = str_pad($keyBytes, 32, "\0");

        self::$key = $keyBytes;
        return self::$key;
    }

    public static function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }

        $key = self::getKey();
        $iv = random_bytes(self::IV_LENGTH);

        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($cipherText === false) {
            throw new RuntimeException('Enkripsi gagal: ' . openssl_error_string());
        }

        $combined = $iv . $cipherText;
        return base64_encode($combined);
    }

    public static function decrypt(string $base64CipherText): string
    {
        if ($base64CipherText === '') {
            return '';
        }

        $combined = base64_decode($base64CipherText, true);
        if ($combined === false || strlen($combined) <= self::IV_LENGTH) {
            throw new RuntimeException('Ciphertext tidak valid (terlalu pendek atau base64 corrupt)');
        }

        $iv = substr($combined, 0, self::IV_LENGTH);
        $cipherText = substr($combined, self::IV_LENGTH);

        $key = self::getKey();

        $plainText = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plainText === false) {
            throw new RuntimeException('Dekripsi gagal: ' . openssl_error_string());
        }

        return $plainText;
    }
}
