<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\DTO\ClientNamePattern;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Forms\NamePattern;

/**
 * How a shop names its customers' clients on the panels (the `client_names` group): the pattern a sold service's name
 * follows, a free test's, and whether a buyer may instead take a random name or type their own (ClientNaming).
 */
final class ClientNameSettings implements DeclaresSettings
{
    private readonly NamePattern $pattern;
    private readonly NamePattern $testPattern;
    private readonly Toggle $random;
    private readonly Toggle $custom;

    public function __construct(private readonly Settings $settings)
    {
        $this->pattern = new NamePattern('name_pattern', 'names.pattern', ClientNamePattern::DEFAULT);
        $this->testPattern = new NamePattern('test_name_pattern', 'names.test_pattern', ClientNamePattern::TEST_DEFAULT);
        $this->random = new Toggle('name_random', 'names.random', default: false);
        $this->custom = new Toggle('name_custom', 'names.custom', default: false);
    }

    public function groups(): array
    {
        return [new Form('client_names', [$this->pattern, $this->testPattern, $this->random, $this->custom])];
    }

    /** The pattern a sold service's client is named by. */
    public function pattern(): ClientNamePattern
    {
        return ClientNamePattern::of($this->settings->read($this->pattern));
    }

    /** The pattern a free test's client is named by. */
    public function testPattern(): ClientNamePattern
    {
        return ClientNamePattern::of($this->settings->read($this->testPattern));
    }

    /**
     * The ways a buyer may name their new service, the shop's pattern first; just that one while the shop offers no
     * other (the bot and the website then ask nothing).
     *
     * @return list<ClientNameMode>
     */
    public function modes(): array
    {
        return [
            ClientNameMode::Sequential,
            ...$this->settings->read($this->random) ? [ClientNameMode::Random] : [],
            ...$this->settings->read($this->custom) ? [ClientNameMode::Custom] : [],
        ];
    }
}
