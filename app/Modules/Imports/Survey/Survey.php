<?php

declare(strict_types=1);

namespace App\Modules\Imports\Survey;

use App\Modules\Imports\Records\ImportedPanel;

/**
 * What another bot's staged data holds (Migrator::survey()), for the owner to review before anything is written: how
 * much of each kind, the panels to map to servers, the customer groups its customers would join, its admins, what the
 * import leaves behind, and warnings worth reading first.
 */
final class Survey
{
    /** The kinds `$counts` names, in the order the review shows them. */
    public const KINDS = ['customers', 'balances', 'services', 'tests', 'plans', 'discount_codes', 'gift_codes', 'channels', 'cards', 'resellers'];

    /**
     * @param array<string, int> $counts
     * @param list<ImportedPanel> $panels
     * @param list<SurveyGroup> $groups
     * @param list<SurveyAdmin> $admins
     * @param list<LeftBehind> $left
     * @param list<string> $warnings In the owner's words
     */
    public function __construct(
        /** The other bot's version as the owner reads it ("1.2"); null when it cannot be told. */
        public readonly ?string $version,
        /**
         * Of KINDS: customers, those with a balance (`balances`), live services and tests, plans, codes, channels, cards,
         * and the customers of the other bot's reseller bots (`resellers`).
         */
        public readonly array $counts,
        /** The customers' balances together, Toman, a decimal string (debts taken off). */
        public readonly string $balanceTotal,
        public readonly array $panels,
        public readonly array $groups = [],
        public readonly array $admins = [],
        public readonly array $left = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * As the import keeps it and the panels read it — a panel's connection left out: its secrets never leave the server
     * (connection() reads them back from the import's own encrypted copy).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'counts' => array_merge(array_fill_keys(self::KINDS, 0), $this->counts),
            'balance_total' => $this->balanceTotal,
            'panels' => array_map(static fn(ImportedPanel $panel): array => [
                'key' => $panel->key,
                'name' => $panel->name,
                'type' => $panel->type,
                'url' => $panel->url,
                'driver' => $panel->driver,
                'unsupported' => $panel->unsupported,
                'addable' => $panel->addable(),
                'services' => $panel->services,
            ], $this->panels),
            'groups' => array_map(static fn(SurveyGroup $group): array => ['name' => $group->name, 'agent_pricing' => $group->agentPricing, 'customers' => $group->customers], $this->groups),
            'admins' => array_map(static fn(SurveyAdmin $admin): array => ['key' => $admin->key, 'telegram_id' => $admin->telegramId, 'username' => $admin->username, 'role' => $admin->role], $this->admins),
            'left' => array_map(static fn(LeftBehind $left): array => ['what' => $left->what, 'count' => $left->count], $this->left),
            'warnings' => $this->warnings,
        ];
    }
}
