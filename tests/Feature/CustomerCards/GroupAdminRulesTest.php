<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerCards;

use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Discounts\Services\PersonalDiscounts;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserActions;
use Tests\DatabaseTestCase;

/**
 * What the report group's admins may not do from a customer's card, whatever its buttons offer: their own account, an
 * agent's and another admin's own discount (their prices are the panel's to lower), an admin made of someone with their
 * own discount or in a «همکار» group (the role taken off and given back would go round it), and another admin's website
 * sign-in (it opens the website's admin side) — while a customer's are theirs to change. The panels are held to none of
 * it.
 */
final class GroupAdminRulesTest extends DatabaseTestCase
{
    public function testTheGroupSetsACustomersDiscountNeverAnAdminsAnAgentsOrTheirOwn(): void
    {
        $discounts = $this->service(PersonalDiscounts::class);
        $boss = $this->groupAdminActor();
        $ali = $this->customer(['telegram_id' => 7070]);

        self::assertSame(15, $discounts->set($ali, $boss, ['percent' => 15])->discount_percent);

        $refused = [
            ActorRefusedException::OWN_ACCOUNT => User::query()->where('username', 'boss')->sole(),
            ActorRefusedException::AGENT_ACCOUNT => $this->agent(overrides: ['telegram_id' => 9292]),
            ActorRefusedException::ADMIN_DISCOUNT => $this->admin(['telegram_id' => 9191]),
        ];
        foreach ($refused as $why => $user) {
            try {
                $discounts->set($user, $boss, ['percent' => 50]);
                self::fail("{$why}: set all the same");
            } catch (ActorRefusedException $e) {
                self::assertSame($why, $e->getMessage());
                self::assertSame(0, $user->refresh()->discount_percent);
            }
        }

        $admin = $this->admin(['telegram_id' => 9393]);
        self::assertSame(20, $discounts->set($admin, $this->panelActor(), ['percent' => 20])->discount_percent, 'the panel sets anyone\'s');
    }

    public function testNoOneWithTheirOwnDiscountOrAColleaguesPricesIsMadeAnAdminFromTheGroup(): void
    {
        $actions = $this->service(UserActions::class);
        $discounts = $this->service(PersonalDiscounts::class);
        $boss = $this->groupAdminActor();
        $sara = $this->admin(['telegram_id' => 9191]);
        $colleagues = $this->customerGroup('همکاران', [], ['agent_pricing' => true]);
        $promote = static fn() => $actions->setRole($sara, $boss, ['role' => UserRole::Admin->value]);

        // The way round the admins' rules — two admins can do it for each other: taken off, given a discount and the
        // agents' prices as a customer, then made an admin again.
        $actions->setRole($sara, $boss, ['role' => UserRole::Customer->value]);
        $discounts->set($sara, $boss, ['percent' => 50]);
        $actions->assignGroups($sara, $boss, [$colleagues->id]);
        self::assertRefused(ActorRefusedException::ADMIN_WITH_OWN_PRICES, $promote);
        self::assertSame(UserRole::Customer, $sara->refresh()->role);

        // Either alone keeps them from it.
        $discounts->set($sara, $boss, ['percent' => 0]);
        self::assertRefused(ActorRefusedException::ADMIN_WITH_OWN_PRICES, $promote, 'in a «همکار» group');
        $actions->assignGroups($sara, $boss, []);
        $discounts->set($sara, $boss, ['percent' => 10]);
        self::assertRefused(ActorRefusedException::ADMIN_WITH_OWN_PRICES, $promote, 'their own discount');
        self::assertSame(UserRole::Customer, $sara->refresh()->role);

        $discounts->set($sara, $boss, ['percent' => 0]);
        self::assertSame(UserRole::Admin, $promote()->role, 'neither: made an admin');

        $ali = $this->customer(['telegram_id' => 7070, 'discount_percent' => 20]);
        $actions->assignGroups($ali, $boss, [$colleagues->id]);
        self::assertSame(UserRole::Admin, $actions->setRole($ali, $this->panelActor(), ['role' => UserRole::Admin->value])->role, 'the panel makes anyone an admin');
        self::assertSame(UserRole::Admin, $actions->setRole($ali, $boss, ['role' => UserRole::Admin->value])->role, 'one already an admin stays one');
    }

    public function testTheGroupNeverAdjustsAnAgentsWallet(): void
    {
        $actions = $this->service(UserActions::class);
        $agent = $this->agent(overrides: ['telegram_id' => 9292]);

        try {
            $actions->adjustWallet($agent, $this->groupAdminActor(), ['type' => 'credit', 'amount' => 50000]);
            self::fail('credited all the same');
        } catch (ActorRefusedException $e) {
            self::assertSame(ActorRefusedException::AGENT_ACCOUNT, $e->getMessage());
        }
        self::assertSame('0.00', $agent->balance());

        $actions->adjustWallet($agent, $this->panelActor(), ['type' => 'credit', 'amount' => 50000]);
        self::assertSame('50000.00', $agent->balance(), 'the panel adjusts it');
    }

    public function testTheGroupNeverTouchesAnotherAdminsWebsiteSignIn(): void
    {
        $actions = $this->service(UserActions::class);
        $boss = $this->groupAdminActor();
        $admin = $this->admin(['telegram_id' => 9191]);

        foreach (['disableTwoFactor', 'endSessions'] as $action) {
            try {
                $actions->{$action}($admin, $boss);
                self::fail("{$action}: done all the same");
            } catch (ActorRefusedException $e) {
                self::assertSame(ActorRefusedException::ADMIN_ACCOUNT, $e->getMessage(), $action);
            }
        }

        $ali = $this->customer(['telegram_id' => 7070]);
        $sessions = $this->service(CustomerSessions::class);
        $this->customerSession($ali);
        self::assertSame(1, $sessions->count($ali));
        $actions->endSessions($ali, $boss);
        self::assertSame(0, $sessions->count($ali), 'a customer\'s devices are signed out from the group');
    }

    private static function assertRefused(string $why, \Closure $decision, string $message = ''): void
    {
        try {
            $decision();
            self::fail("{$message}: made all the same");
        } catch (ActorRefusedException $e) {
            self::assertSame($why, $e->getMessage(), $message);
        }
    }
}
