<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Core\Security\Encrypter;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportStage;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportProblem;
use App\Modules\Imports\Records\ImportedCard;
use App\Modules\Imports\Records\ImportedChannel;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedGiftCode;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Imports\Writers\CardWriter;
use App\Modules\Imports\Writers\ChannelWriter;
use App\Modules\Imports\Writers\CustomerWriter;
use App\Modules\Imports\Writers\DiscountCodeWriter;
use App\Modules\Imports\Writers\GiftCodeWriter;
use App\Modules\Imports\Writers\GroupWriter;
use App\Modules\Imports\Writers\PlanWriter;
use App\Modules\Imports\Writers\ReferralWriter;
use App\Modules\Imports\Writers\Run;
use App\Modules\Imports\Writers\ServerWriter;
use App\Modules\Imports\Writers\ServiceWriter;
use App\Modules\Imports\Writers\TryAgain;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * The writing of an import, a step at a time: its stages in ImportStage's order, each from the import's cursor — the
 * staged row (or the survey's place) last written —, a thing at a time by the stage's writer, until the budget is spent.
 * After every thing the stage, the cursor, the tally and the problems found are saved together, and only while the
 * import is still importing: a cancel from elsewhere stops the step at the next thing. A stage the owner did not choose
 * is passed over. A thing whose writing fails is that thing's problem, logged — never the import's.
 */
final class Importer
{
    /** The kind each stage counts its things under (Run::KINDS), and names a failure by. */
    private const KINDS = [
        'servers' => 'servers',
        'groups' => 'groups',
        'customers' => 'customers',
        'referrals' => 'customers',
        'plans' => 'plans',
        'services' => 'services',
        'discount_codes' => 'discount_codes',
        'gift_codes' => 'gift_codes',
        'channels' => 'channels',
        'cards' => 'cards',
    ];

    public function __construct(
        private readonly ServerWriter $servers,
        private readonly GroupWriter $groups,
        private readonly CustomerWriter $customers,
        private readonly ReferralWriter $referrals,
        private readonly PlanWriter $plans,
        private readonly ServiceWriter $services,
        private readonly DiscountCodeWriter $discountCodes,
        private readonly GiftCodeWriter $giftCodes,
        private readonly ChannelWriter $channels,
        private readonly CardWriter $cards,
        private readonly Links $links,
        private readonly Encrypter $encrypter,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Write on from where the import stopped. True once its last stage is written; false when the budget ran out, a
     * thing is to be tried again next step (TryAgain), or the import is no longer importing (cancelled meanwhile) — what
     * was written is saved either way. What the migrator itself throws (a record it cannot make) goes up: the import
     * cannot go on past it (Imports fails it).
     */
    public function run(Import $import, Migrator $migrator, Staged $staged, Budget $budget): bool
    {
        $run = new Run($import, Choices::of($import->choices ?? []), $migrator->describe()->label, $this->links, $this->encrypter);
        $this->services->forget();

        while (($stage = $import->stage) !== null) {
            if (!$this->stage($run, $migrator, $staged, $stage, $budget)) {
                return false;
            }
            $run->restart();
            if (!$this->save($run, $stage->next(), 0)) {
                return false;
            }
        }

        return true;
    }

    /** Write the stage on from the import's cursor; false when the step must stop first. */
    private function stage(Run $run, Migrator $migrator, Staged $staged, ImportStage $stage, Budget $budget): bool
    {
        $after = $run->import->cursor;
        $on = $run->choices->on(...);
        $survey = $run->import->survey ?? [];

        return match ($stage) {
            ImportStage::Servers => $this->walk($run, $stage, $budget, self::listed($run->panels(), $after), fn(array $panel) => $this->servers->write($run, $panel)),
            ImportStage::Groups => !$on('groups') || $this->walk($run, $stage, $budget, self::listed(is_array($survey['groups'] ?? null) ? $survey['groups'] : [], $after), fn(array $group) => $this->groups->write($run, $group)),
            ImportStage::Customers => $this->walk($run, $stage, $budget, $migrator->customers($staged, $after), fn(ImportedCustomer $customer) => $this->customers->write($run, $customer)),
            ImportStage::Referrals => !$on('referrals') || $this->walk($run, $stage, $budget, $migrator->customers($staged, $after), fn(ImportedCustomer $customer) => $this->referrals->write($run, $customer)),
            ImportStage::Plans => !$on('plans') || $this->walk($run, $stage, $budget, $migrator->plans($staged, $after), fn(ImportedPlan $plan) => $this->plans->write($run, $plan)),
            ImportStage::Services => !$on('services') || $this->walk($run, $stage, $budget, $migrator->services($staged, $after), fn(ImportedService $service) => $this->services->write($run, $service)),
            ImportStage::DiscountCodes => !$on('discount_codes') || $this->walk($run, $stage, $budget, $migrator->discountCodes($staged, $after), fn(ImportedDiscountCode $code) => $this->discountCodes->write($run, $code)),
            ImportStage::GiftCodes => !$on('gift_codes') || $this->walk($run, $stage, $budget, $migrator->giftCodes($staged, $after), fn(ImportedGiftCode $code) => $this->giftCodes->write($run, $code)),
            ImportStage::Channels => !$on('channels') || $this->walk($run, $stage, $budget, $migrator->channels($staged, $after), fn(ImportedChannel $channel) => $this->channels->write($run, $channel)),
            ImportStage::Cards => !$on('cards') || $this->walk($run, $stage, $budget, $migrator->cards($staged, $after), fn(ImportedCard $card) => $this->cards->write($run, $card)),
        };
    }

    /**
     * Each thing after the cursor written in turn, the cursor saved after it; false when the budget ran out first or the
     * import stopped importing.
     *
     * @template T of object|array
     * @param iterable<int, T> $things keyed by their seq (or place)
     * @param \Closure(T): void $write
     */
    private function walk(Run $run, ImportStage $stage, Budget $budget, iterable $things, \Closure $write): bool
    {
        foreach ($things as $seq => $thing) {
            if ($budget->spent()) {
                return false;
            }
            try {
                $write($thing);
            } catch (TryAgain) {
                // Not now: the step stops before it, its cursor where it was, and keeps what it learnt (a panel's silence).
                $this->save($run, $stage, $run->import->cursor);

                return false;
            } catch (\Throwable $e) {
                $key = self::keyOf($thing);
                $this->logger->error('Import {import} could not write {kind} {key}: {message}', ['import' => $run->import->id, 'kind' => self::KINDS[$stage->value], 'key' => $key, 'message' => $e->getMessage(), 'exception' => $e]);
                $run->skipped(self::KINDS[$stage->value], $key, ProblemReason::Failed);
            }
            $run->advance();
            if (!$this->save($run, $stage, $seq)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The import's stage, cursor and tally, and the problems found since the last save, written together — only while
     * the import is importing. False when it is not (cancelled meanwhile): nothing is written then.
     */
    private function save(Run $run, ?ImportStage $stage, int $cursor): bool
    {
        $import = $run->import;
        $counts = $run->counts();
        $problems = $run->problems();
        $saved = $this->db->transaction(static function () use ($import, $stage, $cursor, $counts, $problems): bool {
            $moved = Import::query()->whereKey($import->id)->where('phase', ImportPhase::Importing->value)
                ->update(['stage' => $stage?->value, 'cursor' => $cursor, 'counts' => json_encode($counts, JSON_THROW_ON_ERROR), 'updated_at' => now()]) === 1;
            if ($moved && $problems !== []) {
                ImportProblem::query()->insert($problems);
            }

            return $moved;
        });
        if ($saved) {
            $import->forceFill(['stage' => $stage, 'cursor' => $cursor, 'counts' => $counts])->syncOriginal();
        }

        return $saved;
    }

    /**
     * A list of the survey's (its panels, its groups) as a stage walks it: each by its place from 1, after `$after`.
     *
     * @param array<array-key, mixed> $items
     * @return \Generator<int, array<string, mixed>>
     */
    private static function listed(array $items, int $after): \Generator
    {
        foreach (array_values(array_filter($items, 'is_array')) as $index => $item) {
            if ($index + 1 > $after) {
                yield $index + 1 => $item;
            }
        }
    }

    /**
     * What a thing of the other bot is known by: a record's key, a survey panel's key, a group's name.
     *
     * @param object|array<array-key, mixed> $thing
     */
    private static function keyOf(object|array $thing): string
    {
        if (is_array($thing)) {
            return (string) ($thing['key'] ?? $thing['name'] ?? '');
        }

        return property_exists($thing, 'key') && is_string($thing->key) ? $thing->key : '';
    }
}
