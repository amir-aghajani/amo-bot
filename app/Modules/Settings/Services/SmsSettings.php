<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Core\Drivers\Registry;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Core\Sms\ReportsCredit;
use App\Core\Sms\SmsCredit;
use App\Core\Sms\SmsDriver;
use App\Core\Sms\SmsFailedException;
use App\Core\Sms\SmsSenders;
use App\Core\Sms\SmsSettingsException;
use App\Modules\Settings\Exceptions\SmsCreditUnreadException;
use App\Modules\Settings\Exceptions\SmsNotReadyException;

/**
 * The SMS part of config.php (ConfigSettings' `sms` group): the way codes go out (none or an SMS driver), that
 * driver's settings, and whether agents' websites may send them too. A save checks only the chosen driver's form with
 * the agents' switch; the other drivers' settings stay as kept, for a way back.
 */
final class SmsSettings
{
    /** @param Registry<SmsDriver> $drivers */
    public function __construct(
        private readonly ConfigFile $file,
        private readonly Config $config,
        private readonly Registry $drivers,
    ) {}

    /**
     * The group as the screen shows it: the way out, the agents' switch, every driver described, and each driver's values.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $kept = $this->kept();
        $values = [];
        foreach ($this->drivers->all() as $driver) {
            $values[$driver->key()] = (object) $driver->describe()->form->present($kept);
        }

        return (new Form('sms', [$this->driver(), $this->agents()]))->present($kept) + [
            'drivers' => array_map(static fn(SmsDriver $driver): array => $driver->describe()->toArray(), $this->drivers->all()),
            'values' => $values,
        ];
    }

    /**
     * Check the group: SMS_DRIVER and, for a driver, its settings and SMS_AGENTS. An unknown way out is refused alone,
     * since there is no form to check the rest by.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        try {
            $key = $this->driver()->read($input, null);
        } catch (FieldRefused $refused) {
            throw ValidationException::on('driver', $refused->getMessage());
        }
        if ($key === SmsSenders::NONE) {
            return ['SMS_DRIVER' => SmsSenders::NONE];
        }

        return ['SMS_DRIVER' => $key] + (new Form('sms', [...$this->drivers->get($key)->describe()->form->fields, $this->agents()]))->check($input, $this->kept());
    }

    /**
     * The account's credit by the settings as saved, asked of the provider — null when its driver tells none.
     *
     * @throws SmsNotReadyException 422: no driver, or not its settings, saved
     * @throws SmsCreditUnreadException 502: the provider could not be asked, or refused the key — its words said
     */
    public function credit(): ?SmsCredit
    {
        $file = $this->file->all();
        $driver = $this->drivers->find(trim(is_string($file['SMS_DRIVER'] ?? null) ? $file['SMS_DRIVER'] : SmsSenders::NONE));
        if ($driver === null) {
            throw new SmsNotReadyException();
        }

        try {
            $sender = $driver->sender(SmsSenders::values($driver, $file));
        } catch (SmsSettingsException) {
            throw new SmsNotReadyException();
        }
        if (!$sender instanceof ReportsCredit) {
            return null;
        }

        try {
            return $sender->credit();
        } catch (SmsFailedException $e) {
            throw new SmsCreditUnreadException($e->reason);
        }
    }

    /** The way out: none or a driver; defaults to the running one (none for a driver it does not have). */
    private function driver(): Choice
    {
        $keys = [SmsSenders::NONE, ...array_map(static fn(SmsDriver $driver): string => $driver->key(), $this->drivers->all())];
        $running = (string) $this->config->get('sms.driver');

        return new Choice('driver', 'SMS_DRIVER', in_array($running, $keys, true) ? $running : SmsSenders::NONE, $keys, refusal: 'روش ارسال پیامک باید یکی از این‌ها باشد: ' . implode('، ', $keys) . '.');
    }

    /** Whether agents' websites may send sign-in codes too, on the owner's account. */
    private function agents(): Toggle
    {
        return new Toggle('agents', 'SMS_AGENTS', (bool) $this->config->get('sms.agents', false));
    }

    /** @return \Closure(Field<mixed>): mixed What config.php holds under a field's key */
    private function kept(): \Closure
    {
        $file = $this->file->all();

        return static fn(Field $field): mixed => $file[$field->key] ?? null;
    }
}
