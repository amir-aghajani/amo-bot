<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Auth\StaffGrant;

/**
 * A decision this actor may not make whatever the row's state (403): a shop's admin — also its customer, wherever they
 * work it, the bot's management with the owner's or an agent's seat too — deciding about themselves (their payment,
 * wallet, service, agency request, review, referral alert, an order made for them); from the website, touching an
 * admin's or an agent's account, switching «همکار» on for a group an admin is in, putting a customer in a «همکار» group
 * without the `catalog` grant (one with a `grant`: StaffMiddleware answers it as its own door does), or approving a
 * payment with no receipt in review; from the report group, an admin's own account, an agent's, banning an admin,
 * putting one in a «همکار» group, setting one's own discount, making an admin of someone with either, or their website
 * sign-in; from the bot's management with the owner's seat, what hands over the main bot or the owner's seat (the owner's
 * Telegram accounts, the webhook's secret, the site's address, the main bot's token and @username, the Bot API's address,
 * the database, the panels' session settings) or
 * puts back a version without these guards (the update's rollback). The owner and agents themselves are refused none of
 * these.
 */
final class ActorRefusedException extends DomainRuleException
{
    public const OWN_PAYMENT = 'پرداخت خودتان را نمی‌توانید تایید یا بازپرداخت کنید؛ این کار با مدیر دیگری است.';
    public const OWN_WALLET = 'موجودی کیف پول خودتان را نمی‌توانید تغییر دهید؛ این کار با مدیر دیگری است.';
    public const OWN_SERVICE = 'سرویس خودتان را نمی‌توانید تغییر دهید؛ این کار با مدیر دیگری است.';
    public const OWN_REQUEST = 'درخواست نمایندگی خودتان را نمی‌توانید تایید کنید؛ این کار با مدیر دیگری است.';
    public const OWN_REVIEW = 'نظر خودتان را نمی‌توانید تایید، رد یا حذف کنید؛ این کار با مدیر دیگری است.';
    public const OWN_ALERT = 'هشدار زیرمجموعه‌گیری خودتان را نمی‌توانید رفع کنید؛ این کار با مدیر دیگری است.';
    public const OWN_ACCOUNT = 'حساب خودتان را از گروه گزارش‌ها نمی‌توانید تغییر دهید؛ این کار با مدیر دیگری یا پنل است.';
    public const ADMIN_BANNED = 'مدیر ربات مسدود نمی‌شود؛ اول نقش مدیرش را بردارید.';
    public const ADMIN_TO_AGENT_GROUP = 'مدیر ربات فقط از پنل یا «⚙️ مدیریت» ربات در گروه «همکار» گذاشته می‌شود.';
    public const ADMIN_DISCOUNT = 'تخفیف اختصاصی مدیر ربات فقط از پنل یا «⚙️ مدیریت» ربات تغییر می‌کند.';
    public const ADMIN_WITH_OWN_PRICES = 'این کاربر تخفیف اختصاصی دارد یا در گروه «همکار» است؛ مدیر ربات این‌ها را فقط از پنل یا «⚙️ مدیریت» ربات می‌گیرد. اول تخفیف و گروه «همکار» را از کارتش بردارید، یا از پنل یا «⚙️ مدیریت» ربات مدیرش کنید.';
    public const OWN_ORDER = 'برای خودتان نمی‌توانید سفارش ثبت کنید؛ این کار با مدیر دیگری است.';
    public const ADMIN_ACCOUNT = 'حساب مدیران فروشگاه فقط از پنل یا «⚙️ مدیریت» ربات تغییر می‌کند.';
    public const AGENT_ACCOUNT = 'حساب نماینده‌ها فقط از پنل یا «⚙️ مدیریت» ربات تغییر می‌کند.';
    public const ADMIN_IN_GROUP = 'گروه «%s» حساب یکی از مدیران فروشگاه را دارد؛ «همکار» کردن آن فقط از پنل یا «⚙️ مدیریت» ربات ممکن است.';
    public const RECEIPT_FIRST = 'از وب‌سایت فقط پرداختی تایید می‌شود که رسیدش رسیده و در انتظار بررسی است.';
    public const AGENT_GROUP_NOT_GRANTED = 'فروشگاه گذاشتن مشتری در گروه «همکار» را به مدیران وب‌سایت نسپرده است.';
    public const OWN_PRICES = 'قیمت‌های خودتان را نمی‌توانید عوض کنید؛ تخفیف اختصاصی و گروه «همکار» خودتان با مدیر دیگری است.';
    public const OWNER_ONLY = 'حساب‌های تلگرام مالک را فقط خود مالک تغییر می‌دهد.';
    public const OWN_AGENCY = 'نمایندگی خودتان را نمی‌توانید تغییر دهید؛ این کار با مدیر دیگری است.';
    public const OWNER_BANNED = 'این حساب تلگرام مالک فروشگاه است و مسدود نمی‌شود.';
    public const OWNER_ROLE = 'نقش حساب تلگرام مالک فروشگاه را فقط خود مالک عوض می‌کند.';
    public const AGENT_BANNED = 'این حساب نماینده صاحب این ربات است و مسدود نمی‌شود.';
    public const OWNERS_SECRET = 'رمز Webhook ربات اصلی را فقط خود مالک عوض می‌کند.';
    public const OWNERS_ADDRESS = 'آدرس سایت را فقط خود مالک عوض می‌کند؛ پیام‌های تلگرام همه ربات‌ها به همین آدرس فرستاده می‌شود.';
    public const OWNERS_BOT = 'توکن و نام کاربری ربات اصلی را فقط خود مالک عوض می‌کند.';
    public const OWNERS_DATABASE = 'دیتابیس فروشگاه را فقط خود مالک عوض یا تست می‌کند.';
    public const OWNERS_ROLLBACK = 'برگشت به نسخه قبلی برنامه فقط با خود مالک است.';
    public const OWNERS_API = 'آدرس API تلگرام را فقط خود مالک عوض می‌کند؛ توکن همه ربات‌ها به همین آدرس فرستاده می‌شود.';
    public const OWNERS_SESSION = 'طول Session و کوکی Session پنل‌ها را فقط خود مالک عوض می‌کند.';
    public const OWNERS_BACKUP = 'این فایل پشتیبان پیش از نسخه 0.11.0 ساخته شده و رمز Webhook ربات‌ها در آن است؛ دریافت و بازگردانی‌اش فقط با خود مالک یا خود نماینده است.';

