<?php

namespace Axisops\PluginRepoManager;

use Composer\Composer;
use Composer\IO\IOInterface;

/**
 * Wires up the package source for the active mode:
 *
 *  - Local mode: registers the cloned packages/ subdirectories as symlinked
 *    path repositories. The registry is NOT injected.
 *  - Non-local mode: injects the GitLab composer registry, scoped via "only"
 *    to the required axisops/* packages. packages/ is ignored entirely.
 */
class RepositoryConfigurator
{
    public function __construct(
        private Composer $composer,
        private IOInterface $io
    ) {
    }

    /**
     * Inject the composer-type registry, scoped to the given package names.
     *
     * @param string[] $only Fully-qualified axisops/* package names.
     */
    public function configureRegistry(string $registryUrl, array $only): void
    {
        if ($registryUrl === '') {
            $this->io->writeError('<error>axisops-repo-manager: registry-url is not configured</error>');
            return;
        }

        $config = ['type' => 'composer', 'url' => $registryUrl];
        if ($only !== []) {
            $config['only'] = array_values($only);
        }

        $repo = $this->composer->getRepositoryManager()->createRepository('composer', $config);
        $this->composer->getRepositoryManager()->prependRepository($repo);

        $this->io->write("<info> Added axisops registry: $registryUrl</info>");
        if ($only !== []) {
            $this->io->write('<info>   Scoped to: ' . implode(', ', $only) . '</info>');
        }
    }

    /**
     * Register every subdirectory of $packagesDir as a symlinked path repository,
     * and add each to git's global safe.directory list when needed.
     */
    public function configurePathRepositories(string $packagesDir): void
    {
        if (!is_dir($packagesDir)) {
            $this->io->write("<info>No packages directory found at $packagesDir</info>");
            return;
        }

        foreach (glob($packagesDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $path = realpath($dir);
            if ($path === false) {
                continue;
            }

            $this->composer->getRepositoryManager()->prependRepository(
                $this->composer->getRepositoryManager()->createRepository('path', [
                    'url' => $path,
                    'options' => ['symlink' => true],
                ])
            );

            $this->io->write('<info> Added path repository: ' . basename($dir) . '</info>');
            $this->ensureSafeDirectory($path);
        }
    }

    private function ensureSafeDirectory(string $path): void
    {
        if ($this->isSafeDirectory($path)) {
            return;
        }

        $command = sprintf('git config --global --add safe.directory %s', escapeshellarg($path));
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->io->writeError("<error>   Failed to add $path to Git safe.directory</error>");
        }
    }

    private function isSafeDirectory(string $path): bool
    {
        $output = [];
        exec('git config --global --get-all safe.directory', $output);
        return in_array($path, $output, true);
    }
}
