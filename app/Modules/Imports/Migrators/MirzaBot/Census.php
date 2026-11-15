<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

use App\Modules\Imports\Survey\SurveyAdmin;
use App\Modules\Imports\Survey\SurveyGroup;
use App\Support\Money;
use App\Support\Persian;

/**
 * The customers of a bot of MirzaBot's line as the owner reviews them (People::census()): how many, their wallets, agent
 * groups, the admins the import may make, and what the owner should know of them.
 */
final class Census
{
    private const DEBTORS = '%s مشتری در ربات قبلی بدهکار بودند (جمعا %s)؛ کیف پولشان اینجا هم منفی می‌آید.';
    private const STAFF = '%s نفر از ادمین‌های ربات قبلی پشتیبان یا فروشنده بودند؛ در فهرست هستند ولی اینجا ادمین نمی‌شوند.';
    private const AGENCY_ENDS = '%s نماینده در ربات قبلی تاریخ پایان نمایندگی داشتند؛ این تاریخ منتقل نمی‌شود و اینجا نماینده می‌مانند تا خودتان عوضشان کنید.';

    /**
     * @param array<string, int> $groups Each customer group by name, with its customers
     * @param list<SurveyAdmin> $admins The bot's full admins who are its customers too
     */
    public function __construct(
        public readonly int $customers,
        /** A reseller bot's customers (the rest leave them out). */
        public readonly int $resellers,
        /** Customers with a balance other than zero. */
        public readonly int $balances,
        public readonly string $balanceTotal,
        public readonly int $debtors,
        /** What the debtors owe together, above zero. */
        public readonly string $debt,
        public readonly array $groups,
        /** Agents whose agency had an end not come yet. */
        public readonly int $agencyEnds,
        /** Customers with a percent off of their own. */
        public readonly int $personal,
        /** Advanced agents: those who buy on credit. */
        public readonly int $seniorAgents,
        /** Advanced agents with a limit to that credit (`maxbuyagent` above zero). */
        public readonly int $creditCaps,
        /** The bot's support and Seller staff who are customers too: listed, made no admins. */
        public readonly int $helpers,
        public readonly array $admins,
    ) {}

    /** @return list<SurveyGroup> */
    public function surveyGroups(): array
    {
        return array_values(array_map(static fn(string $name, int $customers): SurveyGroup => new SurveyGroup($name, true, $customers), array_keys($this->groups), $this->groups));
    }

    /**
     * What the owner is told of the customers: what every bot of the line says alike, and each bot's own words, in their
     * places among them — on its agents' credit (`$credit`) and on its customers' own discounts (`$personal`), null for
     * none.
     *
     * @return list<string>
     */
    public function warnings(?string $credit, ?string $personal): array
    {
        return array_values(array_filter([
            $this->debtors > 0 ? sprintf(self::DEBTORS, Persian::number($this->debtors), Money::format($this->debt)) : null,
            $this->helpers > 0 ? sprintf(self::STAFF, Persian::number($this->helpers)) : null,
            $credit,
            $this->agencyEnds > 0 ? sprintf(self::AGENCY_ENDS, Persian::number($this->agencyEnds)) : null,
            $personal,
        ]));
    }
}
