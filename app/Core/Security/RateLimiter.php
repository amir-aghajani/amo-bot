<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Support\Files;

/**
 * Counts attempts per key in a window. Shared hosting has no cache server, so each key is a small file (`throttle.path`)
 * written under an exclusive lock and read under a shared one: two requests at once count twice, and nothing is read
 * half written. A window opens with the first attempt and closes `decay` seconds later on the shop's clock (now()).
 * attempt() is the one to use when a limit must hold however many requests come at once; enforce() is attempt() with the
 * usual 429.
 *
 * It fails closed: with its folder unwritable it would let an attacker try without end, so it throws
 * ThrottleUnavailableException (a 500, logged) until the folder is fixed.
 */
final class RateLimiter
{
    /** A window's file left behind this long is forgotten (one file per address that ever failed). */
    private const PRUNE_AFTER = 86400;

    public function __construct(private readonly string $directory) {}

    /**
     * Seconds the key must wait before trying again: 0 while it has attempts left in its window.
     *
     * @throws ThrottleUnavailableException
     */
    public function availableIn(string $key, int $maxAttempts): int
    {
        $this->assertWritable();
        $state = self::open(self::decode((string) Files::readShared($this->file($key))));
        if ($state === null || $state['count'] < $maxAttempts) {
            return 0;
        }

        return max(0, $state['until'] - now()->getTimestamp());
    }

    /**
     * Count one attempt; the window opens with the first one. Returns the attempts made in the window so far.
     *
     * @throws ThrottleUnavailableException
     */
    public function hit(string $key, int $decaySeconds): int
    {
        $this->assertWritable();
        if (random_int(1, 50) === 1) {
            $this->prune();
        }

        try {
            $written = Files::rewrite($this->file($key), static function (string $content) use ($decaySeconds): string {
                $state = self::open(self::decode($content));

                return (string) json_encode($state === null ? ['count' => 1, 'until' => now()->getTimestamp() + $decaySeconds] : ['count' => $state['count'] + 1, 'until' => $state['until']]);
            });
        } catch (\RuntimeException) {
            throw new ThrottleUnavailableException($this->directory);
        }

        return self::decode($written)['count'] ?? 0;
    }

    /**
     * Count one attempt in every window (`[key, max attempts, decay seconds]` each — an address's and an account's, an
     * hour's and a day's). Returns 0 when every window had room; else nothing stays counted and it returns the seconds
     * until the fullest has room again (at least 1). Each window is counted first under its lock and judged by the count
     * it reached — checking before counting would let simultaneous requests all through.
     *
     * @param non-empty-list<array{string, int, int}> $windows
     * @throws ThrottleUnavailableException
     */
    public function attempt(array $windows): int
    {
        $counted = [];
        foreach ($windows as [$key, $max, $seconds]) {
            $counted[] = $key;
            if ($this->hit($key, $seconds) <= $max) {
                continue;
            }

            foreach ($counted as $taken) {
                $this->release($taken);
            }

            return max(1, ...array_map(fn(array $window): int => $this->availableIn($window[0], $window[1]), $windows));
        }

        return 0;
    }

    /**
     * attempt(), refused when a window is full: a 429 saying `$what` and the wait («…؛ ۵ دقیقه دیگر دوباره امتحان کنید.»).
     *
     * @param non-empty-list<array{string, int, int}> $windows
     * @throws TooManyAttemptsException
     * @throws ThrottleUnavailableException
     */
    public function enforce(array $windows, string $what): void
    {
        $wait = $this->attempt($windows);
        if ($wait > 0) {
            throw TooManyAttemptsException::wait($what, $wait);
        }
    }

    /**
     * Give back one attempt counted by hit() (a code counted before it was judged, and found right). Never below zero.
     *
     * @throws ThrottleUnavailableException
     */
    public function release(string $key): void
    {
        $this->assertWritable();

        try {
            Files::rewrite($this->file($key), static function (string $content): string {
                $state = self::open(self::decode($content));

                return $state === null ? $content : (string) json_encode(['count' => max(0, $state['count'] - 1), 'until' => $state['until']]);
            });
        } catch (\RuntimeException) {
            throw new ThrottleUnavailableException($this->directory);
        }
    }

    public function clear(string $key): void
    {
        Files::delete($this->file($key));
    }

    /** @throws ThrottleUnavailableException */
    private function assertWritable(): void
    {
        if (!Files::writableDirectory($this->directory)) {
            throw new ThrottleUnavailableException($this->directory);
        }
    }

    private function prune(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            if ((Files::modifiedAt($file) ?? PHP_INT_MAX) < time() - self::PRUNE_AFTER) {
                Files::delete($file);
            }
        }
    }

    /**
     * @param array{count: int, until: int}|null $state
     * @return array{count: int, until: int}|null The window, while it is open
     */
    private static function open(?array $state): ?array
    {
        return $state !== null && $state['until'] > now()->getTimestamp() ? $state : null;
    }

    /** @return array{count: int, until: int}|null */
    private static function decode(string $raw): ?array
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_int($data['count'] ?? null) || !is_int($data['until'] ?? null)) {
            return null;
        }

        return ['count' => $data['count'], 'until' => $data['until']];
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }
}
