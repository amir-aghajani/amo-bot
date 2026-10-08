<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Core\Support\FileCache;
use App\Core\Support\Files;
use App\Core\Support\Picture;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Users\Enums\PictureFolder;
use App\Modules\Users\Exceptions\StorageFullException;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Validation;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * The one rule for a picture the shop is sent — a card transfer's receipt, a support ticket's picture —, wherever it
 * comes from. Sent in the bot, it stays Telegram's: the shop keeps its file id and asks Telegram for its bytes when a
 * screen shows it, keeping them a short while for the reads that follow (fromTelegram(), Core\Support\FileCache); a file
 * sent there is a picture by what it says it is, how big Telegram knows it to be and its bytes (isPicture()). Uploaded —
 * from a website, from a panel —, it is judged by its bytes alone, never by what its sender says it is: a JPEG, a PNG or
 * a WebP (what the panels show), MAX_BYTES at most, read no further (judge()) — and none at all while the host's disk has
 * less than ROOM left, or once the shop's uploads took DAY_MEGABYTES in a day (a 503, StorageFullException). What a
 * customer uploads — receipts and tickets' pictures alike — is one budget, UPLOADS in UPLOAD_WINDOW (budget()). Kept in
 * its kind's folder under a name of the shop's own — the device's name only ever a label (cleanName()) —, as GD writes
 * it again when the host has GD (keep(): no larger than MAX_SIDE, turned as its camera said, nothing of the sender's file
 * kept but its pixels), read back (read(), a report's photo by its reference()), and deleted only here, once what made it
 * is undone or over (discard()). Served by its bytes' own type (served(): what ApiController::bytes() shows as itself, or
 * hands over as a file to save).
 */
final class CustomerPictures
{
    /** The largest picture the shop takes — in the bot, or uploaded: a picture is far smaller. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** The pictures a customer uploads — receipts and tickets' together — in a window of UPLOAD_WINDOW seconds (budget()). */
    public const UPLOADS = 10;
    public const UPLOAD_WINDOW = 3600;

    /** The budget's refusal, in the customer's words: TooManyAttemptsException::wait() adds the wait. */
    public const TOO_MANY = 'تصویر زیادی فرستاده‌اید';

    /** The room the shop keeps free on the host's disk: an upload that would eat into it is refused (the container's). */
    public const ROOM = 200 * 1024 * 1024;

    /**
     * The megabytes a shop's uploads may take in a day — every picture of it kept, a customer's or support's, counted by
     * the megabytes it begins —: accounts made by the hundred fill no host's disk. Past it the shop takes no picture until
     * its day is over, and its owner hears it in the log once.
     */
    public const DAY_MEGABYTES = 1024;

    /** The longest side a kept picture has: a receipt's or a screenshot's words stay legible, a camera's photo shrinks. */
    public const MAX_SIDE = 2560;

    /** The quality a picture is written again with (JPEG, WebP). */
    private const QUALITY = 85;

    /** The longest file name a picture keeps. */
    private const NAME_MAX = 255;

