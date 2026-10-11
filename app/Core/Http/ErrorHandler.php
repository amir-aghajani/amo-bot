<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Logging\Redact;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Slim\Interfaces\CallableResolverInterface;

/**
 * Turns every failure into the one JSON error shape (Json::error()). A DomainRuleException is answered with its own
 * status, message and field errors (a 429 with `Retry-After`); a missing row (findOrFail) is a 404; anything else is the
 * generic 500, logged once with its trace under the request's id — and shown in a `debug` block only with APP_DEBUG,
 * to a request from this machine.
 */
final class ErrorHandler extends SlimErrorHandler
{
    /** A row the request names that does not exist, or is another shop's. */
    public const NOT_FOUND = 'مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.';

    /** The frames of a trace a debug block shows at most. */
    public const DEBUG_FRAMES = 20;

    /** The words for the router's own answers (no route, wrong method) and for a failure; refusals bring their own. */
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
        /** The app's folder: the paths in a debug block are given relative to it. */
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
     * Log failures only — one line naming the request, the exception in its context so the formatter prints the trace.
     * Refusals, missing rows and client errors are answers, and noise in an error log.
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

    /** Whether the server failed, as opposed to answering a refusal, a missing row or a client error. */
    private function failed(): bool
    {
        $exception = $this->exception;

        return !($exception instanceof DomainRuleException || $exception instanceof ModelNotFoundException || ($exception instanceof HttpException && $this->statusCode < 500));
    }

    /**
     * With APP_DEBUG, for a server failure answered to a request straight from this machine (never one a proxy or tunnel
     * forwarded — those make every visitor look local): the exception's class, its redacted message and DEBUG_FRAMES
     * frames, paths relative to the app's folder, no arguments.
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

    /** A class's name without the file path PHP appends to an anonymous class's. */
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
