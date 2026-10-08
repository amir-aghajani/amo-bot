<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

use App\Core\Config\Repository as Config;
use App\Core\Logging\Redact;
use App\Core\Support\Sleeper;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Thin client for the Telegram Bot API: the methods the shop calls, each a call() with its parameters, Telegram's
 * refusals as TelegramApiException (its refusal() says what kind).
 *
 * It speaks as the current bot (CurrentBot): the main one with the token of config.php, an agent's with the token they
 * handed over — so the same services serve every bot's shop. forToken() speaks with a token no bot has yet (an agent's,
 * checked before it is kept).
 *
 * Answering a press and deleting a message are courtesies: they say whether they went through and never throw. An edit
 * to what a message shows already is done, not refused.
 *
 * @see https://core.telegram.org/bots/api
 */
final class BotApi
{
    /** The option a message takes when the links in it should not unfold into a preview (a subscription link, a report). */
    public const NO_LINK_PREVIEW = ['link_preview_options' => ['is_disabled' => true]];

    /** Longest 429 retry_after (seconds) worth sleeping through instead of failing the call — once a call, then it is asked again. */
    public const MAX_FLOOD_WAIT = 5;

    /** The largest file fetched (fileBytes()): the Bot API's own limit — a Bot API server of one's own hands over far larger. */
    public const MAX_DOWNLOAD = 20 * 1024 * 1024;

    /**
     * Seconds a long poll's answer may be read after its own timeout (getUpdatesAsync()). Telegram answers within the
     * timeout; but curl counts a transfer's time from its start and checks it before it reads, and the poller moves its
     * polls only between batches — one held up meanwhile (a batch waiting on a panel) would be failed for "0 bytes" with
     * its answer waiting unread. Only a connection that never answers is given up on.
     */
    public const LONG_POLL_SLACK = 60;

    private readonly string $apiUrl;

    /** A token this client speaks with whatever the current bot (forToken()). */
    private ?string $fixedToken = null;

    /**
     * @param \Closure(): string $mainToken The main bot's token, read when a call is made rather than copied into the
     *                                      client (the configuration's — fromConfig() —, which the settings screen rewrites)
     * @param Sleeper $sleeper How a flood wait is waited out; tests hand in one that does not
     * @param PremiumEmojiStatus|null $premiumEmoji What the current bot last found about its premium emoji; without
     *                                              it, a message Telegram turned down over one is still sent plain, and nothing is remembered
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly \Closure $mainToken,
        string $apiUrl,
        private readonly Sleeper $sleeper,
        private readonly ?PremiumEmojiStatus $premiumEmoji = null,
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    /** The shop's client: the main bot's token is the configuration's (config.php's TELEGRAM_BOT_TOKEN). */
    public static function fromConfig(ClientInterface $http, Config $config, Sleeper $sleeper, PremiumEmojiStatus $premiumEmoji): self
    {
        return new self($http, static fn(): string => (string) $config->get('telegram.token', ''), (string) $config->get('telegram.api_url'), $sleeper, $premiumEmoji);
    }

    /**
     * The longest one call can take, in whole seconds, when a request may take `$timeout` (the outgoing timeout): its
     * request, a flood wait honoured (MAX_FLOOD_WAIT), and the request again — what a hold on work that calls Telegram is
     * sized by, so nobody takes it over while it still goes on (a report being sent, a topic being made).
     */
    public static function longestCall(float $timeout): int
    {
        return 2 * (int) ceil($timeout) + self::MAX_FLOOD_WAIT;
    }

    /** A client that speaks with this token — one no bot has yet (an agent's, asked who it is before it is kept). */
    public function forToken(string $token): self
    {
        $client = new self($this->http, $this->mainToken, $this->apiUrl, $this->sleeper);
        $client->fixedToken = $token;

        return $client;
    }

    /** The same client over another HTTP client — the poller's, whose requests it drives itself (getUpdatesAsync()). */
    public function withHttp(ClientInterface $http): self
    {
        return new self($http, $this->mainToken, $this->apiUrl, $this->sleeper, $this->premiumEmoji);
    }

