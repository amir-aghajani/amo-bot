<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Channels\ChannelReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the admin pastes to name a required channel, reduced to what getChat takes: a public link or handle to
 * "@name", a private chat's numeric id as it is; a private invite link recognised as one the Bot API cannot look up.
 */
final class ChannelReferenceTest extends TestCase
{
    /** @return iterable<string, array{string, int|string}> */
    public static function publicForms(): iterable
    {
        yield 'https link' => ['https://t.me/my_channel', '@my_channel'];
        yield 'http link with www' => ['http://www.t.me/my_channel/', '@my_channel'];
        yield 'bare domain' => ['t.me/My_Channel', '@My_Channel'];
        yield 'telegram.me' => ['https://telegram.me/my_channel', '@my_channel'];
        yield 'telegram.dog' => ['https://telegram.dog/my_channel', '@my_channel'];
        yield 'handle' => ['@my_channel', '@my_channel'];
        yield 'plain name' => ['  my_channel ', '@my_channel'];
        yield 'numeric id' => ['-1001234567890', -1001234567890];
    }

    #[DataProvider('publicForms')]
    public function testPublicLinksAndIdsReduceToWhatGetChatTakes(string $input, int|string $chat): void
    {
        $reference = ChannelReference::parse($input);

        self::assertNotNull($reference);
        self::assertSame($chat, $reference->chat());
        self::assertFalse($reference->isInviteLink());
    }

    public function testPrivateInviteLinksAreRecognisedButNotResolvable(): void
    {
        foreach (['https://t.me/+AbC-dEf123', 't.me/joinchat/AbCdEf123'] as $link) {
            $reference = ChannelReference::parse($link);
            self::assertNotNull($reference, $link);
            self::assertTrue($reference->isInviteLink(), $link);
        }
    }

    public function testAnythingElseIsRejected(): void
    {
        foreach (['', 'https://example.com/foo', 'abc', '1abc', 'name with space', 'https://t.me/', '12'] as $input) {
            self::assertNull(ChannelReference::parse($input), "\"{$input}\" is not a channel");
        }
    }
}
