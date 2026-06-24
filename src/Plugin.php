<?php

namespace Axisops\PluginRepoManager;

use Composer\Plugin\PluginInterface;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Configures package sources and dependency versions for axisops/* packages
 * based on the AXISOPS_CONTEXT environment variable:
 *
 *   local   clone required packages into packages/ (develop branch) and
 *           require them as dev-develop; registry is not used.
 *   alpha   require >=<version>-alpha.1 from the registry
 *   beta    require >=<version>-beta.1  from the registry
 *   rc      require >=<version>-rc.1    from the registry
 *   prod    require >=<version>         from the registry  (the default)
 *   (unset) same as prod
 *
 * e.g. AXISOPS_CONTEXT=local composer update
 *
 * All work happens in activate() — before the dependency solver snapshots the
 * root requirements, so constraint rewrites and the injected repository are
 * honoured during resolution.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const DEFAULTS = [
        'registry-url'    => '',
        'git-base-url'    => '',
        'vendor-prefix'   => 'axisops/',
        'channel-version' => '12.0.0',
        // Package names never cloned, scoped, or constraint-rewritten. The
        // plugin excludes itself by default as a safeguard.
        'exclude'         => ['axisops/plugin-repository-manager'],
    ];

    public function activate(Composer $composer, IOInterface $io)
    {
        // Determine the active channel from AXISOPS_CONTEXT.
        try {
            $flags = new FlagParser();
        } catch (\RuntimeException $e) {
            $io->writeError('<error>' . $e->getMessage() . '</error>');
            throw $e;
        }

        $config = $this->config($composer);
        $vendorPrefix = $config['vendor-prefix'];
        $exclude = $config['exclude'];

        $required = $this->requiredAxisopsPackages($composer, $vendorPrefix, $exclude);

        $io->write('');
        $io->write('<info>Configuring axisops plugin repositories (channel: '
            . $flags->channel() . ')</info>');

        // Rewrite axisops/* constraints for the active channel.
        $constraint = $flags->constraint($config['channel-version']);
        $rewritten = (new ChannelResolver($vendorPrefix, $exclude))
            ->apply($composer->getPackage(), $constraint);
        if ($rewritten !== []) {
            $io->write('<info> Set ' . count($rewritten) . " package(s) to: $constraint</info>");
        }

        $configurator = new RepositoryConfigurator($composer, $io);

        if ($flags->isLocal()) {
            $packagesDir = $this->packagesDir($composer);

            // Credentials are needed to read the registry (for source URLs) and
            // to clone over HTTPS.
            (new AuthConfigurator($composer, $io))
                ->ensureCredentials($config['registry-url']);

            $resolver = new SourceResolver($composer, $io, $config['registry-url']);

            (new PackageCloner($io, $packagesDir, $vendorPrefix, $resolver, $config['git-base-url']))
                ->cloneMissing($required); // throws on failure -> aborts the command

            $configurator->configurePathRepositories($packagesDir);
        } else {
            (new AuthConfigurator($composer, $io))
                ->ensureCredentials($config['registry-url']); // throws if no creds & non-interactive
            $configurator->configureRegistry($config['registry-url'], $required);
        }

        $io->write('');
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD  => 'onPostInstallOrUpdate',
        ];
    }

    /**
     * After install/update completes, run `php artisan core:update` if this is a
     * Laravel project that has the command. Best-effort: warns, never fails.
     */
    public function onPostInstallOrUpdate(Event $event): void
    {
        $composer = $event->getComposer();
        $root = $this->projectRoot($composer);

        (new PostUpdateRunner($event->getIO(), $root))->run();
    }

    private function projectRoot(Composer $composer): string
    {
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $root = realpath($vendorDir . '/..');

        return $root !== false ? $root : getcwd();
    }

    /**
     * Read and normalise the plugin's "extra.axisops-repo-manager" config.
     *
     * @return array{registry-url:string,git-base-url:string,vendor-prefix:string,channel-version:string,exclude:string[]}
     */
    private function config(Composer $composer): array
    {
        $extra = $composer->getPackage()->getExtra();
        $given = $extra['axisops-repo-manager'] ?? [];

        $config = array_merge(self::DEFAULTS, is_array($given) ? $given : []);
        $config['exclude'] = is_array($config['exclude']) ? array_values($config['exclude']) : [];

        return $config;
    }

    /**
     * Names of axisops/* packages in the root require + require-dev, minus any
     * excluded ones.
     *
     * @param string[] $exclude
     * @return string[]
     */
    private function requiredAxisopsPackages(Composer $composer, string $vendorPrefix, array $exclude): array
    {
        $root = $composer->getPackage();
        $links = array_merge($root->getRequires(), $root->getDevRequires());

        $names = [];
        foreach ($links as $link) {
            $target = $link->getTarget();
            if (str_starts_with($target, $vendorPrefix) && !in_array($target, $exclude, true)) {
                $names[$target] = true;
            }
        }

        return array_keys($names);
    }

    private function packagesDir(Composer $composer): string
    {
        return $this->projectRoot($composer) . '/packages';
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
