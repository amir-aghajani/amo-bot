<?php

declare(strict_types=1);

namespace App\Core\Session;

use App\Core\Support\Files;

/**
 * The panels' session: PHP's own, so any host runs it. On the CLI (the tests) it is an in-memory array.
 *
 * An idle session (past SESSION_LIFETIME) starts empty whatever the host's collector does: the last use is kept in it,
 * written at most once a minute. PHP locks the session file while a request holds it, so a request lets go (release())
 * as soon as it can; after that a change would be lost, so set(), remove() and regenerate() throw.
 */
final class Session
{
    /** When the session was last used (unix seconds). */
    private const LAST_USED = '_last_used';

    /** Running without a real PHP session (CLI, tests): $_SESSION is a plain array. */
    private bool $inMemory = false;

    /** The request let go of the session (release()). */
    private bool $released = false;

    /**
     * config/session.php, its save path filled in (the container's).
     *
     * @param array{name: string, lifetime: int, save_path: string, cookie: array{path: string, domain: string, secure: bool, httponly: bool, samesite: string}} $config
     */
    public function __construct(private readonly array $config) {}

    /** @param bool $https The request came over HTTPS: the cookie is then always secure */
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
     * A new session id (at a sign-in or sign-out), so an id someone learnt before is worth nothing.
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
     * Let go of the session for the rest of this request: written if it holds anything, destroyed with its file if it
     * holds only its last use (nobody signed in) — PHP makes a file for every cookieless request, and left to the
     * collector they would let anyone fill a shared host's file quota. Its values can still be read.
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

    /** The request is over: release(), and ready for the next request. */
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
            // SESSION_SECURE_COOKIE forces it behind a proxy that hides the browser's HTTPS.
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
            // PHP's collector cleans the app's own folder; some hosts switch it off and clean only theirs.
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
