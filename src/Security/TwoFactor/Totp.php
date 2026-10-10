<?php

namespace App\Security\TwoFactor;

/**
 * Time-based one-time passwords as used by authenticator apps (RFC 6238):
 * six digits, a new code every 30 seconds, HMAC-SHA1.
 */
final class Totp
{
    public const PERIOD = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new random secret in the Base32 spelling authenticator apps expect.
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);
        $offset = \ord($hash[19]) & 0x0F;
        $number = (unpack('N', substr($hash, $offset, 4))[1] ?? 0) & 0x7FFFFFFF;

        return str_pad((string) ($number % 10 ** self::DIGITS), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    public function currentStep(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    /**
     * Checks a code against the current time, allowing for clocks that are up to 30 seconds apart.
     *
     * @return int|null the time step the code belongs to, or null when it is wrong
     */
    public function verify(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (1 !== preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $current = $this->currentStep($timestamp);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if (hash_equals($this->code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The address encoded in the QR code that an authenticator app scans.
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        // A colon separates issuer and account in the label, so neither may contain one.
        $issuer = str_replace(':', ' ', $issuer);

        return \sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode(str_replace(':', ' ', $account)),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if (false !== $position) {
                $bits .= str_pad(decbin($position), 5, '0', \STR_PAD_LEFT);
            }
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (8 === \strlen($chunk)) {
                $bytes .= \chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
