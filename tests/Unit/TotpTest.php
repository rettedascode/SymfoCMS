<?php

namespace App\Tests\Unit;

use App\Security\TwoFactor\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** The ASCII secret "12345678901234567890" of the RFC 6238 test vectors, in Base32. */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testCodesMatchTheReferenceValuesOfTheStandard(): void
    {
        $totp = new Totp();

        // The standard lists eight-digit codes; apps show the last six.
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            self::assertSame($code, $totp->code(self::SECRET, $totp->currentStep($time)), (string) $time);
        }
    }

    public function testVerifyToleratesSlightlyDifferentClocksOnly(): void
    {
        $totp = new Totp();
        $now = 1234567890;
        $step = $totp->currentStep($now);

        self::assertSame($step, $totp->verify(self::SECRET, '005924', $now));
        self::assertSame($step, $totp->verify(self::SECRET, ' 005 924 ', $now), 'Spaces typed for readability do not matter.');
        self::assertSame($step - 1, $totp->verify(self::SECRET, $totp->code(self::SECRET, $step - 1), $now));
        self::assertSame($step + 1, $totp->verify(self::SECRET, $totp->code(self::SECRET, $step + 1), $now));
        self::assertNull($totp->verify(self::SECRET, $totp->code(self::SECRET, $step - 2), $now));
        self::assertNull($totp->verify(self::SECRET, $totp->code(self::SECRET, $step + 2), $now));
        self::assertNull($totp->verify(self::SECRET, '12345', $now));
        self::assertNull($totp->verify(self::SECRET, 'abcdef', $now));
        self::assertNull($totp->verify(self::SECRET, '', $now));
    }

    public function testSecretsAreRandomAndUsableByApps(): void
    {
        $totp = new Totp();
        $secret = $totp->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertNotSame($secret, $totp->generateSecret());
        self::assertSame(
            'otpauth://totp/My%20Site:ada%40example.com?secret='.$secret.'&issuer=My%20Site&digits=6&period=30',
            $totp->provisioningUri($secret, 'ada@example.com', 'My Site'),
        );
        self::assertStringContainsString('otpauth://totp/A%20%20B:', $totp->provisioningUri($secret, 'x', 'A: B'));
    }
}
