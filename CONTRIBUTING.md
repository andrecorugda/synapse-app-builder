# Contributing to Synapse

Thanks for helping build Synapse! A few essentials to get you productive fast.

## Workflow & branches

We use a gitflow-lite model — branch off `develop`, PR back into `develop`, releases
flow `develop → main → tag`. The full rules (branch names, PRs, releases, hotfixes,
versioning) live in **[BRANCHING_STRATEGY.md](BRANCHING_STRATEGY.md)** — read it before
your first PR.

## Before you open a PR

Run the quality gates locally (all enforced in CI):

```bash
vendor/bin/pint            # auto-fix code style (CI runs `pint --test`)
vendor/bin/phpstan analyse # static analysis
vendor/bin/pest            # tests
```

If your host lacks `pdo_sqlite` / `gd`, run the tests in Docker:

```bash
docker run --rm -v "$PWD":/pkg -w /pkg php:8.3-cli \
  sh -lc "php -d memory_limit=512M vendor/bin/pest"
```

- Keep PRs small and focused; one logical change each.
- Use [Conventional Commits](https://www.conventionalcommits.org) (`feat(flow): …`, `fix(ui): …`).
- Update `CHANGELOG.md` (Unreleased) for any user-facing change.
- Add tests for new behaviour and bug fixes.

## Reporting bugs / proposing features

Open an issue with steps to reproduce (bugs) or the use case and proposed API
(features). For anything security-sensitive, please **do not** open a public issue —
contact the maintainer directly first.
