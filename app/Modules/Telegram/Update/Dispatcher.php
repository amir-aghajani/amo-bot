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
 * Routes an incoming Update to a Handler — each update once: one Telegram sends again is recognised by its id
 * (ReceivedUpdates) and left alone, and so is one of a chat flooding the bot (faster than a person: its updates go
 * unanswered while it keeps on). Customers are served in private chats only — a private chat is its sender's own, and
 * an update saying otherwise is no update of Telegram's, left alone —: an update from a group or supergroup
 * (the admins' report group) goes to the group's routes — a button by its callback data's longest prefix (one no route
 * takes is acknowledged), a command by its name (one no route takes is nobody's), anything else to the group handler —
 * and no further, without a user, a session or the gates. A private chat's update first passes the gates (the bot's
 * master switch, phone verification, required channels — a gate that holds an update back answers it itself), then
 * goes to the first route that takes it:
 *   1. /command             (navigation: the flow in progress is left)
 *   2. keyboard label       (exact text of a reply-keyboard button; navigation like a command)
 *   3. contact card         (a shared phone number; navigation like a command)
 *   4. callback data prefix (longest registered prefix wins, e.g. "plan:" for "plan:42"; navigation where routes/bot.php
 *                            says so — the menu's `menu:*`; a tap no prefix takes goes to the fallback)
 *   5. conversation state   (a message, by the longest registered prefix again: "topup" takes the state "topup.amount")
 *   6. fallback             (the menu again, so navigation too)
 * A command or button only the bot's admins reach answers anyone else nothing at all (a step of an admin's flow left
 * behind by an admin who is one no more goes to the fallback). A tap the handler left unanswered is acknowledged once
 * it is done, and a handler that fails is reported to the customer — on the button while the tap is unanswered, in a
 * message once it was.
 *
 * Gates and handlers are registered in routes/bot.php and resolved from the container lazily.
 */
final class Dispatcher
{
    /** @var list<class-string<Gate>> In the order they run. */
    private array $gates = [];

    /** @var array<string, Route> By the command's name, without its slash */
    private array $commands = [];

    /** @var list<array{labels: \Closure, route: Route}> The label lists are asked per update (the admin edits them). */
    private array $labels = [];

    /** @var array<string, Route> By callback data prefix */
    private array $callbacks = [];

    /** @var array<string, Route> By conversation state prefix */
    private array $states = [];

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
     * @param \Closure $labels Returns the exact message texts — the reply keyboard's buttons — and is called per
     *                         update with its parameters injected from the container, since the admin edits them
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

    /** @param class-string<Handler> $handler The handler for a shared contact card (the phone gate's answer, once it passed) */
    public function contact(string $handler): self
    {
        $this->contact = new Route($handler, navigation: true);

        return $this;
    }

    /**
     * @param class-string<Handler> $handler What nothing else takes — an unknown command or text, a button from a
     *                                       screen long gone; it shows the menu, so it is navigation
     */
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

    /** @param class-string<GroupHandler> $handler A command written in a group — to this bot or to another: the handler tells */
    public function groupCommand(string $name, string $handler): self
    {
        $this->groupCommands[strtolower(ltrim($name, '/'))] = $handler;

        return $this;
    }

    /** @param class-string<GroupHandler> $handler What else a group's or supergroup's updates go to — the bot's membership there, the group's messages */
    public function group(string $handler): self
    {
        $this->group = $handler;

        return $this;
    }

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
        // A private chat is its one person's: an update saying otherwise is none of Telegram's (an agent's webhook can be
        // posted anything), and would be served as a chat of someone else's.
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
            // A tap nothing answered — the handler's or a gate's work was the answer — still stops its spinner.
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

    /**
     * Whether the update is the bot's to serve: a copy of one it took already is left alone, and so is one of a chat
     * flooding the bot — said once, as the flood begins.
     */
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
     * Tell the customer something broke, once: on the tapped button while the tap is unanswered, in a message
     * otherwise — a message they sent, a tap answered before its handler failed, or one Telegram no longer takes an
     * answer for. A failure while telling is logged.
     */
    private function reportFailure(Update $update, int $chatId, ?Context $ctx): void
    {
        try {
            // The admin's wording when it can be read — the failure may be the settings table's own.
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
     * The route the update takes, in the order the class comment gives; null for none — an admin's command or button
     * pressed by anyone else (no answer at all), or no fallback. A step of an admin's flow the chat is still at, its
     * customer no admin any more, is not theirs either: the fallback takes the message, and the flow is left.
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
            // A button no route knows is from a screen long gone, never an answer to the step the chat is at.
            $button = self::longestPrefix($this->callbacks, (string) $update->callbackData());

            return $button === null ? $this->fallback : $allowed($button);
        }

        if ($state !== null) {
            $step = $allowed(self::longestPrefix($this->states, $state));
            if ($step !== null) {
                return $step;
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
