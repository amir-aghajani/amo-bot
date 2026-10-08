<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Logging\Redact;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Slim\Interfaces\CallableResolverInterface;

/**
 * Every error the HTTP side answers, in the one JSON shape (`{message, errors?, request_id}` — Json::error()): the app
 * only speaks JSON — the panel is static files and renders its own pages. A controller lets the shop's refusals through
 * instead of catching them: a DomainRuleException (ValidationException among them) is answered with its own status,
 * message and field errors — a TooManyAttemptsException with its wait in `Retry-After` too —, and a row the request
 * names that is not there — `findOrFail()`, `ApiController::load()` — with a 404: the BelongsToBot scope keeps a shop's
 * lookups inside that shop, so another shop's row is not there either. Anything else is the generic 500: what failed
 * stays in the log, under the request's id the answer names — and, with APP_DEBUG, in a `debug` block of the answer to a
 * request from this machine alone (debug()).
 */
final class ErrorHandler extends SlimErrorHandler
{
    /** A row the request names that does not exist — or is another shop's. */
    public const NOT_FOUND = 'مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.';

    /** The frames of a trace a debug block shows at most. */
    public const DEBUG_FRAMES = 20;

    /**
     * The words for what the router answers (an address that is nobody's route, a method it does not take) and for a
     * failure; every other refusal is worded where it is made.
     */
    public const MESSAGES = [
        404 => 'صفحه مورد نظر پیدا نشد.',
        405 => 'این روش درخواست پشتیبانی نمی‌شود.',
        500 => 'خطایی در سرور رخ داد. لطفا بعدا دوباره تلاش کنید.',
    ];

    public function __construct(
        CallableResolverInterface $callableResolver,
        ResponseFactoryInterface $responseFactory,
        LoggerInterface $logger,
        private readonly RequestOrigin $origin,
        /** The app's folder: what the paths of a debug block are told relative to. */
        private readonly string $basePath,
    ) {
        parent::__construct($callableResolver, $responseFactory, $logger);
    }

    protected function determineStatusCode(): int
    {
        return match (true) {
            $this->exception instanceof DomainRuleException => $this->exception->status(),
            $this->exception instanceof ModelNotFoundException => 404,
            default => parent::determineStatusCode(),
        };
    }

    /**
     * Only failures are recorded — one line naming the request, with the exception in the context so the formatter
     * prints its trace (Slim's own renderer would write a second copy). The shop's refusals, a missing row and the
     * client errors (404, 405, 403…) are answers, and noise in an error log.
     */
    protected function writeToErrorLog(): void
    {
        if (!$this->failed()) {
            return;
        }

        $this->logger->error(
            sprintf('%s %s -> %d %s', $this->request->getMethod(), $this->request->getUri()->getPath(), $this->statusCode, $this->exception->getMessage()),
            ['exception' => $this->exception],
        );
    }

    protected function respond(): ResponseInterface
    {
        $exception = $this->exception;
        [$message, $errors] = match (true) {
            $exception instanceof DomainRuleException => [$exception->getMessage(), $exception->errors()],
            $exception instanceof ModelNotFoundException => [self::NOT_FOUND, []],
            default => [self::MESSAGES[$this->statusCode] ?? self::MESSAGES[500], []],
        };

        $response = Json::error($this->responseFactory->createResponse($this->statusCode), $message, $this->statusCode, $errors, $this->debug());

        // A refusal to try again yet says for how long, as a client reads it.
        return $exception instanceof TooManyAttemptsException ? $response->withHeader('Retry-After', (string) $exception->retryAfter) : $response;
    }

    /** Whether the server failed — not a refusal of the shop's, a row not there, a client's mistake: an answer each. */
    private function failed(): bool
    {
        $exception = $this->exception;

        return !($exception instanceof DomainRuleException || $exception instanceof ModelNotFoundException || ($exception instanceof HttpException && $this->statusCode < 500));
    }

    /**
     * With APP_DEBUG, what failed — only when the server failed (a 500; never an answer, whatever its status), and only to
     * a request straight from this machine (RequestOrigin::fromThisMachine(): never one a proxy or a tunnel forwarded,
     * which makes every visitor look local): the exception's class, its message, where it was thrown and DEBUG_FRAMES of
     * the calls that led there — every path relative to the app's folder (a closure's or an anonymous class's name carries
     * its file's too), no call's arguments, no secret (a transport error's message carries Telegram's URL with the bot
     * token, a request path the webhook secret).
     *
     * @return array{exception: string, detail: string, trace: list<string>}|null
     */
    private function debug(): ?array
    {
        if (!$this->displayErrorDetails || !$this->failed() || !$this->origin->fromThisMachine($this->request)) {
            return null;
        }

        $exception = $this->exception;
        $trace = [sprintf('thrown at %s(%d)', $this->path($exception->getFile()), $exception->getLine())];
        foreach (array_slice($exception->getTrace(), 0, self::DEBUG_FRAMES) as $i => $frame) {
            $where = isset($frame['file']) ? sprintf('%s(%d)', $this->path($frame['file']), $frame['line'] ?? 0) : '[internal]';
            $trace[] = sprintf('#%d %s: %s%s%s()', $i, $where, self::named($frame['class'] ?? ''), $frame['type'] ?? '', $this->relative($frame['function']));
        }

        return [
            'exception' => self::named($exception::class),
            'detail' => Redact::text($this->relative($exception->getMessage())),
            'trace' => array_map(fn(string $line): string => Redact::text($this->relative($line)), $trace),
        ];
    }

    /** A class's name as a debug block tells it: an anonymous class's ends where PHP writes its file's path after it. */
    private static function named(string $class): string
    {
        return explode("\0", $class, 2)[0];
    }

    /** A file's path as a debug block tells it: from the app's folder, with forward slashes. */
    private function path(string $file): string
    {
        return strtr($this->relative($file), '\\', '/');
    }

    /** `$text` with the app's folder taken out of every path in it. */
    private function relative(string $text): string
    {
        $base = rtrim($this->basePath, '/\\');

        return str_replace([$base . '/', $base . '\\'], '', $text);
    }
}
