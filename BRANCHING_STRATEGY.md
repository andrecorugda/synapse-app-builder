# Branching Strategy

How we branch, review, and release **Synapse** (`andrecorugda/ai-page-builder`) — a
published Composer package on Packagist and a listed Filament plugin. Now that more
than one person contributes, this is the shared contract. It mirrors the convention
used by its sibling package, the AI OpenRouter Gateway, so the mental model is the
same across both.

**TL;DR** — branch off `develop`, open a PR back into `develop`, keep CI green, get
one review. Releases flow `develop → main → tag`. Never push straight to `main` or
`develop`.

---

## Long-lived branches

| Branch | Role | Direct commits? | Releases tagged here? |
|---|---|---|---|
| **`develop`** | Integration branch for the **next** release. The **default** branch — all feature/fix branches target it. Must always be green. | No — PRs only | No |
| **`main`** | The **stable** branch. Every commit is release-quality; this is what the latest tag points at and what most users install. | No — PRs/back-merges only | **Yes** (`vX.Y.Z`) |
| **`N.x`** (e.g. `1.x`) | A **maintenance line** for an older major, created only once a newer major lands. Receives backported fixes + patch releases for that line. | No — PRs/back-merges only | Yes (for that line) |

> Synapse currently has only `main`. `develop` is introduced with this strategy —
> see **Initial setup** at the bottom. Until then, treat `main` as the integration
> branch and adapt the PR target accordingly.

## Short-lived branches

Cut from `develop` (except `hotfix/*`, which is cut from `main`), and **deleted after merge**.

| Prefix | For | Branch from | PR into |
|---|---|---|---|
| `feature/` | a new capability | `develop` | `develop` |
| `fix/` | a non-urgent bug fix | `develop` | `develop` |
| `docs/` | documentation only | `develop` | `develop` |
| `refactor/` `test/` `chore/` `perf/` `ci/` | internal changes | `develop` | `develop` |
| `hotfix/` | an **urgent** fix to a shipped release | `main` | `main` (then back-merged to `develop`) |

**Naming:** lowercase kebab-case, optionally prefixed with the issue id.
`feature/loop-node`, `fix/142-csrf-origin`, `docs/extending-flows`.

---

## Everyday workflow (contributors)

```bash
# 1. Start from an up-to-date develop
git switch develop
git pull --ff-only

# 2. Branch
git switch -c feature/transaction-node

# 3. Commit in small, conventional steps (see "Commit messages")
git commit -m "feat(flow): add transaction node with rollback branches"

# 4. Push and open a PR targeting `develop`
git push -u origin feature/transaction-node
```

5. CI must go green and **one maintainer** must approve.
6. **Squash-merge** into `develop`. Delete the branch.
7. Add a line to `CHANGELOG.md` under **Unreleased** for any user-facing change.

Keep your branch current by rebasing on `develop` (preferred over merge commits):
`git fetch origin && git rebase origin/develop`.

---

## Pull requests

- **Target `develop`** (only `hotfix/*` targets `main`).
- **One logical change per PR.** Smaller PRs review faster and revert cleanly.
- **Description:** what changed and *why*; link the issue (`Closes #123`); include
  before/after screenshots for any UI change.
- **All quality gates pass** (see below) — PRs that are red don't get reviewed.
- **One approving review** from a maintainer; author doesn't merge their own PR
  without it. Resolve all review threads before merging.
- **Update `CHANGELOG.md`** (Unreleased) for anything users would notice.

## Commit messages — [Conventional Commits](https://www.conventionalcommits.org)

```
type(scope): imperative summary, <=72 chars

Optional body explaining the WHY (not the what — the diff shows that).
Footer: Closes #123 / BREAKING CHANGE: ...
```

**Types:** `feat` `fix` `docs` `refactor` `test` `chore` `perf` `security` `build` `ci`.
**Scopes** in use: `flow`, `ui`, `data`, `auth`, `security`, `docs`, `art`, … (the
subsystem you touched). Examples from history:

```
feat(flow): loop + transaction nodes with scoped sub-sequences
fix(ui): left-anchor the node drawer to match GrapesJS
security(H3): CSRF-guard cookie data-API writes
docs: cover Wave 4 — helper library + extensibility
```

