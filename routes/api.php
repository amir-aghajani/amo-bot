<?php

declare(strict_types=1);

use App\Core\Http\Middleware\InstalledMiddleware;
use App\Core\Http\Middleware\JsonCsrfMiddleware;
use App\Core\Http\Middleware\SessionMiddleware;
use App\Core\Http\Urls;
use App\Modules\Accounts\Http\CustomerAuthMiddleware;
use App\Modules\Accounts\Http\OptionalCustomerMiddleware;
use App\Modules\Accounts\Http\RecentSignInMiddleware;
use App\Modules\Admin\Api\AccountController;
use App\Modules\Admin\Api\AgencyController;
use App\Modules\Admin\Api\AgencyLevelsController;
use App\Modules\Admin\Api\AgencySettingsController;
use App\Modules\Admin\Api\AgentAuthController;
use App\Modules\Admin\Api\BotChannelsController;
use App\Modules\Admin\Api\BotSettingsController;
use App\Modules\Admin\Api\BotTextsController;
use App\Modules\Admin\Api\BotWebhooksController;
use App\Modules\Admin\Api\BroadcastsController;
use App\Modules\Admin\Api\ChangesController;
use App\Modules\Admin\Api\ClientErrorsController;
use App\Modules\Admin\Api\ConfigSettingsController;
use App\Modules\Admin\Api\CustomEmojisController;
use App\Modules\Admin\Api\CustomerGroupsController;
use App\Modules\Admin\Api\DashboardController;
use App\Modules\Admin\Api\KeyboardsController;
use App\Modules\Admin\Api\MassGrantsController;
use App\Modules\Admin\Api\OrdersController;
use App\Modules\Admin\Api\OwnerAuthController;
use App\Modules\Admin\Api\PaymentMethodsController;
use App\Modules\Admin\Api\PaymentsController;
use App\Modules\Admin\Api\PlanCategoriesController;
use App\Modules\Admin\Api\PlansController;
use App\Modules\Admin\Api\QueuesController;
use App\Modules\Admin\Api\ReferralsController;
use App\Modules\Admin\Api\ReportGroupController;
use App\Modules\Admin\Api\ReviewsController;
use App\Modules\Admin\Api\ServerGrantsController;
use App\Modules\Admin\Api\ServersController;
use App\Modules\Admin\Api\SubscriptionsController;
use App\Modules\Admin\Api\SystemController;
use App\Modules\Admin\Api\TicketsController;
use App\Modules\Admin\Api\UsersController;
use App\Modules\Admin\Api\WebsiteController;
use App\Modules\Api\Controllers\AppController;
use App\Modules\Api\Controllers\HealthController;
use App\Modules\Installer\Controllers\InstallerController;
use App\Modules\Installer\Middleware\InstallKeyMiddleware;
use App\Modules\Store\Api\AuthController as StoreAuthController;
use App\Modules\Store\Api\CaptchaController;
use App\Modules\Store\Api\CatalogController;
use App\Modules\Store\Api\CheckoutController;
use App\Modules\Store\Api\IdentitiesController;
use App\Modules\Store\Api\MeController;
use App\Modules\Store\Api\NotificationsController;
use App\Modules\Store\Api\OrdersController as StoreOrdersController;
use App\Modules\Store\Api\ReferralController;
use App\Modules\Store\Api\ReviewsController as StoreReviewsController;
use App\Modules\Store\Api\StoreController;
use App\Modules\Store\Api\SubscriptionsController as StoreSubscriptionsController;
use App\Modules\Store\Api\TicketsController as StoreTicketsController;
use App\Modules\Store\Api\TwoFactorController;
use App\Modules\Store\Api\WalletController;
use App\Modules\Store\Enums\StaffGrant;
use App\Modules\Store\Http\JsonBodiesMiddleware;
use App\Modules\Store\Http\StaffMiddleware;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Services\Storefront;
use App\Modules\Updates\Controllers\UpdateController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/*
 * Everything the app answers over HTTP is JSON. Two panels, each with its own API and session: /api/admin/* is the
 * owner's (the `panel.admin` middleware: the login config.php keeps, each request in the shop it names — its tab's),
 * /api/agent/* an agent's (`panel.agent`: signed in with the main bot's one-time link, in their bot's shop). The shop's
 * own screens are registered once under both: its daily work ($operations) and its configuration ($configuration); the
 * owner's sections that are the shop's as a whole run across every bot's services (`shop.everywhere`) or in the main
 * bot's shop (`shop.main`). /api/app tells a panel the shop's name and whether it is installed; /api/install/* is the
 * web installer, there only until it is (`installer.open`). A panel's API waits for the installation, then asks for the
 * CSRF header on a change, then opens the session — in that order.
 * /api/store/v1/{store}/* is the shops' websites' API (the Store API): no panel, no session — a store key and, for a
 * customer's own, their bearer token; its /admin/* is the shop's daily work again ($operations, never its
 * configuration), for the shop's admins signed in on it (StaffMiddleware).
 */
