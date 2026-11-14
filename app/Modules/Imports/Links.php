<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportLink;
use App\Modules\Users\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the shop's imports of one bot brought (import_links): a thing of the other bot, by its kind and its own key, and
 * the row it became here — found again by any later import of the same bot into the same shop, so nothing is brought
 * twice. A link is never deleted with its import.
 */
final class Links
{
    public const SERVER = 'server';
    public const GROUP = 'group';
    public const CUSTOMER = 'customer';
    /** A customer put into their group (by the customer's key): once, so a removal by the owner sticks. */
    public const MEMBERSHIP = 'membership';
    public const BALANCE = 'balance';
    public const CATEGORY = 'category';
    /** A category an import made (beside its CATEGORY link): switched on once a plan of it comes in switched on. */
    public const MADE_CATEGORY = 'made_category';
    public const PLAN = 'plan';
    public const SERVICE = 'service';
    public const DISCOUNT_CODE = 'discount_code';
    public const GIFT_CODE = 'gift_code';
    public const CHANNEL = 'channel';
    public const CARD = 'card';

    /** The longest key a link keeps (its column); a longer one is kept as its hash, the same every time. */
    private const KEY_MAX = 191;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * The shop's customer a customer of the other bot is: the row they became, or — merged since into another account
     * (Accounts\Services\AccountMerger, account_merges) — the account that stayed. Null when never brought, or deleted by
     * the owner since.
     */
    public function customer(Import $import, string $key): ?int
    {
        $id = $this->target($import, self::CUSTOMER, $key);
        $seen = [];
        while ($id !== null && !isset($seen[$id])) {
            if (User::query()->whereKey($id)->exists()) {
                return $id;
            }
            $seen[$id] = true;
            $next = $this->db->table('account_merges')->where('merged_user_id', $id)->latest('id')->value('user_id');
            $id = $next === null ? null : (int) $next;
        }

        return null;
    }

    /** The row the thing became, as linked — alive() when it must still be there; null when it was never brought. */
    public function target(Import $import, string $kind, string $key): ?int
    {
        $target = self::query($import, $kind)->where('source_key', self::key($key))->value('target_id');

        return $target === null ? null : (int) $target;
    }

    /**
     * The rows several things became, by their keys — those never brought left out.
     *
     * @param list<string> $keys
     * @return array<string, int>
     */
    public function targets(Import $import, string $kind, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $hashed = [];
        foreach ($keys as $key) {
            $hashed[self::key($key)] = $key;
        }
        $targets = [];
        foreach (self::query($import, $kind)->whereIn('source_key', array_keys($hashed))->whereNotNull('target_id')->get(['source_key', 'target_id']) as $link) {
            $targets[$hashed[$link->source_key]] = (int) $link->target_id;
        }

        return $targets;
    }

    /**
     * Whether any import of this bot brought the thing already — whatever became of it since. Brought once, never again:
     * what the owner changed or deleted since is theirs, and a later import (a newer backup, a feature added since) never
     * brings it back.
     */
    public function brought(Import $import, string $kind, string $key): bool
    {
        return self::query($import, $kind)->where('source_key', self::key($key))->exists();
    }

    /**
     * The row of `$model` the thing became, while it is still there; null once deleted (or never brought) — the link kept.
     *
     * @param class-string<Model> $model
     */
    public function row(Import $import, string $kind, string $key, string $model): ?int
    {
        $target = $this->target($import, $kind, $key);

        return $target !== null && $model::query()->withoutGlobalScopes()->whereKey($target)->exists() ? $target : null;
    }

    /**
     * The row of `$model` the thing became, while it is still there — or, deleted since, its link dropped and null: made
     * again. Only for what the owner asks this import for again (a panel chosen to be added as a server) or what a thing
     * brought now cannot be without (a new plan's category); everything else is brought() once.
     *
     * @param class-string<Model> $model
     */
    public function alive(Import $import, string $kind, string $key, string $model): ?int
    {
        $link = self::query($import, $kind)->where('source_key', self::key($key))->first(['id', 'target_id']);
        if ($link === null) {
            return null;
        }
        if ($link->target_id !== null && $model::query()->withoutGlobalScopes()->whereKey($link->target_id)->exists()) {
            return $link->target_id;
        }
        $link->delete();

        return null;
    }

    /** Forget what the thing became: what it was made as is gone again. */
    public function drop(Import $import, string $kind, string $key): void
    {
        self::query($import, $kind)->where('source_key', self::key($key))->delete();
    }

    /** Remember that the thing became `$target` here. */
    public function add(Import $import, string $kind, string $key, ?int $target): void
    {
        ImportLink::query()->create([
            'bot_id' => $import->bot_id,
            'migrator' => $import->migrator,
            'kind' => $kind,
            'source_key' => self::key($key),
            'target_id' => $target,
            'import_id' => $import->id,
        ]);
    }

    /** @return Builder<ImportLink> */
    private static function query(Import $import, string $kind): Builder
    {
        return ImportLink::query()->where('bot_id', $import->bot_id)->where('migrator', $import->migrator)->where('kind', $kind);
    }

    private static function key(string $key): string
    {
        return strlen($key) <= self::KEY_MAX ? $key : 'sha256:' . hash('sha256', $key);
    }
}
