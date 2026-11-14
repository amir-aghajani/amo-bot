<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Providers\Models\Server;
use App\Support\Input;
use App\Support\Validation;

/**
 * What the owner decided an import brings (imports.choices): for each of the other bot's panels, the shop's server its
 * services are found on (`server`), a new server it is added as (`add`, only a panel the shop can add), or nothing
 * (`skip`) — a panel no connector of the shop speaks to can only be skipped —, and a switch for each kind of thing
 * (TOGGLES, each with its default; the tests only with the services, the plans switched on only with the plans).
 * Checked against the import's survey and the shop's servers, every refusal at once.
 */
final class Choices
{
    public const SERVER = 'server';
    public const ADD = 'add';
    public const SKIP = 'skip';

    /** Each switch and what it is while the owner says nothing: the review's starting point. */
    public const TOGGLES = [
        'balances' => true,
        'banned' => true,
        'phones' => true,
        'referrals' => true,
        'discounts' => true,
        'groups' => true,
        'admins' => false,
        'resellers' => false,
        'services' => true,
        'tests' => true,
        'plans' => true,
        'plans_on' => false,
        'discount_codes' => true,
        'gift_codes' => true,
        'channels' => false,
        'cards' => true,
    ];

    private const UNREADABLE = 'انتخاب‌ها خوانده نشد؛ صفحه را دوباره باز کنید.';
    private const UNKNOWN_PANEL = 'این پنل در داده‌های ربات قبلی نیست؛ صفحه را دوباره باز کنید.';
    private const CHOOSE = 'برای سرویس‌های این پنل انتخاب کنید: یکی از سرورهای فروشگاه، افزودن به‌عنوان سرور تازه، یا منتقل نشود.';
    private const NO_CONNECTOR = 'فروشگاه کانکتوری برای این پنل ندارد؛ سرویس‌هایش فقط منتقل نمی‌شود.';
    private const NOT_ADDABLE = 'این پنل را نمی‌شود به‌عنوان سرور تازه اضافه کرد؛ یکی از سرورهای فروشگاه را انتخاب کنید یا «منتقل نشود».';
    private const NO_SERVER = 'سرور انتخاب‌شده دیگر نیست؛ سرور دیگری انتخاب کنید یا «منتقل نشود».';
    private const OTHER_CONNECTOR = 'این سرور با کانکتور دیگری کار می‌کند؛ سروری از نوع همین پنل انتخاب کنید.';

    /**
     * @param array<string, array{action: string, server_id: int|null}> $panels
     * @param array<string, bool> $toggles
     */
    private function __construct(
        private readonly array $panels,
        private readonly array $toggles,
    ) {}

    /** @param array<string, mixed> $kept The import's own (toArray()), as it was checked */
    public static function of(array $kept): self
    {
        $panels = [];
        foreach (is_array($kept['panels'] ?? null) ? $kept['panels'] : [] as $key => $choice) {
            $choice = is_array($choice) ? $choice : [];
            $panels[(string) $key] = ['action' => is_string($choice['action'] ?? null) ? $choice['action'] : self::SKIP, 'server_id' => is_int($choice['server_id'] ?? null) ? $choice['server_id'] : null];
        }
        $toggles = [];
        foreach (self::TOGGLES as $toggle => $default) {
            $toggles[$toggle] = is_bool($kept[$toggle] ?? null) ? $kept[$toggle] : $default;
        }

        return new self($panels, self::together($toggles));
    }

    /**
     * The owner's choices, checked against the survey's panels (Survey::toArray()) and the shop's servers: every panel with
     * services named, a server only of the panel's own connector, an addition only of a panel the shop can add; a panel
     * without services, or without a connector, left out is skipped. A switch left out takes its default; one that is no
     * boolean is refused.
     *
     * @param array<string, mixed> $survey
     * @throws ValidationException every refusal at once, under `choices.panels.<key>` and `choices.<switch>`
     */
    public static function check(mixed $input, array $survey): self
    {
        if (!is_array($input)) {
            throw ValidationException::on('choices', self::UNREADABLE);
        }
        $sent = $input['panels'] ?? [];
        if (!is_array($sent)) {
            throw ValidationException::on('choices.panels', self::UNREADABLE);
        }

        $errors = [];
        $known = [];
        $servers = Server::query()->pluck('driver', 'id')->all();
        $panels = [];
        foreach (self::panelsOf($survey) as $panel) {
            $key = (string) $panel['key'];
            $known[$key] = true;
            $choice = $sent[$key] ?? null;
            $action = is_array($choice) ? Input::text($choice, 'action') : '';
            $serverId = is_array($choice) ? Input::integerOf($choice['server_id'] ?? null) : null;
            $message = match (true) {
                $choice === null && ((int) ($panel['services'] ?? 0) === 0 || $panel['driver'] === null), $action === self::SKIP => null,
                $panel['driver'] === null => self::NO_CONNECTOR,
                $action === self::ADD => ($panel['addable'] ?? false) === true ? null : self::NOT_ADDABLE,
                $action === self::SERVER => match (true) {
                    $serverId === null || !isset($servers[$serverId]) => self::NO_SERVER,
                    $servers[$serverId] !== $panel['driver'] => self::OTHER_CONNECTOR,
                    default => null,
                },
                default => self::CHOOSE,
            };
            if ($message !== null) {
                $errors["choices.panels.{$key}"][] = $message;

                continue;
            }
            $panels[$key] = in_array($action, [self::SERVER, self::ADD], true)
                ? ['action' => $action, 'server_id' => $action === self::SERVER ? $serverId : null]
                : ['action' => self::SKIP, 'server_id' => null];
        }
        foreach (array_keys($sent) as $key) {
            if (!isset($known[(string) $key])) {
                $errors["choices.panels.{$key}"][] = self::UNKNOWN_PANEL;
            }
        }

        $toggles = [];
        foreach (self::TOGGLES as $toggle => $default) {
            if (!array_key_exists($toggle, $input)) {
                $toggles[$toggle] = $default;
            } elseif (Input::isBoolean($input[$toggle])) {
                $toggles[$toggle] = Input::truthy($input[$toggle]);
            } else {
                $errors["choices.{$toggle}"][] = Validation::NOT_A_SWITCH;
            }
        }
        ValidationException::ifAny($errors);

        return new self($panels, self::together($toggles));
    }

