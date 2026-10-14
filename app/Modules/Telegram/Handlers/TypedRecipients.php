<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerLookup;

/**
 * A customer of the shop named by the numeric Telegram id typed at a step that hands them something — money
 * («💸 انتقال موجودی», WalletTransferHandler) or a service («🔁 انتقال سرویس», ServiceTransferHandler) —, and the one
 * confirmation of handing it over, taken the one way both steps do. `$refused` is the flow's «… ممکن نیست: {reason}»,
 * `$back` the «بازگشت» every answer carries.
 */
final class TypedRecipients
{
    public function __construct(private readonly BotTexts $texts) {}

    /**
     * The customer `$find` names from the text typed; null after replying: a recipient refused (a refusal on
     * `telegram_id`: no id, nobody of the shop, themselves, banned) says why in `$recipientRefused` and another id is
     * still awaited; any other refusal, or a customer who looked up too many, ends the step (stop()).
     *
     * @param \Closure(string): User $find
     * @param array{reply_markup: array<string, mixed>} $back
     */
    public function read(Context $ctx, \Closure $find, BotText $recipientRefused, BotText $refused, array $back): ?User
    {
        try {
            return $find((string) $ctx->update->text());
        } catch (ValidationException $e) {
            if (array_key_exists('telegram_id', $e->errors())) {
                $ctx->reply($this->texts->render($recipientRefused, ['reason' => $e->getMessage()]), $back);

                return null;
            }
            $this->stop($ctx, $e->getMessage(), $refused, $back);
        } catch (TooManyAttemptsException $e) {
            $this->stop($ctx, $e->getMessage(), $refused, $back);
        }

        return null;
    }

    /**
     * Nothing may be handed over now (or the customer tried too often): why, as a new message, and the step is over.
     *
     * @param array{reply_markup: array<string, mixed>} $back
     */
    public function stop(Context $ctx, string $reason, BotText $refused, array $back): void
    {
        $ctx->session->clear();
        $ctx->reply($this->texts->render($refused, ['reason' => $reason]), $back);
    }

    /**
     * The confirmation tapped, claimed first (`$state`, the one the chat waits on): a second tap, or one after the chat
     * moved on, gets the popup `$expired` and nothing more. Then `$give` hands the recipient `$recipientId` it over (every
     * rule asked again) and says so; a refusal (the recipient gone too) says why in place of the question.
     *
     * @param \Closure(User): void $give
     * @param array{reply_markup: array<string, mixed>} $back
     */
    public function confirm(Context $ctx, string $state, int $recipientId, \Closure $give, BotText $expired, BotText $refused, array $back): void
    {
        if (!$ctx->session->claim($state)) {
            $ctx->answer($this->texts->get($expired), alert: true);

            return;
        }

        try {
            $give(User::query()->find($recipientId) ?? throw ValidationException::on('telegram_id', CustomerLookup::UNKNOWN));
        } catch (ValidationException|TooManyAttemptsException $e) {
            $ctx->edit($this->texts->render($refused, ['reason' => $e->getMessage()]), $back);
        }
    }
}
