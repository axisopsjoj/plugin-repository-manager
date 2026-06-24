<?php

namespace Axisops\PluginRepoManager;

use Composer\IO\IOInterface;

/**
 * Runs `php artisan core:update` after a composer install/update, when the
 * project is a Laravel app (an `artisan` file exists) AND the core:update
 * command is actually registered (so projects without laravel-core are skipped
 * silently). A failure is reported as a warning and never fails the command —
 * the install/update itself has already completed successfully.
 */
class PostUpdateRunner
{
    private const COMMAND = 'core:update';

    public function __construct(
        private IOInterface $io,
        private string $projectRoot
    ) {
    }

    public function run(): void
    {
        $artisan = $this->projectRoot . '/artisan';
        if (!is_file($artisan)) {
            return; // not a Laravel project root
        }

        if (!$this->commandExists($artisan)) {
            return; // core:update not registered (e.g. laravel-core absent)
        }

        $this->io->write('<info>Running php artisan ' . self::COMMAND . '</info>');

        if ($this->io->isInteractive()) {
            // Inherit the terminal so core:update can prompt the user and render
            // its own output live.
            $command = sprintf(
                '%s %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($artisan),
                escapeshellarg(self::COMMAND)
            );
            passthru($command, $exitCode);
        } else {
            // No TTY (CI): capture output, pass --no-interaction so it can't hang.
            $command = sprintf(
                '%s %s %s --no-interaction 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($artisan),
                escapeshellarg(self::COMMAND)
            );
            exec($command, $output, $exitCode);
            foreach ($output as $line) {
                $this->io->write('   ' . $line);
            }
        }

        if ($exitCode !== 0) {
            $this->io->writeError(sprintf(
                '<warning>php artisan %s failed (exit %d); continuing.</warning>',
                self::COMMAND,
                $exitCode
            ));
        }
    }

    /**
     * Whether `core:update` appears in `artisan list`. Failure to inspect is
     * treated as "not present" so we never run an unregistered command.
     */
    private function commandExists(string $artisan): bool
    {
        $command = sprintf(
            '%s %s list --raw 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($artisan)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            return false;
        }

        foreach ($output as $line) {
            // `artisan list --raw` lines start with the command name.
            if (str_starts_with(trim($line), self::COMMAND . ' ')
                || trim($line) === self::COMMAND) {
                return true;
            }
        }

        return false;
    }
}
