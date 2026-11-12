<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Auth\Actor;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Log\LoggerInterface;

/**
 * A panel's look at the shop's mini app as one of its customers sees it («پیش‌نمایش»), on a live shop: a preview
 * session for them (CustomerSessions::openPreview() — read only, through the mini app's key alone, for PREVIEW_MINUTES,
 * the door open to it while the app is switched off too) and where the app opens on this host. The customer is told
 * nothing; the log keeps who looked, and as whom.
 */
final class MiniAppPreviews
{
    public const NO_USER = 'مشتری‌ای را برای پیش‌نمایش انتخاب کنید.';
    public const NOT_A_CUSTOMER = 'این کاربر از مشتریان این فروشگاه نیست.';
    public const BANNED = 'این مشتری مسدود است؛ پیش‌نمایش برای او باز نمی‌شود.';

    public function __construct(
        private readonly CustomerSessions $sessions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * A preview of `$app` as `$input['user_id']` — a customer of the current shop, not banned —, opened by `$actor`: its
     * token (shown this once), its end, and the app's address from the host's root under `$basePath` (MiniApps::path()).
     *
     * @param array<string, mixed> $input {user_id}
     * @return array{token: string, expires_at: string, path: string}
     * @throws ValidationException 422 on `user_id`: none sent, nobody of this shop, or a banned customer
     */
    public function open(MiniApp $app, array $input, Actor $actor, string $basePath): array
    {
        $id = Input::integer($input, 'user_id') ?? throw ValidationException::on('user_id', self::NO_USER);
        $user = User::query()->find($id) ?? throw ValidationException::on('user_id', self::NOT_A_CUSTOMER);
        if ($user->isBanned()) {
            throw ValidationException::on('user_id', self::BANNED);
        }

        $preview = $this->sessions->openPreview($user);
        $this->logger->info('{reviewer} opened a preview of the mini app as customer #{customer}', ['reviewer' => $actor->reviewer, 'customer' => $user->id]);

        return [
            'token' => $preview->token,
            'expires_at' => $preview->session->expires_at->toIso8601String(),
            'path' => MiniApps::path($app, $basePath),
        ];
    }
}
