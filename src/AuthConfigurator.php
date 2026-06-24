<?php

namespace Axisops\PluginRepoManager;

use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use Composer\Util\Url;

/**
 * Ensures Composer can actually authenticate to the axisops GitLab registry
 * BEFORE the dependency solver makes its (otherwise 401-ing) requests.
 *
 * Runs in Plugin::activate():
 *  1. ensure credentials exist (prompt when interactive, fail fast in CI);
 *  2. probe the registry with one cheap authenticated request;
 *  3. on an auth failure (401/403), clear the registry's stale cached metadata
 *     — Composer caches 401 responses, which is what makes a rotated token keep
 *     failing until `composer clear-cache` is run — then, when interactive,
 *     re-prompt for a token and re-probe; in CI, fail fast with a clear message.
 */
class AuthConfigurator
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private Composer $composer,
        private IOInterface $io
    ) {
    }

    public function ensureCredentials(string $registryUrl): void
    {
        $host = parse_url($registryUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return;
        }

        if (!$this->io->hasAuthentication($host)) {
            $this->promptForToken($host); // throws in CI / on empty input
        }

        $this->verify($registryUrl, $host);
    }

    /**
     * Probe the registry; on auth failure clear its stale cache and recover.
     */
    private function verify(string $registryUrl, string $host): void
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $status = $this->probe($registryUrl);

            if ($status === null || ($status >= 200 && $status < 400)) {
                return; // reachable / authorised (or a non-auth error we let the solver surface)
            }

            if ($status !== 401 && $status !== 403) {
                return; // not an auth problem — leave it for normal resolution
            }

            // Auth failure: the cached 401 is the usual culprit after a token change.
            $this->clearRegistryCache($registryUrl);
            $this->io->writeError("<warning>axisops registry returned HTTP $status; cleared its cached metadata.</warning>");

            if (!$this->io->isInteractive()) {
                throw new \RuntimeException(sprintf(
                    "Authentication to the axisops registry at %s failed (HTTP %d).\n"
                    . "Update the gitlab-token for %s in auth.json / COMPOSER_AUTH "
                    . "(token needs read_api scope). The stale cache has been cleared.",
                    $host,
                    $status,
                    $host
                ));
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                $this->io->write("<info>Re-enter your GitLab token for $host:</info>");
                $this->promptForToken($host);
            }
        }

        throw new \RuntimeException("Could not authenticate to the axisops registry at $host after "
            . self::MAX_ATTEMPTS . " attempts.");
    }

    /**
     * One authenticated request. Returns the HTTP status, or null if the host is
     * simply unreachable (network/DNS) — which is not an auth problem.
     */
    private function probe(string $registryUrl): ?int
    {
        $downloader = new HttpDownloader($this->io, $this->composer->getConfig());

        try {
            return $downloader->get($registryUrl)->getStatusCode();
        } catch (TransportException $e) {
            $status = $e->getStatusCode();
            return is_int($status) && $status > 0 ? $status : null;
        } catch (\Throwable $e) {
            return null; // treat unknown failures as "let the solver decide"
        }
    }

    private function promptForToken(string $host): void
    {
        if (!$this->io->isInteractive()) {
            throw new \RuntimeException(sprintf(
                "No credentials for the axisops registry at %s.\n"
                . "Set one of the following before running composer:\n"
                . "  - auth.json:  {\"gitlab-token\": {\"%s\": \"<token>\"}}\n"
                . "  - env:        COMPOSER_AUTH='{\"gitlab-token\":{\"%s\":\"<token>\"}}'\n"
                . "The token needs read_api (or read_package_registry) scope.",
                $host,
                $host,
                $host
            ));
        }

        $this->io->write("<info> The axisops registry at $host needs a GitLab token (read_api scope).</info>");
        $token = trim((string) $this->io->askAndHideAnswer("  GitLab token (input hidden): "));

        if ($token === '') {
            throw new \RuntimeException("No token entered; cannot authenticate to $host.");
        }

        // Use immediately for this run (token as user, 'private-token' => PRIVATE-TOKEN header).
        $this->io->setAuthentication($host, $token, 'private-token');

        if ($this->io->askConfirmation("  Save this token to auth.json for future runs? [y/N] ", false)) {
            $this->persist($host, $token);
        }
    }

    private function persist(string $host, string $token): void
    {
        try {
            $source = $this->composer->getConfig()->getAuthConfigSource();
            $source->addConfigSetting('gitlab-token.' . $host, $token);
            $this->io->write("<info>   Saved to " . $source->getName() . " (ensure it is gitignored).</info>");
        } catch (\Throwable $e) {
            $this->io->writeError("<error>   Could not save token: " . $e->getMessage() . "</error>");
        }
    }

    /**
     * Delete only this registry's cached metadata directory, mirroring how
     * Composer names it: cache-repo-dir + URL with non-[a-z0-9.] chars => '-'.
     */
    private function clearRegistryCache(string $registryUrl): void
    {
        $repoCacheDir = $this->composer->getConfig()->get('cache-repo-dir');
        if (!is_string($repoCacheDir) || $repoCacheDir === '') {
            return;
        }

        // Mirror ComposerRepository: cache-repo-dir / preg_replace(Url::sanitize(url)).
        $name = preg_replace('{[^a-z0-9.]}i', '-', Url::sanitize($registryUrl));
        $dir = rtrim($repoCacheDir, '/') . '/' . $name;

        if (is_dir($dir)) {
            exec(sprintf('rm -rf %s', escapeshellarg($dir)));
        }
    }
}
