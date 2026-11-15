<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Customers\CustomerRowsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One support ticket (GET /tickets/{id} — opening it reads the customer's messages as read): its facts, the
 * conversation a page at a time — the latest first, each message's picture sent at a tap, a long message whole at a
 * tap, a page of PART characters at a time —, support's answer (words, and a picture sent with its caption: one Idempotency-Key a question, so an answer sent
 * again is one answer), closing it (asked first; the customer is told) and opening it again (asked first when it drops
 * the customer's rating), as the panel's ticket page has them.
 *
 * Shown with (id, page: 1 the latest). Actions: `m` (id, message, part: whole, 1 the first), `pic` (id, message, page:
 * its picture sent), `ans` (id: asks), `cls` (id: asks), `clsd` (id), `rop` (id: asks when rated), `ropd` (id).
 */
final class TicketScreen extends BaseScreen
{
    public const ID = 'tkv';

    /** Messages a page of the conversation. */
    public const PER_PAGE = 5;

    /** The most of a message a page shows; a longer one is cut, «📄 پیام کامل» showing it whole. */
    public const CUT = 500;

    /**
     * The most of a message «📄 پیام کامل» shows at once: a message may run to the panel's 4000 characters, more once
     * escaped, past what Telegram shows — the rest a page away.
     */
    public const PART = 3000;

    /** Where a message was written (the panel's TICKET_CHANNEL). */
    private const CHANNEL = ['web' => 'وب‌سایت', 'bot' => 'ربات', 'panel' => 'پنل', 'staff' => 'مدیریت وب‌سایت', 'group' => 'گروه گزارش‌ها', 'manage' => 'مدیریت ربات'];

    /** Support's word for itself (Auth\Services\Reviewers::SUPPORT). */
    private const SUPPORT = 'پشتیبانی';

    public function show(Admin $admin, array $args): View
    {
        $id = self::id($args);
        CustomerRowsScreen::opened($admin, self::ID, $id, $args);

        return $this->view($admin, $this->read($admin, $id), self::page($args, 1));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'm':
                return $this->message($this->read($admin, $id), self::id($args, 1), self::page($args, 2));
            case 'pic':
                if ($admin->send($admin->api->file("/tickets/{$id}/messages/" . self::id($args, 1) . '/attachment'), '🖼 ' . Fmt::e('تصویر پیام تیکت ') . Fmt::id($id))) {
                    $admin->notice('🖼 تصویر فرستاده شد');
                }

                return $this->view($admin, $this->read($admin, $id), self::page($args, 2));
            case 'ans':
                return $this->ask($admin, $this->read($admin, $id));
            case 'cls':
                return $admin->confirm(
                    'بستن تیکت',
                    Button::to('🔒 بستن تیکت', self::ID, 'clsd', $id),
                    Button::to('✖️ انصراف', self::ID, Nav::SHOW, $id),
                    'تیکت ' . Fmt::id($id) . ' بسته می‌شود و مشتری خبردار می‌شود.' . "\n" . 'اگر مشتری دوباره پیامی بفرستد، تیکت خودبه‌خود باز می‌شود.',
                );
            case 'clsd':
                $ticket = (array) ($admin->api->post("/tickets/{$id}/close")['ticket'] ?? []);
                $admin->notice('✅ تیکت بسته شد');

                return $this->view($admin, $ticket, 1);
            case 'rop':
                $ticket = $this->read($admin, $id);
                if (($ticket['rating'] ?? null) !== null) {
                    return $admin->confirm(
                        'باز کردن دوباره تیکت',
                        Button::to('🔓 باز کردن دوباره', self::ID, 'ropd', $id),
                        Button::to('✖️ انصراف', self::ID, Nav::SHOW, $id),
                        'باز کردن دوباره امتیاز مشتری را پاک می‌کند.' . "\n" . 'تیکت ' . Fmt::id($id) . ' در وضعیت «در انتظار پاسخ» قرار می‌گیرد و مشتری از آن خبردار نمی‌شود. اگر تیکت دوباره بسته شود، مشتری می‌تواند باز هم امتیاز بدهد.',
                    );
                }

                return $this->reopen($admin, $id);
            case 'ropd':
                return $this->reopen($admin, $id);
        }

        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);
        if ($action !== 'ans' || $id === 0) {
            return $this->show($admin, $args);
        }
        $message = $answer->message();
        $picture = $answer->picture();
        if ($picture === null && (isset($message['photo']) || isset($message['document']))) {
            throw new ApiRefusal(422, 'فقط تصویر ' . Fmt::ltr('JPG') . '، ' . Fmt::ltr('PNG') . ' یا ' . Fmt::ltr('WebP') . ' همراه پاسخ فرستاده می‌شود؛ تصویر را دوباره بفرستید، یا فقط متن پاسخ را.');
        }
        $headers = ['Idempotency-Key' => $this->key($admin, $id)];
        $body = $answer->text();
        $ticket = $picture === null
            ? $admin->api->post("/tickets/{$id}/messages", ['body' => $body], $headers)
            : $admin->api->upload("/tickets/{$id}/messages", 'file', $picture['name'], $picture['bytes'], $picture['mime'], ['body' => $body], $headers);
        $admin->remember(self::ID, ['for' => null, 'key' => null]);
        $admin->notice('✅ پاسخ فرستاده شد');

        return $this->view($admin, (array) ($ticket['ticket'] ?? []), 1);
    }

    /**
     * The ticket with its conversation, as the panel now has it.
     *
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    private function read(Admin $admin, int $id): array
    {
        return (array) ($admin->api->get("/tickets/{$id}")['ticket'] ?? []);
    }

    /**
     * «✍️ پاسخ»: words, or a picture with the words as its caption — the panel's answer form, its warnings over it.
     *
     * @param array<string, mixed> $ticket
     */
    private function ask(Admin $admin, array $ticket): View
    {
        $id = (int) ($ticket['id'] ?? 0);
        $hint = ['متن پاسخ را بنویسید و بفرستید؛ برای تصویر، تصویر را با متن پاسخ در کپشنش بفرستید (' . Fmt::ltr('JPG') . '، ' . Fmt::ltr('PNG') . ' یا ' . Fmt::ltr('WebP') . '، حداکثر ۱۰ مگابایت).'];
        $hint[] = 'مشتری از پاسخ شما خبردار می‌شود: در تلگرام، یا اگر تلگرام ندارد با ایمیل؛ پاسخ در وب‌سایت فروشگاه هم دیده می‌شود.';
        if (($ticket['status'] ?? null) === 'closed') {
            $hint[] = 'ℹ️ این تیکت بسته شده است؛ پاسخ شما دوباره بازش می‌کند و در وضعیت «پاسخ‌داده‌شده» می‌گذارد.' . (($ticket['rating'] ?? null) !== null ? ' امتیازی که مشتری به آن داده بود کنار گذاشته می‌شود.' : '');
        }
        $this->key($admin, $id);

        return $admin->ask(new Prompt('پاسخ به مشتری · تیکت ' . Fmt::id($id), self::ID, 'ans', [$id], implode("\n", $hint), file: true));
    }

    /**
     * The key the answer to ticket `$id` goes under: one a question, kept until the answer went — sent again after no
     * answer, the same words and picture find the one the key wrote.
     */
    private function key(Admin $admin, int $id): string
    {
        $state = $admin->state(self::ID);
        if (($state['for'] ?? null) === $id && is_string($state['key'] ?? null)) {
            return $state['key'];
        }
        $key = bin2hex(random_bytes(16));
        $admin->remember(self::ID, ['for' => $id, 'key' => $key]);

        return $key;
    }

    /** @throws ApiRefusal */
    private function reopen(Admin $admin, int $id): View
    {
        $ticket = (array) ($admin->api->post("/tickets/{$id}/reopen")['ticket'] ?? []);
        $admin->notice('✅ تیکت دوباره باز شد');

        return $this->view($admin, $ticket, 1);
    }

    /**
     * The ticket's page `$page` of its conversation (1: the latest messages), its facts over it.
     *
     * @param array<string, mixed> $ticket
     */
    private function view(Admin $admin, array $ticket, int $page): View
    {
        $id = (int) ($ticket['id'] ?? 0);
        $status = (string) ($ticket['status'] ?? '');
        $customer = is_array($ticket['customer'] ?? null) ? $ticket['customer'] : null;
        $service = is_array($ticket['subscription'] ?? null) ? $ticket['subscription'] : null;
        $closed = $status === 'closed';
        $messages = array_values(array_filter(Json::part($ticket, 'messages'), is_array(...)));
        $count = count($messages);
        $pages = max(1, (int) ceil($count / self::PER_PAGE));
        $page = min($page, $pages);

        $view = View::make('🎧 تیکت ' . Fmt::id($id), 'مدیریت', 'پشتیبانی')
            ->line('📌 <b>' . Fmt::e((string) ($ticket['subject'] ?? '')) . '</b>')
            ->facts([
                'وضعیت' => Fmt::e(TicketsScreen::STATUSES[$status] ?? $status),
                'مشتری' => Words::account($customer),
                'سرویس' => $service !== null ? Fmt::ltr(is_string($service['name'] ?? null) ? $service['name'] : null) : 'بدون سرویس',
                'باز شده' => Fmt::date(is_string($ticket['created_at'] ?? null) ? $ticket['created_at'] : null),
                'بسته شده' => $closed && is_string($ticket['closed_at'] ?? null) ? Fmt::date($ticket['closed_at']) : null,
                'امتیاز مشتری' => ($ticket['rating'] ?? null) !== null ? Words::stars($ticket['rating']) : null,
            ]);
        if (($ticket['rating'] ?? null) !== null && is_string($ticket['rating_note'] ?? null) && $ticket['rating_note'] !== '') {
            $view->line('<blockquote>' . Fmt::e($ticket['rating_note']) . '</blockquote>');
        }
        $view->line('💬 گفتگو: ' . Fmt::number($count) . ' پیام · آخرین پیام ' . Words::ago(is_string($ticket['last_message_at'] ?? null) ? $ticket['last_message_at'] : null)
            . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) . ($page === 1 ? ' (تازه‌ترین)' : '') : ''));

        $end = $count - ($page - 1) * self::PER_PAGE;
        $start = max(0, $end - self::PER_PAGE);
        $tools = [];
        for ($i = $start; $i < $end; $i++) {
            $tools = [...$tools, ...$this->said($view, $ticket, $messages[$i], $i + 1, $count, $page)];
        }

        $view->grid(2, ...$tools);
        if ($pages > 1) {
            $view->row(
                $page > 1 ? Button::to('جدیدتر ›', self::ID, Nav::SHOW, $id, $page - 1) : null,
                Button::to(Fmt::number($page) . ' / ' . Fmt::number($pages), self::ID, Nav::SHOW, $id, $page),
                $page < $pages ? Button::to('‹ قدیمی‌تر', self::ID, Nav::SHOW, $id, $page + 1) : null,
            );
        }
        $view->row(Button::to('✍️ پاسخ', self::ID, 'ans', $id), $closed ? Button::to('🔓 باز کردن دوباره', self::ID, 'rop', $id) : Button::to('🔒 بستن تیکت', self::ID, 'cls', $id));
        $view->row(
            Links::customer($admin, $customer, 'مشتری'),
            $service !== null && is_int($service['id'] ?? null) ? Links::to($admin, '📡 سرویس', Links::SERVICE, Nav::SHOW, $service['id']) : null,
            Button::to('🔄 تازه‌سازی', self::ID, Nav::SHOW, $id, $page),
        );

        return $view->back(CustomerRowsScreen::back($admin, self::ID, $id, TicketsScreen::ID));
    }

    /**
     * One message on the page: who wrote it, where and when, its words (cut past CUT), its picture; and the buttons it
     * needs (its picture, its whole words).
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $message
     * @return list<Button>
     */
    private function said(View $view, array $ticket, array $message, int $number, int $count, int $page): array
    {
        $id = (int) ($ticket['id'] ?? 0);
        $messageId = (int) ($message['id'] ?? 0);
        $body = (string) ($message['body'] ?? '');
        $cut = mb_strlen($body) > self::CUT;
        $view->line()
            ->line('<i>— پیام ' . Fmt::number($number) . ' از ' . Fmt::number($count) . ' —</i>')
            ->line(self::author($ticket, $message))
            ->line('<blockquote>' . Fmt::e($cut ? rtrim(mb_substr($body, 0, self::CUT - 1)) . '…' : $body) . '</blockquote>');

        $tools = [];
        $attachment = is_array($message['attachment'] ?? null) ? $message['attachment'] : null;
        if ($attachment !== null) {
            if (Json::on($attachment, 'kept')) {
                $view->line('🖼 تصویر: ' . Fmt::ltr(is_string($attachment['name'] ?? null) ? $attachment['name'] : null));
                $tools[] = Button::to('🖼 تصویر پیام ' . Fmt::number($number), self::ID, 'pic', $id, $messageId, $page);
            } else {
                $view->line('🖼 <i>تصویر این پیام دیگر نگه داشته نمی‌شود؛ تصویرهای آپلودشده به‌طور پیش‌فرض ۳۰ روز بعد از بسته شدن تیکت پاک می‌شوند.</i>');
            }
        }
        if ($cut) {
            $tools[] = Button::to('📄 پیام کامل ' . Fmt::number($number), self::ID, 'm', $id, $messageId, 1);
        }

        return $tools;
    }

    /**
     * Message `$messageId` whole, alone — part `$part` of it when it runs past PART, the other parts a tap away.
     *
     * @param array<string, mixed> $ticket
     */
    private function message(array $ticket, int $messageId, int $part): View
    {
        $id = (int) ($ticket['id'] ?? 0);
        $messages = array_values(array_filter(Json::part($ticket, 'messages'), is_array(...)));
        foreach ($messages as $index => $message) {
            if ((int) ($message['id'] ?? 0) !== $messageId) {
                continue;
            }
            $parts = self::parts((string) ($message['body'] ?? ''));
            $count = count($parts);
            $part = min($part, $count);
            $view = View::make('📄 پیام ' . Fmt::number($index + 1) . ' از ' . Fmt::number(count($messages)), 'مدیریت', 'پشتیبانی', 'تیکت ' . Fmt::id($id))
                ->line(self::author($ticket, $message) . ($count > 1 ? ' · بخش ' . Fmt::number($part) . ' از ' . Fmt::number($count) : ''))
                ->line('<blockquote>' . Fmt::e($parts[$part - 1]) . '</blockquote>');
            $view->pager($part, $count, self::ID, 'm', $id, $messageId);

            return $view->back(self::ID, Nav::SHOW, $id, (int) ceil((count($messages) - $index) / self::PER_PAGE));
        }

        return View::make('📄 پیام')->line('این پیام دیگر در تیکت نیست.')->back(self::ID, Nav::SHOW, $id);
    }

    /**
     * A message's words in parts of PART characters at most once escaped, each cut after a line or a word where one is
     * near its end.
     *
     * @return non-empty-list<string>
     */
    private static function parts(string $body): array
    {
        $parts = [];
        while (mb_strlen(Fmt::e($body)) > self::PART) {
            $piece = mb_substr($body, 0, self::PART);
            // Quotes and ampersands grow when escaped: fewer of them a part.
            while (mb_strlen(Fmt::e($piece)) > self::PART) {
                $piece = mb_substr($piece, 0, intdiv(mb_strlen($piece) * 4, 5));
            }
            $length = mb_strlen($piece);
            $at = max((int) mb_strrpos($piece, "\n"), (int) mb_strrpos($piece, ' '));
            $piece = $at > $length / 2 ? mb_substr($piece, 0, $at + 1) : $piece;
            $parts[] = rtrim($piece);
            $body = ltrim(mb_substr($body, mb_strlen($piece)));
        }

        return $body === '' && $parts !== [] ? $parts : [...$parts, $body];
    }

    /**
     * Who wrote a message — the customer, or «پشتیبانی» and its writer by their name where the panel read one —, where
     * and when.
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $message
     */
    private static function author(array $ticket, array $message): string
    {
        $channel = (string) ($message['channel'] ?? '');
        if (($message['author'] ?? null) === 'support') {
            $writer = is_string($message['reviewer_name'] ?? null) ? $message['reviewer_name'] : (is_string($message['reviewer'] ?? null) ? $message['reviewer'] : null);
            $who = '🎧 <b>' . self::SUPPORT . '</b>' . ($writer === null || $writer === self::SUPPORT ? '' : ' · ' . Fmt::e($writer));
        } else {
            $who = '👤 <b>' . Fmt::e(Words::name(is_array($ticket['customer'] ?? null) ? $ticket['customer'] : null)) . '</b>';
        }

        return $who . ' · ' . Fmt::e(self::CHANNEL[$channel] ?? $channel) . ' · ' . Fmt::date(is_string($message['created_at'] ?? null) ? $message['created_at'] : null, true);
    }
}
