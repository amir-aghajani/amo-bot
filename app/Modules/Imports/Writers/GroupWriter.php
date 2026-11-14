<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Services\CustomerGroups;
use Illuminate\Database\ConnectionInterface;

/**
 * The groups stage: each customer group of the survey (the other bot's agents, say) is the shop's group of that name, or
 * a new one made as the panels make it (CustomerGroups), paying plans' agent prices when the survey says so. Linked by
 * its name, which the customers join it by. Brought once: a group the owner deleted since stays deleted, and nobody joins it.
 */
final class GroupWriter
{
    private const KIND = 'groups';

    public function __construct(
        private readonly CustomerGroups $groups,
        private readonly Links $links,
        private readonly ConnectionInterface $db,
    ) {}

    /** @param array<string, mixed> $group A group of the survey (Survey::toArray()) */
    public function write(Run $run, array $group): void
    {
        $name = trim((string) ($group['name'] ?? ''));
        if ($name === '') {
            return;
        }
        if ($run->broughtBefore(Links::GROUP, self::KIND, $name, CustomerGroup::class)) {
            return;
        }

        $existing = CustomerGroup::query()->where('name', $name)->first();
        if ($existing !== null) {
            $this->links->add($run->import, Links::GROUP, $name, $existing->id);
            $run->linked(self::KIND);

            return;
        }
        try {
            $this->db->transaction(function () use ($run, $group, $name): void {
                $made = $this->groups->create(['name' => $name, 'agent_pricing' => ($group['agent_pricing'] ?? false) === true]);
                $this->links->add($run->import, Links::GROUP, $name, $made->id);
            });
        } catch (ValidationException $e) {
            $run->skipped(self::KIND, $name, ProblemReason::Refused, Run::words($e));

            return;
        }
        $run->created(self::KIND);
    }
}
