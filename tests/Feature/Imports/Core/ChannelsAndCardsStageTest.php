<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Modules\Imports\Records\ImportedCard;
use App\Modules\Imports\Records\ImportedChannel;
use App\Modules\Imports\Writers\CardWriter;
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Telegram\Models\BotChannel;
use Tests\Support\FakeTelegram;

/**
 * The last stages: the other bot's required channels added as the bot's own are (Telegram asked, the bot an admin
 * there), one already required linked and one refused named in Telegram's words; and its bank cards added to the
 * shop's card-to-card method — one made switched off when the shop has none —, a card it shows already linked, a
 * number that is no card's named.
 */
final class ChannelsAndCardsStageTest extends ImportTestCase
{
    public function testAChannelIsAddedAsTheBotsRequiredChannelsAreAndOneRequiredAlreadyIsLinked(): void
    {
        $this->channel(-1001000000009, 'قدیمی', ['username' => 'Old_News']);
        $this->telegram()->reply(['id' => -1001000000001, 'type' => 'channel', 'title' => 'اخبار', 'username' => 'shop_news']);
        $this->telegram()->reply(['status' => 'administrator', 'user' => ['id' => FakeTelegram::BOT_ID]]);
        $this->migrator->channels = [new ImportedChannel('ch1', '@shop_news', 'اخبار فروشگاه'), new ImportedChannel('ch2', 'https://t.me/old_news')];

        $import = $this->importAll(['channels' => true]);

        self::assertSame(['old_news' => -1001000000009, 'shop_news' => -1001000000001], BotChannel::query()->pluck('chat_id', 'username')->mapWithKeys(static fn(int $id, string $name): array => [strtolower($name) => $id])->sortKeys()->all());
        self::assertSame(['created' => 1, 'linked' => 1, 'skipped' => 0], self::counts($import)['channels']);
    }

    public function testAChannelTelegramRefusesIsNamedInItsWords(): void
    {
        $this->telegram()->fail(400, 'Bad Request: chat not found');
        $this->migrator->channels = [new ImportedChannel('ch1', '@private_one', 'کانال ما', 'https://t.me/+AbCdEf')];

        $import = $this->importAll(['channels' => true]);

        self::assertSame(0, BotChannel::query()->count());
        $problem = self::problemRows($import)[0];
        self::assertSame(['ch1', 'channel_refused'], [$problem['key'], $problem['reason']]);
        self::assertStringStartsWith("\u{2068}@private_one\u{2069} «کانال ما» \u{2068}https://t.me/+AbCdEf\u{2069}: کانال پیدا نشد", (string) $problem['detail'], 'what finds it again, and Telegram\'s word');
    }

    public function testChannelsAreOffByDefault(): void
    {
        $this->migrator->channels = [new ImportedChannel('ch1', '@shop_news')];

        $this->importAll();

        self::assertSame([], $this->telegram()->calls());
        self::assertSame(0, BotChannel::query()->count());
    }

    public function testCardsGoToANewCardMethodSwitchedOffWhenTheShopHasNone(): void
    {
        $this->migrator->cards = [
            new ImportedCard('k1', '6037-9977-0000-0004', 'علی رضایی', 'ملت'),
            new ImportedCard('k2', '6104 3378 1234 0006', 'سارا کریمی'),
            new ImportedCard('k3', '1234', 'کسی'),
        ];

        $import = $this->importAll();

        $method = PaymentMethod::query()->where('driver', 'manual')->sole();
        self::assertSame([CardWriter::METHOD, false], [$method->label, $method->enabled]);
        self::assertSame([['6037997700000004', 'علی رضایی', 'ملت', false], ['6104337812340006', 'سارا کریمی', null, false]], $method->cards()->get()->map(static fn(PaymentCard $card): array => [$card->number, $card->holder, $card->bank, $card->enabled])->all(), 'every card switched off, whatever the other bot said');
        self::assertSame([['kind' => 'cards', 'key' => 'k3', 'reason' => 'card_invalid', 'detail' => "\u{2068}1234\u{2069}"]], self::problemRows($import));
        self::assertSame(['created' => 2, 'linked' => 0, 'skipped' => 1], self::counts($import)['cards']);
    }

    public function testCardsJoinTheShopsCardMethodAndOneItShowsAlreadyIsLinked(): void
    {
        $method = $this->cardMethod();
        $this->migrator->cards = [new ImportedCard('k1', '6037997700001119', 'AmoBot'), new ImportedCard('k2', '6273530011220004', 'رضا', 'ملی'), new ImportedCard('k3', '6037997700000004', '')];

        $import = $this->importAll();

        self::assertSame(['6037997700001119' => true, '6273530011220004' => false], $method->cards()->pluck('enabled', 'number')->all(), 'the shop\'s own card as it was, the new one switched off');
        self::assertSame(1, PaymentMethod::query()->where('driver', 'manual')->count());
        self::assertSame(['created' => 1, 'linked' => 1, 'skipped' => 1], self::counts($import)['cards']);
        self::assertSame(['k3', 'refused'], [self::problemRows($import)[0]['key'], self::problemRows($import)[0]['reason']], 'a card without its holder is no card the method takes');

        $again = $this->importAll();
        self::assertSame(['created' => 0, 'linked' => 2, 'skipped' => 1], self::counts($again)['cards']);
    }

    public function testACardTheOwnerDeletedSinceIsNeverAddedBack(): void
    {
        $method = $this->cardMethod();
        $this->migrator->cards = [new ImportedCard('6273530011220004', '6273530011220004', 'رضا')];
        $this->importAll();
        $method->cards()->delete();

        $again = $this->importAll();

        self::assertSame(0, $method->cards()->count(), 'the owner removed it: brought once');
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 1], self::counts($again)['cards']);
    }
}
