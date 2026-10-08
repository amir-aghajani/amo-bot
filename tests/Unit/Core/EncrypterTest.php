<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Security\DecryptionException;
use App\Core\Security\Encrypter;
use PHPUnit\Framework\TestCase;

final class EncrypterTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $encrypter = Encrypter::fromKey(Encrypter::generateKey());

        $payload = $encrypter->encrypt('panel-password-تست');

        self::assertNotSame('panel-password-تست', $payload);
        self::assertSame('panel-password-تست', $encrypter->decrypt($payload));
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        $encrypter = Encrypter::fromKey(Encrypter::generateKey());

        self::assertNotSame($encrypter->encrypt('same'), $encrypter->encrypt('same'));
    }

    public function testWrongKeyFails(): void
    {
        $payload = Encrypter::fromKey(Encrypter::generateKey())->encrypt('secret');

        $this->expectException(DecryptionException::class);
        Encrypter::fromKey(Encrypter::generateKey())->decrypt($payload);
    }

    public function testMalformedPayloadFails(): void
    {
        $this->expectException(DecryptionException::class);
        Encrypter::fromKey(Encrypter::generateKey())->decrypt('not-base64!!');
    }

    public function testRejectsKeysOfWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Encrypter::fromKey('base64:' . base64_encode('too-short'));
    }

    public function testAKeyedHashIsMadeUnderAKeyOfItsOwnNeverTheEncryptionKey(): void
    {
        $raw = random_bytes(32);
        $encrypter = Encrypter::fromKey('base64:' . base64_encode($raw));
        $code = 'sign_up|sara@example.com|123456';

        self::assertSame($encrypter->mac($code), $encrypter->mac($code), 'the same text hashes the same');
        self::assertNotSame(hash_hmac('sha256', $code, $raw), $encrypter->mac($code), 'never under the key that encrypts');
        self::assertSame(hash_hmac('sha256', $code, hash_hkdf('sha256', $raw, 32, 'amobot-mac')), $encrypter->mac($code), 'under the key derived from APP_KEY for it');
        self::assertNotSame($encrypter->mac($code), Encrypter::fromKey(Encrypter::generateKey())->mac($code), "another APP_KEY's hash is another");
    }

    public function testEmptyKeyIsNotReady(): void
    {
        $encrypter = Encrypter::fromKey('');

        self::assertFalse($encrypter->isReady());
        $this->expectException(\RuntimeException::class);
        $encrypter->encrypt('x');
    }
}
