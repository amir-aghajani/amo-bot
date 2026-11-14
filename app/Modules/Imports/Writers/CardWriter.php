<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Records\ImportedCard;
use App\Modules\Payments\Drivers\Manual\ManualGateway;
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentCards;
use App\Modules\Payments\Services\PaymentMethods;
use App\Support\BankCard;
use Illuminate\Database\ConnectionInterface;

/**
 * The cards stage: each bank card the other bot showed added to the shop's card-to-card method — the first, or one made
 * for them switched off, «کارت به کارت», for the owner to look over before customers see it —, through the method's own
 * card list (PaymentCards: 16 digits passing the Luhn check, a holder, no number twice) — each switched off, whatever
 * the other bot said, for the owner to switch on: a card it showed may be one nobody checks any more. A card the shop
 * shows already is linked.
 */
final class CardWriter
{
    private const KIND = 'cards';

    /** The card-to-card method made when the shop has none. */
    public const METHOD = 'کارت به کارت';

    public function __construct(
        private readonly PaymentMethods $methods,
        private readonly PaymentCards $cards,
        private readonly Links $links,
        private readonly ConnectionInterface $db,
    ) {}

    public function write(Run $run, ImportedCard $card): void
    {
        if ($run->broughtBefore(Links::CARD, self::KIND, $card->key, PaymentCard::class)) {
            return;
        }
        $number = BankCard::normalize($card->number);
        if (!BankCard::isValid($number)) {
            $run->skipped(self::KIND, $card->key, ProblemReason::CardInvalid, Run::isolate($card->number));

            return;
        }
        $existing = PaymentCard::query()->where('number', $number)->value('id');
        if ($existing !== null) {
            $this->links->add($run->import, Links::CARD, $card->key, (int) $existing);
            $run->linked(self::KIND);

            return;
        }

        // Switched off whatever the other bot said: a card customers are shown takes their money, so the owner switches
        // each on once they have checked it is theirs.
        $row = ['id' => null, 'number' => $number, 'holder' => trim($card->holder), 'bank' => $card->bank === null || trim($card->bank) === '' ? null : trim($card->bank), 'enabled' => false, 'daily_cap' => null];
        try {
            // The card and its link together.
            $this->db->transaction(function () use ($run, $card, $row, $number): void {
                $method = $this->add($row);
                $made = PaymentCard::query()->where('payment_method_id', $method->id)->where('number', $number)->value('id');
                $this->links->add($run->import, Links::CARD, $card->key, $made === null ? null : (int) $made);
            });
        } catch (ValidationException $e) {
            $run->skipped(self::KIND, $card->key, ProblemReason::Refused, BankCard::mask($number) . ': ' . Run::words($e));

            return;
        }
        $run->created(self::KIND);
    }

    /**
     * The card added to the shop's first card-to-card method, as the method's form keeps its list — its cards locked
     * first, as an edit of the method locks them —, or to a method made for it, switched off.
     *
     * @param array{id: null, number: string, holder: string, bank: string|null, enabled: bool, daily_cap: null} $row
     * @throws ValidationException the method's card list refuses it
     */
    private function add(array $row): PaymentMethod
    {
        $method = PaymentMethod::query()->where('driver', ManualGateway::key())->oldest('sort')->oldest('id')->first();
        if ($method === null) {
            return $this->methods->create(['driver' => ManualGateway::key(), 'label' => self::METHOD, 'enabled' => false, 'cards' => [$row]]);
        }

        $this->db->transaction(function () use ($method, $row): void {
            $kept = $method->cards()->lockForUpdate()->get()->map(static fn(PaymentCard $card): array => [
                'id' => $card->id,
                'number' => $card->number,
                'holder' => $card->holder,
                'bank' => $card->bank,
                'enabled' => $card->enabled,
                'daily_cap' => $card->daily_cap,
            ])->all();
            $this->cards->keep($method, $this->cards->read(['cards' => [...array_values($kept), $row]], $method));
        });

        return $method;
    }
}
