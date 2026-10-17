<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Support\Input;
use App\Support\Persian;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the owner keeps from an agent's shop («سرورها و پلن‌های پنهان», the owner's agents page): servers — its plans are
 * not sold on them, its free tests not given there, its staff move or build no service there (Bot::hiddenServerIds()) —
 * and plans of its own kept from sale (Plan::$hidden_by_owner, «پنهان‌شده توسط پشتیبانی»). The agent sees both in their
 * panel and changes neither. What the shop already has on a hidden server runs on — renewed, given extras, an order paid
 * before delivered (ServerSelector::resolve()) — since nothing new is sold there, and the owner's moves of a server's
 * services carry its services there too; a hidden plan's services renew as well.
 */
final class AgentRestrictions
{
    private const NO_SERVER = 'یکی از سرورهای انتخاب‌شده دیگر وجود ندارد؛ صفحه را دوباره باز کنید.';
    private const NO_PLAN = 'یکی از پلن‌های انتخاب‌شده از پلن‌های این نماینده نیست؛ صفحه را دوباره باز کنید.';
    private const NOT_A_LIST = 'لیست را از گزینه‌ها انتخاب کنید.';
    private const TOO_MANY = 'لیست شناسه‌ها بلندتر از حد است؛ حداکثر %s شناسه.';

    /** The most ids one list takes (the contract's maxItems): far more than an installation's servers or a shop's plans. */
    private const MAX_IDS = 1000;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Every server of the installation and every plan of the shop, in their orders, each with whether it is kept from it.
     *
     * @return array{servers: list<array{id: int, name: string, is_active: bool, hidden: bool}>, plans: list<array{id: int, name: string, is_active: bool, hidden: bool}>}
     */
    public function present(Bot $bot): array
    {
        $hidden = $bot->hiddenServerIds();

        return [
            'servers' => array_values(Server::query()->oldest('sort')->oldest('id')->get(['id', 'name', 'is_active'])->map(static fn(Server $server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'is_active' => $server->is_active,
                'hidden' => in_array($server->id, $hidden, true),
            ])->all()),
            'plans' => array_values(self::plans($bot)->oldest('sort')->oldest('id')->get(['id', 'name', 'is_active', 'hidden_by_owner'])->map(static fn(Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'is_active' => $plan->is_active,
                'hidden' => $plan->hidden_by_owner,
            ])->all()),
        ];
    }

    /**
     * Keeps exactly these from the shop, in one transaction: `server_ids` of the installation's servers, `plan_ids` of its
     * own plans — both sent, an empty list for none: one left out is refused, never read as none.
     *
     * @param array<string, mixed> $input {server_ids: list<int>, plan_ids: list<int>}
     * @throws ValidationException every refusal at once, under its field
     */
    public function save(Bot $bot, array $input): void
    {
        $errors = [];
        $serverIds = self::ids($input, 'server_ids', $errors);
        if ($serverIds !== [] && Server::query()->whereIn('id', $serverIds)->count() !== count($serverIds)) {
            $errors['server_ids'][] = self::NO_SERVER;
        }
        $planIds = self::ids($input, 'plan_ids', $errors);
        if ($planIds !== [] && self::plans($bot)->whereIn('id', $planIds)->count() !== count($planIds)) {
            $errors['plan_ids'][] = self::NO_PLAN;
        }
        ValidationException::ifAny($errors);

        $this->db->transaction(static function () use ($bot, $serverIds, $planIds): void {
            $bot->hideServers($serverIds);
            self::plans($bot)->whereNotIn('id', $planIds)->where('hidden_by_owner', true)->update(['hidden_by_owner' => false]);
            if ($planIds !== []) {
                self::plans($bot)->whereIn('id', $planIds)->where('hidden_by_owner', false)->update(['hidden_by_owner' => true]);
            }
        });
    }

    /**
     * A list of ids under `$field`: each once, sorted; refused (under the field) when it is left out, no list of whole
     * numbers, or longer than MAX_IDS.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     * @return list<int>
     */
    private static function ids(array $input, string $field, array &$errors): array
    {
        $value = $input[$field] ?? null;
        if (is_array($value) && count($value) > self::MAX_IDS) {
            $errors[$field][] = sprintf(self::TOO_MANY, Persian::number(self::MAX_IDS));

            return [];
        }
        $ids = is_array($value) && array_is_list($value) ? array_map(Input::integerOf(...), $value) : [null];
        if (in_array(null, $ids, true)) {
            $errors[$field][] = self::NOT_A_LIST;

            return [];
        }
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        sort($ids);

        return $ids;
    }

    /** @return Builder<Plan> The shop's own plans, whichever shop the code runs in. */
    private static function plans(Bot $bot): Builder
    {
        return Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->where('bot_id', $bot->id);
    }
}
