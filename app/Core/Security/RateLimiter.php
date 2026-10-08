<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Support\Files;

/**
 * Counts attempts per key over a window — a sign-in that keeps failing from one address waits. Shared hosting has no
 * cache server, so each key is a small file in its folder (the container's `throttle.path`), written under an exclusive
 * lock and read under a shared one: two requests at once count twice, never once, and a count is never read half
 * written. A window opens with the first attempt and closes `decay` seconds later, on the shop's clock (now() — the
 * tests move it); clear() closes it early (a sign-in that worked). What must never pass its limit, however many requests
 * ask at once, is attempt()'s: counted first, under the lock, and given back when it is over.
 *
 * A throttle that cannot keep count fails closed: with its folder not writable it would let an attacker try without
 * end, so it answers nothing — availableIn() and hit() throw, the request is a 500 and the error log says why — until
 * the folder is fixed.
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
     * One attempt of something held to a limit in every window it is counted in — `[key, max attempts, decay seconds]`
     * each (an address's and an account's, an hour's and a day's) —: counted in every one while each has room, and 0;
     * else nothing counted, and the seconds until the fullest has room again (1 at least) — what the caller words as its
     * refusal (a 429, Core\Exceptions\TooManyAttemptsException). Each window is counted first, under its lock (hit()),
     * and judged by the count it came to: one over its limit gives back what this attempt counted (release()). So of
     * requests that ask at the same moment, never more pass than a window has room for — a look at the counts before
     * counting would let them all through.
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
     * One attempt counted by hit() given back, under the same lock — what a reservation that was not spent returns (a
     * code counted before it was judged, and right). Never below none; a window that closed meanwhile stays closed.
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
