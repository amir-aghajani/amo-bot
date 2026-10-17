<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\TextCatalog;
use App\Modules\Telegram\Texts\TextKind;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\BotTestCase;

/**
 * The bot speaks in the admin's words: a reworded text reaches the customer with its variables filled —
 * the greeting, a button, the delivered service — and a caption the admin made too long for Telegram goes
 * as a message rather than not at all. A handle or a number filled in after words carries a left-to-right mark, in a
 * default and in the admin's wording alike.
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

    /**
     * A value that starts with a handle or a row's number — whom a service or money went to («@sara», «#12»), the agent's
     * bot, support's contact — has a left-to-right mark before it wherever words come before it on its line, in every
     * default: right to left, «به @sara» reads «sara@ به». Never one with no words before it on its line: the line takes
     * the direction of its first letter, and the mark would be it.
     */
    public function testAHandleOrARowNumberFilledInAfterWordsReadsAsTyped(): void
    {
        $bare = [];
        $leading = [];
        foreach (['@sara', '#12'] as $named) {
            foreach (BotText::cases() as $text) {
                $spec = $text->spec();
                $values = [];
                foreach (array_keys($spec->variables) as $name) {
                    $values[$name] = $name === 'name' ? $named : TextCatalog::VARIABLES[$name][1];
                }
                $said = $spec->kind === TextKind::Part ? (string) $this->texts->part($text, $values) : $this->texts->render($text, $values);
                foreach (explode("\n", $said) as $line) {
                    // A handle — not an address's «name@…» — and a row's number — not an entity's «&#039;».
                    preg_match_all('/(?<![\w.\/])@(?=\w)|(?<!&)#(?=[0-9۰-۹])/u', $line, $signs, PREG_OFFSET_CAPTURE);
                    foreach ($signs[0] as [, $at]) {
                        $before = substr($line, 0, $at);
                        if (Messages::wordsBefore($before) && !str_ends_with($before, "\u{200E}")) {
                            $bare[] = "{$text->value} ({$named})";
                        }
                    }
                    preg_match_all('/\x{200E}/u', $line, $marks, PREG_OFFSET_CAPTURE);
                    foreach ($marks[0] as [, $at]) {
                        if (!Messages::wordsBefore(substr($line, 0, $at))) {
                            $leading[] = "{$text->value} ({$named})";
                        }
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($bare)), 'a left-to-right mark right before each handle or number with words before it');
        self::assertSame([], array_values(array_unique($leading)), 'never a mark with no words before it on its line');
        self::assertStringContainsString("به \u{200E}@sara منتقل شد", $this->texts->render(BotText::TransferDone, ['client' => 'ali_1', 'name' => '@sara']));
        self::assertStringContainsString("\n👤 @sara\n", $this->texts->render(BotText::TransferConfirm, ['client' => 'ali_1', 'name' => '@sara']), 'alone on its line after an emoji: no mark');
        self::assertStringContainsString("ربات \u{200E}@my_shop_bot وصل شد", $this->texts->render(BotText::AgencyBotSaved, ['bot' => '@my_shop_bot']));
        self::assertSame("نیاز به کمک دارید؟ با ما در تماس باشید: \u{200E}@amo_support", $this->texts->render(BotText::SupportContact, ['contact' => '@amo_support']), 'the contact the admin typed in the settings');
    }

    /** The admin's wording gets the mark as the default does: before a value after words, not before one alone on its line nor twice. */
    public function testAnAdminsWordingMarksAHandleAfterWordsToo(): void
    {
        $this->texts->save(BotText::TransferDone, "✅ <code>%client%</code> رفت برای %name%\n👤 %name%\nگیرنده: \u{200E}%name%");

        self::assertSame(
            "✅ <code>ali_1</code> رفت برای \u{200E}@sara\n👤 @sara\nگیرنده: \u{200E}@sara",
            $this->texts->render(BotText::TransferDone, ['client' => 'ali_1', 'name' => '@sara']),
        );
        self::assertSame(
            "✅ <code>ali_1</code> رفت برای Sara (\u{200E}@sara)\n👤 Sara (\u{200E}@sara)\nگیرنده: \u{200E}Sara (\u{200E}@sara)",
            $this->texts->render(BotText::TransferDone, ['client' => 'ali_1', 'name' => "Sara (\u{200E}@sara)"]),
            'a name first, its handle marked by whoever named it: nothing more',
        );
    }
}
