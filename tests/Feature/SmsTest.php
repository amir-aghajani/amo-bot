<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Sms\Sms;
use App\Core\Sms\SmsFailedException;
use Tests\DatabaseTestCase;

/**
 * The installation's SMS as the shop asks it: off until a driver is set up, agents' websites off until the owner lets
 * them, a code handed to the provider, and a refusal told as one.
 */
final class SmsTest extends DatabaseTestCase
{
    public function testNothingGoesUntilADriverIsSetUp(): void
    {
        $sms = $this->service(Sms::class);
        self::assertFalse($sms->available());
        self::assertFalse($sms->forAgents());

        $this->expectException(SmsFailedException::class);
        $sms->sendCode('+989121234567', '123456');
    }

    public function testACodeGoesToTheProviderOnceSetUp(): void
    {
        $provider = $this->sms();
        $sms = $this->service(Sms::class);

        self::assertTrue($sms->available());
        self::assertFalse($sms->forAgents(), 'agents\' websites wait for the owner');
        $sms->sendCode('+989121234567', '123456');
        self::assertSame('123456', $provider->lastCodeTo('+989121234567'));

        $this->sms(forAgents: true);
        self::assertTrue($this->service(Sms::class)->forAgents());
    }

    public function testARefusalIsToldAsOne(): void
    {
        $this->sms()->refuse('insufficient credit');

        try {
            $this->service(Sms::class)->sendCode('+989121234567', '123456');
            self::fail('sent all the same');
        } catch (SmsFailedException $e) {
            self::assertSame(SmsFailedException::MESSAGE, $e->getMessage());
            self::assertSame('پیامک فرستاده نشد: insufficient credit', $e->explained()->getMessage());
        }
    }
}
