<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\MirzaBot;

use App\Modules\Imports\Migrators\MirzaBot\Cell;
use PHPUnit\Framework\TestCase;

/** MirzaBot's values read (and Faoxima's, descended from it): its placeholders as nothing, numbers kept as text, unix times, lists, channels. */
final class CellTest extends TestCase
{
    public function testTextWithoutItsPlaceholders(): void
    {
        self::assertSame(['a b', null, null, null, null], [Cell::text(' a b '), Cell::text(''), Cell::text('none'), Cell::text('NULL'), Cell::text(null)]);
    }

    public function testNumbersInTheirPlainestDigits(): void
    {
        self::assertSame(
            ['10', '12.5', '10000', '10000', '-3', '0', '30', null, null, null],
            [Cell::number('10'), Cell::number('12.50'), Cell::number('10,000'), Cell::number('۱۰٬۰۰۰'), Cell::number('-3'), Cell::number('-0'), Cell::number('30.000'), Cell::number('abc'), Cell::number('1e5'), Cell::number(null)],
        );
        self::assertSame([150000, 0, 12], [Cell::whole('150000'), Cell::whole('none'), Cell::whole('12.9')]);
        self::assertSame(['120000.00', '-30000.00', '0.00'], [Cell::amount('120000'), Cell::amount('-30000'), Cell::amount('')]);
    }

    public function testUnixTimesAndLists(): void
    {
        self::assertEquals(new \DateTimeImmutable('@1700000000'), Cell::moment('1700000000'));
        self::assertSame([null, null, null], [Cell::moment('0'), Cell::moment('none'), Cell::moment('1403/01/01')]);
        self::assertSame(['آلمان ۱', 'هلند'], Cell::list(' آلمان ۱ ,هلند,,آلمان ۱'));
        self::assertSame([], Cell::list(null));
    }

    public function testAChannelAsTheBotAsksTelegramAboutIt(): void
    {
        self::assertSame(
            ['-1001234567890', '@my_channel', '@my_channel', '@my_channel', '@vpn_news', '@vpn_news', '@vpn_news'],
            [Cell::chat('-1001234567890'), Cell::chat('@my_channel'), Cell::chat('my_channel'), Cell::chat('https://t.me/my_channel'), Cell::chat('t.me/s/vpn_news'), Cell::chat('https://telegram.me/vpn_news/'), Cell::chat('https://t.me/vpn_news?start=1')],
            'a chat id, an @username kept with its @, bare, or in a t.me link',
        );
        self::assertSame(
            [null, null, null, null, null],
            [Cell::chat('https://t.me/joinchat/AbCdEf'), Cell::chat('https://t.me/+AbCdEf'), Cell::chat('t.me/joinchat'), Cell::chat('@ab'), Cell::chat('-123')],
            'an invite link, a username Telegram would not take, a short number',
        );
    }
}
