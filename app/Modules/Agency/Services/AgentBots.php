<?php

declare(strict_types=1);

namespace App\Modules\Agency\Services;

use App\Core\Http\Urls;
use App\Modules\Agency\Exceptions\AgencyException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\BotToken;
use App\Modules\Telegram\Api\BotTokenException;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Services\BotLifecycle;
use App\Modules\Users\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * An agent's own bot, as the shop runs it beside the main one — the agency's one part that talks to Telegram: its token
 * handed over from the main bot (connect()), the one-time link to its panel (loginLink()), and its wiring to Telegram as
 * the agency starts again (resume()) or ends (takeDown()).
 */
final class AgentBots
{
    /** How long a panel login link works. */
    public const LOGIN_MINUTES = 10;

    public const TOKEN_MAIN = 'این توکن ربات خود فروشگاه است؛ توکن ربات خودتان را بفرستید.';
    public const TOKEN_TAKEN = 'این ربات به نماینده دیگری وصل است.';
    public const TOKEN_OTHER_BOT = 'ربات شما @%s است؛ برای عوض کردن توکن، توکن تازه همین ربات را بفرستید. برای جابه‌جایی به ربات دیگر با پشتیبانی هماهنگ کنید.';
    public const NO_SHOP = 'حساب نمایندگی شما هنوز باز نشده است.';

    public function __construct(
        private readonly BotApi $api,
        private readonly BotLifecycle $lifecycle,
        private readonly Urls $urls,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The agent hands their bot's token over (from the main bot): Telegram is asked who the bot is, and it becomes their
     * shop's bot — the same bot again with a new token (one revoked in @BotFather), never another one in its place (its
     * customers know the old one). It starts serving at once: a webhook of its own when the shop runs on webhooks, the
     * poller's next round otherwise.
     *
     * @throws AgencyException with the Persian reason it was refused
     */
    public function connect(User $agent, string $token): Bot
    {
        $bot = $agent->ownBot ?? throw new AgencyException(self::NO_SHOP);
        $token = trim($token);
        $main = CurrentBot::run(Bot::MAIN, fn(): ?int => $this->api->botId());
        if ($main !== null && BotToken::botId($token) === $main) {
            throw new AgencyException(self::TOKEN_MAIN);
        }

        try {
            $me = BotToken::identify($this->api, $token);
        } catch (BotTokenException $e) {
            throw new AgencyException($e->getMessage());
        }
        if ($bot->telegram_id !== null && $bot->telegram_id !== $me['id']) {
            throw new AgencyException(sprintf(self::TOKEN_OTHER_BOT, $bot->username ?? (string) $bot->telegram_id));
        }
        if (Bot::query()->where('telegram_id', $me['id'])->whereKeyNot($bot->id)->exists()) {
            throw new AgencyException(self::TOKEN_TAKEN);
        }

        $bot->forceFill([
            'token' => $token,
            'telegram_id' => $me['id'],
            'username' => $me['username'] !== '' ? $me['username'] : $bot->username,
            'title' => $me['name'] !== '' ? $me['name'] : $bot->title,
            'problem' => null,
            'connected_at' => $bot->connected_at ?? now(),
        ]);
        try {
            $bot->save();
        } catch (UniqueConstraintViolationException) {
            // Another agent handed the same bot over in the same moment.
            throw new AgencyException(self::TOKEN_TAKEN);
        }

        $this->lifecycle->follow($bot);

        return $bot;
    }

    /**
     * A one-time link that signs the agent in to their panel (/agent/, their bot's shop) — good for LOGIN_MINUTES,
     * once; a new link replaces the last. Null while their bot is not on. The code rides in the fragment (`#code=`),
     * which a browser never sends: no server's or proxy's access log holds it, and the panel takes it off the address
     * bar before it signs in with it.
     */
    public function loginLink(User $agent): ?string
    {
        $bot = $agent->ownBot;
        if ($bot === null || $bot->status() !== BotStatus::Active) {
            return null;
        }

        // Written as given, whatever a copy of the row held: a used code is cleared in the database alone (AgentAuth).
        $code = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        Bot::query()->whereKey($bot->id)->update(['login_code' => hash('sha256', $code), 'login_code_expires_at' => now()->addMinutes(self::LOGIN_MINUTES)]);

        return $this->urls->panel('agent', '/login#code=' . $code);
    }

    /**
     * The agency was given back: what kept the bot from running is forgotten, and — once it has a token — it follows
     * the shop's mode again.
     */
    public function resume(Bot $bot): void
    {
        $bot->forceFill(['problem' => null])->save();
        if (($bot->token ?? '') !== '') {
            $this->lifecycle->follow($bot);
        }
    }

    /**
     * The agency ended and the bot with it (Bot::status()): its webhook is taken down so Telegram stops calling. Best
     * effort — the bot is off whatever Telegram answers, and its webhook answers nothing while it is.
     */
    public function takeDown(Bot $bot): void
    {
        if (($bot->token ?? '') === '') {
            return;
        }

        try {
            CurrentBot::run($bot, fn() => $this->lifecycle->disableWebhook(false));
        } catch (TelegramApiException $e) {
            $this->logger->warning('The webhook of bot #{bot} could not be taken down: {message}', ['bot' => $bot->id, 'message' => $e->getMessage()]);
        }
    }
}
