<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Database\DateRange;
use App\Core\Database\Ledger;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Core\Security\ReadableCode;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Rewards\DTO\Given;
use App\Modules\Rewards\DTO\Redeemed;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\GiftCodeKind;
use App\Modules\Rewards\Enums\GiftCodeRefusal;
use App\Modules\Rewards\Exceptions\GiftCodeRefusedException;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Gift codes («کد هدیه»): made in batches on the panels and redeemed once by a customer in the bot or on the website.
 * Every try is counted against the customer (and the website's address network) before it is judged and given back when
 * the code exists, so only wrong codes spend the budget and codes cannot be guessed. A code is taken by one conditional
 * update, so two customers never both get it; Toman is credited in the same transaction, while a plan's service is given
 * after it — and a plan no server can deliver gives the code back.
 */
final class GiftCodes
{
    /** The most codes one batch makes. */
    public const COUNT_MAX = 500;

    public const BATCH_MAX = 64;

    /** A prefix of the admin's own before the random part («NOWRUZ»): Latin letters and digits. */
    public const PREFIX_MAX = 12;

    /** The random part: ReadableCode's 31 characters — some 50 bits. */
    public const RANDOM_LENGTH = 10;

    /** Wrong codes a customer may send in WRONG_WINDOW — and an address network, behind which many customers may be (a carrier's shared address). */
    public const WRONG_CODES = 10;

    public const NETWORK_WRONG_CODES = 30;

    public const WRONG_WINDOW = 3600;

    /** The wallet line of a code's Toman. */
    public const REASON = 'کد هدیه %s';

    private const TOO_MANY = 'کد اشتباه زیادی فرستاده‌اید';
    private const SHAPE = '/^[A-Z0-9]{1,32}$/';
    private const TAKEN_KEPT = 'این کد استفاده شده است و برای سابقه می‌ماند؛ حذف نمی‌شود.';

    public function __construct(
        private readonly GiftCodeSettings $settings,
        private readonly Rewards $rewards,
        private readonly Eligibility $eligibility,
        private readonly RateLimiter $limiter,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Makes a batch: `count` codes of `prefix` and a random part, each worth `amount` Toman or a service of `plan_id`
     * (any plan, even one off sale), labelled `batch`, good until the end of `expires_on` (a shop day) or for ever.
     *
     * @param array<string, mixed> $input
     * @return array{batch: string, codes: list<string>}
     * @throws ValidationException
     */
    public function make(array $input): array
    {
        [$count, $kind, $amount, $plan, $batch, $prefix, $expiresAt] = $this->validate($input);

        $codes = $this->fresh($prefix, $count);
        $now = now()->utc();
        $bot = CurrentBot::forNewRow();
        $rows = array_map(static fn(string $code): array => [
            'bot_id' => $bot,
            'code' => $code,
            'batch' => $batch,
            'kind' => $kind->value,
            'amount' => $amount,
            'plan_id' => $plan?->id,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ], $codes);
        $this->db->transaction(static function () use ($rows): void {
            foreach (array_chunk($rows, 100) as $chunk) {
                GiftCode::query()->insert($chunk);
            }
        });
        $this->logger->info('{count} gift codes made in the batch «{batch}»', ['count' => count($codes), 'batch' => $batch]);

        return ['batch' => $batch, 'codes' => $codes];
    }

    /**
     * Redeems the code the customer typed. `$network` is a website request's address network (counted too; the bot has
     * none); `$tell` sends a plan's service once delivered (the bot — the website shows it itself).
     *
     * @throws GiftCodeRefusedException
     * @throws TooManyAttemptsException
     */
    public function redeem(User $customer, string $typed, ?string $network = null, bool $tell = true): Redeemed
    {
        if (!$this->settings->enabled()) {
            throw new GiftCodeRefusedException(GiftCodeRefusal::Off);
        }
        $code = self::normalize($typed);
        if ($code === '') {
            throw new GiftCodeRefusedException(GiftCodeRefusal::Empty);
        }

        $windows = [['gift|customer|' . $customer->id, self::WRONG_CODES, self::WRONG_WINDOW]];
        if ($network !== null) {
            $windows[] = ['gift|network|' . $customer->bot_id . '|' . $network, self::NETWORK_WRONG_CODES, self::WRONG_WINDOW];
        }
        $this->limiter->enforce($windows, self::TOO_MANY);

        $gift = preg_match(self::SHAPE, $code) === 1 ? GiftCode::query()->with('plan')->where('code', $code)->first() : null;
        if ($gift === null) {
            $this->logger->info('A gift code the shop does not have was tried by user {user}', ['user' => $customer->id]);

            throw new GiftCodeRefusedException(GiftCodeRefusal::Unknown);
        }
        foreach ($windows as [$key]) {
            $this->limiter->release($key);
        }

        if ($gift->redeemed_at !== null) {
            throw new GiftCodeRefusedException($gift->redeemed_by === $customer->id ? GiftCodeRefusal::Yours : GiftCodeRefusal::Taken);
        }
        if ($gift->isExpired()) {
            throw new GiftCodeRefusedException(GiftCodeRefusal::Expired);
        }

        $reward = $gift->reward();
        if ($reward === null || $this->eligibility->givable($customer, [$reward]) === []) {
            // A plan's service: none at all from an agent's shop whose selling is suspended, as Rewards::give() says.
            throw new GiftCodeRefusedException(GiftCodeRefusal::Unavailable, $customer->shop()->sells() ? RewardUnavailableException::NO_SERVER : RewardUnavailableException::SUSPENDED);
        }

        $given = $reward->plan === null ? $this->credit($gift, $customer, $reward) : $this->givePlan($gift, $customer, $reward, $tell);
        $this->logger->info('Gift code {code} redeemed by user {user}: {reward}', ['code' => $gift->code, 'user' => $customer->id, 'reward' => $reward->words()]);

        return new Redeemed($gift, $given);
    }

    /**
     * Deletes a code nobody took (expired ones too); a taken code is kept for the record.
     *
     * @throws ValidationException
     */
    public function delete(GiftCode $code): void
    {
        if (GiftCode::query()->whereKey($code->id)->whereNull('redeemed_at')->delete() !== 1) {
            throw ValidationException::on('status', self::TAKEN_KEPT);
        }
    }

    /**
     * Deletes the batch's codes nobody took (expired ones too) and returns how many went.
     *
     * @throws ValidationException 422 on `batch` when none is named
     */
    public function deleteUnused(string $batch): int
    {
        if (trim($batch) === '') {
            throw ValidationException::on('batch', 'سری کدها را انتخاب کنید.');
        }
        $deleted = (int) GiftCode::query()->where('batch', trim($batch))->whereNull('redeemed_at')->delete();
        $this->logger->info('{count} unused gift codes of the batch «{batch}» deleted', ['count' => $deleted, 'batch' => $batch]);

        return $deleted;
    }

    /** A code as it is kept and matched: Latin digits, capital letters, nothing between them («ab-12 cd» is «AB12CD»). */
    public static function normalize(string $typed): string
    {
        $code = (string) preg_replace('/[\s\x{200B}-\x{200F}\x{2010}-\x{2015}\x{2212}_-]+/u', '', Persian::latinDigits($typed));

        return strtoupper($code);
    }

    /** Takes the code and credits its Toman in one transaction: both, or neither. */
    private function credit(GiftCode $gift, User $customer, Reward $reward): Given
    {
        return $this->db->transaction(function () use ($gift, $customer, $reward): Given {
            $this->take($gift, $customer);

            return $this->rewards->give($customer, $reward, sprintf(self::REASON, $gift->code));
        }, Ledger::ATTEMPTS);
    }

    /**
     * Takes the code, then gives the plan's service and keeps its order on the code; a plan no server delivers now gives
     * the code back.
     */
    private function givePlan(GiftCode $gift, User $customer, Reward $reward, bool $tell): Given
    {
        $this->take($gift, $customer);
        try {
            $given = $this->rewards->give($customer, $reward, sprintf(self::REASON, $gift->code), tell: $tell);
        } catch (RewardUnavailableException $e) {
            GiftCode::query()->whereKey($gift->id)->where('redeemed_by', $customer->id)->update(['redeemed_by' => null, 'redeemed_at' => null]);

            throw new GiftCodeRefusedException(GiftCodeRefusal::Unavailable, $e->getMessage());
        }
        $gift->forceFill(['order_id' => $given->order?->id])->save();

        return $given;
    }

    /**
     * Takes the code for the customer by one conditional update, so of two takers at once only one wins.
     *
     * @throws GiftCodeRefusedException the other took it a moment ago
     */
    private function take(GiftCode $gift, User $customer): void
    {
        $now = now()->utc();
        $taken = GiftCode::usable()->whereKey($gift->id)->update(['redeemed_by' => $customer->id, 'redeemed_at' => $now]) === 1;
        if (!$taken) {
            $gift->refresh();

            throw new GiftCodeRefusedException($gift->redeemed_by === $customer->id ? GiftCodeRefusal::Yours : ($gift->redeemed_at !== null ? GiftCodeRefusal::Taken : GiftCodeRefusal::Expired));
        }
        $gift->forceFill(['redeemed_by' => $customer->id, 'redeemed_at' => $now])->syncOriginal();
    }

    /**
     * `$count` new codes the shop does not have yet: the prefix and a random part each, drawn again on a clash.
     *
     * @return list<string>
     */
    private function fresh(string $prefix, int $count): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $drawn = [];
            for ($i = count($codes); $i < $count; $i++) {
                $drawn[$prefix . ReadableCode::make(self::RANDOM_LENGTH)] = true;
            }
            foreach (GiftCode::query()->whereIn('code', array_keys($drawn))->pluck('code') as $taken) {
                unset($drawn[$taken]);
            }
            $codes += $drawn;
        }

        return array_keys($codes);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{int, GiftCodeKind, string|null, Plan|null, string, string, Carbon|null}
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $errors = [];

        $count = Input::integer($input, 'count');
        if ($count === null || $count < 1 || $count > self::COUNT_MAX) {
            $errors['count'][] = 'تعداد کدها باید عددی بین 1 تا ' . self::COUNT_MAX . ' باشد.';
        }

        $kind = GiftCodeKind::tryFrom(Input::text($input, 'kind'));
        $amount = null;
        $plan = null;
        if ($kind === null) {
            $errors['kind'][] = 'نوع کد را انتخاب کنید: شارژ کیف پول یا سرویس رایگان.';
        } elseif ($kind === GiftCodeKind::Wallet) {
            $value = Input::amount($input, 'amount');
            if ($value === null || $value < Reward::WALLET_MIN || $value > WalletSettings::TOPUP_MAX) {
                $errors['amount'][] = sprintf('مبلغ کد باید مبلغی بین %s تا %s باشد.', Money::format(Reward::WALLET_MIN), Money::format(WalletSettings::TOPUP_MAX));
            } else {
                $amount = Money::normalize($value);
            }
        } else {
            $id = Input::integer($input, 'plan_id');
            $plan = $id === null ? null : Plan::query()->find($id);
            if ($plan === null) {
                $errors['plan_id'][] = 'پلنی را انتخاب کنید که سرویسش هدیه داده می‌شود.';
            }
        }

        $batch = Input::text($input, 'batch');
        $message = match (true) {
            $batch === '' => 'نام سری کدها را بنویسید؛ مثلا «نوروز ۱۴۰۵».',
            mb_strlen($batch) > self::BATCH_MAX => Validation::tooLong('نام سری', self::BATCH_MAX),
            default => null,
        };
        if ($message !== null) {
            $errors['batch'][] = $message;
        }

        $prefix = self::normalize(Input::text($input, 'prefix'));
        if (preg_match('/^[A-Z0-9]{0,' . self::PREFIX_MAX . '}$/', $prefix) !== 1) {
            $errors['prefix'][] = 'پیشوند فقط حرف انگلیسی و عدد است، حداکثر ' . self::PREFIX_MAX . ' کاراکتر.';
        }

        $expiresAt = null;
        $day = Input::text($input, 'expires_on');
        if ($day !== '') {
            $expiresAt = DateRange::of('', $day)->until;
            if ($expiresAt === null) {
                $errors['expires_on'][] = 'تاریخ پایان معتبر نیست.';
            } elseif ($expiresAt->lessThanOrEqualTo(now())) {
                $errors['expires_on'][] = 'تاریخ پایان نمی‌تواند گذشته باشد.';
            }
        }
        ValidationException::ifAny($errors);

        return [(int) $count, $kind ?? GiftCodeKind::Wallet, $amount, $plan, $batch, $prefix, $expiresAt];
    }
}
