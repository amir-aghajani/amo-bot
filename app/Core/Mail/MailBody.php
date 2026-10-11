<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * How every email of the shop looks: a right-to-left card in inline styles (mail readers keep no stylesheet) with the
 * shop's name, a heading and the text, plus the same as plain text. message() escapes everything it is given;
 * formatted() takes HTML its maker already made safe.
 */
final class MailBody
{
    /**
     * @param list<string> $before Paragraphs above the code (all of them, without one)
     * @param list<string> $after Paragraphs under it
     */
    public static function message(string $to, string $subject, string $shop, string $heading, array $before, ?string $code = null, array $after = []): MailMessage
    {
        $paragraphs = static fn(array $lines): string => implode('', array_map(
            static fn(string $line): string => '<p style="margin:0 0 12px;font-size:14px;line-height:1.9;">' . self::escape($line) . '</p>',
            $lines,
        ));
        $box = $code === null ? '' : '<div dir="ltr" style="margin:20px 0;padding:14px;border-radius:8px;background:#f4f4f5;text-align:center;font-size:28px;font-weight:bold;letter-spacing:8px;font-family:Consolas,Menlo,monospace;color:#18181b;">' . self::escape($code) . '</div>';

        return new MailMessage($to, $subject, self::html($subject, $shop, $heading, $paragraphs($before) . $box . $paragraphs($after)), self::text($shop, $heading, [...$before, ...($code === null ? [] : [$code]), ...$after]), $shop);
    }

    /** The card around `$html`, already safe (WebText::html(): a notice as the bot words it), with its plain text. */
    public static function formatted(string $to, string $subject, string $shop, string $heading, string $html, string $text): MailMessage
    {
        return new MailMessage($to, $subject, self::html($subject, $shop, $heading, '<div style="margin:0 0 12px;font-size:14px;line-height:1.9;">' . $html . '</div>'), self::text($shop, $heading, [$text]), $shop);
    }

    private static function html(string $subject, string $shop, string $heading, string $body): string
    {
        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::escape($subject) . '</title></head>'
            . '<body style="margin:0;padding:24px 12px;background:#f4f4f5;direction:rtl;text-align:right;font-family:Tahoma,\'Segoe UI\',Arial,sans-serif;color:#18181b;">'
            . '<div style="max-width:480px;margin:0 auto;padding:24px;border:1px solid #e4e4e7;border-radius:12px;background:#ffffff;">'
            . '<div style="margin:0 0 16px;font-size:13px;color:#71717a;">' . self::escape($shop) . '</div>'
            . '<h1 style="margin:0 0 16px;font-size:18px;line-height:1.6;">' . self::escape($heading) . '</h1>'
            . $body
            . '</div></body></html>';
    }

    /** @param list<string> $words The paragraphs, the code among them where it stands */
    private static function text(string $shop, string $heading, array $words): string
    {
        return implode("\n\n", [$shop, $heading, ...$words]) . "\n";
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
