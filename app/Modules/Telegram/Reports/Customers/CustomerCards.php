<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Core\Database\Lease;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Reports\ReportGroupState;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Reports\UserManagement;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Builder;
use Psr\Container\ContainerInterface;

/**
 * What every section of a customer's card in the report group shares (UserManagement is the card's front; the sections
 * — services, wallet, discount, history, website sign-in, a service made by hand — each its own callback prefix):
 *
 * - press(): a press checked once for all — in the shop's connected report group (a card elsewhere would show a
 *   customer to another group), by one of its bot admins (GroupButtons::admin(), never what a button's data says),
 *   about a customer of the shop (its scope: another shop's number is nobody here);
 * - the card's look: header() atop every section, back() to the front, data() and button() for the buttons, show() to
 *   rewrite the card in place and answer the press, refused() for a refusal in a popup;
 * - the rules the report group adds to every change: mayChange() — never the admin's own account nor an agent's (the
 *   panel's) — and lockOf(), the card's word for why it offers none;
 * - typed input: ask() posts a question under the card (a ForceReply) recorded with its section, kind and arguments;
 *   reply() — from ReportGroupUpdates — takes the question (one conditional update of its record: of two replies, or
 *   two admins at once, one goes on; any later one is told it was answered) and routes the admin's reply back to that
 *   section (AnswersPrompts), which ends it with done() (the card rewritten, the question deleted, a word under the
 *   reply: it stays taken) or retry() (a word, the question given back for another reply). Anyone but a bot admin
 *   replying is told who may; a reply that is no text is asked again;
 * - a button that must act once — a «✔️ بله» that gives —: nonce() draws its nonce as its card is drawn, recorded on
 *   the card's message; once() lets one press act on it, however often and by whomever it is pressed.
 *
 * Every change goes through the service the panels use, under the admin's name (Actor::groupAdmin()), by its rules.
 * What dies midway (a crash, not a refusal) keeps its question or button taken: the admin asks again from the card.
 */
final class CustomerCards
{
    /** What only the bot's admins may do here, as the refusal says it (GroupButtons::onlyAdmins()). */
    public const MAY = 'کاربرها را مدیریت کنند';

    public const GONE = 'این کاربر دیگر پیدا نشد.';
    public const WRITE_REPLY = 'جواب را در پاسخ به پیام ربات بنویسید.';
    public const TEXT_ONLY = 'جواب را به صورت متن، در پاسخ به همان پیام ربات بنویسید.';
    public const ANSWERED = 'به این پرسش پیش‌تر جواب داده شده است؛ برای تغییر دیگری دوباره دکمه‌اش را روی کارت بزنید.';

    /** A button that acts once (once()), pressed again — or its nonce none this card drew: pruned since, or forged. */
    public const DONE_ALREADY = 'این کار پیش‌تر با همین دکمه انجام شده است.';
    public const BUTTON_OLD = 'این دکمه قدیمی است و دیگر کاری نمی‌کند؛ دوباره از اول انتخاب کنید.';

    /** What a recorded question's `ref` starts with: `ask:<section>:<kind>:<customer id>:<card message id>[:<arg>…]`. */
    private const PROMPT_REF = 'ask:';

    /** A button that acts once, as recorded on its card's message: `once:<section>:<nonce>`. */
    private const ONCE_REF = 'once:';

    /** The sections that ask for typed input, by their prefix without its colon. */
    private const ANSWERING = [
        'us' => CustomerServices::class,
        'uw' => CustomerWallet::class,
        'ud' => CustomerDiscount::class,
        'uo' => CustomerOrder::class,
    ];

