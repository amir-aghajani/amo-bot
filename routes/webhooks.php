<?php

declare(strict_types=1);

use App\Core\Http\Controllers\CronController;
use App\Core\Http\Middleware\InstalledMiddleware;
use App\Core\Http\Urls;
use App\Modules\Payments\Controllers\CallbackController;
use App\Modules\Payments\Controllers\DepositWebhookController;
use App\Modules\Payments\Controllers\GatewayWebhookController;
use App\Modules\Payments\Controllers\RikhtamWebhookController;
use App\Modules\Telegram\Controllers\WebhookController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/*
 * What machines call: Telegram's webhooks (the main bot's and each agent's), an external pinger's cron trigger and the
 * online payment gateways' callbacks — a secret in the address authenticates each —, the gateways' webhooks (a signed one
 * authenticated by its signature, one that only names a payment believed in nothing), the bank SMS a phone app forwards
 * (the shop's key in a header authenticates it) and Rikhtam's word (a Rikhtam key of the shop's in its body); none of
 * them answers before the shop is installed.
 */
return static function (App $app): void {
    $app->group('', function (RouteCollectorProxy $machines): void {
        $machines->post(Urls::TELEGRAM_WEBHOOK, WebhookController::class);
        $machines->post(Urls::AGENT_WEBHOOK, [WebhookController::class, 'agent']);
        $machines->get(Urls::CRON, CronController::class);
        // A gateway posts its word, or sends the customer's browser back with it: either only makes the shop ask the gateway.
        $machines->map(['GET', 'POST'], Urls::PAYMENT_CALLBACK, CallbackController::class);
        // A gateway's webhook for its every payment: a signed word believed when the signature is a method's, or one that
        // only names a payment, which makes the shop ask the gateway about it.
        $machines->post(Urls::PAYMENT_WEBHOOK, GatewayWebhookController::class);
        // A phone app forwards the bank's deposit SMS: believed when it carries the shop's key.
        $machines->post(Urls::DEPOSIT_WEBHOOK, DepositWebhookController::class);
        // Rikhtam tells the shop of its deposits and crypto payments, heard when the body carries one of the shop's Rikhtam
        // keys: a deposit believed, a crypto payment only a reason to ask Rikhtam.
        $machines->post(Urls::RIKHTAM_WEBHOOK, RikhtamWebhookController::class);
    })->add(InstalledMiddleware::class);
};
