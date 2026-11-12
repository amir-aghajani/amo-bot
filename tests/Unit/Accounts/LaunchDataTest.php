<?php

declare(strict_types=1);

namespace Tests\Unit\Accounts;

use App\Modules\Accounts\Support\LaunchData;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTelegram;
use Tests\Support\TelegramLaunch;

/**
 * A mini app's launch data checked as Telegram's documentation says (Telegram.WebApp.initData): signed with the bot's
 * token — no other bot's, nothing changed, added or taken away since, `signature` part of what is signed —, each field
 * once, decoded as a query string, an account in it, made within its maximum age and not later than the shop's clock
 * allows; and no longer than a launch is.
 */
final class LaunchDataTest extends TestCase
{
    /** The shop's clock in these tests. */
    private const NOW = '2026-10-27 12:00:00';

    /** How old a launch may be in these tests: a day, as a sign-in's. */
    private const MAX_AGE = 86_400;

    public function testTelegramsLaunchDataProvesTheAccountItIsAbout(): void
    {
        $madeAt = self::now()->subMinutes(5);

        $launch = LaunchData::read(TelegramLaunch::initData(5151, $madeAt), FakeTelegram::TOKEN, self::MAX_AGE, self::now());

        self::assertNotNull($launch);
        self::assertSame(5151, $launch->telegramId);
        self::assertSame(['username' => 'ali', 'first_name' => 'Ali', 'last_name' => 'Rezaei'], $launch->profile);
        self::assertSame($madeAt->getTimestamp(), $launch->madeAt->getTimestamp(), 'proven when Telegram made it');
    }

    /** The example of @telegram-apps' documentation (a bot token of theirs): a signature made by someone else's code holds. */
    public function testAPublishedExampleHolds(): void
    {
        $initData = 'query_id=AAHdF6IQAAAAAN0XohDhrOrc&user=%7B%22id%22%3A279058397%2C%22first_name%22%3A%22Vladislav%22%2C%22last_name%22%3A%22Kibenko%22%2C%22username%22%3A%22vdkfrost%22%2C%22language_code%22%3A%22ru%22%2C%22is_premium%22%3Atrue%7D&auth_date=1662771648&hash=c501b71e775f74ce10e377dea85a7ea24ecd640b223ea86dfe453e0eaed2e2b2';
        $token = '5768337691:AAH5YkoiEuPk8-FZa32hStHTqXiLPtAEhx8';

        $launch = LaunchData::read($initData, $token, self::MAX_AGE, Carbon::createFromTimestamp(1662771648 + 60));

        self::assertSame([279058397, 'vdkfrost'], [$launch?->telegramId, $launch?->profile['username']]);
        self::assertNull(LaunchData::read($initData, FakeTelegram::TOKEN, self::MAX_AGE, Carbon::createFromTimestamp(1662771648 + 60)), 'under another token');
    }

    /**
     * Launch data that proves nothing, and why.
     *
     * @return array<string, array{\Closure(): string}>
     */
    public static function refused(): array
    {
        $fields = static fn(array $over = []): array => TelegramLaunch::fields(5151, self::now()->subMinutes(5), fields: $over);
        $signed = static fn(array $over = []): \Closure => static fn(): string => TelegramLaunch::signed($fields($over), FakeTelegram::TOKEN);
        $tampered = static function (array $over) use ($fields): \Closure {
            return static fn(): string => http_build_query($over + $fields() + ['hash' => TelegramLaunch::hash($fields(), FakeTelegram::TOKEN)], '', '&', PHP_QUERY_RFC3986);
        };

        return [
            'a wrong hash' => [static fn(): string => (string) preg_replace_callback('/hash=([0-9a-f])/', static fn(array $digit): string => 'hash=' . ($digit[1] === '0' ? '1' : '0'), $signed()())],
            'signed with another bot\'s token' => [static fn(): string => TelegramLaunch::signed($fields(), FakeTelegram::AGENT_TOKEN)],
            'no hash' => [static fn(): string => http_build_query($fields(), '', '&', PHP_QUERY_RFC3986)],
            'another account put in' => [$tampered(['user' => (string) json_encode(['id' => 9090, 'first_name' => 'Thief'])])],
            'made later than it was' => [$tampered(['auth_date' => (string) self::now()->getTimestamp()])],
            'another start put in' => [$tampered(['start_param' => 'ref_thief'])],
            'Telegram\'s signature taken away' => [static fn(): string => (string) preg_replace('/&signature=[^&]*/', '', $signed()())],
            'signed without Telegram\'s signature' => [static function () use ($fields): string {
                $all = $fields();
                $unsigned = $all;
                unset($unsigned['signature']);

                return http_build_query($all + ['hash' => TelegramLaunch::hash($unsigned, FakeTelegram::TOKEN)], '', '&', PHP_QUERY_RFC3986);
            }],
            'a field twice' => [static fn(): string => $signed()() . '&auth_date=' . self::now()->getTimestamp()],
            'the hash twice' => [static function () use ($signed): string {
                $data = $signed()();
                preg_match('/hash=\w+/', $data, $hash);

                return $data . '&' . $hash[0];
            }],
            'a field without a value' => [static fn(): string => $signed()() . '&query_id'],
            'a field without a name' => [static fn(): string => '=x&' . $signed()()],
            'nothing' => [static fn(): string => ''],
            'made longer ago than it may be used' => [$signed(['auth_date' => (string) self::now()->subSeconds(self::MAX_AGE + 1)->getTimestamp()])],
            'made over a minute ahead of the shop\'s clock' => [$signed(['auth_date' => (string) self::now()->addSeconds(61)->getTimestamp()])],
            'made at no time' => [$signed(['auth_date' => null])],
            'made at a time that is no number' => [$signed(['auth_date' => '1761566400.5'])],
            'about no account' => [$signed(['user' => null])],
            'an account that is no JSON' => [$signed(['user' => '{"id":5151'])],
            'an account without an id' => [$signed(['user' => (string) json_encode(['first_name' => 'Ali'])])],
            'an account whose id is text' => [$signed(['user' => (string) json_encode(['id' => '5151', 'first_name' => 'Ali'])])],
            'an account whose id is no account\'s' => [$signed(['user' => (string) json_encode(['id' => 0, 'first_name' => 'Ali'])])],
            'longer than a launch is' => [$signed(['start_param' => str_repeat('a', LaunchData::MAX_BYTES)])],
        ];
    }