    public function __construct(
        private readonly GroupButtons $groupButtons,
        private readonly ReportGroupState $state,
        private readonly BotApi $api,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * The press on a button of `$prefix` (`<prefix><action>:<customer id>[:<arg>…]`), checked; null after answering it —
     * silently for one malformed or outside the report group, with who may for anyone but a bot admin, and taking the
     * card's buttons off for a customer gone.
     */
    public function press(Update $update, string $prefix): ?CardPress
    {
        $args = $update->callbackArgs($prefix) ?? [];
        $action = (string) array_shift($args);
        $id = (string) array_shift($args);
        if ($action === '' || !ctype_digit($id) || strlen($id) > 18 || (int) $update->chatId() !== $this->state->chatId()) {
            $this->groupButtons->answer($update);

            return null;
        }

        $admin = $this->groupButtons->presser($update, GroupButtons::onlyAdmins(self::MAY));
        if ($admin === null) {
            return null;
        }
        $user = User::query()->find((int) $id);
        if ($user === null) {
            $this->groupButtons->dismiss($update, self::GONE);

            return null;
        }

        return new CardPress($update, $admin, $user, $action, array_values(array_map(strval(...), $args)));
    }

    /**
     * The card rewritten in place with these buttons, and the press answered — with `$word`, a popup when `$alert`.
     *
     * @param list<list<array<string, mixed>>> $rows
     */
    public function show(CardPress $press, string $html, array $rows, ?string $word = null, bool $alert = false): void
    {
        $this->groupButtons->rewrite($press->update, $html, $rows);
        $this->groupButtons->answer($press->update, $word, $alert);
    }

    /** The press answered with what refused it, in a popup; the card stays as it is. */
    public function refused(CardPress $press, DomainRuleException $e): void
    {
        $this->groupButtons->answer($press->update, self::words($e), alert: true);
    }

    /** Just the press answered — a toast with `$word`, or only its spinner stopped. */
    public function answer(CardPress $press, ?string $word = null, bool $alert = false): void
    {
        $this->groupButtons->answer($press->update, $word, $alert);
    }

    /**
     * A nonce for a button of `$prefix`'s section that must act once (a «✔️ بله» that gives), drawn as its card is drawn
     * — six base-36 characters —: recorded on the card's message, as the bot's messages outside the queue are, with
     * `$text` (what the card asks), so once() lets one press act on it.
     */
    public function nonce(CardPress $press, string $prefix, string $text): string
    {
        $nonce = str_pad(base_convert((string) random_int(0, 36 ** 6 - 1), 10, 36), 6, '0', STR_PAD_LEFT);
        ReportMessage::posted(Topic::Users, $text, self::onceRef($prefix, $nonce), (int) $press->update->chatId(), (int) $press->update->messageId());

        return $nonce;
    }

    /**
     * `$act` run for the press's button — its nonce one nonce() drew on this card — once: taken by one conditional
     * update, so of the same button pressed twice, or by two admins at once, one acts. A refusal from `$act` (nothing
     * done) gives the button back for another press.
     *
     * @param \Closure(): void $act
     * @throws ValidationException 422 on `status`: the button acted already (DONE_ALREADY), or this card drew no such
     *                             nonce (BUTTON_OLD)
     * @throws DomainRuleException what `$act` threw
     */
    public function once(CardPress $press, string $prefix, string $nonce, \Closure $act): void
    {
        $button = static fn(): Builder => ReportMessage::query()
            ->where('chat_id', (int) $press->update->chatId())
            ->where('message_id', (int) $press->update->messageId())
            ->where('ref', self::onceRef($prefix, $nonce));
        $claim = self::claim();
        if ($button()->whereNull(Lease::TOKEN)->update([Lease::TOKEN => $claim]) === 0) {
            throw ValidationException::on('status', $button()->exists() ? self::DONE_ALREADY : self::BUTTON_OLD);
        }

        try {
            $act();
        } catch (DomainRuleException $e) {
            $button()->where(Lease::TOKEN, $claim)->update([Lease::TOKEN => null]);

            throw $e;
        }
    }

    /**
     * Ask the admin who pressed for typed input about the customer: `$question` (plain text) as a reply to the card,
     * naming the admin when they have a @username, with Telegram's reply field open — for them alone when it names them
     * (Telegram's `selective` opens it only for those the text @mentions: for an admin without a handle, for nobody) —;
     * recorded so their reply comes back to the section `$prefix` (one that AnswersPrompts) with `$kind` and `$args`.
     *
     * @param list<int|string> $args
     */
    public function ask(CardPress $press, string $prefix, string $kind, array $args, string $question, string $placeholder): void
    {
        $section = rtrim($prefix, ':');
        if (!isset(self::ANSWERING[$section])) {
            throw new \LogicException("No section answers questions under \"{$prefix}\".");
        }
        $chatId = (int) $press->update->chatId();
        $cardId = (int) $press->update->messageId();
        $ref = self::PROMPT_REF . implode(':', [$section, $kind, $press->user->id, $cardId, ...$args]);
        if (strlen($ref) > 64) {
            throw new \LogicException("The question's record \"{$ref}\" is longer than its column.");
        }

        // After a left-to-right mark: the Persian words before it would show the handle «sara@».
        $handle = $press->admin->handle();
        $text = $question . ($handle === null ? '' : " \u{200E}@" . $handle);
        $promptId = $this->groupButtons->say($chatId, $cardId, GroupButtons::threadOf($press->update->message() ?? []), $text, [
            'force_reply' => true,
            ...($handle === null ? [] : ['selective' => true]),
            'input_field_placeholder' => $placeholder,
        ]);
        if ($promptId !== null) {
            ReportMessage::posted(Topic::Users, $text, $ref, $chatId, $promptId);
        }
        $this->groupButtons->answer($press->update, self::WRITE_REPLY);
    }

    /**
     * A message replying to one of the cards' questions, to the section that asked — the question taken first, so it is
     * answered once —; false when the message is no such reply (not the cards' business).
     */
    public function reply(Update $update): bool
    {
        $message = $update->raw['message'] ?? null;
        $prompt = $this->groupButtons->botReplied($update);
        $chatId = (int) $update->chatId();
        if (!is_array($message) || $prompt === null || $chatId !== $this->state->chatId()) {
            return false;
        }
        $promptId = (int) ($prompt['message_id'] ?? 0);
        $question = ReportMessage::query()->where('chat_id', $chatId)->where('message_id', $promptId)->latest('id')->first(['id', 'ref']);
        $ref = $question?->ref;
        if ($question === null || !is_string($ref) || !str_starts_with($ref, self::PROMPT_REF)) {
            return false;
        }
        $parts = explode(':', substr($ref, strlen(self::PROMPT_REF)));
        $section = self::ANSWERING[$parts[0]] ?? null;
        if ($section === null || count($parts) < 4) {
            return false;
        }

        $admin = $this->groupButtons->writer($chatId, $message, GroupButtons::onlyAdmins(self::MAY));
        if ($admin === null) {
            return true;
        }
        $text = trim((string) $update->text());
        if ($text === '') {
            $this->groupButtons->sayUnder($chatId, $message, self::TEXT_ONLY);

            return true;
        }
        $user = User::query()->find((int) $parts[2]);
        if ($user === null) {
            $this->groupButtons->sayUnder($chatId, $message, self::GONE);
            $this->api->deleteMessage($chatId, $promptId);

            return true;
        }
        // Taken by one conditional update: a second reply — before the question is deleted, or once Telegram no longer
        // deletes it (48 hours on) —, or two admins at once, never does it twice.
        $claim = self::claim();
        if (ReportMessage::query()->whereKey($question->id)->whereNull(Lease::TOKEN)->update([Lease::TOKEN => $claim]) === 0) {
            $this->groupButtons->sayUnder($chatId, $message, self::ANSWERED);

            return true;
        }

        $reply = new CardReply($chatId, $message, $promptId, (int) $parts[3], $admin, $user, $parts[1], array_slice($parts, 4), $text, $question->id, $claim);
        $answering = $this->container->get($section);
        if (!$answering instanceof AnswersPrompts) {
            throw new \LogicException("{$section} answers no questions.");
        }
        try {
            $answering->answered($reply);
        } catch (DomainRuleException $e) {
            $this->retry($reply, self::words($e));
        }

        return true;
    }

    /**
     * A reply that did what it asked: the card rewritten as `$html` with `$rows`, the question deleted (and kept taken:
     * a later reply to it is told it was answered), `$word` under the reply.
     *
     * @param list<list<array<string, mixed>>> $rows
     */
    public function done(CardReply $reply, string $word, string $html, array $rows): void
    {
        $this->groupButtons->rewriteMessage($reply->chatId, $reply->cardId, $html, $rows);
        $this->api->deleteMessage($reply->chatId, $reply->promptId);
        $this->groupButtons->sayUnder($reply->chatId, $reply->message, $word);
    }

    /** A reply that did nothing: `$error` under it; the question given back, for another reply. */
    public function retry(CardReply $reply, string $error): void
    {
        ReportMessage::query()->whereKey($reply->questionId)->where(Lease::TOKEN, $reply->claim)->update([Lease::TOKEN => null]);
        $this->groupButtons->sayUnder($reply->chatId, $reply->message, $error);
    }

    /**
     * Whether the admin may change this customer's account from the report group: never their own account (another admin
     * or the panel does), never an agent's (the panel's).
     *
     * @throws ActorRefusedException 403
     */
    public static function mayChange(User $user, User $admin): void
    {
        if ($user->id === $admin->id) {
            throw ActorRefusedException::ownAccount();
        }
        if ($user->isAgent()) {
            throw ActorRefusedException::agentAccount();
        }
    }

    /** The card's word for why it offers no change (mayChange()); null when it offers them. */
    public static function lockOf(User $user, User $admin): ?string
    {
        return match (true) {
            $user->id === $admin->id => '🔒 حساب خودتان است؛ با مدیر دیگری یا از پنل تغییر می‌کند.',
            $user->isAgent() => '🔒 حساب نماینده است؛ فقط از پنل تغییر می‌کند.',
            default => null,
        };
    }

    /** The two lines atop every section: «⚙️ مدیریت کاربر › <section> · #12», then who the customer is. */
    public static function header(User $user, ?string $section = null): string
    {
        return sprintf("⚙️ <b>مدیریت کاربر%s</b> · \u{200E}#%d", $section === null ? '' : ' › ' . $section, $user->id) . "\n👤 " . ShopReports::customer($user);
    }

    /** A button's callback data: `<prefix><action>:<customer id>[:<arg>…]` (Telegram's 64 bytes at most). */
    public static function data(string $prefix, string $action, int $userId, int|string ...$args): string
    {
        return CallbackData::build($prefix, $action, $userId, ...$args);
    }

    /** @return array<string, mixed> A button pressing `$data`; `$style` one of Telegram's: success, danger, primary. */
    public static function button(string $label, string $data, ?string $style = null): array
    {
        return InlineKeyboard::callback($label, $data, $style);
    }

    /** @return array<string, mixed> «⬅️ بازگشت» to the card's front, as the account now stands. */
    public static function back(int $userId, string $label = '⬅️ بازگشت'): array
    {
        return InlineKeyboard::callback($label, CallbackData::build(UserManagement::PREFIX, UserManagement::OPEN, $userId));
    }

    /** What a refusal says: a form's first word of each field it refused, or its message. */
    public static function words(DomainRuleException $e): string
    {
        if ($e instanceof ValidationException && $e->errors() !== []) {
            return implode(' ', array_map(static fn(array $messages): string => (string) ($messages[0] ?? ''), $e->errors()));
        }

        return $e->getMessage();
    }

    /** A whole number typed in any digits — «۱۲٬۰۰۰», «12,000», «12000» —; null when it is none. */
    public static function number(string $typed): ?int
    {
        $digits = str_replace([',', '٬', '،', ' ', "\u{200C}"], '', Persian::latinDigits(trim($typed)));

        return ctype_digit($digits) && strlen($digits) <= 15 ? (int) $digits : null;
    }

    /** What taking a question or a button writes in its record's `lease_token`: whose it is, to give it back by. */
    private static function claim(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function onceRef(string $prefix, string $nonce): string
    {
        return self::ONCE_REF . rtrim($prefix, ':') . ':' . $nonce;
    }
}
