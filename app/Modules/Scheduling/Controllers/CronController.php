<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Core\Config\Repository as Config;
use App\Core\Http\ApiController;
use App\Core\Http\Json;
use App\Core\Http\LongRunning;
use App\Core\Scheduling\Scheduler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /cron/{token} — the scheduler's tick for a host without a shell cron, called every minute by an external pinger
 * (cron-job.org, UptimeRobot, …). Off while CRON_TOKEN is empty; the token in the address is the only key.
 */
final class CronController extends ApiController
{
    /** Seconds a run may take: the scheduler shares out less than this (Scheduler::RUN_SECONDS). */
    private const TIME_LIMIT = 300;

    /** What a call without the right token gets. */
    public const REFUSED = 'این آدرس فعال نیست یا توکن آن درست نیست.';

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Config $config,
    ) {}

    /** @param array{token: string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $expected = (string) $this->config->get('shop.cron_token', '');
        if ($expected === '' || !hash_equals($expected, $args['token'])) {
            return Json::error($response, self::REFUSED, 403);
        }

        // A pinger gives up long before a slow sync is done: the run goes on without it.
        LongRunning::keepGoing(self::TIME_LIMIT);

        return $this->json($response, ['ran' => $this->scheduler->run()]);
    }
}
