<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\BotTestCase;

/**
 * The bot speaks in the admin's words: a reworded text reaches the customer with its variables filled —
 * the greeting, a button, the delivered service — and a caption the admin made too long for Telegram goes
 * as a message rather than not at all.
 */
final class BotCustomTextsTest extends BotTestCase
{
    private BotTexts $texts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->texts = $this->service(BotTexts::class);
    }

    public function testTheGreetingIsTheAdmins(): void
    {
        $this->texts->save(BotText::Welcome, 'درود <b>%name%</b> 🌸');

        $this->send($this->message('/start'));

        self::assertSame('درود <b>Ali</b> 🌸', $this->params(0)['text']);
    }

    public function testAButtonIsTheAdmins(): void
    {
        $user = $this->customer();
        $this->subscription($user, $this->plan(), $this->fakeServer(), 'ali_1');
        $this->inlineStartMenu();
        $this->texts->save(BotText::ServiceButton, '🔹 %client%');
        $this->texts->save(BotText::Back, '🔙 منو');

        $this->send($this->tap(MainMenu::SUBSCRIPTIONS));

        $rows = $this->inlineKeyboard(0);
        self::assertSame('🔹 ali_1', $rows[0][0]['text']);
        self::assertSame('🔙 منو', end($rows)[0]['text']);
    }

    public function testADeliveryCarriesTheAdminsWordingAndTheLink(): void
    {
        $this->withoutQr();
        $subscription = $this->subscription($this->customer(), $this->plan(), $this->fakeServer(), 'ali_1');
        $this->texts->save(BotText::PaySuccess, "🎉 سرویس %client% روی %server% آماده است\n<code>%subscription%</code>");

        $this->service(ServiceCard::class)->send(self::CHAT, $subscription, BotText::PaySuccess);

        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame("🎉 سرویس ali_1 روی آلمان آماده است\n<code>https://fake.test/sub/ali_1</code>", $this->params(0)['text']);
    }

    #[RequiresPhpExtension('gd')]
    public function testACaptionTooLongForTelegramGoesAsAMessage(): void
    {
        $subscription = $this->subscription($this->customer(), $this->plan(), $this->fakeServer(), 'ali_1', [
            'subscription_url' => 'https://fake.test/sub/' . str_repeat('a', 400),
        ]);
        $this->texts->save(BotText::PaySuccess, str_repeat('ب', 700) . "\n<code>%subscription%</code>");

        $this->service(ServiceCard::class)->send(self::CHAT, $subscription, BotText::PaySuccess);

        self::assertSame(['sendMessage'], $this->calls(), 'over 1024 characters: no card, the text alone');

        $this->texts->reset(BotText::PaySuccess);
        $this->telegram()->reset();
        $this->service(ServiceCard::class)->send(self::CHAT, $subscription, BotText::PaySuccess);
        self::assertSame(['sendPhoto'], $this->calls(), 'the shop\'s wording fits under the card');
    }
}
