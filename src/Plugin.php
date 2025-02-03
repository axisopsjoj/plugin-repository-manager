<?php

namespace Axisops\PluginRepoManager;

use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Composer;
use Composer\IO\IOInterface;

class Plugin implements PluginInterface
{
    private $composer;
    private $io;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;

        $composer->getEventDispatcher()->addListener('pre-command-run', [$this, 'addRepositories']);
    }

    /**
     * Add both local and remote repositories dynamically for axisops/* packages
     */
    public function addRepositories(PreCommandRunEvent $event)
    {
        $validCommands = [
            'install',
            'update', 
            'require'
        ];

        if (!in_array($event->getCommand(), $validCommands)) {
            return;
        }

        $this->io->write("");
        $this->io->write("<info>Configuring Plugin Repositories</info>");

        $rootPath = $this->composer->getConfig()->get('vendor-dir');
        $packagesDir = realpath($rootPath . '/..') . '/packages';

        if (is_dir($packagesDir)) {
            $directories = glob($packagesDir . '/*', GLOB_ONLYDIR);

            foreach ($directories as $dir) {
                $pluginName = basename($dir);
                $this->addRepos($pluginName);

                $this->io->write("<info> Added repository for plugin: $pluginName </info>");
            }
        } else {
            $this->io->write("<info>No plugins directory found at $packagesDir </info>");
        }

        $this->io->write("");
    }

    private function addRepos($packageName)
    {
        $packagePath = realpath('./packages/' . $packageName);
    
        if ($packagePath && is_dir($packagePath)) {
            $this->composer->getRepositoryManager()->addRepository(
                $this->composer->getRepositoryManager()->createRepository('path', [
                    'url' => $packagePath,
                    'options' => ['symlink' => true],
                ])
            );

            $packagePath = $packagePath;
            if (!$this->isSafeDirectory($packagePath)) {              
                $command = sprintf('git config --global --add safe.directory %s', escapeshellarg($packagePath));
                exec($command, $output, $exitCode);

                if ($exitCode === 0) {
                    $this->io->write("<info>   Successfully added to Git safe.directory</info>");
                } else {
                    $this->io->writeError("<error>   Failed to add to Git safe.directory</error>");
                }
            }
        }
    }

    private function isSafeDirectory(string $path): bool
    {
        $output = [];
        exec('git config --global --get-all safe.directory', $output);
        return in_array($path, $output, true);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $io->write("Plugin deactivated.");
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $io->write("Plugin uninstalled.");
    }
}
