<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Imports;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as Vocabulary;

/**
 * «پنل‌ها» of an import's review: each of the other bot's panels — its name, kind, address and services — and where its
 * services go: a server of the shop on the same connector (the one on the same address suggested), the panel added as a
 * new server, or nowhere; a panel no connector speaks to says why. A choice is saved at once (PUT …/choices, every
 * choice as it stands).
 *
 * Actions: `p` (n, page: panel n's choices, its servers a page at a time — no page, the picked one's), `s` (n, server
 * id), `a` (n: added as a new server), `k` (n: left behind).
 */
final class ImportPanelsScreen extends BaseScreen
{
    public const ID = 'ppip';

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $import = self::reviewed($admin);
        if ($import === null) {
            return $admin->open(ImportsScreen::ID);
        }
        $servers = self::servers($admin);
        $view = View::make('🖥 پنل‌ها', ...ImportsScreen::under())
            ->line('سرویس‌های هر پنل روی سروری از فروشگاه که به همان پنل وصل است، با نام کاربری‌شان پیدا می‌شوند. پلن‌ها هم روی همان سرورها فروخته می‌شوند. روی پنل‌ها چیزی تغییر نمی‌کند.');
        $buttons = [];
        foreach (self::panels($import) as $n => $panel) {
            $choice = self::choiceOf($import, $panel);
            $view->line()->line("\u{200F}🖥 <b>" . Fmt::e((string) ($panel['name'] ?? '')) . '</b> · ' . Fmt::ltr((string) ($panel['type'] ?? '')) . ' · ' . Fmt::number((int) ($panel['services'] ?? 0)) . ' سرویس');
            if (is_string($panel['url'] ?? null)) {
                $view->line(Fmt::ltr($panel['url']));
            }
            $view->line('<i>' . Fmt::e(self::where($panel, $choice, $servers)) . '</i>');
            $buttons[] = Button::to('🖥 ' . (string) ($panel['name'] ?? '') . ': ' . self::brief($choice, $servers), self::ID, 'p', $n);
        }

        return $view->grid(1, ...$buttons)->back(ImportsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $import = self::reviewed($admin);
        $panel = $import === null ? null : (self::panels($import)[self::id($args)] ?? null);
        if ($import === null || $panel === null) {
            return $this->show($admin, []);
        }
        $n = self::id($args);
        switch ($action) {
            case 'p':
                return $this->panel($admin, $import, $panel, $n, isset($args[1]) ? self::page($args, 1) : null);
            case 's':
                return $this->choose($admin, $import, $panel, ['action' => 'server', 'server_id' => self::id($args, 1)]);
            case 'a':
                return $this->choose($admin, $import, $panel, ['action' => 'add', 'server_id' => null]);
            case 'k':
                return $this->choose($admin, $import, $panel, ['action' => 'skip', 'server_id' => null]);
        }

        return $this->show($admin, []);
    }

    /**
     * One panel: what it is, and where its services may go — the shop's servers on its connector at page `$page` (none:
     * the page of the server picked, else of the one suggested) — or why they cannot go anywhere.
     *
     * @param array<string, mixed> $import
     * @param array<string, mixed> $panel
     */
    private function panel(Admin $admin, array $import, array $panel, int $n, ?int $page): View
    {
        $choice = self::choiceOf($import, $panel);
        $servers = self::servers($admin);
        $view = View::make("\u{200F}🖥 " . (string) ($panel['name'] ?? ''), ...ImportsScreen::under('پنل‌ها'))
            ->card([
                'نوع' => Fmt::ltr((string) ($panel['type'] ?? '')),
                'آدرس' => is_string($panel['url'] ?? null) ? Fmt::ltr($panel['url']) : null,
                'سرویس‌ها' => Fmt::number((int) ($panel['services'] ?? 0)),
            ]);
        if (($panel['driver'] ?? null) === null) {
            return $view->line()->line('⚠️ ' . Fmt::e(is_string($panel['unsupported'] ?? null) ? $panel['unsupported'] : 'AmoBot به این نوع پنل وصل نمی‌شود.') . ' سرویس‌های این پنل منتقل نمی‌شوند.')
                ->back(self::ID);
        }
        $view->line()->line('<b>سرویس‌های «' . Fmt::e((string) ($panel['name'] ?? '')) . '» کجا بروند؟</b>')
            ->line('<i>' . Fmt::e(self::where($panel, $choice, $servers)) . '</i>');
        $choices = [];
        [$pickedAt, $suggestedAt] = [null, null];
        foreach ($servers as $server) {
            if (($server['driver'] ?? null) !== $panel['driver']) {
                continue;
            }
            $picked = ($choice['action'] ?? null) === 'server' && ($choice['server_id'] ?? null) === $server['id'];
            $suggested = ($server['id'] ?? null) === ($panel['suggested_server_id'] ?? false);
            $pickedAt ??= $picked ? count($choices) : null;
            $suggestedAt ??= $suggested ? count($choices) : null;
            $choices[] = Button::to(($picked ? Vocabulary::PICKED : '') . 'سرور «' . (string) ($server['name'] ?? '') . '»' . ($suggested ? ' ⭐️ پیشنهادی' : ''), self::ID, 's', $n, (int) ($server['id'] ?? 0));
        }
        // Opened afresh: at the server picked, else the one suggested.
        $page ??= intdiv($pickedAt ?? $suggestedAt ?? 0, View::CHOICES_MOST) + 1;
        $addable = Json::on($panel, 'addable');
        if ($choices === [] && !$addable) {
            $view->line('سروری با همین نوع پنل در فروشگاه نیست؛ برای انتقال سرویس‌هایش، اول آن را در «🖥 سرورها» اضافه کنید.');
        }

        // The shop's servers a page at a time (a keyboard takes 100 buttons); the panel added or left behind on every page.
        return $view->paged(1, $choices, $page, static fn(int $at): array => [self::ID, 'p', [$n, $at]])
            ->row($addable ? Button::to((($choice['action'] ?? null) === 'add' ? Vocabulary::PICKED : '') . '➕ افزودن به‌عنوان سرور تازه', self::ID, 'a', $n) : null)
            ->row(Button::to((($choice['action'] ?? 'skip') === 'skip' ? Vocabulary::PICKED : '') . '🚫 منتقل نشود', self::ID, 'k', $n))
            ->back(self::ID);
    }

