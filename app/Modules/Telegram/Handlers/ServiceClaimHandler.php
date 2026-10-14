<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Subscriptions\Enums\ClaimRefusal;
use App\Modules\Subscriptions\Exceptions\ServiceClaimRefusedException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceClaims;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * «➕ افزودن سرویس»: a service support made on a panel by hand, added to the customer's account by its subscription link
 * or one of its configs (ServiceClaims) — the first to send it gets it:
 *   claim:         → the link asked for (state `claim`), «بازگشت» to «سرویس‌های من»
 *   state `claim`  → what the customer sends there
 *   a link sent    → a message holding one at no step that takes it (Dispatcher::pattern()); only its configs and its web
 *                    addresses on a host the shop's subscription links are on are looked for (unasked()), so a channel's
 *                    post forwarded asks no panel and costs no try. One holding none of those, one no panel has, or any
 *                    while the shop takes no services by their link, is answered as any message the bot does not
 *                    understand
 * The service added is said with the way to its screen; a refusal in its own words, one the customer has already with
 * that way too. Only a message holding no link keeps the link awaited.
 */
final class ServiceClaimHandler implements Handler
{
    public const PREFIX = 'claim:';
    public const STATE = 'claim';

    public function __construct(
        private readonly ServiceClaims $claims,
        private readonly FallbackHandler $fallback,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    public function handle(Context $ctx): void
    {
        if ($ctx->update->isCallback()) {
            $this->ask($ctx);

            return;
        }
        $asked = $ctx->session->inState(self::STATE);
        // A picture of the link carries it in its caption.
        $text = (string) ($ctx->update->text() ?? $ctx->update->caption());
        if (!$asked) {
            $text = $this->claims->offered() ? self::unasked($text) : '';
            if ($text === '') {
                $this->fallback->handle($ctx);

                return;
            }
        }

        $this->claim($ctx, $text, $asked);
    }

    /** `claim:` — the link asked for in place of the list; while the shop takes none, why not. */
    private function ask(Context $ctx): void
    {
        if (!$this->claims->offered()) {
            $ctx->edit($this->texts->get(BotText::ClaimOff), $this->back());

            return;
        }

        $ctx->session->enter(self::STATE);
        $ctx->edit($this->texts->get(BotText::ClaimPrompt), $this->back());
    }

    /**
     * The service the links in `$text` are for, added — or why not, or the wait once the customer tried too often. Where
     * none was `$asked` for, links that are no service's, or no panel's, are any message the bot does not understand.
     */
    private function claim(Context $ctx, string $text, bool $asked): void
    {
        try {
            $service = $this->claims->claim($ctx->user, $text);
        } catch (ServiceClaimRefusedException $e) {
            if (!$asked && in_array($e->reason, [ClaimRefusal::NotALink, ClaimRefusal::NotFound], true)) {
                $this->fallback->handle($ctx);

                return;
            }
            if ($e->reason !== ClaimRefusal::NotALink) {
                $ctx->session->clear();
            }
            $ctx->reply($this->texts->get(self::refusal($e->reason)), $e->service === null ? $this->back() : ['reply_markup' => $this->buttons->serviceOnly($e->service->id)]);

            return;
        } catch (TooManyAttemptsException $e) {
            $ctx->session->clear();
            $ctx->reply($this->texts->render(BotText::ClaimWait, ['minutes' => TooManyAttemptsException::minutes($e->retryAfter)]), $this->back());

            return;
        }

        $ctx->session->clear();
        $ctx->reply(
            $this->texts->render(BotText::ClaimAdded, ['client' => $service->remote_name, 'server' => $service->server->name]),
            ['reply_markup' => $this->buttons->serviceOnly($service->id)],
        );
    }

    /**
     * The links of a message sent at no step that may be a service's, one a line: its configs, and its web addresses on
     * a host the shop's subscription links are on (linkHosts()) — no other web address. '' for none.
     */
    private static function unasked(string $text): string
    {
        $hosts = null;

        return implode("\n", array_filter(ClientLink::links($text), static function (string $link) use (&$hosts): bool {
            if (preg_match('~^https?://~i', $link) !== 1) {
                return true;
            }
            $hosts ??= self::linkHosts();

            return in_array(self::host($link), $hosts, true);
        }));
    }

    /**
     * The hosts the shop's subscription links are on: each server's panel address (where 3x-ui serves them unless set
     * otherwise), the prefix set on it for them, and its newest service's link — any shop's.
     *
     * @return list<string>
     */
    private static function linkHosts(): array
    {
        $urls = [];
        foreach (Server::query()->get(['base_url', 'meta']) as $server) {
            $connection = PanelConnection::of($server);
            array_push($urls, $connection->baseUrl, (string) $connection->subscriptionUrl);
        }
        $newest = Subscription::acrossShops()->selectRaw('MAX(id)')->groupBy('server_id');
        array_push($urls, ...Subscription::acrossShops()->whereIn('id', $newest)->pluck('subscription_url')->all());

        return array_values(array_filter(array_unique(array_map(self::host(...), $urls))));
    }

    /** A web address's host, in lower case; '' for none. */
    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private static function refusal(ClaimRefusal $reason): BotText
    {
        return match ($reason) {
            ClaimRefusal::Off => BotText::ClaimOff,
            ClaimRefusal::NotALink => BotText::ClaimNotALink,
            ClaimRefusal::NotFound => BotText::ClaimNotFound,
            ClaimRefusal::Taken => BotText::ClaimTaken,
            ClaimRefusal::Yours => BotText::ClaimYours,
            ClaimRefusal::NoLink => BotText::ClaimNoLink,
            ClaimRefusal::Unavailable => BotText::ClaimUnavailable,
        };
    }

    /** @return array{reply_markup: array<string, mixed>} «بازگشت» to «سرویس‌های من». */
    private function back(): array
    {
        return ['reply_markup' => $this->buttons->backOnly(MenuHandler::subscriptionsCallback(1))];
    }
}
