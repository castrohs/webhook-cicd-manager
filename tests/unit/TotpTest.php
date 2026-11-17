<?php

use App\Libraries\Totp;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TotpTest extends CIUnitTestCase
{
    public function testRfc6238VectorForSha1(): void
    {
        // RFC 6238 test secret: "12345678901234567890" -> Base32: GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);

        // RFC 6238 Table 1 (SHA1, 8 digits -> last 6 digits for 6-digit TOTP) at t=59 => 94287082 -> 287082
        $code = Totp::getCode($secret, 59);
        $this->assertSame('287082', $code);
        $this->assertTrue(Totp::verify($secret, '287082', 1, 59));
        $this->assertTrue(Totp::verify($secret, '287082', 1, 60)); // within +1 window
        $this->assertFalse(Totp::verify($secret, '000000', 1, 59));
        $this->assertFalse(Totp::verify($secret, 'abc123', 1, 59));
    }

    public function testGenerateSecretAndProvisioningUriWithCustomIssuer(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);

        $uri = Totp::getProvisioningUri($secret, 'admin', 'Minha VPS Prod');
        $this->assertStringStartsWith('otpauth://totp/Minha%20VPS%20Prod:admin?', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=Minha%20VPS%20Prod', $uri);
    }

    public function testRecoveryCodesAreGeneratedAndConsumedOnce(): void
    {
        $codes = Totp::generateRecoveryCodes(6);
        $this->assertCount(6, $codes);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{4}-[A-F0-9]{4}$/', $codes[0]);

        $hashes = array_map(static fn (string $c): string => password_hash($c, PASSWORD_DEFAULT), $codes);

        // First use succeeds and removes the used hash
        $first = Totp::consumeRecoveryCode(strtolower($codes[0]), $hashes);
        $this->assertTrue($first['matched']);
        $this->assertCount(5, $first['remaining']);

        // Second use of the same code fails
        $second = Totp::consumeRecoveryCode($codes[0], $first['remaining']);
        $this->assertFalse($second['matched']);
        $this->assertCount(5, $second['remaining']);
    }
}
