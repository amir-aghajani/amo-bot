<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;

/**
 * A connector failure in words, for two audiences. The owner, who runs the servers, gets the diagnosis — the same for
 * every panel, with the driver's remedy (`hint`) appended — and the technical detail apart (the panel's own words,
 * cURL's, where a redirect points): explain() / describe(). Anyone else — an agent, the shop's admins on its website, an
 * agent's report group — gets summary(): what happened in a word, never the panel's host, port, path or answers. Which
 * one a reader gets is decided where it is read: by who reads a screen (a failed order's `notes` and `diagnosis`,
 * OrderDirectory::notes(); a subscriptions screen's answer), by whose group a report goes to.
 */
final class ProviderErrorPresenter
{
    /** @return array{message: string, detail: string} The owner's diagnosis and its technical detail. */
    public static function explain(ProviderException $e): array
    {
        [$message, $detail] = match (true) {
            $e instanceof ConnectionException => [self::connection($e->failure), $e->detail],
            $e instanceof AuthenticationException => [self::withHint(self::authentication($e->failure), $e->hint), $e->panelMessage],
            $e instanceof UnexpectedResponseException => [self::withHint(self::unexpected($e), $e->hint), $e->isRedirect() ? '' : $e->excerpt],
            $e instanceof NotFoundException => ['موردی که خواسته شد روی پنل پیدا نشد.', $e->getMessage()],
            $e instanceof UnsupportedOperationException => ['این پنل چنین امکانی ندارد.', $e->getMessage()],
            $e instanceof PanelApiException => ['پنل درخواست را رد کرد.', $e->panelMessage !== '' ? $e->panelMessage : self::http($e->httpStatus)],
            default => ['ارتباط با پنل به مشکل خورد.', $e->getMessage()],
        };

        return ['message' => $message, 'detail' => trim($detail)];
    }

    /** The owner's diagnosis on one line, the detail in parentheses — a server's `last_error`, a grant's failures. */
    public static function describe(ProviderException $e): string
    {
        ['message' => $message, 'detail' => $detail] = self::explain($e);

        return self::join($message, $detail);
    }

    /** What anyone but the owner may read: what happened, in a word — nothing of the panel itself. */
    public static function summary(ProviderException $e): string
    {
        return match (true) {
            $e->unavailable() => 'سرور در دسترس نبود.',
            $e instanceof NotFoundException => 'این سرویس روی سرور پیدا نشد.',
            $e instanceof UnsupportedOperationException => 'این سرور چنین امکانی ندارد.',
            $e instanceof PanelApiException => 'سرور درخواست را نپذیرفت.',
            default => 'ارتباط با سرور به مشکل خورد.',
        };
    }

    public static function join(string $message, string $detail): string
    {
        return $detail === '' ? $message : "{$message} ({$detail})";
    }

    private static function connection(ConnectionFailure $failure): string
    {
        return match ($failure) {
            ConnectionFailure::Dns => 'نام دامنه پنل پیدا نشد؛ آدرس پنل را بررسی کنید.',
            ConnectionFailure::Refused => 'پنل روی این آدرس و پورت پاسخ نمی‌دهد؛ پورت، فایروال و روشن بودن سرور را بررسی کنید.',
            ConnectionFailure::Timeout => 'پنل جواب نداد (تایم‌اوت)؛ دسترسی شبکه را بررسی کنید یا «تایم‌اوت اتصال» را در تنظیمات اتصال سرور بیشتر کنید.',
            ConnectionFailure::Tls => 'گواهی TLS پنل معتبر نیست؛ اگر گواهی خودامضا است، «بررسی گواهی TLS» را خاموش کنید.',
            ConnectionFailure::Other => 'اتصال به پنل برقرار نشد؛ آدرس، پورت و دسترسی شبکه را بررسی کنید.',
        };
    }

    private static function authentication(AuthenticationFailure $failure): string
    {
        return match ($failure) {
            AuthenticationFailure::Missing => 'توکن API یا نام کاربری و رمز عبور وارد نشده است.',
            AuthenticationFailure::Token => 'پنل توکن API را نپذیرفت (' . self::http(401) . ').',
            AuthenticationFailure::Scope => 'این توکن API دسترسی لازم را ندارد.',
            AuthenticationFailure::Login => 'پنل نام کاربری یا رمز عبور را نپذیرفت.',
            AuthenticationFailure::TwoFactor => 'ورود دومرحله‌ای روی پنل فعال است؛ کلید TOTP را وارد کنید.',
            AuthenticationFailure::Session => 'پنل Session را بلافاصله بعد از ورود رد کرد؛ تنظیمات کوکی یا ریورس‌پراکسی پنل را بررسی کنید.',
            AuthenticationFailure::Csrf => 'پنل درخواست ورود را رد کرد چون کوکی Session یا توکن CSRF به آن نرسید (' . self::http(403) . ')؛ اگر پنل پشت ریورس‌پراکسی است، مطمئن شوید کوکی‌ها و هدرها عبور می‌کنند.',
        };
    }

    private static function unexpected(UnexpectedResponseException $e): string
    {
        $http = self::http($e->httpStatus);

        return match (true) {
            $e->isRedirect() => "پنل درخواست را به {$e->location} هدایت می‌کند ({$http})؛ آدرس پنل را مطابق آن اصلاح کنید.",
            $e->httpStatus === 404 => "پنل این مسیر را نمی‌شناسد ({$http}).",
            $e->httpStatus === 403 => "پنل دسترسی به این درخواست را رد کرد ({$http}).",
            $e->httpStatus >= 500 => "پنل یا ریورس‌پراکسی جلوی آن خطای سرور داد ({$http})؛ لاگ پنل یا تنظیمات پراکسی را بررسی کنید.",
            default => "پاسخ پنل قابل خواندن نبود ({$http})؛ به‌جای API یک صفحه وب برگشت.",
        };
    }

    /** The diagnosis, then the driver's remedy when it gave one. */
    private static function withHint(string $lead, string $hint): string
    {
        return $hint === '' ? $lead : "{$lead} {$hint}";
    }

    /** Status codes are identifiers, so they keep Latin digits like ports, versions and URLs. */
    private static function http(int $status): string
    {
        return "HTTP {$status}";
    }
}