    /** @param \Closure(): string $initData */
    #[DataProvider('refused')]
    public function testLaunchDataThatDoesNotHoldProvesNothing(\Closure $initData): void
    {
        self::assertNull(LaunchData::read($initData(), FakeTelegram::TOKEN, self::MAX_AGE, self::now()));
    }

    public function testNoTokenProvesNothing(): void
    {
        $initData = TelegramLaunch::initData(5151, self::now(), '');

        self::assertNull(LaunchData::read($initData, '', self::MAX_AGE, self::now()), 'a bot without a token (an agent\'s not handed over yet)');
    }

    public function testTheMaximumAgeIsTheCallersAndHoldsToTheSecond(): void
    {
        $madeAt = self::now()->subSeconds(900);
        $initData = TelegramLaunch::initData(5151, $madeAt);

        self::assertNotNull(LaunchData::read($initData, FakeTelegram::TOKEN, 900, self::now()), 'exactly as old as it may be');
        self::assertNull(LaunchData::read($initData, FakeTelegram::TOKEN, 899, self::now()), 'a second older than that');
    }

    public function testAClockAMinuteAheadIsTakenAndItsLaunchMadeNowAsFarAsTheShopKnows(): void
    {
        $launch = LaunchData::read(TelegramLaunch::initData(5151, self::now()->addSeconds(60)), FakeTelegram::TOKEN, self::MAX_AGE, self::now());

        self::assertSame(self::now()->getTimestamp(), $launch?->madeAt->getTimestamp(), 'a session it proves is never proven in the shop\'s future');
    }

    /** A space comes as `%20` from one client and `+` from another: both are the space Telegram signed. */
    public function testAValueIsReadAsAQueryStringDecodesIt(): void
    {
        $fields = TelegramLaunch::fields(5151, self::now(), fields: ['user' => (string) json_encode(['id' => 5151, 'first_name' => 'Ali Reza', 'last_name' => 'C++ & co'])]);

        foreach ([false, true] as $plus) {
            $initData = TelegramLaunch::signed($fields, FakeTelegram::TOKEN, $plus);
            self::assertStringContainsString($plus ? 'Ali+Reza' : 'Ali%20Reza', $initData);

            $launch = LaunchData::read($initData, FakeTelegram::TOKEN, self::MAX_AGE, self::now());
            self::assertSame(['Ali Reza', 'C++ & co'], [$launch?->profile['first_name'], $launch?->profile['last_name']], $plus ? 'spaces as +' : 'spaces as %20');
        }
    }

    public function testTheFieldsAreSignedInTheOrderOfTheirNamesWhateverOrderTheyCameIn(): void
    {
        $fields = TelegramLaunch::fields(5151, self::now(), 'ref_ABC123');
        $hash = TelegramLaunch::hash($fields, FakeTelegram::TOKEN);

        $reversed = http_build_query(['hash' => $hash] + array_reverse($fields, true), '', '&', PHP_QUERY_RFC3986);

        self::assertSame(5151, LaunchData::read($reversed, FakeTelegram::TOKEN, self::MAX_AGE, self::now())?->telegramId);
    }

    private static function now(): Carbon
    {
        return Carbon::parse(self::NOW);
    }
}
