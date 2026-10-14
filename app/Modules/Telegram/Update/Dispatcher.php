<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Gates\Gate;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\UserResolver;
use Invoker\InvokerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Routes each update to its handler, as routes/bot.php registers them. Every update is served once: a copy Telegram
 * sends again is recognised by its id, and a chat sending faster than a person is ignored while it floods
 * (ReceivedUpdates). A group's update goes to the group routes only (no user, session or gates). A private chat's update
 * — whose chat must be its sender's — passes the ban check and the gates (a /start link a gate holds back waits in the
 * chat for the update that gets through), then takes the first route that matches:
 *   1. /command             (navigation)
 *   2. reply-keyboard label (navigation)
 *   3. contact card         (navigation)
 *   4. callback prefix      (longest prefix wins; navigation where registered so; an unknown one goes to the fallback)
 *   5. conversation state   (longest prefix: "topup" takes "topup.amount")
 *   6. text pattern         (a text message one matches — a link sent outside any step: navigation)
 *   7. fallback             (the menu: navigation)
 * Navigation leaves the flow in progress. An admin-only route answers anyone else nothing. A tap the handler left
 * unanswered is acknowledged afterwards, and a failure is reported on the button or, once the tap was answered, in a message.
 */
final class Dispatcher
{
    /** What a t.me link's `?start=` can carry (Telegram's deep-link payload); other text after /start was typed. */
    private const START_PAYLOAD = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @var list<class-string<Gate>> In the order they run. */
    private array $gates = [];

    /** @var array<string, Route> By the command's name, without its slash */
    private array $commands = [];

    /** @var list<array{labels: \Closure, route: Route}> The label lists are read per update: the admin edits them. */
    private array $labels = [];

    /** @var array<string, Route> By callback data prefix */
    private array $callbacks = [];

    /** @var array<string, Route> By conversation state prefix */
    private array $states = [];

    /** @var array<string, Route> By the regular expression a text message matches, in the order registered */
    private array $patterns = [];

    private ?Route $contact = null;

    private ?Route $fallback = null;

    /** @var array<string, class-string<GroupHandler>> A group's buttons, by callback data prefix */
    private array $groupCallbacks = [];

    /** @var array<string, class-string<GroupHandler>> A group's commands, by name */
    private array $groupCommands = [];

    /** @var class-string<GroupHandler>|null What else a group sends */
    private ?string $group = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly InvokerInterface $invoker,
        private readonly BotApi $api,
        private readonly ReceivedUpdates $received,
        private readonly UserResolver $users,
        private readonly SessionStore $sessions,
        private readonly BotTexts $texts,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param class-string<Gate> $gate */
    public function gate(string $gate): self
    {
        $this->gates[] = $gate;

        return $this;
    }

    /**
     * @param class-string<Handler> $handler
     * @param bool $adminOnly For the bot's admins only: anyone else gets no answer at all
     */
    public function command(string $name, string $handler, bool $adminOnly = false): self
    {
        $this->commands[strtolower(ltrim($name, '/'))] = new Route($handler, navigation: true, adminOnly: $adminOnly);

        return $this;
    }

    /**
     * @param \Closure $labels Returns the reply keyboard's labels; called per update, its parameters injected
     * @param class-string<Handler> $handler
     */
    public function text(\Closure $labels, string $handler): self
    {
        $this->labels[] = ['labels' => $labels, 'route' => new Route($handler, navigation: true)];

        return $this;
    }

    /**
     * @param class-string<Handler> $handler
     * @param bool $navigation A tap on it leaves the flow in progress, as a command does (the menu's screens)
     * @param bool $adminOnly For the bot's admins only: anyone else gets no answer at all
     */
    public function callback(string $prefix, string $handler, bool $navigation = false, bool $adminOnly = false): self
    {
        $this->callbacks[$prefix] = new Route($handler, $navigation, $adminOnly);

        return $this;
    }

    /**
     * @param class-string<Handler> $handler
     * @param bool $adminOnly A step of an admin's flow: anyone else's message there goes to the fallback
     */
    public function state(string $prefix, string $handler, bool $adminOnly = false): self
    {
        $this->states[$prefix] = new Route($handler, adminOnly: $adminOnly);

        return $this;
    }

    /**
     * A text message that matches `$regex` while the chat is at no step that takes it — a step's answer stays the step's.
     * It leaves the flow in progress, as the fallback it would otherwise reach does.
     *
     * @param class-string<Handler> $handler
     */
    public function pattern(string $regex, string $handler): self
    {
        $this->patterns[$regex] = new Route($handler, navigation: true);

        return $this;
    }

