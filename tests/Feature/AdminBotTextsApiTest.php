<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\TextCatalog;
use App\Modules\Telegram\Texts\TextKind;
use Tests\HttpTestCase;

/**
 * «متن‌های ربات»: every text the bot sends a customer, by group, and the admin's rewording of one — checked
 * like any form (422 on `value`) — or its reset; an agent's shop, whose bot has no agency, lists none of the agency's.
 */
final class AdminBotTextsApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testTheScreenListsEveryTextByGroup(): void
    {
        $response = $this->get('/api/admin/bot/texts');

        self::assertSame(200, $response->getStatusCode());
        $groups = $this->decode($response)['groups'];
        self::assertSame(array_keys(TextCatalog::GROUPS), array_column($groups, 'key'), 'in the screen\'s order');
        self::assertSame(count(BotText::cases()), array_sum(array_map(static fn(array $group): int => count($group['texts']), $groups)));

        $welcome = $groups[0]['texts'][0];
        $spec = BotText::Welcome->spec();
        [$meaning, $sample] = TextCatalog::VARIABLES['name'];
        self::assertSame([
            'key' => BotText::Welcome->value,
            'group' => 'general',
            'kind' => TextKind::Message->value,
            'html' => true,
            'limit' => TextKind::Message->limit(),
            'title' => $spec->title,
            'description' => $spec->description,
            'variables' => [['name' => 'name', 'description' => $meaning, 'sample' => $sample, 'required' => false]],
            'default' => $spec->default,
            'value' => $spec->default,
            'customized' => false,
        ], $welcome);
    }

    public function testARewordingIsStoredAndUsed(): void
    {
        $saved = $this->putJson('/api/admin/bot/texts/welcome', ['value' => 'درود %name%!']);

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $text = $this->decode($saved)['text'];
        self::assertSame(['درود %name%!', true], [$text['value'], $text['customized']]);
        self::assertSame('درود Ali!', $this->service(BotTexts::class)->render(BotText::Welcome, ['name' => 'Ali']));
    }

    public function testAWordingThatCannotBeSentIsRefusedOnValue(): void
    {
        $refused = $this->putJson('/api/admin/bot/texts/pay_success', ['value' => '<b>سرویس آماده است</b>']);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['متغیر %subscription% باید در متن بماند.'], $this->decode($refused)['errors']['value']);
        self::assertFalse($this->service(BotTexts::class)->isCustomized(BotText::PaySuccess));

        $empty = $this->unchecked()->putJson('/api/admin/bot/texts/pay_success', []);
        self::assertSame(422, $empty->getStatusCode(), 'no wording at all');
    }

    public function testPremiumEmojiAreTakenAndOnlyWhatTheCustomerSeesIsCounted(): void
    {
        // A QR card's caption shows at most 850 characters; thirty premium emoji are ~1,600 of markup but thirty on screen.
        $emoji = str_repeat('<tg-emoji emoji-id="5368324170671202286">🔥</tg-emoji>', 30);
        $saved = $this->putJson('/api/admin/bot/texts/pay_success', ['value' => "{$emoji}\n%subscription%"]);

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame("{$emoji}\n%subscription%", $this->decode($saved)['text']['value']);
        self::assertTrue($this->service(BotTexts::class)->isCustomized(BotText::PaySuccess));

        $refused = $this->putJson('/api/admin/bot/texts/pay_success', ['value' => '<tg-emoji emoji-id="5368324170671202286">لایک</tg-emoji> %subscription%']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('فقط یک ایموجی', $this->decode($refused)['errors']['value'][0]);
    }

    public function testResetGoesBackToTheShopsWording(): void
    {
        $this->putJson('/api/admin/bot/texts/cancel', ['value' => '✖️ بیخیال']);

        $reset = $this->postJson('/api/admin/bot/texts/cancel/reset');

        self::assertSame(200, $reset->getStatusCode());
        self::assertSame([BotText::Cancel->spec()->default, false], [$this->decode($reset)['text']['value'], $this->decode($reset)['text']['customized']]);
    }

    public function testAnUnknownTextIsNotFound(): void
    {
        self::assertSame(404, $this->putJson('/api/admin/bot/texts/nope', ['value' => 'x'])->getStatusCode());
        self::assertSame(404, $this->postJson('/api/admin/bot/texts/nope/reset')->getStatusCode());
    }

    public function testAnAgentsBotSaysNoneOfTheAgencysTexts(): void
    {
        $this->loginAsAgent($this->agentBot());

        $groups = $this->decode($this->get('/api/agent/bot/texts'))['groups'];
        self::assertSame(array_values(array_diff(array_keys(TextCatalog::GROUPS), ['agency'])), array_column($groups, 'key'), 'its bot has no agency of its own');
        $keys = array_merge(...array_map(static fn(array $group): array => array_column($group['texts'], 'key'), $groups));
        self::assertNotContains(BotText::AgencyTerms->value, $keys);
        self::assertNotContains(BotText::WalletCredit->value, $keys, "an agent's credit is on their wallet with the main bot: no customer of an agent's bot has one");
        self::assertContains(BotText::WalletCharged->value, $keys, 'the rest of the wallet is its own');
        self::assertContains(BotText::Welcome->value, $keys);

        self::assertSame(404, $this->putJson('/api/agent/bot/texts/' . BotText::AgencyTerms->value, ['value' => 'x'])->getStatusCode(), 'nothing there to reword');
        self::assertSame(404, $this->postJson('/api/agent/bot/texts/' . BotText::AgencyTerms->value . '/reset')->getStatusCode());
        self::assertSame(404, $this->putJson('/api/agent/bot/texts/' . BotText::WalletCredit->value, ['value' => 'x'])->getStatusCode());
        self::assertSame(200, $this->putJson('/api/agent/bot/texts/cancel', ['value' => '✖️ بیخیال'])->getStatusCode(), 'its own texts are its own');

        $this->loginAsAdmin();
        $main = $this->decode($this->get('/api/admin/bot/texts'))['groups'];
        self::assertContains('agency', array_column($main, 'key'), "the main bot's agency speaks them");
        self::assertContains(BotText::WalletCredit->value, array_merge(...array_map(static fn(array $group): array => array_column($group['texts'], 'key'), $main)));
    }
}
