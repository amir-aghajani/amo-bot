<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Core\Mail\MailBody;
use App\Core\Mail\MailMessage;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Telegram\Texts\WebText;

/**
 * A notice as an email: its kind's subject line as the subject and heading (NoticeType::subject()), and the bot's words
 * as safe HTML and plain text (WebText), in the shop's one email look (MailBody).
 */
final class NoticeMail
{
    /**
     * The email of a notice to `$to`, signed `$shop`.
     *
     * @param string $text The notice, Telegram HTML as the bot words it
     */
    public static function message(string $to, string $shop, NoticeType $type, string $text): MailMessage
    {
        return MailBody::formatted($to, $type->subject(), $shop, $type->subject(), WebText::html($text), WebText::plain($text));
    }
}