    /** @param class-string<Handler> $handler The handler of a shared contact card that passed the gates */
    public function contact(string $handler): self
    {
        $this->contact = new Route($handler, navigation: true);

        return $this;
    }

    /** @param class-string<Handler> $handler What no other route takes; it shows the menu, so it is navigation */
    public function fallback(string $handler): self
    {
        $this->fallback = new Route($handler, navigation: true);

        return $this;
    }

    /** @param class-string<GroupHandler> $handler A button pressed in a group, under a message of the bot's */
    public function groupCallback(string $prefix, string $handler): self
    {
        $this->groupCallbacks[$prefix] = $handler;

        return $this;
    }

    /** @param class-string<GroupHandler> $handler A command written in a group (to this bot or another: the handler tells) */
    public function groupCommand(string $name, string $handler): self
    {
        $this->groupCommands[strtolower(ltrim($name, '/'))] = $handler;

        return $this;
    }

    /** @param class-string<GroupHandler> $handler Every other group update: the bot's membership, the group's messages */
    public function group(string $handler): self
    {
        $this->group = $handler;

        return $this;
    }

    /** Serve one update, as the class comment describes; nothing it throws escapes (it is logged). */
    public function dispatch(Update $update): void
    {
        $chatId = $update->chatId();
        if ($chatId === null) {
            return;
        }

        // Customers are served in private chats; a group's update is the group handler's alone, a channel's nobody's.
        if ($update->isGroupChat()) {
            $this->serveGroup($update);

            return;
        }
        // A private chat's id is its person's: an update saying otherwise is not Telegram's (an agent's webhook can be
        // posted anything) and would act on someone else's chat.
        if (!$update->isPrivateChat() || $update->fromId() !== $chatId) {
            return;
        }

        $ctx = null;
        try {
            if (!$this->claim($update)) {
                return;
            }

            $user = $this->users->resolve($update);
            if ($user === null) {
                return;
            }

            // The customer blocked or unblocked the bot: remember it, there is nothing to answer.
            if ($update->type() === 'my_chat_member') {
                $this->users->recordMembership($user, $update);

                return;
            }

            $ctx = new Context($update, $this->api, $user, $this->sessions->load($chatId));
            $this->serve($ctx);
            $ctx->session->save();
            // A tap nobody answered still needs its spinner stopped.
            $ctx->answer();
        } catch (\Throwable $e) {
            $this->logger->error('Telegram update {id} failed: {message}', [
                'id' => $update->id(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->reportFailure($update, $chatId, $ctx);
        }
    }

    /**
     * Serve the message as one sent at no step, the flow left: a step that finds it is not its own (a ticket the chat left
     * a while ago) hands it to the text pattern it matches, or the fallback — the one list of them.
     */
    public function atNoStep(Context $ctx): void
    {
        $ctx->session->clear();
        $route = $this->route($ctx->update, null, $ctx->isAdmin());
        if ($route !== null) {
            $this->container->get($route->handler)->handle($ctx);
        }
    }

    /** A group's update, to its route, once; whatever fails there is logged — nobody in the group is told. */
    private function serveGroup(Update $update): void
    {
        try {
            if (!$this->claim($update)) {
                return;
            }

            $handler = match (true) {
                $update->isCallback() => self::longestPrefix($this->groupCallbacks, (string) $update->callbackData()),
                $update->isCommand() => $this->groupCommands[(string) $update->command()] ?? null,
                default => $this->group,
            };
            if ($handler !== null) {
                $this->container->get($handler)->handle($update);
            } elseif ($update->isCallback()) {
                // A button no route takes: its spinner stops all the same.
                $this->api->answerCallbackQuery((string) $update->callbackId());
            }
        } catch (\Throwable $e) {
            $this->logger->error('Group update {id} failed: {message}', ['id' => $update->id(), 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /** Whether to serve the update: not a copy of one served already, nor from a chat flooding the bot (logged once). */
    private function claim(Update $update): bool
    {
        if (!$this->received->claim($update)) {
            $this->logger->info('Telegram sent update {id} again; it was served once already', ['id' => $update->id()]);

            return false;
        }

        $lately = $this->received->lately($update);
        if ($lately > ReceivedUpdates::FLOOD_UPDATES) {
            if ($lately === ReceivedUpdates::FLOOD_UPDATES + 1) {
                $this->logger->warning('Chat {chat} sent over {limit} updates in {seconds} seconds: what it sends is left unanswered while it keeps on', [
                    'chat' => $update->chatId(),
                    'limit' => ReceivedUpdates::FLOOD_UPDATES,
                    'seconds' => ReceivedUpdates::FLOOD_SECONDS,
                ]);
            }

            return false;
        }

        return true;
    }

    /** Ban check, gates, then the handler the update routes to. */
    private function serve(Context $ctx): void
    {
        if ($ctx->user->isBanned()) {
            if ($ctx->update->isCallback()) {
                $ctx->answer($this->texts->get(BotText::Banned), alert: true);
            } else {
                $ctx->reply($this->texts->message(BotText::Banned));
            }

            return;
        }

        foreach ($this->gates as $gate) {
            if (!$this->container->get($gate)->pass($ctx)) {
                self::keepStartLink($ctx);

                return;
            }
        }

        $route = $this->route($ctx->update, $ctx->session->state(), $ctx->isAdmin());
        if ($route === null) {
            return;
        }

        if ($route->navigation) {
            $ctx->session->clear();
        }

        $this->container->get($route->handler)->handle($ctx);
    }

    /**
     * A /start link a gate held back (`?start=usertest` before the phone was in) is kept in the chat, so it still opens
     * once the update that gets through lands on the menu (StartHandler, JoinHandler). Only what a link can carry is
     * kept (START_PAYLOAD).
     */
    private static function keepStartLink(Context $ctx): void
    {
        $link = $ctx->update->command() === 'start' ? (string) $ctx->update->commandArgument() : '';
        if (preg_match(self::START_PAYLOAD, $link) === 1) {
            $ctx->session->keepStartLink($link);
        }
    }

    /**
     * Tell the customer something broke: on the button while the tap is unanswered, else in a message. A failure while
     * telling is only logged.
     */
    private function reportFailure(Update $update, int $chatId, ?Context $ctx): void
    {
        try {
            // The admin's wording when it can be read; the failure may be the settings table's own.
            try {
                $text = $this->texts->get(BotText::Error);
            } catch (\Throwable) {
                $text = BotText::Error->spec()->default;
            }

            $tap = $update->callbackId();
            if ($tap !== null && !($ctx?->answered() ?? false) && $this->api->answerCallbackQuery($tap, $text, true)) {
                return;
            }

            $this->api->sendText($chatId, $text);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not report a failure to chat {chat}: {message}', ['chat' => $chatId, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The route the update takes, in the class comment's order; null for an admin-only command or button pressed by
     * anyone else. A step of an admin flow left by someone who is no admin any more goes to the fallback.
     */
    private function route(Update $update, ?string $state, bool $admin): ?Route
    {
        $allowed = static fn(?Route $route): ?Route => $route !== null && $route->adminOnly && !$admin ? null : $route;

        if ($update->isCommand()) {
            $command = $this->commands[(string) $update->command()] ?? null;

            return $command === null ? $this->fallback : $allowed($command);
        }

        if ($update->isMessage()) {
            $label = $this->labelRoute(trim((string) $update->text()));
            if ($label !== null) {
                return $label;
            }
            if ($update->contact() !== null && $this->contact !== null) {
                return $this->contact;
            }
        }

        if ($update->isCallback()) {
            // An unknown button is from a screen long gone, never an answer to the step the chat is at.
            $button = self::longestPrefix($this->callbacks, (string) $update->callbackData());

            return $button === null ? $this->fallback : $allowed($button);
        }

        if ($state !== null) {
            $step = $allowed(self::longestPrefix($this->states, $state));
            if ($step !== null) {
                return $step;
            }
        }

        if ($update->isMessage()) {
            // A picture's caption is its words.
            $text = (string) ($update->text() ?? $update->caption());
            foreach ($this->patterns as $regex => $route) {
                if (preg_match($regex, $text) === 1) {
                    return $route;
                }
            }
        }

        return $this->fallback;
    }

    /** The route of the reply-keyboard button whose label the message is, if it is one. */
    private function labelRoute(string $text): ?Route
    {
        if ($text === '') {
            return null;
        }

        foreach ($this->labels as ['labels' => $labels, 'route' => $route]) {
            foreach ($this->invoker->call($labels) as $label) {
                if (trim((string) $label) === $text) {
                    return $route;
                }
            }
        }

        return null;
    }

    /**
     * @template T
     * @param array<string, T> $routes
     * @return T|null
     */
    private static function longestPrefix(array $routes, string $subject): mixed
    {
        $best = null;
        $bestLength = -1;

        foreach ($routes as $prefix => $route) {
            if (str_starts_with($subject, $prefix) && strlen($prefix) > $bestLength) {
                $best = $route;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }
}
