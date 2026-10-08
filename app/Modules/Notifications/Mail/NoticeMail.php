<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Core\Mail\MailBody;
use App\Core\Mail\MailMessage;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Telegram\Texts\WebText;

/**
 * A notice emailed to a customer who has no Telegram account: what it is about as its subject and heading
 * (NoticeType::subject()), and its words as the bot words them — the admin's one wording, in the website's safe HTML and
 * as plain text —, in the look of every email of the shop.
 */
final class NoticeMail
{
    /** @param string $text The notice, Telegram HTML as the bot words it */
    public static function message(string $to, string $shop, NoticeType $type, string $text): MailMessage
    {
        return MailBody::formatted($to, $type->subject(), $shop, $type->subject(), WebText::html($text), WebText::plain($text));
    }
}
