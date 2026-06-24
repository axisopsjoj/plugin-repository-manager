<?php

namespace Axisops\PluginRepoManager;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;

/**
 * Resolves the authoritative git source URL for an axisops/* package by reading
 * the registry's per-package metadata (packages.json -> p2/<package>.json), where
 * each version carries a "source": { "url": "git@host:group/.../pkg.git" }.
 *
 * This is required because packages live at varied subgroup paths that a single
 * base URL cannot express.
 */
class SourceResolver
{
    private HttpDownloader $downloader;
    private string $metadataTemplate;
    private string $registryBase;

    /** @var array<string,string|null> Memoised package name => source url. */
    private array $cache = [];

    public function __construct(
        Composer $composer,
        private IOInterface $io,
        private string $registryUrl
    ) {
        $this->downloader = new HttpDownloader($io, $composer->getConfig());

        // Registry origin, e.g. https://gitlab.example.com
        $scheme = parse_url($registryUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($registryUrl, PHP_URL_HOST) ?: '';
        $this->registryBase = $scheme . '://' . $host;

        // metadata-url from the root packages.json, e.g.
        // /api/v4/group/<GROUP_ID>/-/packages/composer/p2/%package%.json
        $this->metadataTemplate = $this->loadMetadataTemplate($registryUrl);
    }

    /**
     * The git source URL for a package, or null if it can't be resolved.
     */
    public function sourceUrl(string $packageName): ?string
    {
        if (array_key_exists($packageName, $this->cache)) {
            return $this->cache[$packageName];
        }

        $url = $this->registryBase . str_replace('%package%', $packageName, $this->metadataTemplate);

        try {
            $body = $this->downloader->get($url)->getBody();
            $data = json_decode((string) $body, true);
        } catch (\Throwable $e) {
            return $this->cache[$packageName] = null;
        }

        $versions = $data['packages'][$packageName] ?? [];
        foreach ($versions as $version) {
            if (!empty($version['source']['url'])) {
                return $this->cache[$packageName] = (string) $version['source']['url'];
            }
        }

        return $this->cache[$packageName] = null;
    }

    private function loadMetadataTemplate(string $registryUrl): string
    {
        $default = '/api/v4/group/0/-/packages/composer/p2/%package%.json';

        try {
            $body = $this->downloader->get($registryUrl)->getBody();
            $root = json_decode((string) $body, true);
            if (!empty($root['metadata-url']) && is_string($root['metadata-url'])) {
                return $root['metadata-url'];
            }
        } catch (\Throwable $e) {
            // fall through to default
        }

        return $default;
    }
}
