<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Settings\Services\ConfigSettings;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The panel's own configuration (config.php), the owner's settings screen: its groups, the checks the screen offers
 * before a save, and the cron trigger's address in full for the screen's copy action.
 */
final class ConfigSettingsController extends ApiController
{
    public function __construct(private readonly ConfigSettings $settings) {}

    /** GET /settings/config */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->settings->present());
    }

    /**
     * PUT /settings/config/{group} — app, database, telegram, mail or advanced, as the screen shows it; the main bot's,
     * moving the Bot API's address, with the owner's current password
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, $this->settings->update($args['group'], $this->input($request), $request));
    }

    /** POST /settings/config/telegram/test — {token?}: whose it is (the kept one when none is typed) */
    public function testTelegram(Request $request, Response $response): Response
    {
        return $this->json($response, ['bot' => $this->settings->testTelegram(Input::text($this->input($request), 'token'))]);
    }

    /** POST /settings/config/database/test — {driver?, …its fields}: a secret left blank is the stored one */
    public function testDatabase(Request $request, Response $response): Response
    {
        return $this->json($response, ['database' => $this->settings->testDatabase($this->input($request))->toArray()]);
    }

    /** POST /settings/config/mail/test — {to}: a short email by the mail settings as saved — or why none goes */
    public function testMail(Request $request, Response $response): Response
    {
        $this->settings->testMail(Input::text($this->input($request), 'to'));

        return $this->json($response, ['sent' => true]);
    }

    /** GET /settings/config/cron-url — the trigger's whole address (the screen shows it masked) */
    public function cronUrl(Request $request, Response $response): Response
    {
        return $this->json($response, ['url' => $this->settings->cronUrl()]);
    }
}
