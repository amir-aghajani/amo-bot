<?php

declare(strict_types=1);

namespace App\Modules\Installer\Services;

use App\Core\Config\ConfigFile;
use App\Core\Config\ConfigValues;
use App\Core\Database\Drivers\ProbeFailedException;
use App\Core\Database\Schema;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\FieldType;
use App\Core\Installation;
use App\Core\Security\Encrypter;
use App\Modules\Auth\Credentials;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Settings\Services\ConfigSettings;
use App\Modules\Settings\Services\DatabaseSettings;
use App\Modules\Telegram\Api\BotTokenException;
use App\Support\Input;
use App\Support\Requirements;
use Psr\Log\LoggerInterface;

/**
 * Setting the shop up, step by step — the web installer's (the owner's panel, over /api/install): the host's
 * requirements, config.php with a key of the shop's own, the database — written only once its driver's probe answers —
 * and its tables, the panel's login, the shop's name and address (and the bot's token, when it is at hand), then the
 * lock that ends the installation. Every step can be taken again until then; each answers a refusal with a
 * ValidationException in the owner's words.
 */
final class Installer
{
    public function __construct(
        private readonly ConfigFile $file,
        private readonly ConfigSettings $settings,
        private readonly DatabaseSettings $database,
        private readonly Schema $schema,
        private readonly AdminAccount $admin,
        private readonly Requirements $requirements,
        private readonly Installation $installation,
        private readonly InstallKey $key,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array<string, mixed> Where the installation stands, step by step */
    public function status(): array
    {
        $requirements = $this->requirements->check();
        $file = $this->file->all();
        $text = static fn(string $key): string => is_scalar($file[$key] ?? null) ? (string) $file[$key] : '';

        return [
            'requirements' => $requirements,
            'ready' => Requirements::met($requirements),
            'database' => $this->database((new ConfigValues($file))->prefixed('DB_')),
            'admin' => ['configured' => $this->admin->configured(), 'username' => $this->admin->username()],
            'site' => ['name' => $text('APP_NAME'), 'url' => $text('APP_URL')],
            'telegram' => ['configured' => $text('TELEGRAM_BOT_TOKEN') !== '', 'username' => $text('TELEGRAM_BOT_USERNAME')],
        ];
    }

    /**
     * The database the shop runs on, written to config.php only once its driver's probe answers. A secret left blank is
     * an empty one here (a local MySQL often has no password): the installer shows nothing kept.
     *
     * @param array<string, mixed> $input {driver?, …the driver's fields}
     * @throws ValidationException
     */
    public function saveDatabase(array $input): void
    {
        $this->prepareConfigFile();
        foreach ($this->database->chosen($input)->describe()->form->fields as $field) {
            if ($field->type() === FieldType::Secret && Input::text($input, $field->name) === '') {
                $input['clear_' . $field->name] = true;
            }
        }
        $this->settings->saveDatabase($input);
    }

    /**
     * The shop's tables, on the database config.php names (on the web, a request after the one that wrote it): the ones
     * it does not have yet.
     *
     * @return list<string> The tables made now
     * @throws ValidationException
     */
    public function createTables(): array
    {
        if (!$this->database((new ConfigValues($this->file->all()))->prefixed('DB_'))['connected']) {
            throw ValidationException::on('tables', 'اول دیتابیس را وصل کنید.');
        }

        try {
            return $this->schema->create();
        } catch (\PDOException $e) {
            // The database's own words (a missing privilege, a full disk) are what the owner fixes it by.
            $this->logger->error('The tables could not be made: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            throw ValidationException::on('tables', 'ساختن جدول‌ها ناموفق بود: ' . $e->getMessage());
        }
    }

    /**
     * The panel's login — the installer's step —, by the one rule (Credentials), its password kept as a hash.
     *
     * @param array<string, mixed> $input {username, password, password_confirmation}
     * @throws ValidationException
     */
    public function saveAdmin(array $input): void
    {
        [$credentials, $errors] = Credentials::fromInput($input);
        ValidationException::ifAny($errors);

        $this->admin->save($credentials);
    }

    /**
     * The shop's name and public address, and the bot's token when the owner gives it (written once Telegram knows it).
     *
     * @param array<string, mixed> $input {name, url, token?}
     * @throws ValidationException|BotTokenException
     */
    public function saveSite(array $input): void
    {
        $this->prepareConfigFile();
        $this->settings->saveSite($input);

        $token = Input::text($input, 'token');
        if ($token !== '') {
            $this->settings->saveBotToken($token);
        }
    }

    /**
     * End the installation: from now on the panel signs in, and the installer and its key are gone.
     *
     * @throws ValidationException when a step the shop cannot run without is not done
     */
    public function finish(): void
    {
        $status = $this->status();

        $errors = [];
        if (!$status['ready']) {
            $errors['requirements'][] = 'پیش‌نیازهای سرور کامل نیست.';
        }
        if (!$status['database']['tables']) {
            $errors['tables'][] = 'دیتابیس وصل نیست یا جدول‌هایش ساخته نشده است.';
        }
        if (!$status['admin']['configured']) {
            $errors['admin'][] = 'نام کاربری و رمز ورود پنل تعیین نشده است.';
        }
        ValidationException::ifAny($errors);

        try {
            $this->installation->markInstalled();
        } catch (\RuntimeException $e) {
            $this->logger->error('The installation lock could not be written: {message}', ['message' => $e->getMessage()]);

            throw ValidationException::on('requirements', 'فایل پایان نصب در پوشه storage نوشته نشد.');
        }
        $this->key->forget();
    }

    /**
     * The config.php the shop runs on, with a key of the shop's own — made when there is none.
     *
     * @throws ValidationException when it cannot be made or written
     */
    private function prepareConfigFile(): void
    {
        $key = $this->file->get('APP_KEY');
        if (is_string($key) && $key !== '') {
            return;
        }

        try {
            $this->file->set('APP_KEY', Encrypter::generateKey());
        } catch (\RuntimeException $e) {
            $this->logger->error('{file} could not be written: {message}', ['file' => $this->file->path(), 'message' => $e->getMessage()]);

            throw ValidationException::on('file', ConfigFile::UNWRITABLE);
        }
    }

    /**
     * The database step as it shows, whether a database answers to what config.php names and whether the shop's tables
     * are all there. Not asked before config.php names a driver: a fresh copy has nothing worth trying.
     *
     * @param array<string, string> $config config.php's DB_* settings
     * @return array<string, mixed>
     */
    private function database(array $config): array
    {
        $state = $this->database->present() + ['connected' => false, 'tables' => false, 'error' => null];
        if (!isset($config['DB_CONNECTION'])) {
            return $state;
        }

        try {
            $this->database->probe($config);
        } catch (ProbeFailedException $e) {
            return ['error' => $e->getMessage()] + $state;
        }

        $state['connected'] = true;
        try {
            $state['tables'] = $this->schema->missing() === [];
        } catch (\PDOException) {
            // This process still runs on the database it started with, which may not answer; the next request reads
            // the one config.php names now.
        }

        return $state;
    }
}
