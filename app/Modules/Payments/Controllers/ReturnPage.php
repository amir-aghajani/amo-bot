<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Exceptions\PaymentHeldException;
use App\Modules\Payments\Models\Payment;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * The page a customer's browser lands on when an online gateway sends them back to the shop (Zarinpal does, once paid
 * or not; Aban does from its page on an https shop): what the shop's own asking found — paid, its money to the wallet,
 * not yet, not paid, held for support —, or that the gateway could not be asked or the address names no payment; and, for
 * a customer with a Telegram account, the way back to the bot (one of the website alone is pointed to their account on
 * the site, which Payments does not know the address of). The only page PHP answers: plain HTML with its own policy — no
 * script, nothing loaded, its styles inline.
 */
final class ReturnPage
{
    /** Its own Content-Security-Policy: nothing but its inline styles. */
    public const POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    /** Paid after its order had closed: the money went to the customer's wallet (Payment::wentToWallet()). */
    public const WALLET = 'wallet';

    /** Held for support (Services\OnlinePayments::isHeld()): not to be paid again. */
    public const HELD = 'held';

    /** Neither paid nor refused: the gateway could not be asked. */
    public const UNANSWERED = 'unanswered';

    /** The address names no payment. */
    public const UNKNOWN = 'unknown';

    /**
     * @var array<string, array{0: string, 1: string, 2?: string}> By what was found: its heading, its words (%d: the order),
     *                                                             and those for a customer with no Telegram account where
     *                                                             they differ
     */
    private const WORDS = [
        'paid' => ['✅ پرداخت شما تایید شد', 'سفارش #%d پرداخت شد و نتیجه آن را در ربات برایتان فرستادیم.', 'سفارش #%d پرداخت شد؛ نتیجه آن را در حساب خود در سایت فروشگاه می‌بینید.'],
        self::WALLET => ['💰 مبلغ به کیف پول شما اضافه شد', 'سفارش #%d پیش از رسیدن این پرداخت بسته شده بود؛ مبلغ آن به کیف پول شما اضافه شد و می‌توانید با آن خرید کنید.'],
        'waiting' => ['⏳ پرداخت شما هنوز تایید نشده است', 'اگر پرداخت را کامل کرده‌اید، تا چند دقیقه دیگر خودکار تایید می‌شود و نتیجه سفارش #%d را در ربات می‌بینید.', 'اگر پرداخت را کامل کرده‌اید، تا چند دقیقه دیگر خودکار تایید می‌شود و نتیجه سفارش #%d را در حساب خود در سایت فروشگاه می‌بینید.'],
        'closed' => ['❌ پرداخت انجام نشد', 'سفارش #%d پرداخت نشد. اگر مبلغی از حسابتان کم شده است، با شماره سفارش به پشتیبانی پیام بدهید.'],
        self::HELD => ['🎧 پرداخت شما به بررسی پشتیبانی رسید', PaymentHeldException::MESSAGE],
        self::UNANSWERED => ['⏳ نتیجه پرداخت هنوز معلوم نیست', 'درگاه پرداخت الان پاسخ نمی‌دهد. اگر پرداخت سفارش #%d را کامل کرده‌اید، تا چند دقیقه دیگر خودکار بررسی و تایید می‌شود.'],
        self::UNKNOWN => ['این آدرس پرداختی را نشان نمی‌دهد', 'نشانی را درست باز کنید، یا از همان جایی که خرید کردید دوباره پرداخت کنید.'],
    ];

    /** Under the page of a customer who may have bought on the website too: where they go back to. */
    private const WEBSITE = 'اگر از سایت فروشگاه خرید کرده‌اید، به سایت برگردید؛ سفارش در حساب شما آنجا هم دیده می‌شود.';

    /**
     * The page as `$response`: what was found (an OnlineState's value, WALLET, HELD, UNANSWERED or UNKNOWN) about
     * `$payment`'s order (null: the address named none), under the shop's name. Its customer with a Telegram account is
     * shown «بازگشت به ربات» to `$bot` (its t.me address; null: none); one without is never told of the bot.
     */
    public static function respond(Response $response, string $found, ?Payment $payment, string $shop, ?string $bot, int $status = 200): Response
    {
        $telegram = $payment === null || $payment->order->user->telegram_id !== null;
        $words = self::WORDS[$found] ?? self::WORDS[OnlineState::Waiting->value];
        $text = $telegram ? $words[1] : ($words[2] ?? $words[1]);
        $back = $payment !== null && $telegram ? $bot : null;
        $button = $back === null ? '' : '<a href="' . self::escape($back) . '" style="display:block;margin:20px 0 0;padding:12px;border-radius:10px;background:#18181b;color:#ffffff;text-align:center;text-decoration:none;font-weight:bold;">بازگشت به ربات</a>';
        $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . self::escape($words[0]) . '</title></head>'
            . '<body style="margin:0;padding:32px 16px;background:#f4f4f5;direction:rtl;text-align:right;font-family:Tahoma,\'Segoe UI\',Arial,sans-serif;color:#18181b;">'
            . '<main style="max-width:440px;margin:0 auto;padding:24px;border:1px solid #e4e4e7;border-radius:14px;background:#ffffff;">'
            . ($shop === '' ? '' : '<div style="margin:0 0 12px;font-size:13px;color:#71717a;">' . self::escape($shop) . '</div>')
            . '<h1 style="margin:0 0 12px;font-size:19px;line-height:1.6;">' . self::escape($words[0]) . '</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.9;">' . self::escape(sprintf($text, $payment->order_id ?? 0)) . '</p>'
            . $button
            . ($telegram ? '<p style="margin:16px 0 0;font-size:13px;line-height:1.8;color:#71717a;">' . self::escape(self::WEBSITE) . '</p>' : '')
            . '</main></body></html>';

        $response->getBody()->write($html);

        return $response->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', self::POLICY);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
