<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Modules\Imports\Choices;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Records\ImportedPanel;
use App\Support\Validation;

/**
 * The owner's choices, checked against the import's survey and the shop's servers: every panel with services named, a
 * server only of the panel's own connector, an addition only of a panel the shop can add, a panel no connector speaks to
 * only skipped, switches that are booleans (the defaults for those left out) — every refusal at once, under its field.
 */
final class ChoicesTest extends ImportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrator->panels = [
            self::fakePanelOf('fake-1', 'آلمان', 5, ['base_url' => 'https://de.test']),
            self::fakePanelOf('fake-2', 'هلند', 0),
            new ImportedPanel('wg', 'وایرگارد', 'WGDashboard', 'https://wg.test', null, 'این نوع پنل در فروشگاه کانکتوری ندارد.', [], 3),
        ];
    }

    public function testEveryRefusalComesAtOnceUnderItsField(): void
    {
        $other = $this->panelServer('DE-1');
        $import = $this->reviewed();

        $errors = $this->refusedWith(fn() => $this->imports()->choose($import, [
            'panels' => ['fake-2' => ['action' => 'add'], 'wg' => ['action' => 'server', 'server_id' => $other->id], 'nobody' => ['action' => 'skip']],
            'balances' => 'maybe',
            'cards' => false,
        ]));

        self::assertSame([
            'choices.panels.fake-1' => ['برای سرویس‌های این پنل انتخاب کنید: یکی از سرورهای فروشگاه، افزودن به‌عنوان سرور تازه، یا منتقل نشود.'],
            'choices.panels.fake-2' => ['این پنل را نمی‌شود به‌عنوان سرور تازه اضافه کرد؛ یکی از سرورهای فروشگاه را انتخاب کنید یا «منتقل نشود».'],
            'choices.panels.wg' => ['فروشگاه کانکتوری برای این پنل ندارد؛ سرویس‌هایش فقط منتقل نمی‌شود.'],
            'choices.panels.nobody' => ['این پنل در داده‌های ربات قبلی نیست؛ صفحه را دوباره باز کنید.'],
            'choices.balances' => [Validation::NOT_A_SWITCH],
        ], $errors);
        self::assertNull($import->refresh()->choices, 'nothing saved');
    }

    public function testAServerMustBeOneOfThePanelsOwnConnector(): void
    {
        $other = $this->panelServer('DE-1');
        $import = $this->reviewed();

        self::assertSame(['choices.panels.fake-1' => ['این سرور با کانکتور دیگری کار می‌کند؛ سروری از نوع همین پنل انتخاب کنید.']], $this->refusedWith(fn() => $this->imports()->choose($import, ['panels' => ['fake-1' => ['action' => 'server', 'server_id' => $other->id]]])));
        self::assertSame(['choices.panels.fake-1' => ['سرور انتخاب‌شده دیگر نیست؛ سرور دیگری انتخاب کنید یا «منتقل نشود».']], $this->refusedWith(fn() => $this->imports()->choose($import, ['panels' => ['fake-1' => ['action' => 'server', 'server_id' => 999]]])));
    }

    public function testChoicesAreSavedWholeOnTheDefaultsAndCheckedAgainAtTheStart(): void
    {
        $server = $this->fakeServer('آلمان');
        $import = $this->reviewed();

        $this->imports()->choose($import, ['panels' => ['fake-1' => ['action' => 'server', 'server_id' => (string) $server->id]], 'admins' => '1', 'balances' => 0]);

        $choices = $import->refresh()->choices ?? [];
        self::assertSame(['fake-1' => ['action' => 'server', 'server_id' => $server->id], 'fake-2' => ['action' => 'skip', 'server_id' => null], 'wg' => ['action' => 'skip', 'server_id' => null]], $choices['panels']);
        self::assertSame(array_merge(Choices::TOGGLES, ['admins' => true, 'balances' => false]), array_diff_key($choices, ['panels' => true]));

        $server->delete();
        self::assertSame(['choices.panels.fake-1' => ['سرور انتخاب‌شده دیگر نیست؛ سرور دیگری انتخاب کنید یا «منتقل نشود».']], $this->refusedWith(fn() => $this->imports()->start($import)), 'a server gone since is said before anything is written');
        self::assertSame(ImportPhase::Review, $import->refresh()->phase);
    }

    public function testNoTestsComeWithoutTheServicesNorPlansSwitchedOnWithoutThePlans(): void
    {
        $import = $this->reviewed();

        $this->imports()->choose($import, ['panels' => ['fake-1' => ['action' => 'skip']], 'services' => false, 'tests' => true, 'plans' => false, 'plans_on' => true]);

        self::assertSame(['services' => false, 'tests' => false, 'plans' => false, 'plans_on' => false], array_intersect_key($import->refresh()->choices ?? [], array_flip(['services', 'tests', 'plans', 'plans_on'])), 'as the review says: the tests come with the services');
    }

    public function testTheReviewProposesEachPanelsLikelyServerOrItsAddition(): void
    {
        $this->panelServer('DE-1');
        $same = $this->fakeServer('آلمان', ['base_url' => 'https://fake-2.test:443']);

        $proposed = Choices::propose($this->reviewed()->survey ?? [])->toArray();

        self::assertSame(['fake-1' => ['action' => 'add', 'server_id' => null], 'fake-2' => ['action' => 'server', 'server_id' => $same->id], 'wg' => ['action' => 'skip', 'server_id' => null]], (array) $proposed['panels']);
        self::assertSame(Choices::TOGGLES, array_diff_key($proposed, ['panels' => true]));
    }
}
