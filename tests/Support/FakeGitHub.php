<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Updates\Releases;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * GitHub for the updater's tests, as the app's outgoing client reaches it (a Guzzle handler: the transport of the
 * container's client, or one of its own): the repository's latest release from its API — publish() it, or none —, and a
 * release's files at github.com, each answered as GitHub does, with a redirect to its storage, where the bytes are.
 * down() takes all of it out of reach, answer() puts another answer in the API's place (a rate limit), storeOn() sends
 * the files to another host. Every request is kept (requests(), calls()).
 */
final class FakeGitHub
{
    public const API = 'https://api.github.com/repos/' . Releases::REPOSITORY . '/releases/latest';

    /** Where GitHub keeps a release's files: what its redirect leads to. */
    public const STORAGE = 'release-assets.githubusercontent.com';

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /** @var array<string, mixed>|null The API's answer for the latest release; null: none published */
    private ?array $release = null;

    /** @var array<string, string> The files attached to it, by name */
    private array $files = [];

    private ?string $version = null;

    private bool $down = false;

    private ?Response $answer = null;

    private string $storage = self::STORAGE;

    /**
     * Release `$version` published with `$files` attached (name => bytes), as the API describes it — `$overrides` merged in
     * (`draft`, `body`…).
     *
     * @param array<string, string> $files
     * @param array<string, mixed> $overrides
     */
    public function publish(string $version, array $files = [], string $notes = '', array $overrides = []): void
    {
        $this->version = $version;
        $this->files = $files;
        $assets = [];
        foreach ($files as $name => $bytes) {
            $assets[] = ['name' => $name, 'size' => strlen($bytes), 'state' => 'uploaded', 'browser_download_url' => $this->download($name)];
        }
        $this->release = [
            'tag_name' => "v{$version}",
            'name' => "v{$version}",
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-10-01T09:30:00Z',
            'html_url' => 'https://github.com/' . Releases::REPOSITORY . "/releases/tag/v{$version}",
            'body' => $notes,
            'assets' => $assets,
            ...$overrides,
        ];
    }

    /** Every address of it out of reach, as an unreachable host is. */
    public function down(): void
    {
        $this->down = true;
    }

    /** The API answers this, whatever is published (a 403 of its rate limit). */
    public function answer(Response $response): void
    {
        $this->answer = $response;
    }

    /** Its redirect sends a release's files to `$host` instead of its storage. */
    public function storeOn(string $host): void
    {
        $this->storage = $host;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->history[] = ['request' => $request, 'options' => $options];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('GitHub is out of reach in this test.', $request));
        }

        return new FulfilledPromise($this->respond((string) $request->getUri()));
    }

    /** @return list<string> Every request made, as "METHOD address" */
    public function calls(): array
    {
        return array_map(static fn(array $call): string => $call['request']->getMethod() . ' ' . $call['request']->getUri(), $this->history);
    }

    public function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'] ?? throw new \OutOfRangeException("No request #{$index} reached GitHub.");
    }

    /** @return array<string, mixed> How the n-th request was sent: its options (redirects, its time limit) */
    public function options(int $index): array
    {
        return $this->history[$index]['options'] ?? throw new \OutOfRangeException("No request #{$index} reached GitHub.");
    }

    private function respond(string $url): Response
    {
        if ($url === self::API) {
            return $this->answer ?? ($this->release === null
                ? new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not Found"}')
                : new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->release)));
        }
        foreach ($this->files as $name => $bytes) {
            if ($url === $this->download($name)) {
                return new Response(302, ['Location' => "https://{$this->storage}/github-production-release-asset/{$name}?sig=x"]);
            }
            if ($url === "https://{$this->storage}/github-production-release-asset/{$name}?sig=x") {
                return new Response(200, ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) strlen($bytes)], $bytes);
            }
        }

        return new Response(404, [], 'Not Found');
    }

    private function download(string $name): string
    {
        return 'https://github.com/' . Releases::REPOSITORY . "/releases/download/v{$this->version}/{$name}";
    }
}
