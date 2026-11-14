<?php

declare(strict_types=1);

namespace App\Modules\Imports\Exceptions;

use App\Core\Exceptions\ValidationException;

/**
 * The refusals of an import, in the owner's words, each a 422 under the field it is about: the backup file (`file`),
 * a database connection (its fields), the import itself (`import`: another one under way, one not at that phase), the
 * owner's choices (`choices.…`).
 */
final class ImportRefusedException
{
    public const NO_FILE = 'فایل پشتیبان ربات قبلی را انتخاب کنید.';
    public const TOO_LARGE = 'فایل بزرگ‌تر از چیزی است که هاست می‌پذیرد (%s)؛ حد بارگذاری را در تنظیمات PHP هاست بالا ببرید یا فایل را فشرده (zip) بارگذاری کنید.';
    // «.sql» is set apart (U+2068…U+2069): in a right-to-left line it would read «sql.».
    public const NOT_A_DUMP = "این فایل، پشتیبان دیتابیس نیست؛ فایل \u{2068}.sql\u{2069} پشتیبان ربات قبلی را بارگذاری کنید، یا zip یا gz آن را.";
    public const NO_SQL_IN_ZIP = "در این فایل zip هیچ فایل \u{2068}.sql\u{2069} پیدا نشد.";
    public const BROKEN = 'این فایل ناقص است یا خراب شده و تا آخر خوانده نشد؛ پشتیبان را دوباره بگیرید و بارگذاری کنید.';
    public const NO_ZIP = "افزونه zip روی این هاست فعال نیست؛ فایل \u{2068}.sql\u{2069} را از داخل zip دربیاورید و همان را بارگذاری کنید.";
    public const NO_SPACE = 'فضای خالی هاست برای نگه داشتن و باز کردن این فایل کافی نیست؛ کمی جا باز کنید و دوباره امتحان کنید.';
    /** %s: the most a backup may unpack to (Messages::bytes()). */
    public const UNPACKS_TOO_LARGE = 'این فایل فشرده باز که شود از %s بزرگ‌تر است؛ چنین پشتیبانی خوانده نمی‌شود.';
    public const GONE = 'آنچه این انتقال از آن خوانده می‌شد دیگر در دسترس نیست؛ انتقال را لغو کنید و از نو شروع کنید.';
    public const HOST_MISSING = 'آدرس سرور دیتابیس ربات قبلی را وارد کنید.';
    public const HOST_INVALID = 'آدرس سرور دیتابیس درست نیست؛ فقط نام یا IP سرور را بنویسید (مثلا localhost).';
    public const PORT_INVALID = 'پورت باید عددی بین 1 تا 65535 باشد.';
    public const DATABASE_MISSING = 'نام دیتابیس ربات قبلی را وارد کنید.';
    public const USERNAME_MISSING = 'نام کاربری دیتابیس را وارد کنید.';
    public const NO_MYSQL = 'افزونه pdo_mysql روی این هاست فعال نیست و اتصال به دیتابیس ممکن نیست؛ به‌جایش فایل پشتیبان را بارگذاری کنید.';
    public const UNREACHABLE = 'به سرور دیتابیس وصل نشد؛ آدرس و پورت را بررسی کنید، و این‌که سرور دیتابیس اتصال از این هاست را می‌پذیرد (Remote MySQL).';
    public const DENIED = 'نام کاربری یا رمز دیتابیس پذیرفته نشد.';
    public const NO_ACCESS = 'این کاربر به این دیتابیس دسترسی ندارد.';
    public const UNKNOWN_DATABASE = 'دیتابیسی با این نام روی این سرور نیست.';
    public const EMPTY_DATABASE = 'این دیتابیس هیچ جدولی ندارد.';
    public const CONNECTION_LOST = 'خواندن دیتابیس ربات قبلی نیمه‌کاره ماند (%s)؛ اتصال را بررسی کنید و انتقال را از نو شروع کنید.';
    public const NOT_THIS_BOT = 'این داده‌ها مال %s نیست؛ جدول‌هایی که این ربات دارد پیدا نشد (%s).';
    /** A data-only export: its INSERTs name no columns and no CREATE TABLE says them (%s: a few of those tables). */
    public const NO_STRUCTURE = 'این فایل فقط داده‌های جدول‌ها را دارد، بدون ساختارشان (%s)؛ پشتیبان را دوباره با ساختار بگیرید (در phpMyAdmin گزینه «ساختار و داده» یا Structure and data)، یا پشتیبان خود ربات قبلی را بارگذاری کنید.';
    public const UNKNOWN_MIGRATOR = 'ربات قبلی را از فهرست انتخاب کنید.';
    public const BUSY = 'یک انتقال دیگر در جریان است؛ اول آن را تمام یا لغو کنید.';
    public const NOT_NOW = 'این انتقال در این مرحله نیست.';

    public static function on(string $field, string $message): ValidationException
    {
        return ValidationException::on($field, $message);
    }
}
