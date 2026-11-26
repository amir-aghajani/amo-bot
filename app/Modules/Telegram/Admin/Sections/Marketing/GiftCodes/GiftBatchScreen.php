<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\Door\ApiFile;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One batch of gift codes (the panel's bar over a list narrowed to it): its summary card — its codes counted, unused,
 * redeemed, expired, when it was made —, its unused codes to hand out: copied at a tap while they fit one copy, and as a
 * text file (the panel's «کپی کدهای استفاده‌نشده»), its codes listed (GiftCodeListScreen), and «🗑 حذف کدهای
 * استفاده‌نشده» — asked first, with the panel's words. The batch is the one picked on GiftCodesScreen (by its place
 * there), kept by name for every action after.
 *
 * Actions: `file`, `codes`, `drop` (asks), `dropped` (mark: discards — the batch the mark names alone; an older
 * window's carries none).
 */
final class GiftBatchScreen extends BaseScreen
{
    public const ID = 'gcb';

    /** The most unused codes read for the copy button: more never fit one copy (Button::COPY_MOST). */
    private const COPY_FEW = 20;

    public function show(Admin $admin, array $args): View
    {
        if (isset($args[0]) && ctype_digit($args[0])) {
            $names = (array) ($admin->state(GiftCodesScreen::ID)['batches'] ?? []);
            $name = $names[(int) $args[0]] ?? null;
            if (!is_string($name)) {
                return $admin->open(GiftCodesScreen::ID);
            }
            $admin->remember(self::ID, ['batch' => $name]);
        }
        $batch = $this->batch($admin);

        return $batch === null ? $admin->open(GiftCodesScreen::ID) : $this->view($admin, $batch);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $batch = $this->batch($admin);
        if ($batch === null) {
            return $admin->open(GiftCodesScreen::ID);
        }
        $name = (string) $batch['batch'];
        switch ($action) {
            case 'file':
                $codes = self::unused($admin, $name);
                if ($codes === []) {
                    $admin->alert('این سری کد استفاده‌نشده‌ای ندارد.');

                    return $this->view($admin, $batch);
                }
                if ($admin->send(self::file($codes), Icons::GIFT_CODES . ' کدهای استفاده‌نشده سری «' . Fmt::e($name) . '»: ' . Fmt::number(count($codes)) . ' کد')) {
                    $admin->notice('📄 ' . Fmt::number(count($codes)) . ' کد فرستاده شد');
                }

                return $this->view($admin, $batch);
            case 'codes':
                $admin->remember(GiftCodeListScreen::ID, ['batch' => $name, 'page' => 1]);

                return $admin->open(GiftCodeListScreen::ID);
            case 'drop':
                $unused = (int) ($batch['unused'] ?? 0) + (int) ($batch['expired'] ?? 0);
                $redeemed = (int) ($batch['redeemed'] ?? 0);

                return $admin->confirm(
                    'کدهای استفاده‌نشده سری «' . $name . '» حذف شوند؟',
                    Button::to(Words::yes('حذف شوند', '🗑'), self::ID, 'dropped', self::mark($name)),
                    Button::to(Words::CANCEL, self::ID),
                    Fmt::number($unused) . ' کدی که کسی استفاده نکرده حذف می‌شود و دیگر پذیرفته نمی‌شود.' . ($redeemed > 0 ? ' ' . Fmt::number($redeemed) . ' کد استفاده‌شده برای سابقه می‌ماند.' : ''),
                    danger: true,
                );
            case 'dropped':
                // The yes names the batch it asked about: under an older window, with another batch opened since, it
                // discards nothing.
                if (isset($args[0]) && $args[0] !== self::mark($name)) {
                    $admin->alert('این دکمه برای سری دیگری بود؛ چیزی حذف نشد.');

                    return $this->view($admin, $batch);
                }
                $deleted = (int) ($admin->api->post('/gift-codes/discard', ['batch' => $name])['deleted'] ?? 0);
                $admin->notice('🗑 ' . Fmt::number($deleted) . ' کد استفاده‌نشده حذف شد');
                $batch = $this->batch($admin);

                return $batch === null ? $admin->open(GiftCodesScreen::ID) : $this->view($admin, $batch);
        }

        return $this->view($admin, $batch);
    }

    /**
     * The kept batch as the panel counts it now; null once none of its codes is left.
     *
     * @return array<string, mixed>|null
     * @throws ApiRefusal
     */
    private function batch(Admin $admin): ?array
    {
        $name = $admin->state(self::ID)['batch'] ?? null;
        foreach (is_string($name) ? GiftCodesScreen::batches($admin) : [] as $batch) {
            if (($batch['batch'] ?? null) === $name) {
                return $batch;
            }
        }

        return null;
    }

    /**
     * Codes as the text file to hand out, one a line.
     *
     * @param list<string> $codes
     */
    public static function file(array $codes): ApiFile
    {
        return new ApiFile(implode("\n", $codes) . "\n", 'text/plain', 'gift-codes.txt');
    }

    /** A batch's name in a few characters a button carries (its name may be longer than callback data takes). */
    private static function mark(string $name): string
    {
        return hash('crc32b', $name);
    }

    /**
     * A batch's unused codes, oldest first (GET /gift-codes/unused).
     *
     * @return list<string>
     * @throws ApiRefusal
     */
    private static function unused(Admin $admin, string $batch): array
    {
        return array_values(array_filter((array) ($admin->api->get('/gift-codes/unused', ['batch' => $batch])['codes'] ?? []), is_string(...)));
    }

    /**
     * @param array<string, mixed> $batch
     * @throws ApiRefusal
     */
    private function view(Admin $admin, array $batch): View
    {
        $name = (string) $batch['batch'];
        $unused = (int) ($batch['unused'] ?? 0);
        // The unused codes copied at a tap while they fit one copy (a few, read only then); a longer batch goes as the file.
        $copied = $unused > 0 && $unused <= self::COPY_FEW ? implode("\n", self::unused($admin, $name)) : '';

        return View::make(Icons::GIFT_CODES . ' سری «' . $name . '»', ...Marketing::in(Marketing::GIFT_CODES))
            ->card([
                'کدها' => Fmt::number((int) ($batch['total'] ?? 0)),
                'استفاده‌نشده' => Fmt::number($unused),
                'استفاده‌شده' => Fmt::number((int) ($batch['redeemed'] ?? 0)),
                'منقضی‌شده' => Fmt::number((int) ($batch['expired'] ?? 0)),
                'ساخته شده' => is_string($batch['created_at'] ?? null) ? Fmt::date($batch['created_at'], true) : null,
            ])
            ->row($unused > 0 ? Button::to('📄 فایل کدهای استفاده‌نشده', self::ID, 'file')->primary() : null, Button::to(Icons::GIFT_CODES . ' کدهای این سری', self::ID, 'codes'))
            ->row(Button::copy(Words::copy('کدهای استفاده‌نشده'), $copied))
            ->row($unused + (int) ($batch['expired'] ?? 0) > 0 ? Button::to(Words::DELETE . ' کدهای استفاده‌نشده', self::ID, 'drop')->danger() : null)
            ->back(GiftCodesScreen::ID);
    }
}