    public function hasToken(): bool
    {
        return $this->token() !== '';
    }

    /** The bot's own user id — the number in front of the ":" of its token; null without a token. */
    public function botId(): ?int
    {
        return BotToken::botId($this->token());
    }

    /** @return array<string, mixed> The bot itself, as Telegram describes it: id, username, first_name, can_join_groups… */
    public function getMe(): array
    {
        return (array) $this->call('getMe');
    }

    /**
     * Who the bot is (getMe): its id, @username without the "@" ('' without one) and the name Telegram shows for it.
     *
     * @return array{id: int, username: string, name: string}
     */
    public function identity(): array
    {
        $me = $this->getMe();

        return [
            'id' => (int) ($me['id'] ?? 0),
            'username' => (string) ($me['username'] ?? ''),
            'name' => mb_substr(trim((string) ($me['first_name'] ?? '')), 0, Limits::TITLE),
        ];
    }

    /**
     * @param int|string $chatId A chat id or "@username"
     * @return array<string, mixed> The Chat object (id, type, title, username?, invite_link? …)
     */
    public function getChat(int|string $chatId): array
    {
        return (array) $this->call('getChat', ['chat_id' => $chatId]);
    }

    /**
     * @return array<string, mixed> The ChatMember object; `status` is creator/administrator/member/restricted/left/kicked
     */
    public function getChatMember(int|string $chatId, int $userId): array
    {
        return (array) $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    /** A fresh primary invite link for a chat the bot administers (needs the "invite users" right). */
    public function exportChatInviteLink(int|string $chatId): string
    {
        return (string) $this->call('exportChatInviteLink', ['chat_id' => $chatId]);
    }

    /**
     * A message in Telegram HTML, like every text the bot words.
     *
     * @param array<string, mixed> $options Any sendMessage parameter (reply_markup, reply_parameters, ...)
     * @return array<string, mixed> The message sent
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        return (array) $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'] + $options);
    }

    /**
     * A message shown exactly as written — no markup read in it: words that are not the bot's own HTML (a report
     * Telegram could not parse, what someone typed).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> The message sent
     */
    public function sendText(int|string $chatId, string $text, array $options = []): array
    {
        return (array) $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text] + $options);
    }

    /** @param array<string, mixed> $options */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $options = []): void
    {
        $this->edit('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'] + $options);
    }

    /**
     * Replace only the buttons under a message; its text or media stays. An empty keyboard takes them off.
     *
     * @param array<string, mixed> $replyMarkup
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, array $replyMarkup): void
    {
        $this->edit('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => $replyMarkup]);
    }

    /**
     * A picture made on the fly in place of a message's content: a photo message gets the new picture, and a
     * text message becomes a photo one (Bot API 10.3). `caption` (HTML like every text) goes with the
     * picture, the other options (reply_markup) on the message — the same options sendPhoto() takes.
     *
     * @param array<string, mixed> $options caption, reply_markup
     */
    public function editMessageMedia(int|string $chatId, int $messageId, string $bytes, string $filename, array $options = []): void
    {
        $media = ['type' => 'photo', 'media' => 'attach://photo', 'parse_mode' => 'HTML'];
        if (array_key_exists('caption', $options)) {
            $media['caption'] = $options['caption'];
            unset($options['caption']);
        }

        $this->edit('editMessageMedia', ['chat_id' => $chatId, 'message_id' => $messageId, 'media' => $media] + $options, ['photo' => [$bytes, $filename]]);
    }

    /** Take a message off a chat; false when Telegram would not (older than 48 hours, gone already) — never thrown. */
    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        try {
            $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);

            return true;
        } catch (TelegramApiException) {
            return false;
        }
    }

    /**
     * Answer a press: the button's spinner stops, with a toast — or a popup (`$alert`) — cut to what Telegram shows.
     * False when Telegram would not take it (a press too old to answer, Telegram out of reach) — never thrown: what
     * came of the press shows in the chat anyway.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $alert = false): bool
    {
        try {
            $this->call('answerCallbackQuery', [
                'callback_query_id' => $callbackQueryId,
                'text' => $text === null ? null : Limits::cut($text, Limits::POPUP),
                'show_alert' => $alert,
            ]);

            return true;
        } catch (TelegramApiException) {
            return false;
        }
    }

    /**
     * The bytes of a file the bot can see (a receipt a customer sent, a sticker), by its file id; null when Telegram
     * offers no download of it, or says it is larger than `$maxBytes` — its size as Telegram knows it, never as an
     * update says (an update posted to an agent's own webhook says what its sender likes): nothing larger is held in
     * memory.
     *
     * @throws TelegramApiException
     */
    public function fileBytes(string $fileId, int $maxBytes = self::MAX_DOWNLOAD): ?string
    {
        $file = $this->getFile($fileId);
        $path = (string) ($file['file_path'] ?? '');

        return $path === '' || (int) ($file['file_size'] ?? 0) > $maxBytes ? null : $this->downloadFile($path);
    }

    /** @return array<string, mixed> The File object; `file_path` is what downloadFile() takes (valid for about an hour). */
    private function getFile(string $fileId): array
    {
        return (array) $this->call('getFile', ['file_id' => $fileId]);
    }

    /**
     * The bytes behind a path getFile() gave.
     *
     * @throws TelegramApiException with no error code: the download is not an API call Telegram describes
     */
    private function downloadFile(string $filePath): string
    {
        $token = $this->requireToken();

        try {
            $response = $this->http->request('GET', "{$this->apiUrl}/file/bot{$token}/" . ltrim($filePath, '/'), ['http_errors' => false]);
        } catch (GuzzleException $e) {
            throw new TelegramApiException('Telegram file download failed: ' . Redact::text($e->getMessage()), 0, [], $e);
        }
        if ($response->getStatusCode() !== 200) {
            throw new TelegramApiException("Telegram file download failed (HTTP {$response->getStatusCode()}).");
        }

        return (string) $response->getBody();
    }

    /**
     * Send a copy of any message (text, media, formatting) to another chat; returns the new message id.
     *
     * @param array<string, mixed> $options Any copyMessage parameter (message_thread_id, caption + parse_mode, reply_parameters…)
     */
    public function copyMessage(int|string $chatId, int|string $fromChatId, int $messageId, array $options = []): int
    {
        $result = (array) $this->call('copyMessage', ['chat_id' => $chatId, 'from_chat_id' => $fromChatId, 'message_id' => $messageId] + $options);

        return (int) ($result['message_id'] ?? 0);
    }

    /**
     * Forward a message as it is — "Forwarded from" its first sender (a channel post the admin forwarded keeps its
     * channel). A forward takes no buttons of its own. Returns the new message's id.
     */
    public function forwardMessage(int|string $chatId, int|string $fromChatId, int $messageId): int
    {
        $result = (array) $this->call('forwardMessage', ['chat_id' => $chatId, 'from_chat_id' => $fromChatId, 'message_id' => $messageId]);

        return (int) ($result['message_id'] ?? 0);
    }

    /** Pin a message in a chat — quietly: the customer gets no second notification for the pin. */
    public function pinChatMessage(int|string $chatId, int $messageId): void
    {
        $this->call('pinChatMessage', ['chat_id' => $chatId, 'message_id' => $messageId, 'disable_notification' => true]);
    }

    /** Unpin that one message (and no other pinned there). */
    public function unpinChatMessage(int|string $chatId, int $messageId): void
    {
        $this->call('unpinChatMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    /**
     * A topic in a forum supergroup (the bot needs the "manage topics" right there): its name (Limits::TITLE characters
     * at most), the icon colour — one of the six Telegram allows — and optionally a custom emoji icon from
     * getForumTopicIconStickers().
     *
     * @return array<string, mixed> The ForumTopic: message_thread_id, name, icon_color, icon_custom_emoji_id?
     */
    public function createForumTopic(int|string $chatId, string $name, int $iconColor, ?string $iconCustomEmojiId = null): array
    {
        return (array) $this->call('createForumTopic', [
            'chat_id' => $chatId,
            'name' => $name,
            'icon_color' => $iconColor,
            'icon_custom_emoji_id' => $iconCustomEmojiId,
        ]);
    }

    /** Open a closed topic again (the "manage topics" right, unless the bot made it). */
    public function reopenForumTopic(int|string $chatId, int $messageThreadId): void
    {
        $this->call('reopenForumTopic', ['chat_id' => $chatId, 'message_thread_id' => $messageThreadId]);
    }

    /**
     * Premium (custom) emoji by their ids — at most Limits::CUSTOM_EMOJI_IDS at once.
     *
     * @param list<string> $ids
     * @return list<array<string, mixed>> Their Sticker objects (`custom_emoji_id`, `emoji`, `thumbnail`, …)
     */
    public function getCustomEmojiStickers(array $ids): array
    {
        return self::stickers($this->call('getCustomEmojiStickers', ['custom_emoji_ids' => array_values($ids)]));
    }

    /** @return list<array<string, mixed>> The custom emoji stickers any topic may take as its icon (`emoji`, `custom_emoji_id`, …). */
    public function getForumTopicIconStickers(): array
    {
        return self::stickers($this->call('getForumTopicIconStickers'));
    }

    /**
     * A picture made on the fly (bytes, not a file on disk), uploaded as multipart; the caption is HTML
     * like every text.
     *
     * @param array<string, mixed> $options sendPhoto options (caption, reply_markup, reply_parameters…)
     * @return array<string, mixed> The message sent
     */
    public function sendPhoto(int|string $chatId, string $bytes, string $filename, array $options = []): array
    {
        return (array) $this->call('sendPhoto', ['chat_id' => $chatId, 'parse_mode' => 'HTML'] + $options, ['photo' => [$bytes, $filename]]);
    }

    /**
     * A photo Telegram keeps, by its file id — one the bot was sent (a picture a bot admin answered a ticket with) —,
     * nothing uploaded; the caption is HTML like every text.
     *
     * @param array<string, mixed> $options sendPhoto options (caption, reply_markup…)
     * @return array<string, mixed> The message sent
     */
    public function sendPhotoById(int|string $chatId, string $fileId, array $options = []): array
    {
        return (array) $this->call('sendPhoto', ['chat_id' => $chatId, 'photo' => $fileId, 'parse_mode' => 'HTML'] + $options);
    }

    /**
     * getUpdates without waiting for it: the poller keeps one long poll open per bot and serves whichever answers. The
     * promise is fulfilled with the updates, or rejected with a TelegramApiException — given up on LONG_POLL_SLACK
     * seconds after the poll's own timeout.
     *
     * @param list<string> $allowedUpdates
     * @return PromiseInterface Fulfilled with list<array<string, mixed>>
     */
    public function getUpdatesAsync(int $offset = 0, int $timeout = 30, array $allowedUpdates = []): PromiseInterface
    {
        $token = $this->requireToken();
        $options = [
            'form_params' => self::encode(['offset' => $offset, 'timeout' => $timeout, 'limit' => 100, 'allowed_updates' => $allowedUpdates]),
            'timeout' => $timeout + self::LONG_POLL_SLACK,
            'http_errors' => false,
        ];

        return $this->http->requestAsync('POST', "{$this->apiUrl}/bot{$token}/getUpdates", $options)->then(
            static fn(ResponseInterface $response): array => array_values((array) self::unwrap('getUpdates', self::decode('getUpdates', $response))),
            static function (mixed $reason): never {
                throw $reason instanceof TelegramApiException
                    ? $reason
                    : new TelegramApiException('Telegram request getUpdates failed: ' . ($reason instanceof \Throwable ? Redact::text($reason->getMessage()) : 'unknown'), 0, [], $reason instanceof \Throwable ? $reason : null);
            },
        );
    }

    /**
     * @param list<string> $allowedUpdates
     * @param int|null $maxConnections The most calls Telegram makes to the webhook at once; null for its default (40)
     */
    public function setWebhook(string $url, ?string $secretToken = null, array $allowedUpdates = [], bool $dropPending = false, ?int $maxConnections = null): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => $allowedUpdates,
            'drop_pending_updates' => $dropPending,
            'max_connections' => $maxConnections,
        ]);
    }

    public function deleteWebhook(bool $dropPending = false): void
    {
        $this->call('deleteWebhook', ['drop_pending_updates' => $dropPending]);
    }

    /** @return array<string, mixed> */
    public function getWebhookInfo(): array
    {
        return (array) $this->call('getWebhookInfo');
    }

    /**
     * One Bot API method. Array parameters (reply_markup, entities, ...) are JSON encoded; with files the request goes
     * multipart, the fields encoded the same way. A short flood wait (429) is honoured once.
     *
     * A message with premium emoji (`<tg-emoji>`, or a button's `icon_custom_emoji_id`) Telegram turns down over them —
     * a bot may use them only while its owner has Telegram Premium — goes again with the plain emoji each stands for
     * and its buttons without icons (a customer never loses a message to an emoji), and the bot remembers the "no"
     * (PremiumEmojiStatus): while it is fresh, messages go plain from the start.
     *
     * @param array<string, mixed> $params
     * @param array<string, array{string, string}> $files Upload parts: field name => [bytes, filename]
     * @throws TelegramApiException
     */
    private function call(string $method, array $params = [], array $files = []): mixed
    {
        $plain = self::withoutPremiumEmoji($params);
        if ($plain !== null && $this->premiumEmoji?->refusedLately() === true) {
            return $this->request($method, $plain, $files);
        }

        try {
            return $this->request($method, $params, $files);
        } catch (TelegramApiException $e) {
            if ($plain === null || !$e->is(Refusal::CustomEmoji)) {
                throw $e;
            }

            $result = $this->request($method, $plain, $files);
            $this->premiumEmoji?->record(false);

            return $result;
        }
    }

    /**
     * An edit to what the message shows already is done, not refused.
     *
     * @param array<string, mixed> $params
     * @param array<string, array{string, string}> $files
     */
    private function edit(string $method, array $params, array $files = []): void
    {
        try {
            $this->call($method, $params, $files);
        } catch (TelegramApiException $e) {
            if (!$e->is(Refusal::NotModified)) {
                throw $e;
            }
        }
    }

    /**
     * The parameters with every premium emoji turned into the plain one it stands for — a message's text or caption, an
     * edited picture's caption — and the icons taken off its buttons (the label stays: a reply button's tap sends only
     * that); null when they carry none.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private static function withoutPremiumEmoji(array $params): ?array
    {
        $changed = false;
        $plain = static function (mixed $value) use (&$changed): mixed {
            if (!is_string($value) || stripos($value, '<tg-emoji') === false) {
                return $value;
            }
            $changed = true;

            return (string) preg_replace('~<tg-emoji\b[^>]*>(.*?)</tg-emoji\s*>~is', '$1', $value);
        };

        foreach (['text', 'caption'] as $key) {
            if (array_key_exists($key, $params)) {
                $params[$key] = $plain($params[$key]);
            }
        }
        if (isset($params['media']) && is_array($params['media']) && array_key_exists('caption', $params['media'])) {
            $params['media']['caption'] = $plain($params['media']['caption']);
        }
        if (isset($params['reply_markup']) && is_array($params['reply_markup'])) {
            foreach (['inline_keyboard', 'keyboard'] as $kind) {
                foreach (is_array($params['reply_markup'][$kind] ?? null) ? $params['reply_markup'][$kind] : [] as $r => $row) {
                    foreach (is_array($row) ? $row : [] as $b => $button) {
                        if (is_array($button) && array_key_exists('icon_custom_emoji_id', $button)) {
                            unset($params['reply_markup'][$kind][$r][$b]['icon_custom_emoji_id']);
                            $changed = true;
                        }
                    }
                }
            }
        }

        return $changed ? $params : null;
    }

    /**
     * One request as asked, with its short flood wait.
     *
     * @param array<string, mixed> $params
     * @param array<string, array{string, string}> $files
     * @throws TelegramApiException
     */
    private function request(string $method, array $params, array $files): mixed
    {
        $token = $this->requireToken();
        $fields = self::encode($params);

        if ($files === []) {
            $options = ['form_params' => $fields];
        } else {
            $multipart = [];
            foreach ($fields as $name => $value) {
                $multipart[] = ['name' => $name, 'contents' => $value];
            }
            foreach ($files as $name => [$bytes, $filename]) {
                $multipart[] = ['name' => $name, 'contents' => $bytes, 'filename' => $filename];
            }
            $options = ['multipart' => $multipart];
        }

        $url = "{$this->apiUrl}/bot{$token}/{$method}";
        $body = $this->send($method, $url, $options);

        // Flood control: Telegram tells us how long to wait; honour it once for short waits.
        if (!($body['ok'] ?? false) && (int) ($body['error_code'] ?? 0) === 429) {
            $retryAfter = (int) ($body['parameters']['retry_after'] ?? 0);
            if ($retryAfter > 0 && $retryAfter <= self::MAX_FLOOD_WAIT) {
                $this->sleeper->sleep($retryAfter);
                $body = $this->send($method, $url, $options);
            }
        }

        return self::unwrap($method, $body);
    }

    /**
     * Parameters as the Bot API takes them in a form or a multipart part: arrays and objects as JSON,
     * booleans as "true"/"false", null left out, the rest as text.
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private static function encode(array $params): array
    {
        $fields = [];
        foreach ($params as $key => $value) {
            $fields[$key] = match (true) {
                $value === null => null,
                is_array($value), is_object($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        return array_filter($fields, static fn(?string $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $options Guzzle request options
     * @return array<string, mixed> Decoded Bot API envelope
     * @throws TelegramApiException on transport failures or non-JSON replies
     */
    private function send(string $method, string $url, array $options): array
    {
        try {
            // Telegram describes failures in the JSON envelope (error_code/description); read it instead of throwing on 4xx.
            $response = $this->http->request('POST', $url, $options + ['http_errors' => false]);
        } catch (GuzzleException $e) {
            throw new TelegramApiException("Telegram request {$method} failed: " . Redact::text($e->getMessage()), 0, [], $e);
        }

        return self::decode($method, $response);
    }

    /**
     * @return array<string, mixed> The Bot API envelope an answer carries
     * @throws TelegramApiException when it carries none (a proxy's page, an outage)
     */
    private static function decode(string $method, ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? $body : throw new TelegramApiException("Telegram returned a non-JSON response for {$method} (HTTP {$response->getStatusCode()}).");
    }

    /**
     * The `result` of a Bot API envelope, or Telegram's refusal as an exception.
     *
     * @param array<string, mixed> $body
     * @throws TelegramApiException
     */
    private static function unwrap(string $method, array $body): mixed
    {
        if (!($body['ok'] ?? false)) {
            throw new TelegramApiException(
                (string) ($body['description'] ?? "Telegram method {$method} failed"),
                (int) ($body['error_code'] ?? 0),
                (array) ($body['parameters'] ?? []),
            );
        }

        return $body['result'] ?? null;
    }

    /** @return list<array<string, mixed>> The sticker objects of an answer that lists them. */
    private static function stickers(mixed $result): array
    {
        return is_array($result) && array_is_list($result) ? array_values(array_filter($result, is_array(...))) : [];
    }

    /** The token of the bot it speaks as: forToken()'s, else the current bot's. */
    private function token(): string
    {
        if ($this->fixedToken !== null) {
            return $this->fixedToken;
        }

        return CurrentBot::isMain() ? ($this->mainToken)() : (string) CurrentBot::get()->token;
    }

    /** @throws TelegramApiException without a token — nothing can be asked, and the URL would leak the absence. */
    private function requireToken(): string
    {
        $token = $this->token();

        return $token !== '' ? $token : throw new TelegramApiException('Telegram bot token is not configured.');
    }
}
