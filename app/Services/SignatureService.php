<?php

namespace App\Services;

use RuntimeException;

class SignatureService
{
    public static function hashBody(string $body): string
    {
        if ($body === '') {
            return '';
        }

        return hash('sha256', $body);
    }

    public static function sign(
        string $method,
        string $endpoint,
        string $token,
        string $hashedBody,
        string $timestamp
    ): string {
        $baseString = "$method|$endpoint|$token|$hashedBody|$timestamp";

        $secret = env('SIGNATURE_SECRET', '');
        if ($secret === '') {
            throw new RuntimeException('SIGNATURE_SECRET tidak dikonfigurasi di .env');
        }

        $hmac = hash_hmac('sha256', $baseString, $secret, true);
        return base64_encode($hmac);
    }

    public static function verify(
        string $method,
        string $endpoint,
        string $token,
        string $body,
        string $timestamp,
        string $providedSignature
    ): bool {
        $hashedBody = self::hashBody($body);
        $expectedSignature = self::sign($method, $endpoint, $token, $hashedBody, $timestamp);

        return hash_equals($expectedSignature, $providedSignature);
    }

    public static function isTimestampValid(string $timestamp, int $windowSeconds = 300): bool
    {
        try {
            $requestTime = new \DateTime($timestamp, new \DateTimeZone('UTC'));
            $currentTime = new \DateTime('now', new \DateTimeZone('UTC'));

            $diff = abs($currentTime->getTimestamp() - $requestTime->getTimestamp());

            return $diff <= $windowSeconds;
        } catch (\Exception $e) {
            return false;
        }
    }
}
