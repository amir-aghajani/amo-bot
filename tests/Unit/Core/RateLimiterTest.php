<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Security\RateLimiter;
use App\Core\Security\ThrottleUnavailableException;
use Tests\TestCase;

/**
 * Attempts counted per key in files: a window opens with the first attempt and closes `decay` seconds later, a success
 * closes it early — and a throttle that cannot keep count lets nobody try. What is held to a limit passes it never,
 * however many processes ask at the same moment.
 */
final class RateLimiterTest extends TestCase
{
    /**
     * One process of many at once: it waits for the others (the `go` file), then tries `tries` times to pass the window
     * `limit` attempts a minute holds, and says how many of them passed.
     */
    private const RACER = <<<'PHP'
        <?php

        declare(strict_types=1);

        [, $autoload, $directory, $go, $tries, $limit] = $argv;
        require $autoload;

        $limiter = new App\Core\Security\RateLimiter($directory);
        while (!is_file($go)) {
            usleep(100);
        }
        $passed = 0;
        for ($try = 0; $try < (int) $tries; $try++) {
            $passed += $limiter->attempt([['race', (int) $limit, 60]]) === 0 ? 1 : 0;
        }
        echo $passed;
        PHP;

    public function testAttemptsFromManyProcessesAtOnceNeverPassTheLimit(): void
    {
        $autoload = $this->app()->basePath('vendor/autoload.php');
        $scratch = $this->scratchDir();
        $racer = $scratch . '/racer.php';
        $go = $scratch . '/go';
        file_put_contents($racer, self::RACER);

        // Six processes, fifty attempts each, at a window that takes a hundred: a look at the count before counting lets
        // most of them through (one reads what another is writing).
        $racers = [];
        $outputs = [];
        for ($process = 0; $process < 6; $process++) {
            $racers[] = proc_open([PHP_BINARY, $racer, $autoload, $scratch . '/throttle', $go, '50', '100'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $outputs[] = $pipes;
        }
        touch($go);

        $passed = 0;
        foreach ($racers as $index => $process) {
            self::assertIsResource($process);
            [1 => $out, 2 => $err] = $outputs[$index];
            $said = (string) stream_get_contents($out);
            $errors = (string) stream_get_contents($err);
            fclose($out);
            fclose($err);
            proc_close($process);
            self::assertSame('', $errors);
            $passed += (int) $said;
        }

        self::assertSame(100, $passed, 'exactly what the window holds, of 300 asked at once');
    }

    public function testAnAttemptOverALaterWindowGivesBackWhatTheEarlierOnesCounted(): void
    {
        $limiter = new RateLimiter($this->scratchDir() . '/throttle');
        $windows = [['open|7', 5, 3600], ['message|7', 1, 600]];

        self::assertSame(0, $limiter->attempt($windows));
        self::assertEqualsWithDelta(600, $limiter->attempt($windows), 2, 'the message window is full: the wait is its');

        self::assertSame(0, $limiter->attempt([['open|7', 2, 3600]]), 'the refused attempt left the hour as it was: one, not two');
        self::assertEqualsWithDelta(3600, $limiter->attempt([['open|7', 2, 3600]]), 2);
    }

    public function testAKeyWaitsOnceItsAttemptsAreSpentAndOnlyThatKey(): void
    {
        $limiter = new RateLimiter($this->scratchDir() . '/throttle');

        self::assertSame(0, $limiter->availableIn('login|1.1.1.1', 3), 'a key that never tried');
        self::assertSame([1, 2, 3], [$limiter->hit('login|1.1.1.1', 900), $limiter->hit('login|1.1.1.1', 900), $limiter->hit('login|1.1.1.1', 900)]);
        self::assertEqualsWithDelta(900, $limiter->availableIn('login|1.1.1.1', 3), 2);
        self::assertSame(0, $limiter->availableIn('login|1.1.1.1', 4), 'one more allowed under a looser limit');
        self::assertSame(0, $limiter->availableIn('login|2.2.2.2', 3), 'another address is not held up');

        $limiter->clear('login|1.1.1.1');
        self::assertSame(0, $limiter->availableIn('login|1.1.1.1', 3), 'a sign-in that worked closes the window');
    }

    public function testAnAttemptIsCountedInEveryWindowWhileEachHasRoomAndInNoneOtherwise(): void
    {
        $limiter = new RateLimiter($this->scratchDir() . '/throttle');
        $windows = [['open|7', 2, 3600], ['message|7', 3, 600]];

        self::assertSame([0, 0], [$limiter->attempt($windows), $limiter->attempt($windows)]);
        self::assertEqualsWithDelta(3600, $limiter->attempt($windows), 2, 'the hour is spent: the wait is its');
        self::assertSame(0, $limiter->attempt([['message|7', 3, 600]]), 'nothing was counted for the refused one');
        self::assertEqualsWithDelta(600, $limiter->attempt([['message|7', 3, 600]]), 2);
    }

    public function testAnAttemptGivenBackLeavesItsRoomNeverLessThanNone(): void
    {
        $limiter = new RateLimiter($this->scratchDir() . '/throttle');
        $limiter->release('second_step|7');

        self::assertSame([1, 2], [$limiter->hit('second_step|7', 3600), $limiter->hit('second_step|7', 3600)], 'nothing was there to give back');
        $limiter->release('second_step|7');
        self::assertSame(2, $limiter->hit('second_step|7', 3600), 'one given back');

        $limiter->release('second_step|7');
        $limiter->release('second_step|7');
        $limiter->release('second_step|7');
        self::assertSame(1, $limiter->hit('second_step|7', 3600), 'never below none');
    }

    public function testAClosedWindowStartsTheCountAgain(): void
    {
        $limiter = new RateLimiter($this->scratchDir() . '/throttle');
        $limiter->hit('link|1.1.1.1', 0);

        self::assertSame(0, $limiter->availableIn('link|1.1.1.1', 1), 'a window of no time is closed already');
        self::assertSame(1, $limiter->hit('link|1.1.1.1', 900), 'counted afresh');
    }

    public function testAThrottleThatCannotKeepCountLetsNobodyTry(): void
    {
        // Its folder cannot be made: a file stands where it would be.
        $blocked = $this->scratchDir() . '/not-a-folder';
        file_put_contents($blocked, '');
        $limiter = new RateLimiter($blocked . '/throttle');

        foreach ([static fn() => $limiter->availableIn('login|1.1.1.1', 10), static fn() => $limiter->hit('login|1.1.1.1', 900)] as $attempt) {
            try {
                $attempt();
                self::fail('the throttle let an attempt through without counting it');
            } catch (ThrottleUnavailableException $e) {
                self::assertStringContainsString($blocked, $e->getMessage(), 'the log says which folder to fix');
            }
        }
    }

    public function testAnAttemptWhoseCountCannotBeWrittenIsRefusedNotLetThroughUncounted(): void
    {
        $dir = $this->scratchDir() . '/throttle';
        $limiter = new RateLimiter($dir);
        $limiter->hit('login|1.1.1.1', 900);

        // Something that is not a file stands where the address's count is kept.
        [$count] = glob($dir . '/*.json') ?: [''];
        unlink($count);
        mkdir($count);

        try {
            $limiter->hit('login|1.1.1.1', 900);
            self::fail('an attempt went uncounted');
        } catch (ThrottleUnavailableException $e) {
            self::assertStringContainsString($dir, $e->getMessage());
        }
    }
}
