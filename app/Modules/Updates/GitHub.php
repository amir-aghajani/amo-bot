<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Support\Files;
use App\Modules\Updates\Exceptions\UpdateRefusedException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * GitHub as the updater speaks to it, through the shop's one outgoing client (its User-Agent names AmoBot and its
 * version): the repository's latest release from its API, and a release's files from github.com — followed through
 * GitHub's redirects to its storage, over https and to GitHub's own hosts alone, and read no further than the caller
 * allows. What comes back is believed by nobody here: the manifest is the release key's to vouch for, the zip the
 * manifest's (Updater).
 */
final class GitHub
{
    public const UNREACHABLE = 'GitHub جواب نداد؛ کمی بعد دوباره امتحان کنید.';

    public const LIMITED = 'GitHub فعلا درخواست بیشتری از آدرس این هاست نمی‌پذیرد؛ ساعتی بعد دوباره امتحان کنید.';

    private const API = 'https://api.github.com/repos/' . Releases::REPOSITORY . '/releases/latest';

    /** The most the API's answer may weigh: a release's notes and its files' list. */
    private const ANSWER_BYTES = 1024 * 1024;

    /** How many of GitHub's redirects a file may take on its way: one, to its storage — a couple more allowed. */
    private const REDIRECTS = 3;

    private const CHUNK = 65536;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The repository's latest release — neither a draft nor a pre-release — as the API describes it; null while the
     * repository has published none.
     *
     * @return array<string, mixed>|null
     * @throws UpdateRefusedException when GitHub does not answer, or answers no release
     */
    public function latest(): ?array
    {
        $response = $this->get(self::API, ['headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']]);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            throw $this->refusal($response, 'the latest release');
        }

        $body = '';
        $answer = $this->read($response, self::ANSWER_BYTES, static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        }) ? json_decode($body, true) : null;
        if (!is_array($answer)) {
            $this->logger->warning('GitHub answered no release for the latest one.');

            throw UpdateRefusedException::unreachable(self::UNREACHABLE);
        }

        /** @var array<string, mixed> $answer */
        return $answer;
    }

    /**
     * A file of release `$version`, written to `$target` whole or not at all: no more than `$maxBytes` of it, within
     * `$seconds`.
     *
     * @throws UpdateRefusedException when it cannot be had, is bigger than allowed, or cannot be written
     */
    public function download(string $version, string $name, string $target, int $maxBytes, int $seconds): void
    {
        $response = $this->follow('https://github.com/' . Releases::REPOSITORY . "/releases/download/v{$version}/" . rawurlencode($name), $name, $seconds);
        if ($response->getStatusCode() === 404) {
            throw UpdateRefusedException::refused("فایل {$name} در این نسخه روی GitHub نیست.");
        }
        if ($response->getStatusCode() !== 200) {
            throw $this->refusal($response, $name);
        }

        $part = $target . '.part';
        try {
            Disk::folder(dirname($part));
            $handle = Disk::create($part);
        } catch (\RuntimeException) {
            throw UpdateRefusedException::refused(Workspace::UNWRITABLE);
        }
        try {
            $whole = $this->read($response, $maxBytes, static fn(string $chunk) => Disk::write($handle, $chunk));
        } catch (\RuntimeException $e) {
            Files::delete($part);

            throw $e instanceof UpdateRefusedException ? $e : UpdateRefusedException::refused(Workspace::UNWRITABLE);
        } finally {
            fclose($handle);
        }
        if (!$whole) {
            Files::delete($part);

            throw UpdateRefusedException::refused("فایل {$name} بزرگ‌تر از چیزی است که این نسخه گفته؛ نصب نمی‌شود.");
        }

        try {
            Disk::move($part, $target);
        } catch (\RuntimeException) {
            Files::delete($part);

            throw UpdateRefusedException::refused(Workspace::UNWRITABLE);
        }
    }

    /**
     * The answer at the end of GitHub's redirects from `$url`, each address on the way GitHub's own.
     *
     * @throws UpdateRefusedException when a redirect leads elsewhere, or GitHub is out of reach
     */
    private function follow(string $url, string $name, int $seconds): ResponseInterface
    {
        for ($redirects = 0; ; $redirects++) {
            if (!self::isGitHubs($url)) {
                $this->logger->warning('GitHub sent {file} to {host}, which is not GitHub\'s.', ['file' => $name, 'host' => (new Uri($url))->getHost()]);

                throw UpdateRefusedException::refused("GitHub فایل {$name} را به آدرسی بیرون از خودش فرستاد؛ دانلود نشد.");
            }
            $response = $this->get($url, ['stream' => true, 'timeout' => $seconds]);
            $location = $response->getHeaderLine('Location');
            if (!in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true) || $location === '' || $redirects === self::REDIRECTS) {
                return $response;
            }
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }
    }

    /** Whether the address is GitHub's: over https, on github.com or its storage (*.githubusercontent.com), naming nobody. */
    private static function isGitHubs(string $url): bool
    {
        $uri = new Uri($url);
        $host = strtolower($uri->getHost());

        return $uri->getScheme() === 'https' && $uri->getUserInfo() === '' && in_array($uri->getPort(), [null, 443], true)
            && ($host === 'github.com' || str_ends_with($host, '.githubusercontent.com'));
    }

    /**
     * @param array<string, mixed> $options
     * @throws UpdateRefusedException when GitHub is out of reach
     */
    private function get(string $url, array $options): ResponseInterface
    {
        try {
            return $this->http->request('GET', $url, ['allow_redirects' => false, 'http_errors' => false] + $options);
        } catch (GuzzleException $e) {
            $this->logger->warning('GitHub is out of reach: {message}', ['message' => $e->getMessage()]);

            throw UpdateRefusedException::unreachable(self::UNREACHABLE);
        }
    }

    /**
     * The body handed to `$take` a chunk at a time — no more than `$max` bytes of it.
     *
     * @param \Closure(string): void $take
     * @return bool Whether that was all of it: false when there was more
     * @throws UpdateRefusedException when the connection breaks off
     * @throws \RuntimeException what `$take` throws
     */
    private function read(ResponseInterface $response, int $max, \Closure $take): bool
    {
        $body = $response->getBody();
        $size = 0;
        while (true) {
            try {
                if ($body->eof()) {
                    return true;
                }
                $chunk = $body->read(self::CHUNK);
            } catch (\RuntimeException $e) {
                $this->logger->warning('GitHub\'s answer broke off: {message}', ['message' => $e->getMessage()]);

                throw UpdateRefusedException::unreachable(self::UNREACHABLE);
            }
            $size += strlen($chunk);
            if ($size > $max) {
                return false;
            }
            $take($chunk);
        }
    }

    /** What an answer that is not the one asked for says: GitHub limiting this host's calls, or not answering as it should. */
    private function refusal(ResponseInterface $response, string $what): UpdateRefusedException
    {
        $status = $response->getStatusCode();
        $limited = $status === 429 || ($status === 403 && $response->getHeaderLine('X-RateLimit-Remaining') === '0');
        $this->logger->warning('GitHub answered {status} for {what}.', ['status' => $status, 'what' => $what]);

        return UpdateRefusedException::unreachable($limited ? self::LIMITED : self::UNREACHABLE);
    }
}
