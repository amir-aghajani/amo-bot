<?php

declare(strict_types=1);

namespace App\Core\Session;

use App\Core\Support\Files;

/**
 * The panels' session: PHP's own, so any shared host runs it with no service of its own. Started for the panels' API
 * alone (SessionMiddleware on /api/admin and /api/agent); on the command line — the bot, the tests — there is no cookie,
 * and the session is a plain array in memory.
 *
 * A session idle longer than its lifetime starts empty, whenever the host's collector comes by: the moment it was last
 * used is kept in it, written at most once a minute so a request that changes nothing does not rewrite its file.
 *
 * PHP holds a session's file locked while a request has it open, so the browser's requests would run one after the
 * other: a request lets go of it (release()) as soon as it has what it needs — every read at once (SessionMiddleware),
 * since only signing in or out, changing the login or ending an agent's other sessions writes to it.
 */
final class Session
{
    /** When the session was last used (unix seconds). */
    private const LAST_USED = '_last_used';

    /** True when running without a real PHP session (CLI / tests): $_SESSION is a plain in-memory array. */
    private bool $inMemory = false;

    /** The request let go of the session (release()): nothing is written to it until the next one starts. */
    private bool $released = false;

    /**
     * config/session.php, its save path filled in (the container's).
     *
     * @param array{name: string, lifetime: int, save_path: string, cookie: array{path: string, domain: string, secure: bool, httponly: bool, samesite: string}} $config
     */
    public function __construct(private readonly array $config) {}

    /** @param bool $https The request came over HTTPS: the cookie never travels in clear text then */
    public function start(bool $https): void
    {
        $this->released = false;
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= [];
            $this->inMemory = true;
        } elseif (session_status() !== PHP_SESSION_ACTIVE) {
            $this->open($https);
        }

        $this->forgetWhenIdle();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /** @throws \LogicException once the request let go of the session (release()) */
    public function set(string $key, mixed $value): void
    {
        $this->assertHeld();
        $_SESSION[$key] = $value;
    }

    /** @throws \LogicException once the request let go of the session (release()) */
    public function remove(string $key): void
    {
        $this->assertHeld();
        unset($_SESSION[$key]);
    }

    /**
     * A new id for the session (a sign-in, a sign-out): an id someone learnt before is worth nothing after.
     *
     * @throws \LogicException once the request let go of the session (release())
     */
    public function regenerate(): void
    {
        $this->assertHeld();
        if (!$this->inMemory && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Done with the session for this request, its lock let go: one that keeps something is written as it stands, one
     * that holds nothing but the moment it was used — nobody signed in, or they signed out — goes, its file too. PHP makes
     * the file as it opens a session, and every request without a cookie opens one: left for the collector, they would
     * let anyone fill a shared host's file quota one request at a time. What it held can still be read; a change cannot
     * be kept any more, so set(), remove() and regenerate() refuse one — a write that would be lost is a mistake to see.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        if (!$this->inMemory && session_status() === PHP_SESSION_ACTIVE) {
            array_diff_key($_SESSION, [self::LAST_USED => true]) === [] ? session_destroy() : session_write_close();
        }
    }

    /** The request is over: the session let go (release()), and the next request's to hold. */
    public function end(): void
    {
        $this->release();
        $this->released = false;
    }

    /** @throws \LogicException once the request let go of the session */
    private function assertHeld(): void
    {
        if ($this->released) {
            throw new \LogicException('The session was let go for the rest of this request (Session::release()): a change would not be kept.');
        }
    }

    private function open(bool $https): void
    {
        $cookie = $this->config['cookie'];

        session_name($this->config['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            // The setting forces it where PHP sees plain HTTP behind a proxy that hides the HTTPS the browser speaks.
            'secure' => $cookie['secure'] || $https,
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ]);
        ini_set('session.gc_maxlifetime', (string) $this->lifetime());
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        $savePath = $this->config['save_path'];
        if (Files::writableDirectory($savePath)) {
            session_save_path($savePath);
            // The app's own folder is cleaned by PHP's collector, which some hosts switch off (they clean only theirs).
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }

        session_start();
    }

    private function forgetWhenIdle(): void
    {
        $now = now()->getTimestamp();
        $last = $_SESSION[self::LAST_USED] ?? null;
        if (is_int($last) && $now - $last > $this->lifetime()) {
            $_SESSION = [];
            $this->regenerate();
            $last = null;
        }
        if (!is_int($last) || $now - $last >= 60) {
            $_SESSION[self::LAST_USED] = $now;
        }
    }

    /** Seconds of quiet a session outlives. */
    private function lifetime(): int
    {
        return $this->config['lifetime'] * 60;
    }
}
