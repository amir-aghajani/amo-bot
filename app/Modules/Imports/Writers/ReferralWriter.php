<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Modules\Imports\Links;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Referrals\Models\ReferralBonus;
use App\Modules\Referrals\Services\ReferralSettings;
use App\Modules\Users\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * The referrals stage, once every customer is in: who brought whom, as the other bot kept it — set on a customer the shop
 * knows no referrer of yet, never to themselves. Nothing is credited: the commissions and start gifts it earned stay the
 * other bot's. The start gift is marked decided with it (a ReferralBonus of zeros, as a withheld one is), so the
 * customer's first purchase here pays none to either side; a customer who bought there (User::boughtElsewhere()) takes
 * no referrer's milestone here either.
 */
final class ReferralWriter
{
    public function __construct(
        private readonly ReferralSettings $settings,
        private readonly Links $links,
        private readonly ConnectionInterface $db,
    ) {}

    public function write(Run $run, ImportedCustomer $customer): void
    {
        if ($customer->referrerKey === null || $customer->referrerKey === $customer->key) {
            return;
        }
        // Either merged into another account since: that one (Links::customer()).
        $user = $this->links->customer($run->import, $customer->key);
        $referrer = $this->links->customer($run->import, $customer->referrerKey);
        if ($user === null || $referrer === null || $user === $referrer) {
            return;
        }

        $this->db->transaction(function () use ($run, $user, $referrer): void {
            if (User::query()->whereKey($user)->whereNull('referred_by')->update(['referred_by' => $referrer]) !== 1) {
                return;
            }
            // Decided already: a start gift the shop decided before (a unique invitee) stands as it is.
            ReferralBonus::query()->insertOrIgnore([
                'bot_id' => $run->import->bot_id,
                'invitee_id' => $user,
                'referrer_id' => $referrer,
                'referrer_amount' => '0',
                'invitee_amount' => '0',
                'trigger' => $this->settings->startWhen()->value,
                'created_at' => now(),
            ]);
        });
    }
}