    /**
     * What the review starts from while the owner has saved nothing: each panel found on the shop's server of its own
     * connector at the same address (suggestedServer()), else added when it can be, else skipped; the switches' defaults.
     *
     * @param array<string, mixed> $survey
     */
    public static function propose(array $survey): self
    {
        $servers = self::servers();
        $panels = [];
        foreach (self::panelsOf($survey) as $panel) {
            $suggested = self::suggestedServer($panel, $servers);
            $panels[(string) $panel['key']] = match (true) {
                $suggested !== null => ['action' => self::SERVER, 'server_id' => $suggested],
                ($panel['addable'] ?? false) === true => ['action' => self::ADD, 'server_id' => null],
                default => ['action' => self::SKIP, 'server_id' => null],
            };
        }

        return new self($panels, self::TOGGLES);
    }

    /**
     * The shop's server a panel of the survey most likely is: the first, in the owner's order, of the panel's connector
     * whose address has the panel's scheme, host and port; null for none.
     *
     * @param array<string, mixed> $panel
     * @param list<array{id: int, driver: string, base_url: string}> $servers servers()
     */
    public static function suggestedServer(array $panel, array $servers): ?int
    {
        $origin = self::origin(is_string($panel['url'] ?? null) ? $panel['url'] : null);
        if ($origin === null || $panel['driver'] === null) {
            return null;
        }
        foreach ($servers as $server) {
            if ($server['driver'] === $panel['driver'] && self::origin($server['base_url']) === $origin) {
                return $server['id'];
            }
        }

        return null;
    }

    /**
     * The shop's servers as suggestedServer() compares them, in the owner's order.
     *
     * @return list<array{id: int, driver: string, base_url: string}>
     */
    public static function servers(): array
    {
        return array_values(Server::query()->oldest('sort')->oldest('id')->get(['id', 'driver', 'base_url'])->map(static fn(Server $server): array => ['id' => $server->id, 'driver' => $server->driver, 'base_url' => $server->base_url])->all());
    }

    public function on(string $toggle): bool
    {
        return $this->toggles[$toggle] ?? self::TOGGLES[$toggle] ?? false;
    }

    /** What becomes of a panel's services: SERVER, ADD or SKIP (a panel the choices do not name). */
    public function action(string $panel): string
    {
        return $this->panels[$panel]['action'] ?? self::SKIP;
    }

    /** The shop's server a panel chosen SERVER maps to. */
    public function serverId(string $panel): ?int
    {
        return $this->panels[$panel]['server_id'] ?? null;
    }

    /**
     * As the import keeps them and the panels read them (ImportChoices): the panels by key — an object even when there
     * are none, or when every key is a number —, then each switch.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['panels' => (object) $this->panels] + $this->toggles;
    }

    /**
     * The panel's address as the import knows a server added of it by (its server link): scheme, host, port and path —
     * the same panel whatever the other bot called it, and never another bot's panel that shares its name. Null for a
     * panel without one.
     */
    public static function address(?string $url): ?string
    {
        $origin = self::origin($url);
        if ($origin === null) {
            return null;
        }
        $path = rtrim((string) parse_url(str_contains((string) $url, '://') ? (string) $url : 'https://' . $url, PHP_URL_PATH), '/');

        return $origin . $path;
    }

    /**
     * The switches as one choice: no tests without the services (the review says they come with them), no plans switched
     * on without the plans.
     *
     * @param array<string, bool> $toggles
     * @return array<string, bool>
     */
    private static function together(array $toggles): array
    {
        $toggles['tests'] = $toggles['tests'] && $toggles['services'];
        $toggles['plans_on'] = $toggles['plans_on'] && $toggles['plans'];

        return $toggles;
    }

    /**
     * @param array<string, mixed> $survey
     * @return list<array<string, mixed>>
     */
    private static function panelsOf(array $survey): array
    {
        return array_values(array_filter(is_array($survey['panels'] ?? null) ? $survey['panels'] : [], 'is_array'));
    }

    /** An address's scheme, host and port («https://panel.example:443»), a missing port the scheme's own; null for none. */
    private static function origin(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url(str_contains($url, '://') ? $url : 'https://' . $url);
        if (!is_array($parts) || ($parts['host'] ?? '') === '') {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');

        return $scheme . '://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
    }
}
