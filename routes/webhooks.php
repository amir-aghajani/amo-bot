<?php

declare(strict_types=1);

use App\Core\Http\Middleware\InstalledMiddleware;
use App\Core\Http\Urls;
use App\Modules\Scheduling\Controllers\CronController;
use App\Modules\Telegram\Controllers\WebhookController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/*
 * What machines call: Telegram's webhooks (the main bot's and each agent's) and an external pinger's cron trigger — a
 * secret in the address authenticates each, and none of them answers before the shop is installed.
 */
return static function (App $app): void {
    $app->group('', function (RouteCollectorProxy $machines): void {
        $machines->post(Urls::TELEGRAM_WEBHOOK, WebhookController::class);
        $machines->post(Urls::AGENT_WEBHOOK, [WebhookController::class, 'agent']);
        $machines->get(Urls::CRON, CronController::class);
    })->add(InstalledMiddleware::class);
};
