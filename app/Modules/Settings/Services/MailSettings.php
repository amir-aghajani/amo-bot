<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Core\Drivers\Registry;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\EmailAddress;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Form;
use App\Core\Mail\MailDriver;
use App\Core\Mail\MailTransport;

/**
 * The shop's email part of config.php, for the settings screen (ConfigSettings' `mail` group): how it goes out — none,
 * or a mail driver (App\Core\Mail\Drivers) —, the chosen driver's own settings by its form, and who the emails come
 * from. Every driver is shown with its form described and its settings as config.php holds them; a save checks the
 * chosen driver's form with the sender's, and leaves the other drivers' settings as they are kept — a secret among them
 * goes nowhere new (its `boundTo`).
 */
final class MailSettings
{
    /** @param Registry<MailDriver> $drivers */
    public function __construct(
        private readonly ConfigFile $file,
        private readonly Config $config,
        private readonly Registry $drivers,
    ) {}

    /**
     * The group as the screen shows it: the way out, who the emails come from, every mail driver described, and each
     * driver's settings by its key — a secret as whether one is kept.
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

        return (new Form('mail', [$this->transport(), ...$this->sender()]))->present($kept) + [
            'drivers' => array_map(static fn(MailDriver $driver): array => $driver->describe()->toArray(), $this->drivers->all()),
            'values' => $values,
        ];
    }

    /**
     * The group's form checked: MAIL_TRANSPORT, and — for a driver — its settings and the sender's, ready to write. A
     * way out none of the drivers' is refused alone: there is no form to check the rest by.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        try {
            $key = $this->transport()->read($input, null);
        } catch (FieldRefused $refused) {
            throw ValidationException::on('transport', $refused->getMessage());
        }
        if ($key === MailTransport::NONE) {
            return ['MAIL_TRANSPORT' => MailTransport::NONE];
        }

        return ['MAIL_TRANSPORT' => $key] + (new Form('mail', [...$this->drivers->get($key)->describe()->form->fields, ...$this->sender()]))->check($input, $this->kept());
    }

    /** None, or a mail driver — what the shop runs with while config.php does not say; none for a driver it does not have. */
    private function transport(): Choice
    {
        $keys = [MailTransport::NONE, ...array_map(static fn(MailDriver $driver): string => $driver->key(), $this->drivers->all())];
        $running = (string) $this->config->get('mail.transport');

        return new Choice('transport', 'MAIL_TRANSPORT', in_array($running, $keys, true) ? $running : MailTransport::NONE, $keys, refusal: 'روش ارسال ایمیل باید یکی از این‌ها باشد: ' . implode('، ', $keys) . '.');
    }

    /**
     * Who the emails come from, whichever driver sends them: an address — required, since no email goes from none —
     * and a name, blank for the shop's own.
     *
     * @return list<Field<mixed>>
     */
    private function sender(): array
    {
        return [
            new EmailAddress('from_address', 'MAIL_FROM_ADDRESS', (string) $this->config->get('mail.from_address'), label: 'ایمیل فرستنده', required: true),
            new Text('from_name', 'MAIL_FROM_NAME', (string) $this->config->get('mail.from_name'), label: 'نام فرستنده', max: 64),
        ];
    }

    /** @return \Closure(Field<mixed>): mixed What config.php holds under a field's key */
    private function kept(): \Closure
    {
        $file = $this->file->all();

        return static fn(Field $field): mixed => $file[$field->key] ?? null;
    }
}
