<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Core\Http\Urls;
use App\Modules\Bots\CurrentBot;
use App\Modules\Payments\Deposits\Contracts\DepositSource;
use App\Modules\Payments\Deposits\DepositSources;
use App\Modules\Payments\Deposits\Services\Deposits;
use App\Modules\Payments\Deposits\Services\DepositSettings;
use App\Modules\Payments\Enums\DepositStatus;
use App\Modules\Payments\Models\Deposit;
use App\Support\Input;
use App\Support\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «تایید خودکار واریز» (both panels, each shop its own): its switch, where the phone app sends the bank's SMS and the key
 * it sends with them, whether they have been coming — and the SMS that came, newest first.
 */
final class DepositsController extends ApiController
{
    public function __construct(
        private readonly DepositSettings $settings,
        private readonly DepositSources $sources,
        private readonly Deposits $deposits,
        private readonly Urls $urls,
    ) {}

    /** GET /deposits/settings */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->present());
    }

    /** PUT /deposits/settings — {enabled} */
    public function update(Request $request, Response $response): Response
    {
        $input = $this->input($request);
        if (!Input::isBoolean($input['enabled'] ?? null)) {
            throw ValidationException::on('enabled', Validation::NOT_A_SWITCH);
        }
        $this->settings->enable(Input::truthy($input['enabled']));

        return $this->json($response, $this->present());
    }

    /** POST /deposits/settings/key — a new key: the old one stops opening the webhook at once */
    public function rotate(Request $request, Response $response): Response
    {
        $this->settings->rotate();

        return $this->json($response, $this->present());
    }

    /** GET /deposits — ?status=&search=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->deposits->search($this->pageRequest($request))->toArray('deposits'));
    }

    /** @return array<string, mixed> */
    private function present(): array
    {
        $enabled = $this->settings->enabled();
        $last = static fn(?DepositStatus $status): ?string => Deposit::query()
            ->when($status !== null, static fn($query) => $query->where('status', $status?->value))
            ->when($status === null, static fn($query) => $query->where('status', '!=', DepositStatus::Test->value))
            ->latest('id')->first()?->created_at?->toIso8601String();

        return [
            'enabled' => $enabled,
            // The key once the shop listens: it is made then (DepositSettings::enable()).
            'key' => $enabled ? $this->settings->key() : null,
            'sources' => array_map(fn(DepositSource $source): array => [
                'key' => $source->key(),
                'label' => $source->label(),
                'webhook_url' => $this->urls->depositWebhook($source->key(), CurrentBot::id()),
            ], $this->sources->all()),
            'last_received_at' => $last(null),
            'last_test_at' => $last(DepositStatus::Test),
            'waiting' => Deposit::query()->whereIn('status', [DepositStatus::Unmatched->value, DepositStatus::Ambiguous->value])->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }
}
