<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is signed in to work a shop, and the shop the request runs in: the owner (any shop the request names), an
 * agent (their bot's shop), or a shop's admin on its website (Store\Http\StaffMiddleware). The bot's management works
 * as the owner or an agent too, one of the shop's admins among them (a customer beside it: Telegram\Admin\AdminSeats).
 * The guard puts it on the request; its decisions are its actor()'s.
 */
final class Principal
{
    /** The request attribute a guard puts the principal under. */
    public const ATTRIBUTE = 'principal';

    public function __construct(
        public readonly PrincipalKind $kind,
        /** The name decisions are recorded under: the owner's login, the agent's "@bot", an admin's Reviewers::forAdmin(). */
        public readonly string $name,
        public readonly Bot $shop,
        /** A shop's admin's customer account — on its website, or in the bot's management —; null on a panel. */
        public readonly ?User $customer = null,
        /**
         * What a shop's admin may do on this request beyond the daily work (StaffGrant values): the website's grants while
         * the session's sign-in is recent — what a granted operation asks —, none otherwise; null on a panel and in the
         * bot's management, which hold every grant.
         *
         * @var list<string>|null
         */
        public readonly ?array $grants = null,
        /** Whether they work from the bot's private chat (Telegram\Admin): where their decisions are said to be made. */
        public readonly bool $inBot = false,
        /**
         * One of the main shop's admins working another shop from the bot: their account in the main shop, still their own
         * there — an agency, an agency request, are the main shop's rows (Telegram\Admin\AdminSeat::in()); null otherwise.
         */
        public readonly ?User $home = null,
    ) {}

    /** The principal a guard put on the request. */
    public static function of(ServerRequestInterface $request): self
    {
        $principal = $request->getAttribute(self::ATTRIBUTE);

        return $principal instanceof self ? $principal : throw new \LogicException('The request did not pass a principal\'s guard.');
    }

    /**
     * Whether this is the owner themselves — config.php's login, on the panel or by a Telegram account linked to it —,
     * not one of the main shop's admins working the bot's management with the owner's seat (a customer account beside it).
     */
    public function isTheOwner(): bool
    {
        return $this->kind === PrincipalKind::Owner && $this->customer === null;
    }

    /** The principal as the actor of its decisions. */
    public function actor(): Actor
    {
        return Actor::of($this);
    }
}
