<?php

declare(strict_types=1);

use App\Core\Http\Controllers\CronController;
use App\Core\Http\Middleware\InstalledMiddleware;
use App\Core\Http\Urls;
use App\Modules\Payments\Controllers\CallbackController;
use App\Modules\Payments\Controllers\GatewayWebhookController;
use App\Modules\Telegram\Controllers\WebhookController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/*
 * What machines call: Telegram's webhooks (the main bot's and each agent's), an external pinger's cron trigger and the
 * online payment gateways' callbacks — a secret in the address authenticates each — and the webhooks a gateway signs
 * (its signature authenticates it); none of them answers before the shop is installed.
 */
return static function (App $app): void {
    $app->group('', function (RouteCollectorProxy $machines): void {
        $machines->post(Urls::TELEGRAM_WEBHOOK, WebhookController::class);
        $machines->post(Urls::AGENT_WEBHOOK, [WebhookController::class, 'agent']);
        $machines->get(Urls::CRON, CronController::class);
        // A gateway posts its word, or sends the customer's browser back with it: either only makes the shop ask the gateway.
        $machines->map(['GET', 'POST'], Urls::PAYMENT_CALLBACK, CallbackController::class);
        // A gateway that answers no question signs its word on a payment: believed when the signature is a method's.
        $machines->post(Urls::PAYMENT_WEBHOOK, GatewayWebhookController::class);
    })->add(InstalledMiddleware::class);
};
