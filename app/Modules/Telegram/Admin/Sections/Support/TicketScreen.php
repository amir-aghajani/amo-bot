<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Customers\CustomerRowsScreen;
use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * One support ticket (GET /tickets/{id} — opening it reads the customer's messages as read): its facts in one card, the
 * conversation a page at a time — the latest page first, each message numbered with who wrote it, where and when, its
 * picture sent at a tap, a long message whole at a tap, a page of PART characters at a time —, support's answer (words,
 * a picture sent with its caption, or one of the quick answers at a tap: one Idempotency-Key a question, so an answer
 * sent again is one answer), closing it (red, asked first; the customer is told) and opening it again (green, asked
 * first when it drops the customer's rating), as the panel's ticket page has them.
 *
 * Shown with (id, page: 1 the latest). Actions: `m` (id, message, part: whole, 1 the first), `pic` (id, message, page:
 * its picture sent), `ans` (id: asks), `qr` (id, n: quick answer n sent — the question's key still unspent), `cls`
 * (id: asks), `clsd` (id), `rop` (id: asks when rated), `ropd` (id).
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

    /**
     * The quick answers under the answer's question, each sent at a tap: its button, and its words as the customer reads
     * them (the question lists them whole).
     */
    public const QUICK = [
        ['⏳ در حال بررسی', 'سلام، پیام شما رسید و در حال بررسی است؛ به‌زودی خبر می‌دهیم.'],
        ['✅ برطرف شد', 'مشکل برطرف شد؛ لطفا دوباره امتحان کنید و اگر هنوز مشکلی بود، همین‌جا بنویسید.'],
        ['📸 توضیح بیشتر', 'لطفا توضیح بیشتری بدهید یا تصویری از خطا بفرستید تا دقیق‌تر بررسی کنیم.'],
    ];

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
                if ($admin->send($admin->api->file("/tickets/{$id}/messages/" . self::id($args, 1) . '/attachment'), '🖼 تصویر پیام تیکت ' . Fmt::id($id))) {
                    $admin->notice('🖼 تصویر فرستاده شد');
                }

                return $this->view($admin, $this->read($admin, $id), self::page($args, 2));
            case 'ans':
                return $this->ask($admin, $this->read($admin, $id));
            case 'qr':
                $quick = isset($args[1]) && ctype_digit($args[1]) ? (self::QUICK[(int) $args[1]] ?? null) : null;
                $state = $admin->state(self::ID);
                if ($quick === null || (int) ($state['for'] ?? 0) !== $id || !is_string($state['key'] ?? null)) {
                    // The question was answered already (a second tap), or left long ago: nothing goes twice.
                    $admin->notice('این پاسخ آماده دیگر فرستاده نمی‌شود؛ برای پاسخ تازه «✍️ پاسخ» را بزنید.');

                    return $this->view($admin, $this->read($admin, $id), 1);
                }

                return $this->answer($admin, $id, $quick[1]);
            case 'cls':
                return $admin->confirm(
                    'تیکت ' . Fmt::plain(Fmt::id($id)) . ' بسته شود؟',
                    Button::to(UiWords::yes('بسته شود'), self::ID, 'clsd', $id),
                    Button::to(UiWords::CANCEL, self::ID, Nav::SHOW, $id),
                    'تیکت بسته می‌شود و مشتری خبردار می‌شود.' . "\n" . 'اگر مشتری دوباره پیامی بفرستد، تیکت خودبه‌خود باز می‌شود.',
                    danger: true,
                );
            case 'clsd':
                $ticket = (array) ($admin->api->post("/tickets/{$id}/close")['ticket'] ?? []);
                $admin->notice('✅ تیکت بسته شد');

                return $this->view($admin, $ticket, 1);
            case 'rop':
                $ticket = $this->read($admin, $id);
                if (($ticket['rating'] ?? null) !== null) {
                    return $admin->confirm(
                        'تیکت ' . Fmt::plain(Fmt::id($id)) . ' دوباره باز شود؟',
                        Button::to(UiWords::yes('باز شود'), self::ID, 'ropd', $id),
                        Button::to(UiWords::CANCEL, self::ID, Nav::SHOW, $id),
                        'باز کردن دوباره امتیاز مشتری را پاک می‌کند.' . "\n" . 'تیکت در وضعیت «در انتظار پاسخ» قرار می‌گیرد و مشتری از آن خبردار نمی‌شود. اگر تیکت دوباره بسته شود، مشتری می‌تواند باز هم امتیاز بدهد.',
                        danger: true,
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

        return $this->answer($admin, $id, $answer->text(), $picture);
    }

    /**
     * Support's answer `$body` to ticket `$id` — with `$picture` as a picture sent with it —, under the question's key:
     * sent again after no answer, the same words find the one the key wrote. The ticket as the panel then has it.
     *
     * @param array{name: string, bytes: string, mime: string}|null $picture
     * @throws ApiRefusal
     */
    private function answer(Admin $admin, int $id, string $body, ?array $picture = null): View
    {
        $headers = ['Idempotency-Key' => $this->key($admin, $id)];
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
     * «✍️ پاسخ»: words, or a picture with the words as its caption — the panel's answer form, its warnings over it —, or
     * one of the quick answers (QUICK) at a tap, each listed whole.
     *
     * @param array<string, mixed> $ticket
     */
    private function ask(Admin $admin, array $ticket): View
    {
        $id = (int) ($ticket['id'] ?? 0);
        $hint = [
            'متن پاسخ را بفرستید؛ یا تصویری (' . Fmt::ltr('JPG') . '، ' . Fmt::ltr('PNG') . ' یا ' . Fmt::ltr('WebP') . '، تا ۱۰ مگابایت) با متن پاسخ در کپشنش.',
            'مشتری از پاسخ شما خبردار می‌شود: در تلگرام، یا اگر تلگرام ندارد با ایمیل؛ پاسخ در وب‌سایت فروشگاه هم دیده می‌شود.',
        ];
        if (($ticket['status'] ?? null) === 'closed') {
            $hint[] = 'ℹ️ این تیکت بسته شده است؛ پاسخ شما دوباره بازش می‌کند و در وضعیت «پاسخ‌داده‌شده» می‌گذارد.' . (($ticket['rating'] ?? null) !== null ? ' امتیازی که مشتری به آن داده بود کنار گذاشته می‌شود.' : '');
        }
        $hint[] = '';
        $hint[] = '<b>⚡️ پاسخ آماده</b>، با یک لمس فرستاده می‌شود:';
        $choices = [];
        foreach (self::QUICK as $n => [$label, $words]) {
            $hint[] = Fmt::e($label) . ': <i>' . Fmt::e($words) . '</i>';
            $choices[] = Button::to($label, self::ID, 'qr', $id, $n);
        }
        $this->key($admin, $id);

        return $admin->ask((new Prompt('پاسخ به مشتری · تیکت ' . Fmt::plain(Fmt::id($id)), self::ID, 'ans', [$id], implode("\n", $hint), file: true))->choices(...$choices));
    }

    /**
     * The key the answer to ticket `$id` goes under: one a question, kept until the answer went — sent again after no
     * answer, the same words and picture find the one the key wrote.
     */
    private function key(Admin $admin, int $id): string
    {
        $state = $admin->state(self::ID);
        if ((int) ($state['for'] ?? 0) === $id && is_string($state['key'] ?? null)) {
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
     * The ticket's page `$page` of its conversation (1: the latest messages), its card over it.
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
        $rated = ($ticket['rating'] ?? null) !== null;
        $messages = Json::rows($ticket, 'messages');
        $count = count($messages);
        $pages = max(1, (int) ceil($count / self::PER_PAGE));
        $page = min($page, $pages);

        $view = View::make(Icons::SUPPORT . ' تیکت ' . Fmt::id($id), UiWords::ROOT, Fmt::bare(TicketsScreen::TITLE))
            ->card([
                'موضوع' => Fmt::e((string) ($ticket['subject'] ?? '')),
                'وضعیت' => Fmt::e(TicketsScreen::STATUSES[$status] ?? $status),
                'مشتری' => Words::account($customer),
                'سرویس' => $service !== null ? Fmt::ltr(is_string($service['name'] ?? null) ? $service['name'] : null) : 'بدون سرویس',
                'باز شده' => Fmt::date(is_string($ticket['created_at'] ?? null) ? $ticket['created_at'] : null),
                'بسته شده' => $closed && is_string($ticket['closed_at'] ?? null) ? Fmt::date($ticket['closed_at']) : null,
                'امتیاز مشتری' => $rated ? Words::stars($ticket['rating']) : null,
            ]);
        if ($rated && is_string($ticket['rating_note'] ?? null) && $ticket['rating_note'] !== '') {
            $view->line('<blockquote>💬 ' . Fmt::e($ticket['rating_note']) . '</blockquote>');
        }
        $view->section('💬 گفتگو · ' . Fmt::number($count) . ' پیام' . ($pages > 1 ? ' · ' . UiWords::page($page, $pages) . ($page === 1 ? ' (تازه‌ترین)' : '') : ''));

        $end = $count - ($page - 1) * self::PER_PAGE;
        $start = max(0, $end - self::PER_PAGE);
        $tools = [];
        for ($i = $start; $i < $end; $i++) {
            if ($i > $start) {
                $view->line();
            }
            $tools = [...$tools, ...$this->said($view, $ticket, $messages[$i], $i + 1, $page)];
        }

        $view->row(
            Button::to('✍️ پاسخ', self::ID, 'ans', $id)->primary(),
            $closed ? Button::to('🔓 باز کردن دوباره', self::ID, 'rop', $id)->success() : null,
        );
        $view->grid(2, ...$tools);
        if ($pages > 1) {
            $view->row(
                $page > 1 ? Button::to('جدیدتر ›', self::ID, Nav::SHOW, $id, $page - 1) : null,
                Button::to(Fmt::number($page) . ' / ' . Fmt::number($pages), Screens::PAGE, Nav::SHOW, $page, $pages),
                $page < $pages ? Button::to('‹ قدیمی‌تر', self::ID, Nav::SHOW, $id, $page + 1) : null,
            );
        }
        $view->row(
            Links::customer($admin, $customer, 'مشتری'),
            $service !== null && is_int($service['id'] ?? null) ? Links::to($admin, Icons::SERVICES . ' سرویس', Links::SERVICE, Nav::SHOW, $service['id']) : null,
        );
        $view->row($closed ? null : Button::to('🔒 بستن تیکت', self::ID, 'cls', $id)->danger());

        return $view->back(CustomerRowsScreen::back($admin, self::ID, $id, TicketsScreen::ID))->refresh(self::ID, Nav::SHOW, $id, $page);
    }

    /**
     * One message on the page: its number, who wrote it, where and when, its words (cut past CUT), its picture; and the
     * buttons it needs (its picture, its whole words).
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $message
     * @return list<Button>
     */
    private function said(View $view, array $ticket, array $message, int $number, int $page): array
    {
        $id = (int) ($ticket['id'] ?? 0);
        $messageId = (int) ($message['id'] ?? 0);
        $body = (string) ($message['body'] ?? '');
        $cut = mb_strlen($body) > self::CUT;
        $view->line(Fmt::number($number) . '. ' . self::author($ticket, $message))
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
        $messages = Json::rows($ticket, 'messages');
        foreach ($messages as $index => $message) {
            if ((int) ($message['id'] ?? 0) !== $messageId) {
                continue;
            }
            $parts = self::parts((string) ($message['body'] ?? ''));
            $count = count($parts);
            $part = min($part, $count);
            $view = View::make('📄 پیام ' . Fmt::number($index + 1) . ' از ' . Fmt::number(count($messages)), UiWords::ROOT, Fmt::bare(TicketsScreen::TITLE), 'تیکت ' . Fmt::plain(Fmt::id($id)))
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
            // Who of support, as a decision's reviewer reads (Sales::reviewer(): a name, a login isolated, «کاربر #۵»).
            $reviewer = Json::text($message, 'reviewer');
            $who = Icons::SUPPORT . ' <b>' . self::SUPPORT . '</b>' . ($reviewer === null || $reviewer === self::SUPPORT ? '' : ' · ' . Sales::reviewer($reviewer, Json::text($message, 'reviewer_name')));
        } else {
            $who = Icons::CUSTOMER . ' <b>' . Fmt::e(Words::name(is_array($ticket['customer'] ?? null) ? $ticket['customer'] : null)) . '</b>';
        }

        return $who . ' · ' . Fmt::e(self::CHANNEL[$channel] ?? $channel) . ' · ' . Fmt::date(is_string($message['created_at'] ?? null) ? $message['created_at'] : null, true);
    }
}
