<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Records\ImportedGiftCode;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\GiftCodeKind;
use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Rewards\Services\GiftCodes;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletSettings;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Text;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * The gift codes stage: each wallet code of the other bot with one redemption left, still on and not past its day,
 * becomes one of the shop's wallet gift codes of its amount — kept as the shop keeps a code (GiftCodes::normalize()),
 * under a batch named after the other bot. The shop's codes are spent once, by whoever has them: one with more uses
 * left, or one the other bot kept for one customer, is named in the report instead, for the owner to give again by hand.
 */
final class GiftCodeWriter
{
    private const KIND = 'gift_codes';

    /** The batch the codes come under: «منتقل‌شده از Faoxima». */
    public const BATCH = 'منتقل‌شده از %s';

    /** What a gift code of the shop may be: capital Latin letters and digits (GiftCodes). */
    private const SHAPE = '/^[A-Z0-9]{1,32}$/';

    private const NOT_A_CODE = 'کد هدیه اینجا فقط حرف انگلیسی و عدد است.';
    private const AMOUNT = 'مبلغ کد هدیه اینجا بین %s تا %s است.';
    private const UNLIMITED = 'بدون محدودیت';
    private const USES_LEFT = '%s بار مانده';
    private const FOR_TELEGRAM = 'برای مشتری با شناسه تلگرام %s';
    private const FOR_KEY = 'برای مشتری %s ربات قبلی';

    private const BATCH_MAX = 64;

    public function __construct(
        private readonly Links $links,
        private readonly ConnectionInterface $db,
    ) {}

    public function write(Run $run, ImportedGiftCode $gift): void
    {
        if ($run->broughtBefore(Links::GIFT_CODE, self::KIND, $gift->key, GiftCode::class)) {
            return;
        }
        $text = GiftCodes::normalize($gift->code);
        $amount = is_numeric($gift->amount) ? Money::normalize($gift->amount) : '0';
        [$reason, $detail] = match (true) {
            $text === '' => [ProblemReason::Unreadable, null],
            $gift->usesLeft !== null && $gift->usesLeft <= 0 => [ProblemReason::UsedUp, null],
            $gift->expiresAt !== null && $gift->expiresAt <= now() => [ProblemReason::Expired, null],
            !$gift->enabled => [ProblemReason::Ended, null],
            $gift->forCustomer !== null => [ProblemReason::OneCustomer, Run::isolate($text) . ' — ' . Money::format($amount) . ' — ' . $this->whose($run, $gift->forCustomer)],
            $gift->usesLeft === null || $gift->usesLeft > 1 => [ProblemReason::MultiUseGift, Run::isolate($text) . ' — ' . ($gift->usesLeft === null ? self::UNLIMITED : sprintf(self::USES_LEFT, Persian::number($gift->usesLeft)))],
            preg_match(self::SHAPE, $text) !== 1 => [ProblemReason::Refused, Run::isolate($text) . ': ' . self::NOT_A_CODE],
            Money::compare($amount, Reward::WALLET_MIN) < 0 || Money::compare($amount, WalletSettings::TOPUP_MAX) > 0 => [ProblemReason::Refused, Run::isolate($text) . ': ' . sprintf(self::AMOUNT, Money::format(Reward::WALLET_MIN), Money::format(WalletSettings::TOPUP_MAX))],
            GiftCode::query()->where('code', $text)->exists() => [ProblemReason::CodeTaken, Run::isolate($text)],
            default => [null, null],
        };
        if ($reason !== null) {
            $run->skipped(self::KIND, $gift->key, $reason, $detail);

            return;
        }

        $row = [
            'code' => $text,
            'batch' => Text::fit(sprintf(self::BATCH, $run->label), self::BATCH_MAX),
            'kind' => GiftCodeKind::Wallet,
            'amount' => explode('.', $amount)[0],
            'plan_id' => null,
            // A day past what the shop keeps is no end at all.
            'expires_at' => $gift->expiresAt === null || LocalTime::tooLate($gift->expiresAt) ? null : Carbon::instance($gift->expiresAt)->utc(),
        ];
        try {
            // The code and its link together.
            $this->db->transaction(function () use ($run, $gift, $row): void {
                $this->links->add($run->import, Links::GIFT_CODE, $gift->key, GiftCode::query()->create($row)->id);
            });
        } catch (UniqueConstraintViolationException) {
            $run->skipped(self::KIND, $gift->key, ProblemReason::CodeTaken, Run::isolate($text));

            return;
        }
        $run->created(self::KIND);
    }

    /**
     * Whom the other bot kept a code for, as the owner finds them: their Telegram id once the import brought them, the
     * other bot's own key for them otherwise.
     */
    private function whose(Run $run, string $customer): string
    {
        $user = $this->links->customer($run->import, $customer);
        $telegramId = $user === null ? null : User::query()->whereKey($user)->value('telegram_id');

        return is_numeric($telegramId) ? sprintf(self::FOR_TELEGRAM, Run::isolate((string) $telegramId)) : sprintf(self::FOR_KEY, Run::isolate($customer));
    }
}
