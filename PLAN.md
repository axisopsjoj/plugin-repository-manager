# Plan: flag-driven repository + dependency management

Extends `axisops/plugin-repository-manager` (a Composer plugin) with auto-generated
repositories and CLI-flag-driven dependency channels.

## Shared mechanism

All flags are detected by reading raw `$_SERVER['argv']` in `Plugin::activate()`,
then **removed** from argv so Symfony Console does not reject them as unknown options
(Symfony validates options before the command runs and would otherwise error).

All root-package mutation (constraint rewrites) happens in `activate()` — before the
dependency solver snapshots the root requirements — NOT in the `pre-command-run`
listener, which is too late for resolution. (To be verified empirically during build;
fallback documented below if timing differs.)

Config lives in the **host project** `composer.json` under `extra`:

```json
"extra": {
  "axisops-repo-manager": {
    "registry-url": "https://gitlab.example.com/api/v4/group/<GROUP_ID>/-/packages/composer/packages.json",
    "git-base-url": "https://gitlab.example.com/axisops",
    "vendor-prefix": "axisops/",
    "channel-version": "12.0.0"
  }
}
```

## Part A — Mode-dependent package source

The two modes are mutually exclusive in where packages come from. The plugin wires up
exactly one source based on the active flag — it does not actively remove or warn about
the other; it simply doesn't set it up.

### Non-local modes (`--alpha`/`--beta`/`--rc`/`--prod`, or default) → registry

- Scan root `require` + `require-dev` for packages matching `vendor-prefix` → build the
  `only` allowlist automatically.
- Register a `composer`-type repository (`registry-url`, `only` = derived list) via
  `RepositoryManager::createRepository('composer', [...])`.
- `packages/` is **ignored entirely** — no path-repo scanning, no clones. If a
  `packages/` dir exists on disk it is left untouched and unused.

### Local mode (`--local`) → path repos

- The registry `repositories` block is **not injected** — local clones are the only
  source. (Plugin just skips registry injection; assumes no hard-coded registry block,
  which holds since Part A generates it rather than hand-maintaining it.)
- The existing local path-repo + git `safe.directory` behaviour is what's active here,
  picking up the clones produced by Part B.

| Mode                       | registry block | `packages/` path repos | clones |
|----------------------------|----------------|------------------------|--------|
| `--local`                  | not injected   | active                 | yes    |
| `--alpha/beta/rc/prod`     | injected       | ignored                | no     |
| (none)                     | injected       | ignored                | no     |

## Part B — Dependency channel flags

At most ONE channel flag permitted. Passing more than one (e.g. `--local --prod`) is an
**error** with a clear message.

For every `axisops/*` package in `require` / `require-dev`, rewrite its constraint by
constructing new `Link` objects (constraint parsed via `VersionParser::parseConstraints`)
and calling `RootPackage::setRequires()` / `setDevRequires()`.

| Flag      | Constraint                  | Clones? |
|-----------|-----------------------------|---------|
| `--local` | `dev-develop as 12.0.0`     | yes — `develop` branch, missing only |
| `--alpha` | `>=12.0.0-alpha.1`          | no (registry) |
| `--beta`  | `>=12.0.0-beta.1`           | no (registry) |
| `--rc`    | `>=12.0.0-rc.1`             | no (registry) |
| `--prod`  | `>=12.0.0`                  | no (registry) |
| (none)    | unchanged                   | no |

`12.0.0` comes from `extra.channel-version` (bumped once per release cycle).

### `--local` cloning rules

- Scope: **required** `axisops/*` packages only.
- For each, if `packages/<name>` is **missing**, `git clone` the `develop` branch into it
  via `exec()` + `escapeshellarg`.
- Existing dirs are **used as-is** — no branch switch, no pull.
- **Clone failure aborts** the composer command immediately with a clear, package-named
  error (continuing would only produce a confusing unresolvable-dependency error later,
  since `dev-develop` would have no source).
- Newly cloned dirs are picked up by the existing path-repo + `safe.directory` logic.

## Code structure

`Plugin` stays a thin orchestrator. Extract:
- `FlagParser`            — raw-argv detection + strip; enforces single-channel rule.
- `RepositoryConfigurator`— Part A (registry repo + derived `only`).
- `PackageCloner`         — `--local` clones (abort-on-failure).
- `ChannelResolver`       — constraint rewrites via setRequires/setDevRequires.

## Files touched

- `src/Plugin.php` + new class files above.
- Plugin `composer.json` — document `extra` schema; updated description.
- Host project `composer.json` — `repositories` block collapses to the `extra` config.

## Decisions locked

1. `channel-version` hardcoded in `extra` config.
2. Conflicting channel flags → error.
3. Cloning uses `exec()` (no new deps).
4. Clone failure → abort the command.

## Open risk to validate during build

`pre-command-run` may be too late for repo injection / constraint rewrites to affect the
solver. Primary approach mutates in `activate()` (bootstrap). If a specific operation
still doesn't take effect there, fallback is to write into the in-memory config the solver
reads, documented at implementation time.
