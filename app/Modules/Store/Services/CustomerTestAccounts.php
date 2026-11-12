<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Providers\Exceptions\PanelFailedException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Store\Exceptions\ProgramOffException;
use App\Modules\Subscriptions\Enums\TestRefusal;
use App\Modules\Subscriptions\Exceptions\TestAccountRefusedException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\TestAccounts;
use App\Modules\Subscriptions\Services\TestAccountSettings;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Persian;
use App\Support\Traffic;
use Psr\Log\LoggerInterface;

/**
 * The free test accounts («اکانت تست») on the website and in the mini app, as the bot's «🧪 اکانت تست» gives them
 * (Subscriptions\Services\TestAccounts): the servers giving one now with their terms, or why the customer can take none;
 * and one taken. The quota is the bot's, counted together, and so is what the bot asks first (CustomerGates: a Telegram
 * account, their number, the shop's channels). The screen shows the test it made, so nothing is sent in
 * Telegram; a panel that fails is logged and said in a word, nothing of the quota spent.
 */
final class CustomerTestAccounts
{
    /** A test the panel did not make, followed by what happened in a word. */
    public const NOT_MADE = 'اکانت تست ساخته نشد: ';

    /** A server the request names that gives no test now. */
    public const SERVER_GIVES_NONE = 'این سرور الان اکانت تست نمی‌دهد؛ صفحه را دوباره باز کنید.';

    /** A server whose panel cannot take a test now (no subscription link to give, no name left for it). */
    public const SERVER_FAILED = 'ساخت اکانت تست روی این سرور الان ممکن نشد؛ کمی بعد دوباره امتحان کنید.';

    public function __construct(
        private readonly TestAccounts $tests,
        private readonly TestAccountSettings $settings,
        private readonly CustomerSubscriptions $subscriptions,
        private readonly CustomerGates $gates,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The servers giving the customer a test now, in the shop's order, each with its terms — or none, and why (the quota's
     * words).
     *
     * @return array{offers: list<array{server: array{id: int, name: string}, traffic_mb: int, duration_hours: int, on_hold: bool}>, refusal: string|null}
     * @throws ProgramOffException 404 while the shop gives none
     */
    public function offers(User $customer): array
    {
        $this->open();
        $held = $this->gates->refusal($customer);
        if ($held !== null) {
            return ['offers' => [], 'refusal' => $held];
        }
        try {
            $choices = $this->tests->offer($customer);
        } catch (TestAccountRefusedException $e) {
            return ['offers' => [], 'refusal' => self::words($e)];
        }

        return ['offers' => array_map(static fn(array $choice): array => [
            'server' => ['id' => $choice['server']->id, 'name' => $choice['server']->name],
            'traffic_mb' => intdiv($choice['terms']->bytes, Traffic::MEGABYTE),
            'duration_hours' => $choice['terms']->hours,
            'on_hold' => $choice['terms']->onHold,
        ], $choices), 'refusal' => null];
    }

    /**
     * Take a test on `server_id` (TestAccounts::issue()): the service made, read as CustomerSubscriptions::find() reads it.
     *
     * @param array<string, mixed> $input {server_id}
     * @throws ProgramOffException 404 while the shop gives none
     * @throws ValidationException 422 on `server_id`: a server giving none now; on `status`: the bot's gates or the quota
     *                             refuse
     * @throws PanelFailedException 502
     */
    public function take(User $customer, array $input): Subscription
    {
        $this->open();
        $held = $this->gates->refusal($customer);
        if ($held !== null) {
            throw ValidationException::on('status', $held);
        }
        $serverId = Input::integerOf($input['server_id'] ?? null) ?? 0;
        try {
            $test = $this->tests->issue($customer, $serverId);
        } catch (TestAccountRefusedException $e) {
            throw $e->reason === TestRefusal::NoServer
                ? ValidationException::on('server_id', self::SERVER_GIVES_NONE)
                : ValidationException::on('status', self::words($e));
        } catch (ProviderException $e) {
            $this->logger->warning('Could not make a test account for user {user} on server {server} for its website: {message}', ['user' => $customer->id, 'server' => $serverId, 'message' => $e->getMessage()]);

            throw new PanelFailedException(self::NOT_MADE . ProviderErrorPresenter::summary($e), $e);
        } catch (NoServerAvailableException $e) {
            $this->logger->warning('Could not make a test account for user {user} on server {server} for its website: {message}', ['user' => $customer->id, 'server' => $serverId, 'message' => $e->getMessage()]);

            throw ValidationException::on('server_id', self::SERVER_FAILED);
        }

        return $this->subscriptions->find($customer, $test->id);
    }

    /** @throws ProgramOffException while the shop gives no tests */
    private function open(): void
    {
        if (!$this->settings->enabled()) {
            throw ProgramOffException::testAccounts();
        }
    }

    /** Why the customer can take no test now — a quota with a window says from when they can. */
    private static function words(TestAccountRefusedException $e): string
    {
        return $e->reason === TestRefusal::Used && $e->nextAt !== null
            ? 'سهمیه اکانت تست شما فعلا تمام شده است؛ اکانت تست بعدی از ' . Persian::date($e->nextAt, withTime: true) . ' ممکن می‌شود.'
            : $e->getMessage();
    }
}
