<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use Tests\HttpTestCase;

/**
 * The keyboard editor: the start menu's rows, the actions a button can stand for — an agent's shop has no «نمایندگی» —,
 * saving a layout (a button's premium emoji icon included) and going back to the built-in one.
 */
final class AdminKeyboardsApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testTheEditorGetsTheDefaultStartMenuTheActionsAndTheStyles(): void
    {
        $response = $this->get('/api/admin/keyboards');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(['start'], array_column($data['keyboards'], 'name'));
        $start = $data['keyboards'][0];
        self::assertSame('منوی شروع', $start['title']);
        self::assertTrue($start['is_default']);
        self::assertSame('reply', $start['type']);
        self::assertSame([['buy'], ['services', 'renew'], ['tutorial', 'wallet'], ['support', 'affiliates'], ['agency']], array_map(static fn(array $row): array => array_column($row, 'action'), $start['rows']), 'rows in reading order; the editor shows «زیرمجموعه‌گیری» and «نمایندگی» whether or not their programs run');
        self::assertSame(Messages::MENU_BUY, $start['rows'][0][0]['label']);
        self::assertNull($start['rows'][0][0]['style']);

        self::assertSame(['buy', 'services', 'renew', 'wallet', 'affiliates', 'agency', 'tutorial', 'support'], array_column($data['actions'], 'key'));
        self::assertSame(['primary', 'success', 'danger'], $data['styles']);
        self::assertSame(8, $data['limits']['per_row']);
    }

    public function testSavingALayoutKeepsItAndValidatesIt(): void
    {
        // A blank style (the panel sends null) is the client default too.
        $response = $this->unchecked()->putJson('/api/admin/keyboards/start', [
            'type' => 'inline',
            'rows' => [
                [['action' => 'buy', 'label' => ' 🛒 خرید ', 'style' => 'primary'], ['action' => 'services', 'label' => 'سرویس‌ها', 'style' => null]],
                [],
                [['action' => 'support', 'label' => 'پشتیبانی', 'style' => '']],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $keyboard = $this->decode($response)['keyboard'];
        self::assertFalse($keyboard['is_default']);
        self::assertSame('inline', $keyboard['type']);
        self::assertCount(2, $keyboard['rows'], 'an emptied row disappears');
        self::assertSame(['action' => 'buy', 'label' => '🛒 خرید', 'style' => 'primary', 'icon' => null], $keyboard['rows'][0][0]);
        self::assertNull($keyboard['rows'][1][0]['style'], 'blank style = the client default');

        $layout = $this->service(KeyboardLayouts::class)->get('start');
        self::assertTrue($layout->isInline());
        self::assertSame(['🛒 خرید', 'سرویس‌ها', 'پشتیبانی'], $layout->labels());

        $bad = $this->unchecked()->putJson('/api/admin/keyboards/start', [
            'type' => 'reply',
            'rows' => [
                [['action' => 'buy', 'label' => 'A', 'style' => 'pink'], ['action' => 'buy', 'label' => 'B'], ['action' => 'nope', 'label' => '']],
                [['action' => 'support', 'label' => 'A']],
            ],
        ]);
        self::assertSame(422, $bad->getStatusCode());
        $errors = $this->decode($bad)['errors'];
        self::assertStringContainsString('رنگ', $errors['rows.0.0'][0]);
        self::assertStringContainsString('دو بار', $errors['rows.0.1'][0]);
        self::assertStringContainsString('وجود ندارد', $errors['rows.0.2'][0]);
        self::assertStringContainsString('خالی', $errors['rows.0.2'][1]);
        self::assertStringContainsString('یک متن', $errors['rows.1.0'][0]);
        self::assertTrue($this->service(KeyboardLayouts::class)->get('start')->isInline(), 'a refused layout changes nothing');

        self::assertSame(422, $this->putJson('/api/admin/keyboards/start', ['type' => 'reply', 'rows' => []])->getStatusCode());
        self::assertSame(404, $this->putJson('/api/admin/keyboards/nope', ['type' => 'reply', 'rows' => []])->getStatusCode());
    }

    public function testAButtonMayCarryAPremiumEmojiIcon(): void
    {
        $response = $this->putJson('/api/admin/keyboards/start', [
            'type' => 'reply',
            'rows' => [[
                ['action' => 'buy', 'label' => 'خرید', 'style' => null, 'icon' => ' 5368324170671202286 '],
                ['action' => 'services', 'label' => 'سرویس‌ها', 'style' => null, 'icon' => ''],
                ['action' => 'support', 'label' => 'پشتیبانی', 'style' => null],
            ]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $icons = static fn(array $row): array => array_map(static fn(array $button): ?string => $button['icon'], $row);
        self::assertSame(['5368324170671202286', null, null], $icons($this->decode($response)['keyboard']['rows'][0]), 'trimmed; blank or left out = none');
        self::assertSame(['5368324170671202286', null, null], $icons($this->decode($this->get('/api/admin/keyboards'))['keyboards'][0]['rows'][0]));
        self::assertSame('5368324170671202286', $this->service(KeyboardLayouts::class)->get('start')->buttons()[0]['icon']);

        $bad = $this->putJson('/api/admin/keyboards/start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید', 'icon' => '<tg-emoji emoji-id="1">🔥</tg-emoji>']]]]);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('شناسه یک ایموجی پرمیوم', $this->decode($bad)['errors']['rows.0.0'][0]);

        // The tag pasted into the label (from /emoji's template) — even cut short by the label's limit — is pointed at the icon.
        $tagged = $this->putJson('/api/admin/keyboards/start', ['type' => 'reply', 'rows' => [[['action' => 'tutorial', 'label' => '📚 آموزش <tg-emoji emoji-id="5852814297184342333">🔥</tg-emo']]]]);
        self::assertSame(422, $tagged->getStatusCode());
        self::assertStringContainsString('آیکون دکمه', $this->decode($tagged)['errors']['rows.0.0'][0]);
        self::assertSame('5368324170671202286', $this->service(KeyboardLayouts::class)->get('start')->buttons()[0]['icon'], 'a refused layout changes nothing');
    }

    public function testAStoredLayoutTheEditorWouldRefuseIsNotTheMenu(): void
    {
        $log = $this->logs();
        $this->service(Settings::class)->set('bot.keyboard.start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید', 'style' => null, 'icon' => 'abc']]]]);

        $keyboard = $this->decode($this->get('/api/admin/keyboards'))['keyboards'][0];

        self::assertSame(MainMenu::defaultLayout()->toArray()['rows'], $keyboard['rows'], 'a row changed by hand never takes the menu away: the built-in one stands in');
        self::assertFalse($keyboard['is_default'], 'the stored row is still there for the editor to replace');
        self::assertTrue($log->hasWarningThatContains('not a layout the editor would save'));
    }

    public function testTelegramsLimitsAndTheTypeAreChecked(): void
    {
        $button = static fn(string $label): array => ['action' => 'buy', 'label' => $label];

        $tooLong = $this->putJson('/api/admin/keyboards/start', ['type' => 'reply', 'rows' => [[$button(str_repeat('ا', KeyboardLayouts::LABEL_MAX + 1))]]]);
        self::assertSame(422, $tooLong->getStatusCode());
        self::assertStringContainsString((string) KeyboardLayouts::LABEL_MAX, $this->decode($tooLong)['errors']['rows.0.0'][0]);

        $wideRow = $this->putJson('/api/admin/keyboards/start', ['type' => 'reply', 'rows' => [array_fill(0, KeyboardLayouts::MAX_PER_ROW + 1, $button('x'))]]);
        self::assertSame(422, $wideRow->getStatusCode());
        self::assertStringContainsString((string) KeyboardLayouts::MAX_PER_ROW, $this->decode($wideRow)['errors']['rows.0'][0]);

        $tallKeyboard = $this->putJson('/api/admin/keyboards/start', ['type' => 'reply', 'rows' => array_fill(0, KeyboardLayouts::MAX_ROWS + 1, [$button('x')])]);
        self::assertSame(422, $tallKeyboard->getStatusCode());
        self::assertStringContainsString((string) KeyboardLayouts::MAX_ROWS, $this->decode($tallKeyboard)['errors']['rows'][0]);

        $badType = $this->unchecked()->putJson('/api/admin/keyboards/start', ['type' => 'popup', 'rows' => [[$button('x')]]]);
        self::assertSame(422, $badType->getStatusCode());
        self::assertArrayHasKey('type', $this->decode($badType)['errors']);

        self::assertTrue($this->service(KeyboardLayouts::class)->isDefault('start'), 'none of it was saved');
    }

    public function testAnAgentsMenuHasNoAgencyOfItsOwn(): void
    {
        $bot = $this->agentBot();
        // A layout the agent's shop kept from before, «نمایندگی» on it.
        CurrentBot::run($bot, fn() => $this->service(Settings::class)->set('bot.keyboard.start', ['type' => 'reply', 'rows' => [
            [['action' => 'buy', 'label' => 'خرید', 'style' => null, 'icon' => null]],
            [['action' => 'agency', 'label' => 'نمایندگی', 'style' => null, 'icon' => null]],
        ]]));
        $this->loginAsAgent($bot);
        $actions = static fn(array $keyboard): array => array_map(static fn(array $row): array => array_column($row, 'action'), $keyboard['rows']);

        $data = $this->decode($this->get('/api/agent/keyboards'));
        self::assertNotContains('agency', array_column($data['actions'], 'key'), 'not offered');
        self::assertSame([['buy']], $actions($data['keyboards'][0]), 'nor shown: the rest of the layout stands');

        $refused = $this->putJson('/api/agent/keyboards/start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید']], [['action' => 'agency', 'label' => 'نمایندگی']]]]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['rows.1.0' => ['این دکمه در ربات وجود ندارد.']], $this->decode($refused)['errors'], 'nor taken');

        $default = $this->decode($this->postJson('/api/agent/keyboards/start/reset'))['keyboard'];
        self::assertSame([['buy'], ['services', 'renew'], ['tutorial', 'wallet'], ['support', 'affiliates']], $actions($default), 'its built-in menu has none either');
    }

    public function testAButtonTheBotNoLongerHasIsNeitherShownNorTaken(): void
    {
        // A layout saved when the menu still had «اکانت تست».
        $this->service(Settings::class)->set('bot.keyboard.start', ['type' => 'reply', 'rows' => [
            [['action' => 'trial', 'label' => '🔑 اکانت تست', 'style' => null, 'icon' => null], ['action' => 'buy', 'label' => 'خرید', 'style' => null, 'icon' => null]],
        ]]);

        $keyboard = $this->decode($this->get('/api/admin/keyboards'))['keyboards'][0];
        self::assertSame([['buy']], array_map(static fn(array $row): array => array_column($row, 'action'), $keyboard['rows']), 'the rest of the admin\'s layout stands');

        $refused = $this->putJson('/api/admin/keyboards/start', ['type' => 'reply', 'rows' => [[['action' => 'trial', 'label' => '🔑 اکانت تست'], ['action' => 'buy', 'label' => 'خرید']]]]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['rows.0.0' => ['این دکمه در ربات وجود ندارد.']], $this->decode($refused)['errors']);
    }

    public function testResetGoesBackToTheBuiltInLayout(): void
    {
        $this->putJson('/api/admin/keyboards/start', ['type' => 'inline', 'rows' => [[['action' => 'buy', 'label' => 'خرید']]]]);

        $response = $this->postJson('/api/admin/keyboards/start/reset');

        self::assertSame(200, $response->getStatusCode());
        $keyboard = $this->decode($response)['keyboard'];
        self::assertTrue($keyboard['is_default']);
        self::assertSame(MainMenu::defaultLayout()->toArray()['rows'], $keyboard['rows']);
    }
}
