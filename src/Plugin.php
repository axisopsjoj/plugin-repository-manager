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

    /**
     * Composer commands for which we configure repositories / rewrite
     * constraints. Read-only commands (config, show, …) are left untouched.
     */
    private const ACTIVE_COMMANDS = ['install', 'update', 'require', 'create-project'];

    public function activate(Composer $composer, IOInterface $io)
    {
        if (!$this->runsForCurrentCommand()) {
            return; // don't mutate config on read-only commands
        }

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

        $this->warnOnContextMismatch($composer, $io, $flags, $vendorPrefix, $exclude);

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

    /**
     * Warn (don't fail) when the existing composer.lock was generated in a
     * different context than the one now requested — e.g. a local (path-repo)
     * lock being used under prod. That mismatch otherwise surfaces as a cryptic
     * Composer error ("found in the lock file but not in remote repositories"
     * or a null-type DownloadManager crash).
     */
    private function warnOnContextMismatch(
        Composer $composer,
        IOInterface $io,
        FlagParser $flags,
        string $vendorPrefix,
        array $exclude
    ): void {
        $lockPath = $this->projectRoot($composer) . '/composer.lock';
        if (!is_file($lockPath)) {
            return;
        }

        $lock = json_decode((string) @file_get_contents($lockPath), true);
        if (!is_array($lock)) {
            return;
        }

        $lockedLocal = false;
        $lockedRegistry = false;
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
            $name = $p['name'] ?? '';
            if (!str_starts_with($name, $vendorPrefix) || in_array($name, $exclude, true)) {
                continue;
            }
            if (($p['dist']['type'] ?? null) === 'path') {
                $lockedLocal = true;
            } else {
                $lockedRegistry = true;
            }
        }

        if (!$lockedLocal && !$lockedRegistry) {
            return; // no axisops packages locked yet
        }

        $wantLocal = $flags->isLocal();
        $mismatch = ($wantLocal && !$lockedLocal) || (!$wantLocal && !$lockedRegistry && $lockedLocal);

        if ($mismatch) {
            $lockedAs = $lockedLocal ? 'local (path repositories)' : 'registry';
            $io->writeError(sprintf(
                '<warning>composer.lock was built for the %s context but you are running "%s". '
                . 'Run "AXISOPS_CONTEXT=%s composer update" to regenerate the lock for this context, '
                . 'or composer may fail to resolve axisops/* packages.</warning>',
                $lockedAs,
                $flags->channel(),
                $flags->channel()
            ));
        }
    }

    /**
     * Whether the composer command currently running is one we act on
     * (install/update/require/create-project). Resolves Composer's unambiguous
     * abbreviations (e.g. "up" -> update, "i" -> install) the same way Composer
     * does. Unknown/ambiguous commands are treated as "act" so we never silently
     * skip a real install — the cost of acting on an unexpected command is low.
     */
    private function runsForCurrentCommand(): bool
    {
        $command = $this->currentCommand();
        if ($command === null) {
            return true; // can't tell — be safe and act
        }

        foreach (self::ACTIVE_COMMANDS as $full) {
            if ($command === $full || str_starts_with($full, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The composer subcommand from argv: the first token after "composer" that
     * is not an option. Returns null if it can't be determined.
     */
    private function currentCommand(): ?string
    {
        $argv = $_SERVER['argv'] ?? [];
        if (!is_array($argv)) {
            return null;
        }

        // Skip argv[0] (the composer binary), then the first non-option token.
        foreach (array_slice($argv, 1) as $arg) {
            if (!is_string($arg) || $arg === '') {
                continue;
            }
            if ($arg[0] === '-') {
                continue; // option like -v, --no-dev
            }
            return $arg;
        }

        return null;
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
