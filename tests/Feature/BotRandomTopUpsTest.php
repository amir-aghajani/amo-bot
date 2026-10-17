<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Services\TopUpAmounts;
use App\Support\Money;
use Tests\BotTestCase;

/**
 * The top-up screen's amounts drawn at random (WalletSettings::randomButtons(), TopUpAmounts): none by default; else as
 * many as the shop asks, after the presets, each a multiple of its rounding within its range — never below the smallest
 * top-up —, distinct, none a preset, ascending, drawn anew each time; each opens its checkout as a preset does.
 */
final class BotRandomTopUpsTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->customer();
        $this->cardMethod();
    }

    public function testTheScreenOffersThePresetsAloneByDefault(): void
    {
        $this->send($this->tap(TopUpHandler::START));

        self::assertSame([
            [TopUpHandler::checkoutCallback(100000), TopUpHandler::checkoutCallback(50000)],
            [TopUpHandler::checkoutCallback(500000), TopUpHandler::checkoutCallback(200000)],
            ['topup:custom'],
            [MainMenu::WALLET],
        ], $this->rows(), 'two a row in reading order: the smaller on the right');
    }

    public function testTheAmountsDrawnComeAfterThePresetsAndEachOpensItsCheckout(): void
    {
        // Two drawn among 60,000, 61,000 and 62,000 — never 61,000, a preset.
        $this->botSettings('wallet', ['topup_presets' => [50000, 61000], 'topup_random_count' => 2, 'topup_random_min' => 59500, 'topup_random_max' => 62999, 'topup_random_round' => 1000]);

        $this->send($this->tap(TopUpHandler::START));

        self::assertSame([
            [TopUpHandler::checkoutCallback(61000), TopUpHandler::checkoutCallback(50000)],
            [TopUpHandler::checkoutCallback(62000), TopUpHandler::checkoutCallback(60000)],
            ['topup:custom'],
            [MainMenu::WALLET],
        ], $this->rows());
        self::assertSame([Money::format(62000), Money::format(60000)], array_column($this->inlineKeyboard(0)[1], 'text'), 'worded as the presets are');

        $this->send($this->tap(TopUpHandler::checkoutCallback(62000)));
        self::assertSame([self::text(BotText::TopupCheckout, ['amount' => Money::format(62000)])], $this->said());
    }

    public function testADrawIsDistinctRoundedWithinItsRangeAndNoPreset(): void
    {
        $this->botSettings('wallet', ['topup_min' => 20000, 'topup_presets' => [50000, 100000], 'topup_random_count' => 8, 'topup_random_min' => 20000, 'topup_random_max' => 400000, 'topup_random_round' => 50000]);
        $amounts = $this->service(TopUpAmounts::class);

        for ($draw = 0; $draw < 10; $draw++) {
            // 50,000 to 400,000 by 50,000 is eight amounts, two of them presets: the six others, every time.
            self::assertSame([150000, 200000, 250000, 300000, 350000, 400000], $amounts->random());
        }

        // A range too wide to list is drawn by picks: still distinct, rounded, within it, ascending.
        $this->botSettings('wallet', ['topup_random_min' => 20000, 'topup_random_max' => 500000000, 'topup_random_round' => 1000]);
        $drawn = $this->service(TopUpAmounts::class)->random();
        self::assertCount(8, array_unique($drawn));
        self::assertSame($drawn, array_values(array_unique($drawn)));
        $sorted = $drawn;
        sort($sorted);
        self::assertSame($sorted, $drawn, 'ascending');
        foreach ($drawn as $amount) {
            self::assertSame(0, $amount % 1000);
            self::assertTrue($amount >= 20000 && $amount <= 500000000 && !in_array($amount, [50000, 100000], true), (string) $amount);
        }

        // A range holding no rounded amount offers none.
        $this->botSettings('wallet', ['topup_random_min' => 60500, 'topup_random_max' => 60900, 'topup_random_round' => 1000]);
        self::assertSame([], $amounts->random());
    }

    /** @return list<list<string>> The callback data of the top-up screen's buttons, row by row. */
    private function rows(): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'callback_data'), $this->inlineKeyboard(0));
    }
}
