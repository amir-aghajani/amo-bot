<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerSubscriptions;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Validation;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * The customer's own name for a service («نام دلخواه», `PATCH /subscriptions/{id}` with `label`): trimmed, 32 characters
 * at most, one line without control characters, cleared when blank; sent with «تمدید خودکار» or alone, both judged before
 * either changes; the bot told nothing.
 */
final class StoreServiceLabelTest extends HttpTestCase
{
    private Website $website;

    private User $customer;

    private Server $server;

    private Plan $plan;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->customer = $this->customer();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
        $this->subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheirNameIsKeptTrimmedAndShown(): void
    {
        $response = $this->patch(['label' => '  گوشی مامان  ']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['گوشی مامان', 'ali_1'], [$this->decode($response)['subscription']['label'], $this->decode($response)['subscription']['name']]);
        self::assertSame('گوشی مامان', $this->subscription->refresh()->label);
        self::assertFalse($this->subscription->auto_renew, 'its «تمدید خودکار» as it was');
        self::assertSame('گوشی مامان', $this->decode($this->get($this->storeApi($this->website, '/subscriptions')))['subscriptions'][0]['label']);
        self::assertSame([], $this->telegram()->calls(), 'the bot says nothing of it');
    }

    public function testThirtyTwoCharactersAtMostCountedAsCharacters(): void
    {
        // Persian letters and a zero-width non-joiner: 32 characters, more than 32 bytes.
        $longest = str_repeat('سرویس‌خا', 4);
        self::assertSame(32, mb_strlen($longest));

        self::assertSame(200, $this->patch(['label' => $longest])->getStatusCode());
        self::assertSame($longest, $this->subscription->refresh()->label);

        $this->assertRefusedUnder('label', Validation::tooLong('نام دلخواه', CustomerSubscriptions::LABEL_MAX), $this->patch(['label' => $longest . 'ن']));
        self::assertSame($longest, $this->subscription->refresh()->label, 'kept as it was');
    }

    public function testOneLineWithoutControlCharacters(): void
    {
        foreach (["گوشی\nمامان", "گوشی\tمامان", "abc\u{202E}def", "گوشی\u{2028}مامان", "a\u{0007}b"] as $label) {
            $this->assertRefusedUnder('label', CustomerSubscriptions::LABEL_CONTROL, $this->patch(['label' => $label]), message: json_encode($label) ?: '');
        }
        self::assertNull($this->subscription->refresh()->label);
    }

    public function testBlankOrNullClearsIt(): void
    {
        foreach (['   ', null, "\u{200C}"] as $cleared) {
            $this->subscription->forceFill(['label' => 'لپ‌تاپ'])->save();

            $response = $this->patch(['label' => $cleared]);

            self::assertSame([200, null], [$response->getStatusCode(), $this->decode($response)['subscription']['label']], json_encode($cleared) ?: '');
            self::assertNull($this->subscription->refresh()->label);
        }
    }

    public function testTextAlone(): void
    {
        foreach ([5, true, ['گوشی']] as $label) {
            $this->assertRefusedUnder('label', CustomerSubscriptions::LABEL_NOT_TEXT, $this->unchecked()->patchJson($this->path(), ['label' => $label]));
        }
    }

    public function testWithAutoRenewBothOrNeither(): void
    {
        $response = $this->patch(['auto_renew' => true, 'label' => 'گوشی']);
        self::assertSame([true, 'گوشی'], [$this->decode($response)['subscription']['auto_renew']['on'], $this->decode($response)['subscription']['label']]);
        self::assertSame([true, 'گوشی'], [$this->subscription->refresh()->auto_renew, $this->subscription->label]);

        $this->assertRefusedUnder('label', Validation::tooLong('نام دلخواه', CustomerSubscriptions::LABEL_MAX), $this->patch(['auto_renew' => false, 'label' => str_repeat('a', 33)]));
        self::assertTrue($this->subscription->refresh()->auto_renew, 'a name refused switches nothing');

        $forever = $this->subscription($this->customer, $this->plan(['duration_hours' => 0], $this->server), $this->server, 'ali_2');
        $this->assertRefusedUnder('auto_renew', (new AutoRenewUnavailableException())->getMessage(), $this->patch(['auto_renew' => true, 'label' => 'گوشی'], $forever));
        self::assertNull($forever->refresh()->label, 'a switch refused names nothing');
        self::assertSame(200, $this->patch(['label' => 'گوشی'], $forever)->getStatusCode(), 'a service without auto-renewal is named all the same');

        $this->assertRefusedUnder('auto_renew', Validation::NOT_A_SWITCH, $this->unchecked()->patchJson($this->path(), ['auto_renew' => 'yes', 'label' => 'تبلت']));
        self::assertSame('گوشی', $this->subscription->refresh()->label);

        $nothing = $this->unchecked()->patchJson($this->path(), []);
        self::assertSame([422, CustomerSubscriptions::NOTHING_TO_CHANGE, null], [$nothing->getStatusCode(), $this->decode($nothing)['message'], $this->decode($nothing)['errors'] ?? null]);
    }

    public function testAnothersServiceIsA404(): void
    {
        $theirs = $this->subscription($this->customer(['telegram_id' => 7272]), $this->plan, $this->server, 'reza_1');

        $response = $this->patch(['label' => 'مال من'], $theirs);

        self::assertSame([404, ErrorHandler::NOT_FOUND], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertNull($theirs->refresh()->label);
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body, ?Subscription $subscription = null): ResponseInterface
    {
        return $this->patchJson($this->path($subscription), $body);
    }

    private function path(?Subscription $subscription = null): string
    {
        return $this->storeApi($this->website, '/subscriptions/' . ($subscription ?? $this->subscription)->id);
    }
}
