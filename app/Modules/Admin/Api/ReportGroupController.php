<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Telegram\Reports\ReportGroup;
use App\Modules\Telegram\Reports\ReportSender;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The admins' report group («گروه گزارش‌ها» on the bot settings screen): the group connected and its topics, the link
 * that connects one, a check and a test, and disconnecting. Which topics get reports is the bot settings' `reports`
 * group.
 */
final class ReportGroupController extends ApiController
{
    /** Seconds the test may spend sending before the answer; what is left goes with the next round. */
    private const TEST_SECONDS = 5.0;

    public function __construct(
        private readonly ReportGroup $group,
        private readonly ReportSender $sender,
    ) {}

    /** GET /bot/report-group */
    public function show(Request $request, Response $response): Response
    {
        return $this->present($response);
    }

    /** POST /bot/report-group/link — a new connect link (the old one stops working); 422 while the bot's @username is unknown. */
    public function link(Request $request, Response $response): Response
    {
        $this->group->newLink();

        return $this->present($response);
    }

    /** POST /bot/report-group/check — the group, its topics and the bot's rights looked up again; 502 when Telegram cannot be asked. */
    public function check(Request $request, Response $response): Response
    {
        $this->group->check();

        return $this->present($response);
    }

    /** POST /bot/report-group/test — a message in every topic the admin wants, sent right away as far as Telegram allows. */
    public function test(Request $request, Response $response): Response
    {
        $queued = $this->group->test();
        $sent = $this->sender->flush(self::TEST_SECONDS);

        return $this->json($response, ['queued' => $queued, 'sent' => $sent, 'group' => $this->group->present()]);
    }

    /** DELETE /bot/report-group — forget the group (the bot stays in it). */
    public function destroy(Request $request, Response $response): Response
    {
        $this->group->disconnect();

        return $this->present($response);
    }

    private function present(Response $response): Response
    {
        return $this->json($response, ['group' => $this->group->present()]);
    }
}