    private function __construct(
        string $message,
        /** The grant the decision asks that the actor does not hold now (Actor::holds()); null for the other refusals. */
        public readonly ?StaffGrant $grant = null,
    ) {
        parent::__construct($message);
    }

    public static function ownPayment(): self
    {
        return new self(self::OWN_PAYMENT);
    }

    public static function ownWallet(): self
    {
        return new self(self::OWN_WALLET);
    }

    public static function ownService(): self
    {
        return new self(self::OWN_SERVICE);
    }

    public static function ownRequest(): self
    {
        return new self(self::OWN_REQUEST);
    }

    public static function ownReview(): self
    {
        return new self(self::OWN_REVIEW);
    }

    public static function ownAlert(): self
    {
        return new self(self::OWN_ALERT);
    }

    public static function ownOrder(): self
    {
        return new self(self::OWN_ORDER);
    }

    /** A report group's admin about their own account (its role, standing, groups). */
    public static function ownAccount(): self
    {
        return new self(self::OWN_ACCOUNT);
    }

    /** A report group's admin banning one of the shop's admins: their role goes first. */
    public static function adminBanned(): self
    {
        return new self(self::ADMIN_BANNED);
    }

    /** A report group's admin putting one of the shop's admins in a «همکار» group, whose agent prices would be theirs. */
    public static function adminToAgentGroup(): self
    {
        return new self(self::ADMIN_TO_AGENT_GROUP);
    }

    /**
     * A report group's admin setting one of the shop's admins' own discount: their prices are lowered from the panel or
     * the bot's management.
     */
    public static function adminDiscount(): self
    {
        return new self(self::ADMIN_DISCOUNT);
    }

    /**
     * A report group's admin making an admin of someone with their own discount or in a «همکار» group: the panel's or the
     * bot's management's to give an admin, so taking the role off, setting either and giving it back does not go round
     * the two rules above.
     */
    public static function adminWithOwnPrices(): self
    {
        return new self(self::ADMIN_WITH_OWN_PRICES);
    }

    public static function adminAccount(): self
    {
        return new self(self::ADMIN_ACCOUNT);
    }

