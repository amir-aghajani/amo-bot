<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Application;
use App\Core\Exceptions\TooLargeException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\RequestOrigin;
use App\Core\Logging\Redact;
use App\Core\Security\RateLimiter;
use App\Modules\Auth\Principal;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The panels' own failures, as their pages report them (POST /client-errors): a page they could not draw (`render`), an
 * error a press or a promise left unhandled (`unhandled`). A report is one error line in the app's log — what was thrown
 * and where, the top of its stack, the panel's build and the shop's version, who and which browser — and nothing is kept
 * anywhere else. It is the browser's word, so it is held short: the body is capped (MAX_BYTES), each part cut to its
 * length, the secrets taken out (Redact), nothing in it can begin a line of the log (a forged line) or carry a terminal's
 * control codes, and a principal or an address sends MAX_REPORTS in a window and MAX_REPORTS_A_DAY in a day — a page
 * failing in a loop, or an agent filling the owner's log, waits.
 */
final class ClientErrors
{
    /** The largest report taken, in bytes: a message and a trimmed stack fit many times over. */
    public const MAX_BYTES = 16384;

    /** Reports a principal — and an address — sends in a window of WINDOW_SECONDS. */
    public const MAX_REPORTS = 20;

    public const WINDOW_SECONDS = 600;

    /**
     * Reports a principal — and an address — sends in a day: a page failing all day long is heard all the same, and the
     * log a principal can fill stays a few megabytes a day.
     */
    public const MAX_REPORTS_A_DAY = 100;

    private const DAY_SECONDS = 86400;

    public const KINDS = ['render', 'unhandled'];

    public const TOO_LARGE = 'گزارش خطا بزرگ‌تر از حد مجاز است.';

    /** What a part of a report keeps, in characters. */
    private const LIMITS = ['message' => 500, 'address' => 300, 'build' => 40, 'stack' => 4000, 'component_stack' => 2000, 'agent' => 200];

    /** How a reported block's lines start in the log: never where a line of the log's own does. */
    private const INDENT = '    ';

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly RequestOrigin $origin,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Log one report of `$principal`'s page.
     *
     * @throws TooLargeException 413: a body over MAX_BYTES
     * @throws TooManyAttemptsException 429: the principal, or the address, sent its reports for the window
     * @throws ValidationException 422: no kind of the panel's, or nothing said
     */
    public function report(ServerRequestInterface $request, Principal $principal): void
    {
        if (strlen((string) $request->getBody()) > self::MAX_BYTES) {
            throw new TooLargeException(self::TOO_LARGE);
        }

        // Who sends it, and from where: each held to its reports in the window and in the day.
        $senders = ['principal|' . $principal->name, 'address|' . $this->origin->clientNetwork($request)];
        $windows = [];
        foreach ($senders as $sender) {
            $windows[] = ['client-errors|' . $sender, self::MAX_REPORTS, self::WINDOW_SECONDS];
            $windows[] = ['client-errors|day|' . $sender, self::MAX_REPORTS_A_DAY, self::DAY_SECONDS];
        }
        $wait = $this->limiter->attempt($windows);
        if ($wait > 0) {
            throw new TooManyAttemptsException(sprintf('گزارش خطا زیاد فرستاده شد؛ %s دقیقه دیگر دوباره فرستاده می‌شود.', TooManyAttemptsException::minutes($wait)), $wait);
        }

        $body = $request->getParsedBody();
        $input = is_array($body) ? $body : [];
        $kind = Input::text($input, 'kind');
        $message = self::line(Input::text($input, 'message'), 'message');
        $errors = [];
        if (!in_array($kind, self::KINDS, true)) {
            $errors['kind'][] = 'نوع خطا معتبر نیست.';
        }
        if ($message === '') {
            $errors['message'][] = 'متن خطا خالی است.';
        }
        ValidationException::ifAny($errors);

        // The address is the panel's own (…/admin/…, …/agent/…): which panel, and where in it.
        $this->logger->error('A panel page failed ({kind}) at {address}: {error}', [
            'kind' => $kind,
            'address' => self::line(self::address(Input::text($input, 'address')), 'address'),
            'error' => $message,
            'principal' => $principal->name,
            'shop' => $principal->shop->id,
            'build' => self::line(Input::text($input, 'build'), 'build'),
            'version' => Application::VERSION,
            'ip' => $this->origin->clientIp($request),
            'agent' => self::line($request->getHeaderLine('User-Agent'), 'agent'),
            'stack' => self::block(self::string($input, 'stack'), 'stack'),
            'component_stack' => self::block(self::string($input, 'component_stack'), 'component_stack'),
        ]);
    }

    /**
     * The page's address as a report keeps it: its path, and of its query the parameters' names alone — never a value (a
     * search may hold a customer's name, a phone, an email) nor the fragment (an agent's sign-in link carries its code
     * there). The panel sends it so already; a page that would not is held to it all the same.
     */
    private static function address(string $address): string
    {
        [$address] = explode('#', $address, 2);
        [$path, $query] = array_pad(explode('?', $address, 2), 2, '');
        $names = array_values(array_unique(array_filter(array_map(static fn(string $pair): string => explode('=', $pair, 2)[0], explode('&', $query)), static fn(string $name): bool => $name !== '')));

        return $names === [] ? $path : $path . '?' . implode('&', $names);
    }

    /** One line of the browser's: no secret, no control code, no line break — cut to what `$part` keeps. */
    private static function line(string $text, string $part): string
    {
        return trim(mb_substr((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', Redact::text($text)), 0, self::LIMITS[$part]));
    }

    /**
     * Lines of the browser's (a stack): no secret, no control code but the line breaks, each line indented so none
     * begins where a line of the log does — cut to what `$part` keeps; null for none.
     */
    private static function block(string $text, string $part): ?string
    {
        $text = mb_substr(str_replace(["\r\n", "\r"], "\n", Redact::text($text)), 0, self::LIMITS[$part]);
        $lines = array_filter(array_map(static fn(string $line): string => rtrim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $line)), explode("\n", $text)), static fn(string $line): bool => trim($line) !== '');

        return $lines === [] ? null : self::INDENT . implode("\n" . self::INDENT, $lines);
    }

    /** @param array<string, mixed> $input A text part as sent, line breaks and all; anything that is no text is none. */
    private static function string(array $input, string $field): string
    {
        $value = $input[$field] ?? '';

        return is_string($value) ? $value : '';
    }
}
