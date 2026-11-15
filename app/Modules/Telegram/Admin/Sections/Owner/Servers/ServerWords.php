<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;

/**
 * How the servers' screens say a server and what its panel answered — the panel's words (servers.tsx, server-status.tsx,
 * probe-result.tsx, inbounds-card.tsx): its state in a dot and a word, a connection test's verdict with the host's
 * vitals, the inbounds on offer and the subscription server, an inbound's name and protocol.
 */
final class ServerWords
{
    /** The panel's core («هسته»), in a word. */
    private const CORE_STATES = ['running' => 'در حال اجرا', 'stopped' => 'متوقف', 'error' => 'خطا', 'unknown' => 'نامشخص'];

    /** The inbounds a test lists at most; «و … اینباند دیگر» after them. */
    private const PROBE_INBOUNDS = 6;

    /**
     * The server whose pages are open: the one `$args` names, else the one last opened (ServerScreen).
     *
     * @param list<string> $args
     */
    public static function current(Admin $admin, array $args): int
    {
        $id = isset($args[0]) && ctype_digit($args[0]) ? (int) $args[0] : 0;

        return $id > 0 ? $id : (int) ($admin->state(ServerScreen::ID)['id'] ?? 0);
    }

    /**
     * The connector `$key` as GET /servers/drivers describes it; null while this installation lacks it.
     *
     * @return array<string, mixed>|null
     */
    public static function driver(Admin $admin, string $key): ?array
    {
        foreach ((array) ($admin->api->get('/servers/drivers')['drivers'] ?? []) as $driver) {
            if (is_array($driver) && ($driver['key'] ?? null) === $key) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * A server's connection as a dot and a word (serverStatus()).
     *
     * @param array<string, mixed> $server
     */
    public static function status(array $server): string
    {
        return match (true) {
            ($server['is_active'] ?? false) !== true => '⚪️ غیرفعال',
            ($server['last_error'] ?? null) !== null => '🔴 خطا در اتصال',
            ($server['last_checked_at'] ?? null) !== null => '🟢 متصل',
            default => '🟡 بررسی نشده',
        };
    }

    /**
     * The state's dot alone, for a button.
     *
     * @param array<string, mixed> $server
     */
    public static function dot(array $server): string
    {
        return explode(' ', self::status($server))[0];
    }

    /**
     * What a connection test found (ProbeResult): a verdict line, the host's vitals, the inbounds on offer, and whether
     * subscription links are served — HTML lines.
     *
     * @param array<string, mixed> $probe
     * @return list<string>
     */
    public static function probe(array $probe): array
    {
        if (($probe['ok'] ?? false) !== true) {
            $lines = ['❌ <b>اتصال برقرار نشد</b>', Fmt::e(is_string($probe['error'] ?? null) ? $probe['error'] : '')];
            if (is_string($probe['error_detail'] ?? null) && $probe['error_detail'] !== '') {
                $lines[] = Fmt::code($probe['error_detail']);
            }

            return $lines;
        }

        $inbounds = is_array($probe['inbounds'] ?? null) ? array_values(array_filter($probe['inbounds'], is_array(...))) : null;
        $enabled = $inbounds === null ? 0 : count(array_filter($inbounds, static fn(array $inbound): bool => Json::on($inbound, 'enabled')));
        $lines = ['✅ <b>اتصال برقرار شد</b> · ' . ($inbounds === null ? 'اینباندها خوانده نشدند' : Fmt::number(count($inbounds)) . ' اینباند (' . Fmt::number($enabled) . ' فعال)')];

        $status = is_array($probe['status'] ?? null) ? $probe['status'] : null;
        if ($status !== null) {
            $core = is_string($status['core_name'] ?? null) && $status['core_name'] !== '' ? 'هسته ' . Fmt::ltr($status['core_name']) : 'هسته';
            $version = is_string($status['core_version'] ?? null) && $status['core_version'] !== '' ? ' ' . Fmt::ltr($status['core_version']) : '';
            $cpu = is_numeric($status['cpu_percent'] ?? null) ? Fmt::percent(round((float) $status['cpu_percent'])) : Fmt::NONE;
            $memory = Fmt::bytes((int) ($status['memory_used'] ?? 0)) . ((int) ($status['memory_total'] ?? 0) > 0 ? ' از ' . Fmt::bytes((int) $status['memory_total']) : '');
            $disk = (int) ($status['disk_total'] ?? 0) === 0 && (int) ($status['disk_used'] ?? 0) === 0 ? Fmt::NONE : Fmt::bytes((int) ($status['disk_used'] ?? 0)) . ((int) ($status['disk_total'] ?? 0) > 0 ? ' از ' . Fmt::bytes((int) $status['disk_total']) : '');
            $lines[] = $core . ': ' . Fmt::e(self::CORE_STATES[(string) ($status['core_state'] ?? 'unknown')] ?? 'نامشخص') . $version;
            $lines[] = Fmt::ltr('CPU') . ': ' . $cpu . ' · ' . Fmt::ltr('RAM') . ': ' . $memory . ' · دیسک: ' . $disk;
        }
        foreach (array_slice($inbounds ?? [], 0, self::PROBE_INBOUNDS) as $inbound) {
            $lines[] = (Json::on($inbound, 'enabled') ? '🟢 ' : '⚪️ ') . Fmt::e(self::inboundName($inbound)) . ' · ' . self::protocol($inbound) . ' · ' . Fmt::number((int) ($inbound['client_count'] ?? 0)) . ' کلاینت';
        }
        if ($inbounds !== null && count($inbounds) > self::PROBE_INBOUNDS) {
            $lines[] = 'و ' . Fmt::number(count($inbounds) - self::PROBE_INBOUNDS) . ' اینباند دیگر…';
        }
        $lines[] = match (true) {
            ($probe['serves_subscriptions'] ?? null) === true => "سرور اشتراک (\u{2068}Subscription\u{2069}) پنل فعال است؛ مشتری لینک اشتراک می‌گیرد.",
            Json::on($probe, 'subscription_probed') => "⚠️ سرور اشتراک (\u{2068}Subscription\u{2069}) در پنل فعال نیست؛ لینک اشتراک برای مشتریان ساخته نمی‌شود و این سرور قابل فروش نیست.",
            default => "⚠️ وضعیت سرور اشتراک (\u{2068}Subscription\u{2069}) مشخص نشد؛ سرور را دوباره بررسی کنید.",
        };

        return $lines;
    }

    /**
     * An inbound as the admin knows it: its remark, else its tag.
     *
     * @param array<string, mixed> $inbound
     */
    public static function inboundName(array $inbound): string
    {
        $remark = is_string($inbound['remark'] ?? null) ? $inbound['remark'] : '';

        return $remark !== '' ? $remark : (string) ($inbound['tag'] ?? '');
    }

    /**
     * An inbound's protocol, its transport, its security and port («vless/tcp+reality :443»), isolated left to right; «—»
     * for a group with none (PasarGuard).
     *
     * @param array<string, mixed> $inbound
     */
    public static function protocol(array $inbound): string
    {
        if (!is_string($inbound['protocol'] ?? null)) {
            return Fmt::NONE;
        }
        $text = $inbound['protocol']
            . (is_string($inbound['network'] ?? null) && $inbound['network'] !== '' ? '/' . $inbound['network'] : '')
            . (is_string($inbound['security'] ?? null) && !in_array($inbound['security'], ['', 'none'], true) ? '+' . $inbound['security'] : '')
            . (is_int($inbound['port'] ?? null) ? ' :' . $inbound['port'] : '');

        return Fmt::ltr($text);
    }
}
