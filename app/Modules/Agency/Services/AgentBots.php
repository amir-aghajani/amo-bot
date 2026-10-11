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
 * An agent's own bot — the agency's only part that talks to Telegram: connecting its token, the one-time panel login
 * link, and resuming or taking down its webhook as the agency returns or ends.
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
     * Connects the agent's bot by its token: Telegram says which bot it is; a new token for the same bot is accepted,
     * another bot is not (its customers know the old one). It starts serving at once (BotLifecycle::follow()).
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
     * A one-time panel login link, good for LOGIN_MINUTES (a new one replaces the last); null while their bot is off.
     * The code rides in the URL fragment, which browsers never send, so no access log holds it.
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

    /** The agency returned: clears the bot's last problem and, once it has a token, puts it back on the shop's update mode. */
    public function resume(Bot $bot): void
    {
        $bot->forceFill(['problem' => null])->save();
        if (($bot->token ?? '') !== '') {
            $this->lifecycle->follow($bot);
        }
    }

    /** The agency ended: takes the bot's webhook down, best effort (the bot is off whatever Telegram answers). */
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
