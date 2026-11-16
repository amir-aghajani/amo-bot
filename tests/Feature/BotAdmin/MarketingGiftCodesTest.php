<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Rewards\Services\GiftCodes;
use App\Modules\Rewards\Services\GiftCodeSettings;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Support\LocalTime;
use GuzzleHttp\Psr7\Response;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * «🎟 کدهای هدیه» in the bot's management (Sections\Marketing\GiftCodes): a batch made as a wizard — its codes sent as a
 * file —, the batch's unused codes as a file again, its codes listed, one deleted, the rest discarded, and the redeem
 * switch.
 */
final class MarketingGiftCodesTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
        $this->send($this->message('/admin', chat: self::OWNER));
    }

    public function testABatchIsMadeSentHandedOutAgainAndDiscarded(): void
    {
        $this->tapAs(Nav::to('gc'));
        self::assertStringContainsString('استفاده از کد هدیه: ⏸ خاموش', $this->said()[0]);
        self::assertStringContainsString('هنوز کد هدیه‌ای نساخته‌اید', $this->said()[0]);

        $this->tapAs(Nav::to('gcf', 'new'));
        $this->tapAs(Nav::to('gcf', 'pick', 0, 0));
        $this->send($this->message('۲۰ هزار تومان', chat: self::OWNER));
        $this->send($this->message('3', chat: self::OWNER));
        $this->send($this->message('نوروز', chat: self::OWNER));
        $this->send($this->message('nw', chat: self::OWNER));
        $this->tapAs(Nav::to('gcf', 'q', 6, 1));
        self::assertStringContainsString('۳۰ روز — تا پایان', $this->said()[0], 'the summary says the last day');
        self::assertSame(0, GiftCode::query()->count());

        $this->tapAs(Nav::to('gcf', 'save'));
        $codes = GiftCode::query()->orderBy('id')->get();
        self::assertCount(3, $codes);
        self::assertSame(['نوروز', 'wallet', '20000.00'], [$codes[0]->batch, $codes[0]->kind->value, $codes[0]->amount]);
        self::assertStringStartsWith('NW', $codes[0]->code);
        self::assertSame(LocalTime::of(now())->modify('+31 days')->format('Y-m-d'), LocalTime::of($codes[0]->expires_at ?? self::fail('no end'))->format('Y-m-d'), 'good to the end of the 30th day');
        self::assertSame($codes->pluck('code')->all(), $this->sentFile(), 'the codes made, as a file');
        self::assertStringContainsString('سری «نوروز»', $this->lastSaid(), 'the new batch');

        self::assertStringContainsString('"copy_text":{"text":"' . implode('\n', $codes->pluck('code')->all()) . '"}', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '', 'a few unused codes copied at a tap');
        $this->tapAs(Nav::to('gcb', 'file'));
        self::assertSame($codes->pluck('code')->all(), $this->sentFile());

        $this->tapAs(Nav::to('gcb', 'codes'));
        self::assertStringContainsString('سری «نوروز»', $this->said()[0]);
        self::assertContains(Nav::to('gcv', 'show', $codes[0]->id), $this->buttons());
        $this->tapAs(Nav::to('gcv', 'show', $codes[0]->id));
        self::assertStringContainsString($codes[0]->code, $this->said()[0]);
        $this->tapAs(Nav::to('gcv', 'del', $codes[0]->id));
        $this->tapAs(Nav::to('gcv', 'gone', $codes[0]->id));
        self::assertSame(2, GiftCode::query()->count());

        $this->tapAs(Nav::to('gc'));
        self::assertContains(Nav::to('gcb', 'show', 0), $this->buttons());
        $this->tapAs(Nav::to('gcb', 'show', 0));
        $this->tapAs(Nav::to('gcb', 'drop'));
        self::assertStringContainsString('۲ کدی که کسی استفاده نکرده حذف می‌شود', $this->said()[0]);
        $this->tapAs(Nav::to('gcb', 'dropped'));
        self::assertSame(0, GiftCode::query()->count());
        self::assertStringContainsString('کدهای هدیه', $this->said()[0], 'the batch gone: the codes\' menu');
    }

    public function testAnOlderWindowsYesDiscardsOnlyTheBatchItAskedAbout(): void
    {
        $codes = $this->service(GiftCodes::class);
        $codes->make(['count' => 2, 'kind' => 'wallet', 'amount' => 10000, 'batch' => 'یلدا']);
        $codes->make(['count' => 3, 'kind' => 'wallet', 'amount' => 20000, 'batch' => 'نوروز']);
        $this->tapAs(Nav::to('gc'));
        $this->tapAs(Nav::to('gcb', 'show', 0));
        self::assertStringContainsString('سری «نوروز»', $this->said()[0], 'the newest first');
        $this->tapAs(Nav::to('gcb', 'drop'));
        $yes = array_values(array_filter($this->buttons(), static fn(string $data): bool => str_starts_with($data, Nav::to('gcb', 'dropped'))))[0] ?? self::fail('no yes');
        self::assertNotSame(Nav::to('gcb', 'dropped'), $yes, 'the yes names its batch');
        self::assertLessThanOrEqual(64, strlen($yes));

        // Another batch opened since (a newer window): the first window's yes discards nothing.
        $this->tapAs(Nav::to('gc'));
        $this->tapAs(Nav::to('gcb', 'show', 1));
        self::assertStringContainsString('سری «یلدا»', $this->said()[0]);
        $this->tapAs($yes);
        self::assertSame('این دکمه برای سری دیگری بود؛ چیزی حذف نشد.', $this->popup());
        self::assertSame(5, GiftCode::query()->count());

        $this->tapAs(Nav::to('gcb', 'drop'));
        $this->tapAs(array_values(array_filter($this->buttons(), static fn(string $data): bool => str_starts_with($data, Nav::to('gcb', 'dropped'))))[0] ?? self::fail('no yes'));
        self::assertSame(['نوروز'], GiftCode::query()->distinct()->pluck('batch')->all(), 'its own yes: that batch alone');
    }

    public function testAPlanCodeNeedsItsPlanAndTheSwitchTurnsOn(): void
    {
        $plan = $this->plan(['name' => 'هدیه یک ماهه', 'is_active' => false]);
        $this->tapAs(Nav::to('gcf', 'new'));
        $this->tapAs(Nav::to('gcf', 'pick', 0, 1));
        self::assertStringContainsString('غیرفعال؛ فقط برای هدیه', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '');
        $this->tapAs(Nav::to('gcf', 'pick', 2, 0));
        $this->tapAs(Nav::to('gcf', 'q', 3, 0));
        $this->send($this->message('یلدا', chat: self::OWNER));
        $this->tapAs(Nav::to('gcf', 'skip', 5));
        $this->tapAs(Nav::to('gcf', 'skip', 6));
        $this->tapAs(Nav::to('gcf', 'save'));
        self::assertSame(10, GiftCode::query()->where('plan_id', $plan->id)->whereNull('expires_at')->count());

        $this->tapAs(Nav::to('gcs'));
        $this->tapAs(Nav::to('gcs', 'e', 0));
        self::assertTrue($this->service(GiftCodeSettings::class)->enabled());
    }

    public function testTelegramRefusingTheFileLeavesNoDraftToMakeASecondBatch(): void
    {
        $this->telegram()->on('sendDocument', static fn(): Response => FakeTelegram::error(400, 'Bad Request: file is too big'));
        $this->tapAs(Nav::to('gcf', 'new'));
        $this->tapAs(Nav::to('gcf', 'pick', 0, 0));
        $this->send($this->message('۲۰ هزار تومان', chat: self::OWNER));
        $this->send($this->message('3', chat: self::OWNER));
        $this->send($this->message('یلدا', chat: self::OWNER));
        $this->tapAs(Nav::to('gcf', 'skip', 5));
        $this->tapAs(Nav::to('gcf', 'skip', 6));

        $this->tapAs(Nav::to('gcf', 'save'));
        self::assertSame(3, GiftCode::query()->count());
        self::assertContains('sendDocument', $this->calls(), 'the file was tried');
        self::assertStringContainsString('تلگرام فایل', (string) $this->popup(), 'its refusal said');
        self::assertStringContainsString('سری «یلدا»', $this->lastSaid(), 'the codes stand: the new batch opens');

        $this->tapAs(Nav::to('gcf', 'save'));
        self::assertSame(3, GiftCode::query()->count(), 'a second tap finds no draft: no second batch');
        self::assertStringContainsString('🎟 کدهای هدیه', $this->said()[0], 'the form left for the codes\' menu');
    }

    public function testAShopOfManyPlansPicksAPlanFromItsPages(): void
    {
        $plans = [];
        for ($n = 1; $n <= 45; $n++) {
            $plans[] = $this->plan(['name' => 'پلن ' . $n]);
        }
        $this->tapAs(Nav::to('gcf', 'new'));
        $this->tapAs(Nav::to('gcf', 'pick', 0, 1));
        self::assertContains(Nav::to('gcf', 'pick', 2, 39), $this->buttons());
        self::assertNotContains(Nav::to('gcf', 'pick', 2, 40), $this->buttons(), 'forty plans a page');
        self::assertContains(Nav::to('gcf', 'pg', 2, 2), $this->buttons(), 'the way to the next page');

        $this->tapAs(Nav::to('gcf', 'pg', 2, 2));
        self::assertContains(Nav::to('gcf', 'pick', 2, 44), $this->buttons());
        self::assertNotContains(Nav::to('gcf', 'pick', 2, 0), $this->buttons());
        $this->tapAs(Nav::to('gcf', 'pick', 2, 44));
        self::assertStringContainsString('چند کد ساخته شود؟', $this->said()[0], 'picked: the next field');
        $this->tapAs(Nav::to('gcf', 'q', 3, 0));
        $this->send($this->message('آخر', chat: self::OWNER));
        $this->tapAs(Nav::to('gcf', 'skip', 5));
        $this->tapAs(Nav::to('gcf', 'skip', 6));
        $this->tapAs(Nav::to('gcf', 'ask', 2));
        self::assertContains(Nav::to('gcf', 'pick', 2, 44), $this->buttons(), 'asked again: on the page of its plan');

        $this->tapAs(Nav::to('gcf', 'save'));
        self::assertSame(10, GiftCode::query()->where('plan_id', $plans[44]->id)->count());
    }

    /**
     * The codes in the file sent last.
     *
     * @return list<string>
     */
    private function sentFile(): array
    {
        $file = null;
        foreach ($this->telegram()->history as $call) {
            if ($call['method'] === 'sendDocument') {
                $file = $call['files']['document'] ?? null;
            }
        }

        return $file === null ? [] : array_values(array_filter(explode("\n", $file)));
    }

    /** @return list<string> The window's buttons' data. */
    private function buttons(): array
    {
        return $this->callbacks($this->buttonsCall());
    }
}