    /** What a picture sent in the bot as a file may be, by its bytes (a photo is Telegram's own JPEG). */
    private const PICTURES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    /** What an upload may be, by its bytes — a picture the panels show — and the extension it is kept under. */
    private const UPLOAD_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const EXTENSIONS = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'image/gif' => '.gif', 'image/heic' => '.heic', 'image/heif' => '.heif'];

    /** How much of an upload is read at a time. */
    private const CHUNK = 65_536;

    /** The window of a shop's day of uploads (DAY_MEGABYTES), in seconds. */
    private const DAY = 86400;

    /**
     * @param array<string, string> $folders Where each kind is kept, by PictureFolder's value — the container's `receipts.path`, `tickets.path`
     * @param int $room The bytes the shop keeps free on their disk (ROOM)
     * @param RateLimiter $limiter What counts a shop's day of uploads (DAY_MEGABYTES)
     * @param FileCache $fetched Where a picture fetched from Telegram is kept a while
     */
    public function __construct(
        private readonly BotApi $api,
        private readonly array $folders,
        private readonly LoggerInterface $logger,
        private readonly int $room,
        private readonly RateLimiter $limiter,
        private readonly FileCache $fetched,
    ) {}

    /**
     * What a customer's uploads are counted in — a receipt and a ticket's picture alike, each as it is kept —: their one
     * window, `[key, UPLOADS, UPLOAD_WINDOW]`, for Core\Security\RateLimiter::attempt() beside the caller's own.
     *
     * @return array{string, int, int}
     */
    public static function budget(User $customer): array
    {
        return ['uploads|' . $customer->bot_id . '|' . $customer->id, self::UPLOADS, self::UPLOAD_WINDOW];
    }

    /**
     * Whether a file sent in the bot is a picture: one by what it says it is — so nothing else is downloaded —, no
     * larger than MAX_BYTES by what it says and by what Telegram knows of it, and one by its bytes.
     *
     * @param array<string, mixed> $document Telegram's Document
     * @throws TelegramApiException when Telegram does not hand the file over
     */
    public function isPicture(array $document): bool
    {
        if (!str_starts_with((string) ($document['mime_type'] ?? ''), 'image/') || (int) ($document['file_size'] ?? 0) > self::MAX_BYTES) {
            return false;
        }
        $bytes = $this->telegramFile((string) ($document['file_id'] ?? ''));

        return $bytes !== null && in_array(Picture::typeOf($bytes), self::PICTURES, true);
    }

    /**
     * An upload judged by its bytes — a JPEG, a PNG or a WebP, MAX_BYTES at most, read no further than that —: its bytes
     * and the extension it is kept under (keep()). `$what` names it in a refusal («تصویر رسید», «تصویر»). None is taken
     * while the host's disk has less than the room the shop keeps free, nor once the shop's day of uploads is spent.
     *
     * @return array{bytes: string, extension: string}
     * @throws ValidationException 422 on `file`
     * @throws StorageFullException 503: the disk is (all but) full, or the shop's uploads took DAY_MEGABYTES today
     */
    public function judge(UploadedFileInterface $file, string $what): array
    {
        $this->assertRoom();
        if ($this->limiter->availableIn(self::dayOf(CurrentBot::id()), self::DAY_MEGABYTES) > 0) {
            $this->refuseToday();
        }
        $failure = Validation::uploadFailure($file);
        if ($failure !== null) {
            throw ValidationException::on('file', $failure);
        }
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            throw ValidationException::on('file', self::tooLarge($what));
        }

        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = '';
        while (!$stream->eof() && strlen($bytes) <= self::MAX_BYTES) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::on('file', self::tooLarge($what));
        }

        return ['bytes' => $bytes, 'extension' => self::UPLOAD_TYPES[Picture::typeOf($bytes)] ?? throw ValidationException::on('file', "{$what} باید JPG، PNG یا WebP باشد.")];
    }

    /**
     * An upload kept in its kind's folder — as GD writes it again (fitted()), counted in its shop's day by the megabytes it
     * begins, written whole, under a name of the shop's own: `$prefix` (whose it is: the shop and the row) and a random
     * part, never the sender's file name —: its name there, what its row records.
     *
     * @throws StorageFullException 503: it would take the shop's uploads past DAY_MEGABYTES today — nothing kept
     * @throws \RuntimeException when the folder cannot be written
     */
    public function keep(PictureFolder $folder, string $prefix, string $bytes, string $extension): string
    {
        [$bytes, $extension] = self::fitted($bytes, $extension);
        // Each megabyte begun one try of the day's window — all of them counted, or none (RateLimiter::attempt()).
        $megabytes = array_fill(0, max(1, (int) ceil(strlen($bytes) / 1048576)), [self::dayOf(CurrentBot::id()), self::DAY_MEGABYTES, self::DAY]);
        if ($this->limiter->attempt($megabytes) > 0) {
            $this->refuseToday();
        }
        $name = sprintf('%s-%s.%s', $prefix, bin2hex(random_bytes(8)), $extension);
        Files::writeAtomically($this->path($folder, $name), $bytes);

        return $name;
    }

    /** A kept picture's bytes, by its name in its kind's folder; null once it is gone. */
    public function read(PictureFolder $folder, string $name): ?string
    {
        return Files::read($this->path($folder, $name));
    }

    /**
     * Kept pictures nobody needs any more — the one place one is deleted —, by their names in their kind's folder: once
     * what made them is undone or over and that is sure (after its commit). One the host will not let go of is logged,
     * never thrown: what deleted it stands.
     */
    public function discard(PictureFolder $folder, string ...$names): void
    {
        foreach ($names as $name) {
            if (!Files::delete($this->path($folder, $name))) {
                $this->logger->warning('The uploaded picture {name} could not be deleted from {folder}', ['name' => $name, 'folder' => $folder->value]);
            }
        }
    }

    /** A kept picture named wherever it is kept — `receipts/1-12-….png` —, for a report that sends it (report_messages.photo_path). */
    public static function reference(PictureFolder $folder, string $name): string
    {
        return $folder->value . '/' . basename($name);
    }

    /** The bytes a reference() names; null once the picture is gone, or for one that names no folder of the shop's. */
    public function readReference(string $reference): ?string
    {
        [$folder, $name] = array_pad(explode('/', $reference, 2), 2, '');
        $kind = PictureFolder::tryFrom($folder);

        return $kind === null || $name === '' ? null : $this->read($kind, $name);
    }

    /**
     * A picture sent in the bot, by its Telegram file id: its bytes — kept a short while for the reads that follow —, null
     * once Telegram no longer hands it over (gone, or one it turns down).
     *
     * @throws TelegramUnreachableException Telegram could not be asked: the picture is still there
     */
    public function fromTelegram(string $fileId): ?string
    {
        try {
            return $this->telegramFile($fileId);
        } catch (TelegramApiException $e) {
            if ($e->is(Refusal::BadRequest)) {
                return null;
            }

            throw new TelegramUnreachableException($e);
        }
    }

    /**
     * A picture as an answer serves it (ApiController::bytes()): its bytes, their type as the bytes say — a picture the
     * panels show goes as itself, anything else as a file to save —, and a name to save it under: the sender's, or
     * `$fallback` with the extension its type has.
     *
     * @return array{body: string, mime: string, name: string}
     */
    public static function served(string $body, ?string $name, string $fallback): array
    {
        $mime = Picture::typeOf($body);

        return ['body' => $body, 'mime' => $mime, 'name' => $name ?? $fallback . (self::EXTENSIONS[$mime] ?? '')];
    }

    /**
     * A file name as the sender's device gave it, kept to show and to save the picture under: its last part alone,
     * without the characters a viewer acts on (control codes, bidi overrides), NAME_MAX characters at most; null for
     * none.
     */
    public static function cleanName(?string $name): ?string
    {
        $clean = (string) preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', (string) $name);
        $clean = trim(basename(str_replace('\\', '/', $clean)));

        return $clean === '' ? null : mb_substr($clean, 0, self::NAME_MAX);
    }

    /**
     * No picture is taken while the disk the pictures go to has less than the room the shop keeps free — the owner hears
     * it in the log; a host that does not say how much is free takes it.
     *
     * @throws StorageFullException
     */
    private function assertRoom(): void
    {
        foreach ($this->folders as $folder) {
            $free = Files::freeSpace($folder);
            if ($free !== null && $free < $this->room) {
                $this->logger->warning('An upload was refused: the disk of {folder} has {free} MB free, under the {room} MB the shop keeps free', [
                    'folder' => $folder,
                    'free' => (int) floor($free / 1048576),
                    'room' => intdiv($this->room, 1048576),
                ]);

                throw StorageFullException::disk();
            }
        }
    }

    /**
     * A file Telegram keeps, by its id — MAX_BYTES at most, kept a short while once fetched (a receipt's screen, a ticket's
     * picture read again and again); null when Telegram has none.
     *
     * @throws TelegramApiException
     */
    private function telegramFile(string $fileId): ?string
    {
        return $this->fetched->remember('telegram|' . $fileId, fn(): ?string => $this->api->fileBytes($fileId, self::MAX_BYTES));
    }

    /**
     * The shop's day of uploads is spent: no picture is taken until it is over — its owner hears it once a day.
     *
     * @throws StorageFullException
     */
    private function refuseToday(): never
    {
        if ($this->limiter->attempt([[self::dayOf(CurrentBot::id()) . '|told', 1, self::DAY]]) === 0) {
            $this->logger->warning('Pictures are refused in shop {shop} for the rest of the day: its uploads took {megabytes} MB', ['shop' => CurrentBot::id(), 'megabytes' => self::DAY_MEGABYTES]);
        }

        throw StorageFullException::today();
    }

    /** What a shop's day of uploads is counted under (Core\Security\RateLimiter). */
    private static function dayOf(int $shop): string
    {
        return 'pictures|day|' . $shop;
    }

    /**
     * The picture as the shop keeps it: written again by GD (Core\Support\Picture) — nothing of the sender's file but its
     * pixels (no camera metadata, no location, nothing appended), turned the way its camera said it is held (EXIF), its
     * longest side MAX_SIDE at most — in its own type, a JPEG and a WebP at QUALITY. As it came where that cannot be done:
     * no GD, a picture GD does not read (an animated WebP), no WebP writer, or one too large to decode in the memory PHP
     * has left (a host's 64 MB is no 50-megapixel camera's) — the budget, the size limit and the room still hold it.
     *
     * @return array{string, string} The bytes and the extension to keep
     */
    private static function fitted(string $bytes, string $extension): array
    {
        $type = array_search($extension, self::UPLOAD_TYPES, true);
        $size = Picture::size($bytes);
        if ($type === false || $size === null || !Picture::decodable($size[0], $size[1], self::MAX_SIDE, strlen($bytes))) {
            return [$bytes, $extension];
        }

        $image = Picture::decode($bytes);
        $image = $image === null ? null : Picture::fitted($image, self::MAX_SIDE);
        if ($image === null) {
            return [$bytes, $extension];
        }
        $image = $type === 'image/jpeg' ? Picture::upright($image, $bytes) : $image;
        $written = Picture::encoded($image, $type, self::QUALITY);
        imagedestroy($image);

        return [$written ?? $bytes, $extension];
    }

    private function path(PictureFolder $folder, string $name): string
    {
        $dir = $this->folders[$folder->value] ?? throw new \LogicException("No folder is set for the {$folder->value} pictures.");

        return $dir . '/' . basename($name);
    }

    private static function tooLarge(string $what): string
    {
        return 'حجم ' . $what . ' حداکثر ' . Persian::number(intdiv(self::MAX_BYTES, 1024 * 1024)) . ' مگابایت می‌تواند باشد.';
    }
}
