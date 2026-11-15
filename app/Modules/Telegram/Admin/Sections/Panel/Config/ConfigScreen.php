<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Config;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftScreen;
use App\Modules\Telegram\Admin\Sections\Panel\PanelSettingsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;

/**
 * A group of config.php (the owner's, in the main shop): read from GET /settings/config — every group, the file and what
 * the screen needs —, saved whole by PUT /settings/config/{group}, a secret only when typed. While the file cannot be
 * written the card says so, as the panel does over its sections.
 */
abstract class ConfigScreen extends DraftScreen
{
    /**
     * @var array<string, mixed>|null The read of the update in hand (readFor)
     */
    private ?array $read = null;

    private ?Admin $readFor = null;

    /** The group's name in the route (`app`, `telegram`…). */
    abstract protected function group(): string;

    /**
     * The form of the group, from the screen's read.
     *
     * @param array<string, mixed> $config GET /settings/config's answer
     */
    abstract protected function formOf(array $config): DraftForm;

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    protected function path(): array
    {
        return ['مدیریت', 'تنظیمات', 'تنظیمات پنل'];
    }

    protected function parent(): string
    {
        return PanelSettingsScreen::ID;
    }

    protected function form(Admin $admin): DraftForm
    {
        return $this->formOf($this->config($admin));
    }

    protected function submit(Admin $admin, array $payload): void
    {
        $this->read = null;
        $admin->api->put('/settings/config/' . $this->group(), $payload);
    }

    protected function intro(Admin $admin, DraftForm $form, string $driver): array
    {
        $file = (array) ($this->config($admin)['file'] ?? []);

        return ($file['writable'] ?? true) === false ? ['⚠️ <b>فایل config.php قابل نوشتن نیست.</b> در File Manager هاست به این فایل و پوشه برنامه اجازه نوشتن بدهید تا تنظیمات از این‌جا ذخیره شوند؛ تا آن موقع می‌توانید خود فایل را همان‌جا ویرایش کنید.'] : [];
    }

    /**
     * GET /settings/config, read once an update (again after a save).
     *
     * @return array<string, mixed>
     */
    protected function config(Admin $admin): array
    {
        if ($this->read === null || $this->readFor !== $admin) {
            $this->read = $admin->api->get('/settings/config');
            $this->readFor = $admin;
        }

        return $this->read;
    }

    /**
     * A group of the read.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected static function groupOf(array $config, string $group): array
    {
        $groups = Json::part($config, 'groups');

        return is_array($groups[$group] ?? null) ? $groups[$group] : [];
    }

    /**
     * What the read says beside the groups (`meta`).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected static function meta(array $config): array
    {
        return Json::part($config, 'meta');
    }
}
