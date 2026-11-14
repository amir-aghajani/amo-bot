<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Http\ApiController;
use App\Core\Http\Json;
use App\Core\Http\LongRunning;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Payments\Deposits\DepositSources;
use App\Modules\Payments\Deposits\Services\Deposits;
use App\Modules\Payments\Deposits\Services\DepositSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /webhooks/deposits/{source}/{bot} — a bank SMS a phone app forwards (Deposits\Contracts\DepositSource: ForApp),
 * to the shop `{bot}`, with the shop's key in `X-API-Key` (Deposits\Services\DepositSettings). An app it does not know is
 * a 404; a key that is not the shop's — or a shop that is none — a 401, a shop not listening (its key right) a 403, a body
 * not the app's a 422 — answers the app gives up on, as it should. A body taken is a 200 whatever it came to (approved a
 * payment, or support's to look at), so the app stops sending it; the same SMS sent again is answered by what came of it
 * the first time.
 */
final class DepositWebhookController extends ApiController
{
    public const UNKNOWN = 'این آدرس وب‌هوک واریز در این فروشگاه نیست.';
    public const OFF = 'تایید خودکار واریز در این فروشگاه خاموش است؛ آن را در پنل، صفحه «روش‌های پرداخت»، روشن کنید.';
    public const WRONG_KEY = 'کلید API درست نیست؛ کلید را از پنل، صفحه «روش‌های پرداخت»، دوباره کپی کنید.';

    public function __construct(
        private readonly DepositSources $sources,
        private readonly DepositSettings $settings,
        private readonly Deposits $deposits,
    ) {}

    /** @param array{source: string, bot: string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $source = $this->sources->find($args['source']);
        if ($source === null) {
            return Json::error($response, self::UNKNOWN, 404);
        }
        // A shop that is none answers as a wrong key does: nothing is told before the key is right.
        $bot = CurrentBot::everywhere(static fn(): ?Bot => Bot::query()->find((int) $args['bot']));
        if ($bot === null) {
            return Json::error($response, self::WRONG_KEY, 401);
        }

        return CurrentBot::run($bot, function () use ($request, $response, $source): Response {
            if (!$this->settings->accepts(trim($request->getHeaderLine('X-API-Key')))) {
                return Json::error($response, self::WRONG_KEY, 401);
            }
            if (!$this->settings->enabled()) {
                return Json::error($response, self::OFF, 403);
            }
            $body = json_decode((string) $request->getBody(), true);
            $incoming = $source->read(is_array($body) ? $body : []);

            LongRunning::keepGoing(Deposits::TIME_LIMIT);
            $deposit = $this->deposits->take($source, $incoming);

            return $this->json($response, ['received' => true, 'status' => $deposit->status->value, 'payment_id' => $deposit->payment_id]);
        });
    }
}
