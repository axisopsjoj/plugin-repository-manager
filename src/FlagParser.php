<?php

namespace Axisops\PluginRepoManager;

/**
 * Determines the active dependency channel from the AXISOPS_CONTEXT environment
 * variable, e.g.:
 *
 *   AXISOPS_CONTEXT=prod composer update
 *   AXISOPS_CONTEXT=local composer install
 *
 * An environment variable is used rather than a CLI flag because Composer
 * (via Symfony Console) validates and rejects unknown command options *before*
 * any plugin is activated, so a bare "--prod" flag can never reach plugin code.
 */
class FlagParser
{
    public const ENV_VAR = 'AXISOPS_CONTEXT';

    /** Channel used when AXISOPS_CONTEXT is unset or empty. */
    public const DEFAULT_CHANNEL = 'prod';

    /**
     * Channel name => the version-constraint template applied to each axisops/*
     * requirement. {version} is replaced with extra.channel-version.
     */
    public const CHANNELS = [
        'local' => 'dev-develop as {version}',
        'alpha' => '>={version}-alpha.1',
        'beta'  => '>={version}-beta.1',
        'rc'    => '>={version}-rc.1',
        'prod'  => '>={version}',
    ];

    /** @var string The active channel name (defaults to DEFAULT_CHANNEL). */
    private string $channel = self::DEFAULT_CHANNEL;

    /**
     * @throws \RuntimeException if AXISOPS_CONTEXT is set to an unknown value.
     */
    public function __construct(?string $rawValue = null)
    {
        $value = $rawValue ?? (getenv(self::ENV_VAR) ?: null);

        if ($value === null || $value === '') {
            return; // keep DEFAULT_CHANNEL
        }

        $value = strtolower(trim($value));

        if (!isset(self::CHANNELS[$value])) {
            throw new \RuntimeException(sprintf(
                'Unknown %s value "%s". Valid channels: %s.',
                self::ENV_VAR,
                $value,
                implode(', ', array_keys(self::CHANNELS))
            ));
        }

        $this->channel = $value;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function isLocal(): bool
    {
        return $this->channel === 'local';
    }

    /**
     * The constraint string for the active channel with {version} substituted.
     */
    public function constraint(string $channelVersion): string
    {
        return str_replace('{version}', $channelVersion, self::CHANNELS[$this->channel]);
    }
}