    public static function agentAccount(): self
    {
        return new self(self::AGENT_ACCOUNT);
    }

    /** The customer group `$group` (its name) holds an admin's account: «همکار» would set their prices. */
    public static function adminInGroup(string $group): self
    {
        return new self(sprintf(self::ADMIN_IN_GROUP, $group));
    }

    /** A shop's admin on its website approving a payment with no receipt in review. */
    public static function receiptFirst(): self
    {
        return new self(self::RECEIPT_FIRST);
    }

    /** A customer put in a «همکار» group — the plans' agent prices — by a shop's admin on its website who holds no `catalog` now. */
    public static function agentGroupNotGranted(): self
    {
        return new self(self::AGENT_GROUP_NOT_GRANTED, StaffGrant::Catalog);
    }

    /** An admin's own prices — their own discount, or a «همکار» group put on their own account. */
    public static function ownPrices(): self
    {
        return new self(self::OWN_PRICES);
    }

    /**
     * What only the owner themselves decides (Principal::isTheOwner()), refused to one of the main shop's admins working
     * the bot's management with the owner's seat: which Telegram accounts are the owner.
     */
    public static function ownerOnly(): self
    {
        return new self(self::OWNER_ONLY);
    }

    /** An admin who is an agent too changing their own agency: its level and credit, its terms, its traffic, its selling. */
    public static function ownAgency(): self
    {
        return new self(self::OWN_AGENCY);
    }

    /** A Telegram account linked as the owner banned by anyone but the owner: it would shut the owner out of the bot. */
    public static function ownerBanned(): self
    {
        return new self(self::OWNER_BANNED);
    }

    /** The role of a Telegram account linked as the owner changed by anyone but the owner. */
    public static function ownerRole(): self
    {
        return new self(self::OWNER_ROLE);
    }

    /** An agent's own account in their bot banned by anyone but the agent or the owner: it would shut them out of it. */
    public static function agentBanned(): self
    {
        return new self(self::AGENT_BANNED);
    }

    /**
     * The main bot's webhook secret changed by anyone but the owner themselves: whoever knows it can send the shop
     * updates in anyone's name — the owner's linked account's too —, a role taken away or not.
     */
    public static function ownersSecret(): self
    {
        return new self(self::OWNERS_SECRET);
    }

    /**
     * The site's address (APP_URL) changed by anyone but the owner themselves: every bot's webhook is registered under it,
     * so whoever moves it to a host of theirs is sent each update with its secret.
     */
    public static function ownersAddress(): self
    {
        return new self(self::OWNERS_ADDRESS);
    }

    /**
     * The main bot's token or @username changed by anyone but the owner themselves: another bot's token shows its webhook's
     * address, secret and all, to whoever holds it; another @username sends the owner's one-time link (OwnerLinks) there.
     */
    public static function ownersBot(): self
    {
        return new self(self::OWNERS_BOT);
    }

    /**
     * The database saved or tried by anyone but the owner themselves: whoever picks it picks who the owner is in the bot
     * (its main shop's settings, OwnerLinks).
     */
    public static function ownersDatabase(): self
    {
        return new self(self::OWNERS_DATABASE);
    }

    /**
     * The last update rolled back by anyone but the owner themselves: the version before lacks the guards that came since
     * — these among them.
     */
    public static function ownersRollback(): self
    {
        return new self(self::OWNERS_ROLLBACK);
    }

    /**
     * The Bot API's address changed by anyone but the owner themselves — its path on the same server too: every bot's token
     * goes there, agents' too.
     */
    public static function ownersApi(): self
    {
        return new self(self::OWNERS_API);
    }

    /**
     * The panels' session settings changed by anyone but the owner themselves: a longer session, or a cookie the host
     * cannot keep, is the owner's own sign-in made weaker or shut.
     */
    public static function ownersSession(): self
    {
        return new self(self::OWNERS_SESSION);
    }

    /**
     * A backup file made before 0.11.0 (Backups::MASKED_SINCE) downloaded or restored by an admin working a bot's
     * management with its owner's or agent's seat, not the owner or the agent themselves: it holds each bot's webhook
     * address whole, its secret with it — the main bot's lets an update in under the owner's Telegram account.
     */
    public static function ownersBackup(): self
    {
        return new self(self::OWNERS_BACKUP);
    }

    public function status(): int
    {
        return 403;
    }
}