return static function (App $app): void {
    $app->get('/health', HealthController::class);
    $app->get('/api/app', AppController::class);

    $app->group('/api/install', function (RouteCollectorProxy $install): void {
        $install->get('', [InstallerController::class, 'status']);
        $install->post('/database', [InstallerController::class, 'database']);
        $install->post('/tables', [InstallerController::class, 'tables']);
        $install->post('/admin', [InstallerController::class, 'admin']);
        $install->post('/site', [InstallerController::class, 'site']);
        $install->post('/finish', [InstallerController::class, 'finish']);
    })->add(InstallKeyMiddleware::class)->add(JsonCsrfMiddleware::class)->add('installer.open');

    // The shop's daily work, the same in both panels and on the shop's website for its admins (StaffMiddleware): each
    // request is worked in the shop its principal is in. What the shop's owner must let its admins do on the website
    // names its grant (StaffGrant::ARGUMENT) — the panels' principals hold every one —, and what delivers a service by an
    // admin's word alone asks them a recent sign-in there (StaffMiddleware::RECENT_SIGN_IN), as a granted one does.
    $operations = static function (RouteCollectorProxy $shop): void {
        $shop->get('/changes', ChangesController::class);
        $shop->get('/queues', QueuesController::class);
        $shop->get('/dashboard', DashboardController::class);

        $shop->get('/broadcasts', [BroadcastsController::class, 'index']);
        $shop->post('/broadcasts/{id:[0-9]+}/pause', [BroadcastsController::class, 'pause']);
        $shop->post('/broadcasts/{id:[0-9]+}/resume', [BroadcastsController::class, 'resume']);
        $shop->post('/broadcasts/{id:[0-9]+}/cancel', [BroadcastsController::class, 'cancel']);
        $shop->post('/broadcasts/{id:[0-9]+}/unpin', [BroadcastsController::class, 'unpin']);

        $shop->get('/plans', [PlansController::class, 'index']);
        $shop->get('/plans/options', [PlansController::class, 'options']);
        $shop->get('/plans/categories', [PlanCategoriesController::class, 'index']);
        $shop->post('/plans/categories', [PlanCategoriesController::class, 'store'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->post('/plans/categories/reorder', [PlanCategoriesController::class, 'reorder'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->put('/plans/categories/{id:[0-9]+}', [PlanCategoriesController::class, 'update'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->patch('/plans/categories/{id:[0-9]+}', [PlanCategoriesController::class, 'patch'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->delete('/plans/categories/{id:[0-9]+}', [PlanCategoriesController::class, 'destroy'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->post('/plans', [PlansController::class, 'store'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->post('/plans/reorder', [PlansController::class, 'reorder'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->put('/plans/{id:[0-9]+}', [PlansController::class, 'update'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->patch('/plans/{id:[0-9]+}', [PlansController::class, 'patch'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->post('/plans/{id:[0-9]+}/duplicate', [PlansController::class, 'duplicate'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);
        $shop->delete('/plans/{id:[0-9]+}', [PlansController::class, 'destroy'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Catalog->value);

        $shop->get('/users', [UsersController::class, 'index']);
        $shop->get('/users/{id:[0-9]+}', [UsersController::class, 'show']);
        $shop->patch('/users/{id:[0-9]+}', [UsersController::class, 'patch']);
        $shop->get('/users/{id:[0-9]+}/wallet', [UsersController::class, 'wallet']);
        $shop->post('/users/{id:[0-9]+}/wallet', [UsersController::class, 'adjustWallet'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Wallet->value);
        $shop->put('/users/{id:[0-9]+}/groups', [UsersController::class, 'groups']);
        $shop->post('/users/{id:[0-9]+}/two-factor/disable', [UsersController::class, 'disableTwoFactor'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::AccountSecurity->value);
        $shop->post('/users/{id:[0-9]+}/sessions/end', [UsersController::class, 'endSessions'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::AccountSecurity->value);

        $shop->get('/customer-groups', [CustomerGroupsController::class, 'index']);
        $shop->post('/customer-groups', [CustomerGroupsController::class, 'store']);
        $shop->post('/customer-groups/reorder', [CustomerGroupsController::class, 'reorder']);
        $shop->put('/customer-groups/{id:[0-9]+}', [CustomerGroupsController::class, 'update']);
        $shop->delete('/customer-groups/{id:[0-9]+}', [CustomerGroupsController::class, 'destroy']);

        $shop->get('/orders', [OrdersController::class, 'index']);
        $shop->get('/orders/{id:[0-9]+}', [OrdersController::class, 'show']);
        $shop->post('/orders/{id:[0-9]+}/retry', [OrdersController::class, 'retry']);
        $shop->post('/orders/{id:[0-9]+}/cancel', [OrdersController::class, 'cancel']);

        $shop->get('/payments', [PaymentsController::class, 'index']);
        $shop->get('/payments/{id:[0-9]+}', [PaymentsController::class, 'show']);
        $shop->get('/payments/{id:[0-9]+}/receipt', [PaymentsController::class, 'receipt']);
        $shop->post('/payments/{id:[0-9]+}/approve', [PaymentsController::class, 'approve'])->setArgument(StaffMiddleware::RECENT_SIGN_IN, 'true');
        $shop->post('/payments/{id:[0-9]+}/reject', [PaymentsController::class, 'reject']);
        $shop->post('/payments/{id:[0-9]+}/cancel', [PaymentsController::class, 'cancel']);
        $shop->post('/payments/{id:[0-9]+}/remind', [PaymentsController::class, 'remind']);
        $shop->post('/payments/{id:[0-9]+}/retry', [PaymentsController::class, 'retry']);
        $shop->post('/payments/{id:[0-9]+}/refund', [PaymentsController::class, 'refund'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Refunds->value);

        $shop->get('/tickets', [TicketsController::class, 'index']);
        $shop->get('/tickets/{id:[0-9]+}', [TicketsController::class, 'show']);
        $shop->post('/tickets/{id:[0-9]+}/messages', [TicketsController::class, 'answer'])->setArgument(JsonBodiesMiddleware::UPLOAD, 'picture');
        $shop->get('/tickets/{id:[0-9]+}/messages/{message:[0-9]+}/attachment', [TicketsController::class, 'attachment']);
        $shop->post('/tickets/{id:[0-9]+}/close', [TicketsController::class, 'close']);
        $shop->post('/tickets/{id:[0-9]+}/reopen', [TicketsController::class, 'reopen']);

        $shop->get('/reviews', [ReviewsController::class, 'index']);
        $shop->post('/reviews/{id:[0-9]+}/approve', [ReviewsController::class, 'approve']);
        $shop->post('/reviews/{id:[0-9]+}/reject', [ReviewsController::class, 'reject']);
        $shop->delete('/reviews/{id:[0-9]+}', [ReviewsController::class, 'destroy']);

        $shop->get('/subscriptions', [SubscriptionsController::class, 'index']);
        $shop->get('/subscriptions/{id:[0-9]+}', [SubscriptionsController::class, 'show']);
        $shop->post('/subscriptions/{id:[0-9]+}/sync', [SubscriptionsController::class, 'sync']);
        $shop->post('/subscriptions/{id:[0-9]+}/extend', [SubscriptionsController::class, 'extend'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Extend->value);
        $shop->post('/subscriptions/{id:[0-9]+}/disable', [SubscriptionsController::class, 'disable']);
        $shop->post('/subscriptions/{id:[0-9]+}/enable', [SubscriptionsController::class, 'enable']);
        $shop->post('/subscriptions/{id:[0-9]+}/move', [SubscriptionsController::class, 'move']);
        $shop->post('/subscriptions/{id:[0-9]+}/delete', [SubscriptionsController::class, 'delete'])->setArgument(StaffGrant::ARGUMENT, StaffGrant::Delete->value);

        $shop->get('/referrals', [ReferralsController::class, 'summary']);
        $shop->get('/referrals/referrers', [ReferralsController::class, 'referrers']);
        $shop->get('/referrals/invitees', [ReferralsController::class, 'invitees']);
        $shop->get('/referrals/commissions', [ReferralsController::class, 'commissions']);
    };

    // The shop's configuration — and the panel's own reports of its failures —, in both panels and nowhere else: how
    // customers pay, how the bot speaks and looks, its report group, its website, who its admins are.
    $configuration = static function (RouteCollectorProxy $shop): void {
        $shop->post('/client-errors', ClientErrorsController::class);

        $shop->put('/users/{id:[0-9]+}/role', [UsersController::class, 'role']);

        $shop->get('/payment-methods', [PaymentMethodsController::class, 'index']);
        $shop->get('/payment-methods/drivers', [PaymentMethodsController::class, 'drivers']);
        $shop->post('/payment-methods', [PaymentMethodsController::class, 'store']);
        $shop->post('/payment-methods/reorder', [PaymentMethodsController::class, 'reorder']);
        $shop->put('/payment-methods/{id:[0-9]+}', [PaymentMethodsController::class, 'update']);
        $shop->patch('/payment-methods/{id:[0-9]+}', [PaymentMethodsController::class, 'patch']);
        $shop->delete('/payment-methods/{id:[0-9]+}', [PaymentMethodsController::class, 'destroy']);

        $shop->get('/bot/settings', [BotSettingsController::class, 'index']);
        $shop->put('/bot/settings/{group:[a-z_]+}', [BotSettingsController::class, 'update']);
        $shop->get('/bot/qr-background', [BotSettingsController::class, 'background']);
        $shop->post('/bot/qr-background', [BotSettingsController::class, 'uploadBackground']);
        $shop->delete('/bot/qr-background', [BotSettingsController::class, 'resetBackground']);
        $shop->get('/bot/channels', [BotChannelsController::class, 'index']);
        $shop->post('/bot/channels', [BotChannelsController::class, 'store']);
        $shop->post('/bot/channels/reorder', [BotChannelsController::class, 'reorder']);
        $shop->post('/bot/channels/{id:[0-9]+}/check', [BotChannelsController::class, 'check']);
        $shop->delete('/bot/channels/{id:[0-9]+}', [BotChannelsController::class, 'destroy']);
        $shop->get('/bot/report-group', [ReportGroupController::class, 'show']);
        $shop->post('/bot/report-group/link', [ReportGroupController::class, 'link']);
        $shop->post('/bot/report-group/check', [ReportGroupController::class, 'check']);
        $shop->post('/bot/report-group/test', [ReportGroupController::class, 'test']);
        $shop->delete('/bot/report-group', [ReportGroupController::class, 'destroy']);

        $shop->get('/keyboards', [KeyboardsController::class, 'index']);
        $shop->put('/keyboards/{name:[a-z_]+}', [KeyboardsController::class, 'update']);
        $shop->post('/keyboards/{name:[a-z_]+}/reset', [KeyboardsController::class, 'reset']);

        $shop->get('/bot/custom-emojis', [CustomEmojisController::class, 'index']);
        $shop->get('/bot/custom-emojis/{id:[0-9]+}/image', [CustomEmojisController::class, 'image']);
        $shop->get('/bot/custom-emojis/{id:[0-9]+}/animation', [CustomEmojisController::class, 'animation']);
        $shop->delete('/bot/custom-emojis/{id:[0-9]+}', [CustomEmojisController::class, 'destroy']);

        $shop->get('/bot/texts', [BotTextsController::class, 'index']);
        $shop->put('/bot/texts/{key:[a-z_]+}', [BotTextsController::class, 'update']);
        $shop->post('/bot/texts/{key:[a-z_]+}/reset', [BotTextsController::class, 'reset']);

        $shop->get('/website', [WebsiteController::class, 'show']);
        $shop->patch('/website', [WebsiteController::class, 'update']);
        $shop->post('/website/key', [WebsiteController::class, 'rotateKey']);
    };

    // The owner's panel: signed in with the login config.php keeps, each request in the shop it names — or, that login
    // lost, with a key off the host's files that sets it again.
    $app->group('/api/admin', function (RouteCollectorProxy $api) use ($operations, $configuration): void {
        $api->post('/auth/login', [OwnerAuthController::class, 'login']);
        $api->post('/auth/recovery/key', [OwnerAuthController::class, 'recoveryKey']);
        $api->post('/auth/recovery', [OwnerAuthController::class, 'recover']);

        $api->group('', function (RouteCollectorProxy $panel) use ($operations, $configuration): void {
            $panel->get('/auth/me', [OwnerAuthController::class, 'me']);
            $panel->post('/auth/logout', [OwnerAuthController::class, 'logout']);
            $panel->put('/auth/credentials', [OwnerAuthController::class, 'credentials']);
            $panel->get('/shops', [OwnerAuthController::class, 'shops']);
            $operations($panel);
            $configuration($panel);
            // The installation's own state, beside the dashboard of whichever shop is open — an agent is not told it —, how
            // every bot gets its updates: on webhooks, or polling (each bot switched in its own shop), and AmoBot's own
            // update to a newer release, a step a request.
            $panel->get('/system', SystemController::class);
            $panel->post('/system/webhook', [BotWebhooksController::class, 'enable']);
            $panel->delete('/system/webhook', [BotWebhooksController::class, 'disable']);
            $panel->get('/system/update', [UpdateController::class, 'show']);
            $panel->post('/system/update/check', [UpdateController::class, 'check']);
            $panel->post('/system/update/start', [UpdateController::class, 'start']);
            $panel->post('/system/update/step', [UpdateController::class, 'step']);
            $panel->post('/system/update/cancel', [UpdateController::class, 'cancel']);
            $panel->post('/system/update/rollback', [UpdateController::class, 'rollback']);

            // The sections that are the shop's as a whole: its servers, their grants and the mass gifts, across every
            // bot's services…
            $panel->group('', function (RouteCollectorProxy $owner): void {
                $owner->get('/servers/drivers', [ServersController::class, 'drivers']);
                $owner->get('/servers', [ServersController::class, 'index']);
                $owner->post('/servers', [ServersController::class, 'store']);
                $owner->post('/servers/test', [ServersController::class, 'test']);
                $owner->get('/servers/{id:[0-9]+}', [ServersController::class, 'show']);
                $owner->put('/servers/{id:[0-9]+}', [ServersController::class, 'update']);
                $owner->delete('/servers/{id:[0-9]+}', [ServersController::class, 'destroy']);
                $owner->post('/servers/{id:[0-9]+}/test', [ServersController::class, 'check']);
                $owner->post('/servers/{id:[0-9]+}/inbounds/sync', [ServersController::class, 'syncInbounds']);
                $owner->patch('/servers/{id:[0-9]+}/inbounds/{inbound:[0-9]+}', [ServersController::class, 'updateInbound']);
                $owner->get('/servers/{id:[0-9]+}/grants', [ServerGrantsController::class, 'index']);
                $owner->post('/servers/{id:[0-9]+}/grants', [ServerGrantsController::class, 'store']);
                $owner->post('/servers/{id:[0-9]+}/grants/{grant:[0-9]+}/run', [ServerGrantsController::class, 'run']);
                $owner->post('/servers/{id:[0-9]+}/grants/{grant:[0-9]+}/cancel', [ServerGrantsController::class, 'cancel']);

                $owner->get('/mass-grants', [MassGrantsController::class, 'index']);
                $owner->post('/mass-grants', [MassGrantsController::class, 'store']);
                $owner->post('/mass-grants/{id:[0-9]+}/run', [MassGrantsController::class, 'run']);
                $owner->post('/mass-grants/{id:[0-9]+}/cancel', [MassGrantsController::class, 'cancel']);
            })->add('shop.everywhere');

            // …and the agency and the panel's settings, in the main bot's shop (the agents are its customers).
            $panel->group('', function (RouteCollectorProxy $owner): void {
                $owner->get('/agency', [AgencyController::class, 'summary']);
                $owner->get('/agency/settings', [AgencySettingsController::class, 'show']);
                $owner->put('/agency/settings', [AgencySettingsController::class, 'update']);
                $owner->get('/agency/requests', [AgencyController::class, 'requests']);
                $owner->post('/agency/requests/{id:[0-9]+}/approve', [AgencyController::class, 'approve']);
                $owner->post('/agency/requests/{id:[0-9]+}/reject', [AgencyController::class, 'reject']);
                $owner->get('/agency/agents', [AgencyController::class, 'agents']);
                $owner->put('/agency/agents/{id:[0-9]+}', [AgencyController::class, 'update']);
                $owner->post('/agency/agents/{id:[0-9]+}/revoke', [AgencyController::class, 'revoke']);
                $owner->get('/agency/agents/{id:[0-9]+}/traffic', [AgencyController::class, 'traffic']);
                $owner->post('/agency/agents/{id:[0-9]+}/traffic', [AgencyController::class, 'adjustTraffic']);
                $owner->get('/agency/levels', [AgencyLevelsController::class, 'index']);
                $owner->post('/agency/levels', [AgencyLevelsController::class, 'store']);
                $owner->post('/agency/levels/reorder', [AgencyLevelsController::class, 'reorder']);
                $owner->put('/agency/levels/{id:[0-9]+}', [AgencyLevelsController::class, 'update']);
                $owner->delete('/agency/levels/{id:[0-9]+}', [AgencyLevelsController::class, 'destroy']);

                $owner->get('/settings/config', [ConfigSettingsController::class, 'index']);
                $owner->get('/settings/config/cron-url', [ConfigSettingsController::class, 'cronUrl']);
                $owner->post('/settings/config/telegram/test', [ConfigSettingsController::class, 'testTelegram']);
                $owner->post('/settings/config/database/test', [ConfigSettingsController::class, 'testDatabase']);
                $owner->post('/settings/config/mail/test', [ConfigSettingsController::class, 'testMail']);
                $owner->put('/settings/config/{group:app|database|telegram|mail|advanced}', [ConfigSettingsController::class, 'update']);
            })->add('shop.main');
        })->add('panel.admin');
    })->add(SessionMiddleware::class)->add(JsonCsrfMiddleware::class)->add(InstalledMiddleware::class);

    // An agent's panel: signed in with the one-time link their account in the main bot gives them, in their bot's shop.
    $app->group('/api/agent', function (RouteCollectorProxy $api) use ($operations, $configuration): void {
        $api->post('/auth/link', [AgentAuthController::class, 'link']);

        $api->group('', function (RouteCollectorProxy $panel) use ($operations, $configuration): void {
            $panel->get('/auth/me', [AgentAuthController::class, 'me']);
            $panel->post('/auth/logout', [AgentAuthController::class, 'logout']);
            $panel->post('/auth/sessions/end', [AgentAuthController::class, 'endSessions']);
            $panel->get('/account', [AccountController::class, 'show']);
            $panel->get('/account/traffic', [AccountController::class, 'traffic']);
            $operations($panel);
            $configuration($panel);
        })->add('panel.agent');
    })->add(SessionMiddleware::class)->add(JsonCsrfMiddleware::class)->add(InstalledMiddleware::class);

    // The shop's website (the Store API, docs/Store-API.md): the store key in its address names the shop — its website
    // switched on — whose shop each request is worked in. A customer signs in with a bearer token of their own: no
    // session cookie, so no CSRF header; a page on the website's origins may read the answers (CORS, HttpKernel), and a
    // body is JSON — a form only where a picture is uploaded (JsonBodiesMiddleware::UPLOAD) —, so another site's page
    // sends none without the preflight the website's origins alone pass. What the shop sells and the reviews it shows
    // are anyone's to read, and a review anyone's to write — its writer's own when their bearer token comes with it; a
    // customer's services, orders, wallet, referrals, notices and support tickets are theirs alone, and so is their
    // checkout — every request that orders carries its Idempotency-Key.
    $app->group(Urls::STORE, function (RouteCollectorProxy $store) use ($operations): void {
        $store->get('', [StoreController::class, 'show']);
        $store->get('/plans', [CatalogController::class, 'plans']);
        $store->get('/plans/{id:[0-9]+}', [CatalogController::class, 'plan']);
        $store->get('/status', [CatalogController::class, 'status']);
        $store->get('/reviews', [StoreReviewsController::class, 'index']);
        $store->post('/reviews', [StoreReviewsController::class, 'store'])->add(OptionalCustomerMiddleware::class);
        $store->post('/auth/nonce', [StoreAuthController::class, 'nonce']);
        $store->post('/auth/telegram/authorize', [StoreAuthController::class, 'authorize']);
        $store->post('/auth/telegram', [StoreAuthController::class, 'telegram']);
        $store->post('/auth/google', [StoreAuthController::class, 'google']);
        $store->post('/auth/register', [StoreAuthController::class, 'register']);
        $store->post('/auth/register/verify', [StoreAuthController::class, 'verify']);
        $store->post('/auth/login', [StoreAuthController::class, 'login']);
        $store->post('/auth/login/2fa', [StoreAuthController::class, 'twoFactor']);
        $store->post('/auth/password/forgot', [StoreAuthController::class, 'forgot']);
        $store->post('/auth/password/reset', [StoreAuthController::class, 'reset']);
        // The website's captcha for forms of its own: its widget's challenge, and a token its backend asks the shop to judge.
        $store->get(Storefront::CHALLENGE, [CaptchaController::class, 'challenge']);
        $store->post('/captcha/verify', [CaptchaController::class, 'verify']);

        $store->group('', function (RouteCollectorProxy $customer): void {
            $customer->post('/auth/logout', [MeController::class, 'logout']);
            $customer->get('/me', [MeController::class, 'show']);
            $customer->patch('/me', [MeController::class, 'update']);
            $customer->post('/me/reauthenticate', [MeController::class, 'reauthenticate']);
            $customer->post('/me/telegram/authorize', [IdentitiesController::class, 'authorize']);
            $customer->get('/me/sessions', [MeController::class, 'sessions']);
            $customer->delete('/me/sessions/{id:[0-9]+}', [MeController::class, 'endSession']);

            // What changes how the account is signed in to asks a recent sign-in of the session — its own, or a way in
            // proven again (POST /me/reauthenticate): a bearer token alone takes no account over.
            $customer->group('', function (RouteCollectorProxy $credentials): void {
                $credentials->put('/me/password', [MeController::class, 'password']);
                $credentials->post('/me/2fa/setup', [TwoFactorController::class, 'setup']);
                $credentials->post('/me/2fa/enable', [TwoFactorController::class, 'enable']);
                $credentials->post('/me/2fa/disable', [TwoFactorController::class, 'disable']);
                $credentials->post('/me/identities/telegram', [IdentitiesController::class, 'telegram']);
                $credentials->post('/me/identities/google', [IdentitiesController::class, 'google']);
                $credentials->post('/me/identities/email', [IdentitiesController::class, 'email']);
                $credentials->post('/me/identities/email/verify', [IdentitiesController::class, 'verifyEmail']);
                $credentials->delete('/me/identities/{kind:telegram|google|email}', [IdentitiesController::class, 'remove']);
                $credentials->post('/me/merge', [IdentitiesController::class, 'merge']);
            })->add(RecentSignInMiddleware::class);

            $customer->get('/subscriptions', [StoreSubscriptionsController::class, 'index']);
            $customer->get('/subscriptions/{id:[0-9]+}', [StoreSubscriptionsController::class, 'show']);
            $customer->patch('/subscriptions/{id:[0-9]+}', [StoreSubscriptionsController::class, 'update']);
            $customer->post('/subscriptions/{id:[0-9]+}/refresh', [StoreSubscriptionsController::class, 'refresh']);
            $customer->post('/subscriptions/{id:[0-9]+}/rotate-link', [StoreSubscriptionsController::class, 'rotateLink']);
            $customer->get('/subscriptions/{id:[0-9]+}/renewal', [CheckoutController::class, 'renewal']);
            $customer->post('/subscriptions/{id:[0-9]+}/renewal', [CheckoutController::class, 'renew']);
            $customer->get('/payment-methods', [CheckoutController::class, 'methods']);
            $customer->get('/orders', [StoreOrdersController::class, 'index']);
            $customer->post('/orders', [CheckoutController::class, 'purchase']);
            $customer->get('/orders/{id:[0-9]+}', [StoreOrdersController::class, 'show']);
            $customer->post('/payments/{id:[0-9]+}/receipt', [CheckoutController::class, 'receipt'])->setArgument(JsonBodiesMiddleware::UPLOAD, 'picture');
            $customer->get('/wallet', [WalletController::class, 'show']);
            $customer->post('/wallet/top-up', [CheckoutController::class, 'topUp']);
            $customer->get('/wallet/transactions', [WalletController::class, 'transactions']);
            $customer->get('/referral', [ReferralController::class, 'show']);
            $customer->get('/notifications', [NotificationsController::class, 'index']);
            $customer->post('/notifications/read', [NotificationsController::class, 'read']);
            $customer->get('/tickets', [StoreTicketsController::class, 'index']);
            $customer->post('/tickets', [StoreTicketsController::class, 'open'])->setArgument(JsonBodiesMiddleware::UPLOAD, 'picture');
            $customer->get('/tickets/{id:[0-9]+}', [StoreTicketsController::class, 'show']);
            $customer->post('/tickets/{id:[0-9]+}/read', [StoreTicketsController::class, 'read']);
            $customer->post('/tickets/{id:[0-9]+}/messages', [StoreTicketsController::class, 'write'])->setArgument(JsonBodiesMiddleware::UPLOAD, 'picture');
            $customer->get('/tickets/{id:[0-9]+}/messages/{message:[0-9]+}/attachment', [StoreTicketsController::class, 'attachment']);
            $customer->post('/tickets/{id:[0-9]+}/close', [StoreTicketsController::class, 'close']);
            $customer->post('/tickets/{id:[0-9]+}/rating', [StoreTicketsController::class, 'rate']);
        })->add(CustomerAuthMiddleware::class);

        // The shop's daily work again, for its admins — its customers whose role is admin — while the website lets them
        // in, each operation the panels' own: never the shop's configuration.
        $store->group('/admin', function (RouteCollectorProxy $staff) use ($operations): void {
            $operations($staff);
        })->add(StaffMiddleware::class)->add(CustomerAuthMiddleware::class);
    })->add(JsonBodiesMiddleware::class)->add(StoreMiddleware::class)->add(InstalledMiddleware::class);
};
