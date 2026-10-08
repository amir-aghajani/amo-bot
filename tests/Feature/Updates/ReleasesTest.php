<?php

declare(strict_types=1);

namespace Tests\Feature\Updates;

use App\Core\Scheduling\Scheduler;
use App\Modules\Updates\GitHub;
use App\Modules\Updates\Releases;
use App\Modules\Updates\Tasks\CheckReleasesTask;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;

/**
 * AmoBot's newest release as the shop knows it: read from GitHub's API, kept as the installation's state and read again
 * once a few hours old — GitHub takes 60 calls an hour from an address, a shared host's every site's —, at once on the
 * owner's «بررسی دوباره», and every day by the scheduler, so the dashboard says when one is out however seldom the
 * update screen is opened. GitHub out of reach, or limiting the host, is said; what was read last stands.
 */
final class ReleasesTest extends UpdateTestCase
{
    public function testTheNewestReleaseIsKeptAndReadAgainOnceItIsAFewHoursOld(): void
    {
        $this->gitHub->publish('0.2.0');
        $releases = $this->service(Releases::class);

        $first = $releases->latest()['release'];
        $again = $releases->latest()['release'];
        self::assertSame(['0.2.0', '0.2.0'], [$first?->version, $again?->version]);
        self::assertCount(1, $this->gitHub->calls(), 'kept');
        self::assertSame('application/vnd.github+json', $this->gitHub->request(0)->getHeaderLine('Accept'));

        Carbon::setTestNow(now()->addSeconds(Releases::KEEP_SECONDS + 1));
        $this->gitHub->publish('0.3.0');
        self::assertSame('0.3.0', $releases->latest()['release']?->version, 'a few hours on, read again');
        self::assertCount(2, $this->gitHub->calls());

        self::assertSame('0.3.0', $releases->check()['release']?->version);
        self::assertCount(3, $this->gitHub->calls(), 'the owner\'s «بررسی دوباره» reads it at once');
    }

    public function testGitHubOutOfReachIsNotAskedAgainOnEveryScreen(): void
    {
        $this->gitHub->publish('0.2.0');
        $releases = $this->service(Releases::class);
        $releases->latest();
        Carbon::setTestNow(now()->addSeconds(Releases::KEEP_SECONDS + 1));
        $this->gitHub->down();

        $first = $releases->latest()['release'];
        $again = $releases->latest()['release'];
        self::assertSame(['0.2.0', '0.2.0'], [$first?->version, $again?->version], 'what was read last stands');
        self::assertCount(2, $this->gitHub->calls(), 'the try is kept: the next screen does not wait on GitHub again');
    }

    public function testGitHubLimitingTheHostIsSaidAsSuch(): void
    {
        $this->gitHub->answer(new Response(403, ['X-RateLimit-Remaining' => '0'], '{"message":"API rate limit exceeded"}'));

        $response = $this->postJson('/api/admin/system/update/check');

        self::assertSame([502, GitHub::LIMITED], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    public function testNoReleasePublishedYetIsNoneAndNoFailure(): void
    {
        $update = $this->screen($this->get('/api/admin/system/update'));

        self::assertSame([null, false], [$update['latest'], $update['available']]);
        self::assertNotNull($update['checked_at'], 'GitHub answered: there is none');
    }

    public function testADraftAPreReleaseOrATagThatNamesNoVersionIsNoRelease(): void
    {
        foreach ([['draft' => true], ['prerelease' => true], ['tag_name' => 'nightly']] as $answer) {
            $this->gitHub->publish('0.2.0', [], '', $answer);

            self::assertNull($this->service(Releases::class)->check()['release'], (string) json_encode($answer));
        }
    }

    public function testTheDailyCheckTellsTheDashboardANewVersionIsOut(): void
    {
        $this->gitHub->publish('0.2.0');
        self::assertSame(24 * 60 * 60, $this->service(Scheduler::class)->tasks()[CheckReleasesTask::class]['interval']);

        $this->service(CheckReleasesTask::class)->run();
        $update = $this->screen($this->get('/api/admin/system/update'));

        self::assertSame(['0.2.0', true], [$update['latest']['version'], $update['available']]);
        self::assertCount(1, $this->gitHub->calls(), 'the screen read what the check kept');
    }

    public function testADailyCheckGitHubDoesNotAnswerFailsNothing(): void
    {
        $this->gitHub->down();
        $logs = $this->logs();

        $this->service(CheckReleasesTask::class)->run();

        self::assertTrue($logs->hasWarningThatContains('GitHub is out of reach'));
        self::assertFalse($logs->hasErrorRecords(), 'tomorrow\'s run asks again');
    }
}
