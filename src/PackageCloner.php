<?php

namespace Axisops\PluginRepoManager;

use Composer\IO\IOInterface;

/**
 * Clones missing axisops/* packages into the packages/ directory for --local
 * mode. Only required packages are considered, only missing directories are
 * cloned (existing checkouts are used as-is), and the develop branch is used.
 *
 * A clone failure aborts the whole command: continuing would only produce a
 * confusing unresolvable-dependency error later, since the dev-develop
 * constraint would have no source.
 */
class PackageCloner
{
    private const BRANCH = 'develop';

    public function __construct(
        private IOInterface $io,
        private string $packagesDir,
        private string $vendorPrefix,
        private SourceResolver $sourceResolver,
        private string $gitBaseUrl = ''
    ) {
    }

    /**
     * @param string[] $packageNames Fully-qualified names, e.g. "axisops/laravel-core".
     * @throws \RuntimeException on the first failed clone.
     */
    public function cloneMissing(array $packageNames): void
    {
        if (!is_dir($this->packagesDir) && !@mkdir($this->packagesDir, 0775, true) && !is_dir($this->packagesDir)) {
            throw new \RuntimeException("Could not create packages directory: {$this->packagesDir}");
        }

        // Determine which packages actually need cloning, so the counter is accurate.
        $toClone = [];
        foreach ($packageNames as $name) {
            $shortName = $this->shortName($name);
            if (is_dir($this->packagesDir . '/' . $shortName)) {
                $this->io->write("<info>   $name already present, using local checkout</info>");
                continue;
            }
            $toClone[$name] = $shortName;
        }

        $total = count($toClone);
        if ($total === 0) {
            return;
        }

        $this->io->write("<info>   Cloning $total package(s) (branch " . self::BRANCH . ")</info>");

        $i = 0;
        foreach ($toClone as $name => $shortName) {
            $i++;
            $this->cloneOne($name, $shortName, $this->packagesDir . '/' . $shortName, $i, $total);
        }

        $this->io->write("<info>   Cloned $total package(s)</info>");
    }

    private function cloneOne(string $name, string $shortName, string $target, int $index, int $total): void
    {
        $this->io->write(sprintf('<info>   [%d/%d] Cloning %s</info>', $index, $total, $name));

        // Authoritative path from the registry; fall back to git-base-url if the
        // registry can't be reached for this package.
        $repoUrl = $this->toHttps($this->sourceResolver->sourceUrl($name))
            ?? $this->fromBaseUrl($shortName);

        if ($repoUrl === null) {
            throw new \RuntimeException(sprintf(
                "Could not resolve a git source URL for %s (registry returned none and no git-base-url is configured).",
                $name
            ));
        }

        // Inject the GitLab token for HTTPS clones so git authenticates without
        // prompting. The displayed URL stays credential-free.
        $cloneUrl = $this->authenticatedUrl($repoUrl);

        if ($this->io->isInteractive()) {
            // Let git draw its native, in-place progress bar straight to the
            // terminal (Receiving objects: …%). Output isn't captured in this
            // mode, so on failure we surface a buffered retry's log below.
            $command = sprintf(
                'git clone --progress --branch %s --single-branch %s %s',
                escapeshellarg(self::BRANCH),
                escapeshellarg($cloneUrl),
                escapeshellarg($target)
            );
            passthru($command, $exitCode);
            $log = '';
        } else {
            // No TTY (CI, piped): capture everything for the error message.
            $command = sprintf(
                'git clone --branch %s --single-branch %s %s 2>&1',
                escapeshellarg(self::BRANCH),
                escapeshellarg($cloneUrl),
                escapeshellarg($target)
            );
            exec($command, $lines, $exitCode);
            // Scrub any token that git might echo back in error output.
            $log = $this->scrub(implode("\n", $lines));
        }

        if ($exitCode !== 0) {
            // Remove any partial checkout git may have left behind.
            $this->removeDir($target);

            throw new \RuntimeException(sprintf(
                "Failed to clone required package %s from %s (branch %s).%s",
                $name,
                $repoUrl,
                self::BRANCH,
                $log !== '' ? "\n" . $log : ' See the git output above.'
            ));
        }
    }

    /**
     * Convert a registry source URL to HTTPS:
     *   git@host:group/sub/pkg.git        -> https://host/group/sub/pkg.git
     *   ssh://git@host/group/sub/pkg.git  -> https://host/group/sub/pkg.git
     *   https://host/...                  -> unchanged
     * Returns null for empty input.
     */
    private function toHttps(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, 'https://')) {
            return $url;
        }

        // scp-style: git@host:path
        if (preg_match('{^[^@/]+@([^:/]+):(.+)$}', $url, $m)) {
            return 'https://' . $m[1] . '/' . ltrim($m[2], '/');
        }

        // ssh://[user@]host[:port]/path
        if (preg_match('{^ssh://(?:[^@/]+@)?([^:/]+)(?::\d+)?/(.+)$}', $url, $m)) {
            return 'https://' . $m[1] . '/' . ltrim($m[2], '/');
        }

        return $url; // unknown scheme — let git try as-is
    }

    private function fromBaseUrl(string $shortName): ?string
    {
        if ($this->gitBaseUrl === '') {
            return null;
        }

        return $this->toHttps(rtrim($this->gitBaseUrl, '/') . '/' . $shortName . '.git');
    }

    /**
     * For an HTTPS GitLab URL, embed the host's token (from Composer auth) as
     * https://oauth2:<token>@host/…. Non-HTTPS URLs (e.g. ssh) are returned
     * unchanged — git handles their auth itself.
     */
    private function authenticatedUrl(string $repoUrl): string
    {
        if (!str_starts_with($repoUrl, 'https://')) {
            return $repoUrl;
        }

        $host = parse_url($repoUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || !$this->io->hasAuthentication($host)) {
            return $repoUrl;
        }

        $auth = $this->io->getAuthentication($host);
        // gitlab-token is stored as username=<token>, password='private-token'.
        // http-basic is stored as username=<user>, password=<token|password>.
        if ($auth['password'] === 'private-token' || $auth['password'] === 'gitlab-ci-token' || $auth['password'] === 'oauth2') {
            $user = 'oauth2';
            $secret = (string) $auth['username'];
        } else {
            $user = (string) $auth['username'];
            $secret = (string) $auth['password'];
        }

        if ($secret === '') {
            return $repoUrl;
        }

        return 'https://' . rawurlencode($user) . ':' . rawurlencode($secret)
            . '@' . substr($repoUrl, strlen('https://'));
    }

    private function scrub(string $text): string
    {
        // Never let an embedded credential survive into displayed/thrown output.
        return preg_replace('{https://[^/@\s]+:[^/@\s]+@}', 'https://', $text) ?? $text;
    }

    private function shortName(string $name): string
    {
        return str_starts_with($name, $this->vendorPrefix)
            ? substr($name, strlen($this->vendorPrefix))
            : $name;
    }

    private function removeDir(string $path): void
    {
        if (is_dir($path)) {
            exec(sprintf('rm -rf %s', escapeshellarg($path)));
        }
    }
}