    /**
     * Panel `$panel`'s choice saved, beside every other choice as it stands.
     *
     * @param array<string, mixed> $import
     * @param array<string, mixed> $panel
     * @param array{action: string, server_id: int|null} $choice
     */
    private function choose(Admin $admin, array $import, array $panel, array $choice): View
    {
        $choices = Words::choices($import);
        $panels = Json::part($choices, 'panels');
        $panels[(string) ($panel['key'] ?? '')] = $choice;
        $choices['panels'] = $panels;
        $admin->api->put('/imports/' . (int) ($import['id'] ?? 0) . '/choices', ['choices' => Words::shown($choices)]);
        $admin->notice('✅ ذخیره شد.');

        return $this->show($admin, []);
    }

    /**
     * Where a panel's services go, in words.
     *
     * @param array<string, mixed> $panel
     * @param array<string, mixed>|null $choice
     * @param list<array<string, mixed>> $servers
     */
    private static function where(array $panel, ?array $choice, array $servers): string
    {
        if (($panel['driver'] ?? null) === null) {
            return (is_string($panel['unsupported'] ?? null) ? $panel['unsupported'] : 'AmoBot به این نوع پنل وصل نمی‌شود.') . ' سرویس‌های این پنل منتقل نمی‌شوند.';
        }

        return match ($choice['action'] ?? 'skip') {
            'server' => ($name = self::serverName($servers, $choice['server_id'] ?? null)) !== null ? 'سرویس‌ها روی سرور «' . $name . '» پیدا و منتقل می‌شوند.' : 'سروری انتخاب کنید.',
            'add' => 'این پنل با همان اطلاعات اتصال ربات قبلی، سرور تازه این فروشگاه می‌شود و سرویس‌ها روی آن منتقل می‌شوند.',
            default => 'سرویس‌های این پنل منتقل نمی‌شوند.',
        };
    }

    /**
     * A panel's choice, short, for its button.
     *
     * @param array<string, mixed>|null $choice
     * @param list<array<string, mixed>> $servers
     */
    private static function brief(?array $choice, array $servers): string
    {
        return match ($choice['action'] ?? 'skip') {
            'server' => '«' . (self::serverName($servers, $choice['server_id'] ?? null) ?? '؟') . '»',
            'add' => 'سرور تازه',
            default => 'منتقل نشود',
        };
    }

    /**
     * A panel's choice as it stands; null for none.
     *
     * @param array<string, mixed> $import
     * @param array<string, mixed> $panel
     * @return array<string, mixed>|null
     */
    private static function choiceOf(array $import, array $panel): ?array
    {
        $panels = Words::choices($import)['panels'] ?? [];
        $choice = is_array($panels) ? ($panels[(string) ($panel['key'] ?? '')] ?? null) : null;

        return is_array($choice) ? $choice : null;
    }

    /** @param list<array<string, mixed>> $servers */
    private static function serverName(array $servers, mixed $id): ?string
    {
        foreach ($servers as $server) {
            if (($server['id'] ?? null) === $id) {
                return (string) ($server['name'] ?? '');
            }
        }

        return null;
    }

    /**
     * The import under way while it is in its review; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public static function reviewed(Admin $admin): ?array
    {
        $current = $admin->api->get('/imports')['current'] ?? null;

        return is_array($current) && ($current['phase'] ?? '') === 'review' && is_array($current['survey'] ?? null) ? $current : null;
    }

    /**
     * @param array<string, mixed> $import
     * @return list<array<string, mixed>>
     */
    private static function panels(array $import): array
    {
        $survey = Json::part($import, 'survey');

        return array_values(array_filter(Json::part($survey, 'panels'), is_array(...)));
    }

    /**
     * The shop's servers (GET /servers).
     *
     * @return list<array<string, mixed>>
     */
    private static function servers(Admin $admin): array
    {
        return Json::rows($admin->api->get('/servers'), 'servers');
    }
}