A `feat` implies a minor release; a `fix` a patch; a `BREAKING CHANGE:` footer (or
`!`, e.g. `feat(data)!:`) implies a major. This keeps versioning honest.

---

## Quality gates (must pass before merge)

| Gate | Command | Notes |
|---|---|---|
| **Style** | `vendor/bin/pint --test` | Run `vendor/bin/pint` to auto-fix. Enforced in CI. |
| **Tests** | `vendor/bin/pest` | Enforced in CI across **PHP 8.2 / 8.3 / 8.4 × Laravel 11 / 12**. |
| **Static analysis** | `vendor/bin/phpstan analyse` | Run locally before pushing. |

The host may lack `pdo_sqlite` / `gd`; run the suite in Docker:

```bash
docker run --rm -v "$PWD":/pkg -w /pkg php:8.3-cli \
  sh -lc "php -d memory_limit=512M vendor/bin/pest"
```

---

## Versioning & releases (maintainers)

Synapse follows [SemVer](https://semver.org): `MAJOR.MINOR.PATCH`.

| Bump | When |
|---|---|
| **PATCH** (`x.y.Z`) | Backwards-compatible bug fixes only. |
| **MINOR** (`x.Y.0`) | Backwards-compatible new features. |
| **MAJOR** (`X.0.0`) | Breaking changes: public API / facade, config keys, DB schema needing a migration, or raising the minimum PHP / Laravel / Filament version. |

### Cutting a release

```bash
# 1. develop is green and CHANGELOG "Unreleased" is filled in.
# 2. Open a release PR: develop -> main. Review, merge.
# 3. Tag main (annotated) and push the tag — Packagist updates via webhook.
git switch main && git pull --ff-only
git tag -a v1.1.0 -m "v1.1.0"
git push origin v1.1.0
# 4. Publish a GitHub Release with the CHANGELOG notes.
# 5. Bump composer.json "branch-alias" if the dev line changed (see below).
# 6. Back-merge main -> develop so the release/tag commits return to develop.
git switch develop && git merge --no-ff main && git push
```

**`branch-alias`** in `composer.json` tells Composer which dev version each branch
represents (so `dev-develop`/`dev-main` resolve sanely). Mirror the gateway:

```json
"branch-alias": {
    "dev-main": "1.0.x-dev",
    "dev-develop": "1.1.x-dev"
}
```

Update these when you open a new minor/major dev line.

### Hotfixes (urgent fix to a shipped release)

```bash
git switch main && git pull --ff-only
git switch -c hotfix/broken-render
# fix + test, PR into main, merge, then:
git tag -a v1.0.3 -m "v1.0.3" && git push origin v1.0.3
# back-merge into develop (and any active N.x lines) so the fix isn't lost
git switch develop && git merge --no-ff main && git push
```

### Maintenance lines (`N.x`)

When work on the next **major** begins on `develop`/`main`, cut a line from the last
release of the old major to keep shipping patches for it:

```bash
git switch -c 1.x v1.4.0 && git push -u origin 1.x   # add dev-1.x to branch-alias
```

Filament-version support maps to major lines (as on the gateway: `^1.0` → Filament 3,
`^2.0` → Filament 4/5). Composer installs the right line for the user's Filament version.

---

## Branch protection (recommended GitHub settings — maintainer applies once)

On **`main`** and **`develop`** (Settings → Branches → Add rule):

- ✅ Require a pull request before merging — **1 approval**; dismiss stale approvals on new commits.
- ✅ Require status checks to pass — select the **tests** matrix jobs; require branches to be up to date.
- ✅ Require conversation resolution before merging.
- ✅ Require linear history (pairs with squash-merge).
- ✅ Do not allow force pushes; do not allow deletions.
- 🚫 Block direct pushes (no bypass, or restrict to maintainers only).

Set the repository's **default branch to `develop`** so PRs target it automatically.

---

## Initial setup (one-time — `develop` does not exist yet)

Run once to bring the repo in line with this strategy:

```bash
git switch main && git pull --ff-only
git switch -c develop && git push -u origin develop
# Then on GitHub: set default branch to `develop`, apply the protection rules above.
# Add the dev-develop branch-alias to composer.json on develop.
```

---

*Questions about the workflow? Open a `docs/` PR against this file — the strategy is
allowed to evolve, in the open, like everything else here.*
