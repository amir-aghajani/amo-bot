<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\DTO\Spun;
use App\Modules\Rewards\DTO\WheelState;
use App\Modules\Rewards\Enums\WheelRefusal;
use App\Modules\Rewards\Exceptions\WheelRefusedException;
use App\Modules\Rewards\Models\WheelPrize;
use App\Modules\Rewards\Models\WheelSpin;
use App\Modules\Rewards\Support\WeightedDraw;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * The lucky wheel («گردونه شانس»). A spin is checked, paid and drawn in one transaction under the customer's row lock, so
 * two taps never spin twice: the cooldown since their last given spin, the slices that can reach them now
 * (Eligibility), the cost from the wallet (an agent's credit does not count). The prize is given after the commit; if
 * that fails, the cost is refunded and the spin keeps why, counting for no cooldown. A buyers-only wheel needs a
 * purchase with a price (Order::paidPurchases()); bot admins spin either way.
 */
final class LuckyWheel
{
    /** The wallet line a prize's Toman is. */
    public const REASON = 'جایزه گردونه شانس';

    public const LINE_COST = 'چرخش گردونه شانس';

    public const LINE_REFUND = 'برگشت هزینه گردونه شانس';

    /** Why a prize was not given when what failed says nothing a customer may read. */
    private const NOT_GIVEN = 'خطایی پیش آمد.';

    /** The `outcome` column's room. */
    private const OUTCOME_MAX = 191;

    public function __construct(
        private readonly WheelSettings $settings,
        private readonly Rewards $rewards,
        private readonly Eligibility $eligibility,
        private readonly WalletService $wallet,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The wheel as the customer finds it: its slices, cost, cooldown and their next spin.
     *
     * @throws WheelRefusedException switched off, or for buyers only and they have bought nothing
     */
    public function state(User $customer): WheelState
    {
        $this->admit($customer);

        return new WheelState($this->prizes(), $this->settings->cost(), $this->settings->cooldownHours(), $this->nextSpinAt($customer));
    }

    /**
     * Spins for the customer: where the wheel landed and what came of it.
     *
     * @param bool $tell Send a plan's service once delivered (the bot)
     * @throws WheelRefusedException nothing spent: switched off, buyers only, too soon, no slice can reach them, a short wallet
     */
    public function spin(User $customer, bool $tell = true): Spun
    {
        $this->admit($customer);
        $prizes = $this->prizes();
        $cost = $this->settings->cost();

        /** @var array{WheelSpin, WheelPrize, Reward} $drawn */
        $drawn = $this->db->transaction(function () use ($customer, $prizes, $cost): array {
            User::query()->whereKey($customer->id)->lockForUpdate()->value('id');
            $next = $this->nextSpinAt($customer);
            if ($next !== null) {
                throw WheelRefusedException::tooSoon($next);
            }

            $rewards = [];
            foreach ($prizes as $index => $prize) {
                $reward = $prize->reward();
                if ($reward !== null) {
                    $rewards[$index] = $reward;
                }
            }
            $givable = $this->eligibility->givable($customer, $rewards);
            if ($givable === []) {
                throw WheelRefusedException::because(WheelRefusal::Empty);
            }

            if ($cost > 0) {
                try {
                    $this->wallet->debit($customer, $cost, self::LINE_COST);
                } catch (InsufficientBalanceException $e) {
                    throw WheelRefusedException::short($e->balance);
                }
            }

            $index = WeightedDraw::draw(array_map(static fn(WheelPrize $slice): int => max(1, $slice->weight), array_intersect_key($prizes, $givable)));
            $prize = $prizes[$index];
            $spin = WheelSpin::query()->create([
                'user_id' => $customer->id,
                'wheel_prize_id' => $prize->id,
                'kind' => $prize->kind,
                'label' => $prize->label,
                'amount' => $prize->amount,
            ]);

            return [$spin, $prize, $givable[$index]];
        });
        [$spin, $prize, $reward] = $drawn;

        $slices = array_map(static fn(WheelPrize $slice): string => $slice->label, $prizes);
        $landed = (int) array_search($prize, $prizes, true);

        try {
            $given = $this->rewards->give($customer, $reward, self::REASON, tell: $tell);
        } catch (\Throwable $e) {
            $why = self::why($e);
            if ($cost > 0) {
                $this->wallet->credit($customer, $cost, self::LINE_REFUND);
            }
            $spin->forceFill(['outcome' => mb_substr($why, 0, self::OUTCOME_MAX)])->save();
            $this->logger->log($e instanceof DomainRuleException || $e instanceof ProviderException ? 'warning' : 'error', 'The lucky wheel could not give user {user} its prize «{prize}» (spin {spin}): {why}', [
                'user' => $customer->id,
                'prize' => $prize->label,
                'spin' => $spin->id,
                'why' => $why,
                'exception' => $e,
            ]);

            return new Spun($spin, $slices, $landed, null, $why, $cost > 0);
        }

        $spin->forceFill(['order_id' => $given->order?->id, 'subscription_id' => $given->subscription?->id])->save();
        $this->logger->info('User {user} spun the lucky wheel: «{prize}» (spin {spin})', ['user' => $customer->id, 'prize' => $prize->label, 'spin' => $spin->id]);

        return new Spun($spin, $slices, $landed, $given);
    }

    /** When the customer may spin next (their last given spin plus the cooldown); null: now. */
    public function nextSpinAt(User $customer): ?Carbon
    {
        $last = WheelSpin::query()->where('user_id', $customer->id)->whereNull('outcome')->latest('created_at')->latest('id')->first();
        $next = $last?->created_at->copy()->addHours($this->settings->cooldownHours());

        return $next !== null && $next->isFuture() ? $next : null;
    }

    /** @throws WheelRefusedException switched off, or for buyers only and the customer — no bot admin — has bought nothing */
    private function admit(User $customer): void
    {
        if (!$this->settings->enabled()) {
            throw WheelRefusedException::because(WheelRefusal::Off);
        }
        if ($this->settings->buyersOnly() && !$customer->isAdmin() && !Order::paidPurchases()->where('user_id', $customer->id)->exists()) {
            throw WheelRefusedException::because(WheelRefusal::BuyersOnly);
        }
    }

    /** @return list<WheelPrize> The enabled slices, in the admin's order. */
    private function prizes(): array
    {
        return array_values(WheelPrize::query()->with('plan')->where('enabled', true)->oldest('sort')->oldest('id')->get()->all());
    }

    /** Why a prize was not given, in words a customer may read (a panel failure only as its summary). */
    private static function why(\Throwable $e): string
    {
        return match (true) {
            $e instanceof ProviderException => ProviderErrorPresenter::summary($e),
            $e instanceof DomainRuleException => $e->getMessage(),
            default => self::NOT_GIVEN,
        };
    }
}
