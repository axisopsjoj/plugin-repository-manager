<?php

namespace Axisops\PluginRepoManager;

use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Composer\Semver\VersionParser;

/**
 * Rewrites the version constraint of every axisops/* requirement on the root
 * package to the constraint dictated by the active channel flag.
 *
 * Must run during Plugin::activate(), before the dependency solver snapshots
 * the root requirements.
 */
class ChannelResolver
{
    private VersionParser $versionParser;

    /**
     * @param string[] $exclude Package names never rewritten.
     */
    public function __construct(
        private string $vendorPrefix,
        private array $exclude = []
    ) {
        $this->versionParser = new VersionParser();
    }

    /**
     * Apply $constraint to all matching links in both requires and dev-requires.
     *
     * @return string[] Names of the packages whose constraints were rewritten.
     */
    public function apply(RootPackageInterface $root, string $constraint): array
    {
        $pretty = $constraint;
        $parsed = $this->versionParser->parseConstraints($constraint);

        $rewritten = [];

        $root->setRequires(
            $this->rewrite($root->getRequires(), Link::TYPE_REQUIRE, $parsed, $pretty, $rewritten)
        );
        $root->setDevRequires(
            $this->rewrite($root->getDevRequires(), Link::TYPE_DEV_REQUIRE, $parsed, $pretty, $rewritten)
        );

        return $rewritten;
    }

    /**
     * @param array<string, Link> $links
     * @param string[]            $rewritten Collects rewritten package names (by ref).
     * @return array<string, Link>
     */
    private function rewrite(array $links, string $type, $parsed, string $pretty, array &$rewritten): array
    {
        $out = [];
        foreach ($links as $key => $link) {
            $target = $link->getTarget();
            if (!$this->isAxisopsPackage($target)) {
                $out[$key] = $link;
                continue;
            }

            $out[$key] = new Link(
                $link->getSource(),
                $target,
                $parsed,
                $type,
                $pretty
            );
            $rewritten[] = $target;
        }

        return $out;
    }

    private function isAxisopsPackage(string $name): bool
    {
        return str_starts_with($name, $this->vendorPrefix)
            && !in_array($name, $this->exclude, true);
    }
}
