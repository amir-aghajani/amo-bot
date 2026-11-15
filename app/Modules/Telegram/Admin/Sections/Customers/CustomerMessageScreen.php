<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «✉️ ارسال پیام» (the panel's dialog of that name): a message from support the shop's bot sends the customer — kept on
 * their website and mini app either way —, typed, read once more, then sent (POST /users/{id}/message); what came of it
 * in Telegram, or by email, is said in the panel's words. One that could not go for now stays, to send again.
 *
 * Opened with the customer's id (asks the text). Actions: `send` (id). Answers: `text` (id).
 */
final class CustomerMessageScreen extends BaseScreen
{
    public const ID = 'usm';

    public function show(Admin $admin, array $args): View
    {
        $id = Customer::current($admin, $args);
        if ($id === 0) {
            return $admin->open(UsersScreen::ID);
        }
        $draft = $admin->state(self::ID);
        $now = ($draft['user'] ?? null) === $id && is_string($draft['text'] ?? null) ? "\n\nالان:\n<blockquote>" . Fmt::e($draft['text']) . '</blockquote>' : '';

        return $admin->ask(new Prompt(
            'پیام برای ' . Customer::known($admin, $id),
            self::ID,
            'text',
            [$id],
            'پیامی از طرف پشتیبانی که ربات فروشگاه برای او می‌فرستد؛ در وب‌سایت و مینی‌اپ او هم می‌ماند. متن ساده؛ خط‌ها همان‌طور که می‌نویسید می‌رسند.' . "\n" . $this->heading($admin) . $now,
            Button::to('✖️ لغو', CustomerScreen::ID, 'show', $id),
        ));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        $draft = $admin->state(self::ID);
        if ($action !== 'send' || $id === 0 || ($draft['user'] ?? null) !== $id || !is_string($draft['text'] ?? null)) {
            return $this->show($admin, $args);
        }

        $delivery = (string) ($admin->api->post("/users/{$id}/message", ['text' => $draft['text']])['delivery'] ?? '');
        $user = Customer::row(Customer::read($admin, $id));
        $telegram = is_int($user['telegram_id'] ?? null);
        $email = Customer::text($user, 'email') !== null;
        switch ($delivery) {
            case 'told':
                $admin->notice('✅ پیام در تلگرام به کاربر رسید');
                break;
            case 'emailed':
                $admin->notice($telegram ? '✅ کاربر در تلگرام در دسترس نبود؛ پیام به ایمیلش رفت' : '✅ پیام به ایمیل کاربر رفت');
                break;
            case 'turned_away':
                $admin->alert('کاربر ربات را بلاک کرده؛ پیام در تلگرام به او نرسید و فقط در وب‌سایتش می‌ماند.');
                break;
            case 'unreachable':
                // It did not go: the text stays, for «✔️ ارسال پیام» again a little later.
                $admin->alert($telegram ? 'تلگرام در دسترس نبود و پیام نرسید؛ کمی بعد دوباره بفرستید.' : 'سرور ایمیل در دسترس نبود و پیام نرسید؛ کمی بعد دوباره بفرستید.');

                return $this->preview($admin, $id, $draft['text']);
            case 'refused':
                $admin->alert('تلگرام پیام را نپذیرفت.');
                break;
            default:
                $admin->alert($email ? 'کاربر تلگرام ندارد و ایمیل هم برایش نرفت؛ پیام فقط در وب‌سایتش می‌ماند.' : 'کاربر تلگرام و ایمیل ندارد؛ پیام فقط در وب‌سایتش می‌ماند.');
        }
        $admin->forget(self::ID);

        return $admin->open(CustomerScreen::ID, 'show', [$id]);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);
        if ($action !== 'text' || $id === 0) {
            return $this->show($admin, $args);
        }
        $admin->remember(self::ID, ['user' => $id, 'text' => $answer->text()]);

        return $this->preview($admin, $id, $answer->text());
    }

    /** The message read once more before it goes: «✔️ ارسال پیام», «✏️ ویرایش», «✖️ انصراف». */
    private function preview(Admin $admin, int $id, string $text): View
    {
        return View::make('✉️ ارسال پیام', 'مدیریت', 'فروش', 'کاربران', Customer::known($admin, $id))
            ->line('<blockquote>' . Fmt::e($text) . '</blockquote>')
            ->note('ربات فروشگاه این پیام را از طرف پشتیبانی برای او می‌فرستد.')
            ->row(Button::to('✔️ ارسال پیام', self::ID, 'send', $id))
            ->row(Button::to('✏️ ویرایش', self::ID, 'show', $id), Button::to('✖️ انصراف', CustomerScreen::ID, 'show', $id));
    }

    /**
     * How the message reads in Telegram — under the bot's heading «📩 پیام از طرف پشتیبانی:», or the words alone — as the
     * bot settings' «عنوان پیام پشتیبانی» says, for a seat that reads them; both said otherwise.
     */
    private function heading(Admin $admin): string
    {
        $header = null;
        if ($admin->seat->configures()) {
            try {
                $settings = BotGroupSource::settings($admin);
                $header = is_bool($settings['support_header'] ?? null) ? $settings['support_header'] : null;
            } catch (ApiRefusal) {
                $header = null;
            }
        }

        return match ($header) {
            true => 'در تلگرام زیر عنوان «📩 پیام از طرف پشتیبانی:» می‌رسد (متن «پیام پشتیبانی به مشتری» در متن‌های ربات؛ عنوان در «تنظیمات ربات › عمومی» خاموش می‌شود).',
            false => 'در تلگرام بدون عنوان، فقط خود متن می‌رسد («عنوان پیام پشتیبانی» در «تنظیمات ربات › عمومی» خاموش است).',
            null => 'با «عنوان پیام پشتیبانی» روشن در «تنظیمات ربات › عمومی»، در تلگرام زیر «📩 پیام از طرف پشتیبانی:» می‌رسد؛ خاموش، فقط خود متن.',
        };
    }
}
