<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;

/**
 * The checkouts a step under them draws again — a discount code (DiscountCodeHandler) or a client name
 * (ClientNameHandler) chosen there —, each named by its own callback data (`plan:…`, `renew:…`, `extra:…`, `custom:…`),
 * whose handler draws it (RedrawableCheckout). A step that awaits something typed for one (ask()) keeps that
 * checkout, and typed() answers it the same way for both: the checkout drawn again, or the refusal with the way back.
 */
final class Checkouts
{
    /** The scratch's checkout while a step under it awaits what is typed. */
    private const AT = 'at';

    public function __construct(
        private readonly PurchaseHandler $purchases,
        private readonly RenewalHandler $renewals,
        private readonly ExtrasHandler $extras,
        private readonly CustomServiceHandler $custom,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** Whether `$checkout` is a checkout a handler draws. */
    public function knows(string $checkout): bool
    {
        return $this->target($checkout) !== null;
    }

    /**
     * The server the purchase whose checkout `$checkout` is makes its service on (RedrawableCheckout::serverOf()); null
     * for a checkout of no purchase, one no handler draws, or a server gone.
     */
    public function server(string $checkout): ?Server
    {
        $target = $this->target($checkout);
        $id = $target === null ? null : $target[0]->serverOf($target[1]);

        return $id === null ? null : Server::query()->find($id);
    }

    /**
     * Draw the checkout `$checkout` again — with `$code` just typed, else with the code kept for it; a checkout no
     * handler draws is any button the bot does not understand.
     *
     * @throws DiscountCodeRefusedException when `$code` does not hold
     */
    public function draw(Context $ctx, string $checkout, ?string $code = null): void
    {
        $target = $this->target($checkout);
        if ($target === null) {
            $ctx->edit($this->texts->get(BotText::Unknown));

            return;
        }
        [$handler, $args] = $target;
        $handler->drawCheckout($ctx, $args, $code);
    }

    /**
     * A step under the checkout `$checkout` awaiting what the customer types (state `$state`): `$prompt` in the
     * checkout's place, «بازگشت» to it.
     */
    public function ask(Context $ctx, string $state, string $checkout, BotText $prompt): void
    {
        $ctx->session->enter($state, [self::AT => $checkout]);
        $ctx->edit($this->texts->get($prompt), ['reply_markup' => $this->buttons->backOnly($checkout)]);
    }

    /**
     * What the customer typed at a step ask() began: `$take` takes it (trimmed) for the step's checkout, drawing the
     * checkout again (draw()) — or refuses it, its words said with `$refused`, «بازگشت» to the checkout, another awaited.
     * A step whose checkout no handler draws any more ends, as any message the bot does not understand.
     *
     * @param \Closure(string $checkout, string $typed): void $take
     */
    public function typed(Context $ctx, BotText $refused, \Closure $take): void
    {
        $checkout = (string) $ctx->session->get(self::AT, '');
        if (!$this->knows($checkout)) {
            $ctx->session->clear();
            $ctx->reply($this->texts->get(BotText::Unknown));

            return;
        }

        try {
            $take($checkout, trim((string) $ctx->update->text()));
        } catch (DomainRuleException $e) {
            $ctx->reply($this->texts->render($refused, ['reason' => $e->getMessage()]), ['reply_markup' => $this->buttons->backOnly($checkout)]);
        }
    }

    /**
     * The handler that draws the checkout `$checkout`, and its callback arguments under the handler's prefix; null for none.
     *
     * @return array{RedrawableCheckout, list<string>}|null
     */
    private function target(string $checkout): ?array
    {
        $handlers = [
            PurchaseHandler::PLAN => $this->purchases,
            RenewalHandler::PREFIX => $this->renewals,
            CustomServiceHandler::PREFIX => $this->custom,
            ExtrasHandler::PREFIX => $this->extras,
        ];
        foreach ($handlers as $prefix => $handler) {
            $args = CallbackData::args($checkout, $prefix);
            if ($args !== null && $args !== []) {
                return [$handler, $args];
            }
        }

        return null;
    }
}
