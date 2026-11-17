<?php

namespace App\Libraries;

/**
 * Implementação nativa RFC 6238 (TOTP) em PHP puro, sem dependências externas.
 */
class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    public static function base32Decode(string $secret): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        if ($clean === '') {
            return '';
        }

        $binary = '';
        foreach (str_split($clean) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }

    public static function getCode(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            throw new \InvalidArgumentException('Segredo TOTP inválido.');
        }

        $timestamp = $timestamp ?? time();
        $counter = (int) floor($timestamp / $period);
        $binCounter = pack('N*', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);

        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $truncated % (10 ** $digits);
        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null, int $period = 30, int $digits = 6): bool
    {
        $normalized = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . $digits . '}$/', $normalized)) {
            return false;
        }

        $timestamp = $timestamp ?? time();
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::getCode($secret, $timestamp + ($i * $period), $period, $digits);
            if (hash_equals($candidate, $normalized)) {
                return true;
            }
        }

        return false;
    }

    public static function getProvisioningUri(string $secret, string $accountName, string $issuer = 'DeployHub'): string
    {
        $cleanIssuer = trim(str_replace([':', "\r", "\n"], ' ', $issuer));
        if ($cleanIssuer === '') {
            $cleanIssuer = 'DeployHub';
        }
        $cleanAccount = trim(str_replace([':', "\r", "\n"], ' ', $accountName));
        if ($cleanAccount === '') {
            $cleanAccount = 'admin';
        }

        $label = rawurlencode($cleanIssuer) . ':' . rawurlencode($cleanAccount);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            rawurlencode($secret),
            rawurlencode($cleanIssuer)
        );
    }

    /**
     * Gera códigos de recuperação de uso único (formato XXXX-XXXX).
     *
     * @return string[]
     */
    public static function generateRecoveryCodes(int $count = 6): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $hex = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = substr($hex, 0, 4) . '-' . substr($hex, 4, 4);
        }
        return $codes;
    }

    /**
     * Verifica e consome um código de recuperação de uso único.
     *
     * @param string[] $hashes
     * @return array{matched: bool, remaining: string[]}
     */
    public static function consumeRecoveryCode(string $input, array $hashes): array
    {
        $normalized = strtoupper(trim($input));
        if ($normalized === '' || empty($hashes)) {
            return ['matched' => false, 'remaining' => $hashes];
        }

        $remaining = [];
        $matched = false;
        foreach ($hashes as $hash) {
            if (!$matched && is_string($hash) && password_verify($normalized, $hash)) {
                $matched = true;
                continue;
            }
            $remaining[] = $hash;
        }

        return ['matched' => $matched, 'remaining' => $remaining];
    }
}
