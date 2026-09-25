# Release process

Development happens on `develop`. A release is a merge to `main`.

GitHub Actions then:

- installs production PHP dependencies (`composer install --no-dev`)
- builds JavaScript assets when `package.json` is present
- creates a git tag from a dist tree (`.distignore` copied to `.gitignore`)
- publishes the package to BeAPI Satis when infra is wired (`BUDDY_SATIS_WEBHOOK` or equivalent)

> **Before starting a release**, warn other developers to avoid unexpected merges on `develop`.

## 1. Develop

1. Create a feature branch from `develop`.

```bash
git switch develop
git pull
git switch -c feat/my-feature
git push -u origin feat/my-feature:feat/my-feature
```

2. Open a pull request to `develop`.
3. PHP quality checks run on the PR (`composer validate`, `composer cs`).
4. Merge into `develop`.

## 2. Prepare a new release

Create a branch from `main`. Replace `X.X.X` with the new version (e.g. `1.0.1`).

```bash
git switch main
git pull
git switch -c ver/X.X.X
git merge develop
```

Update versions in all of the following:

- `rankmath-sitemaps-for-polylang.php` (plugin header **and** `RMSP_VERSION`)
- `.plugin-data`
- `readme.txt` (`Stable tag` and changelog section)
- `CHANGELOG.md`
- `package.json` / `block.json` / `.wordpress-org/blueprints/blueprint.json` when present

```bash
git add .
git commit -m "chore: release X.X.X"
git push -u origin ver/X.X.X:ver/X.X.X
```

## 3. Deploy the release

1. Open a pull request `ver/X.X.X` → `main`.
2. The version workflow verifies that git tag `X.X.X` does not exist yet.
3. Merge the PR. The push to `main` runs the release workflow and creates tag `X.X.X`.
4. Merge `ver/X.X.X` into `develop` to keep branches aligned.

```bash
git switch develop
git pull
git merge ver/X.X.X
git push
```

Confirm the package appears on `composer.beapi.fr` after Satis regeneration.

## Phase 2 repository setup

When this plugin lives in its own private GitHub repository, copy GitHub Actions from the BeAPI open-source template (`common/` + `private/`). See [docs/EXTRACTION_PRIVATE.md](docs/EXTRACTION_PRIVATE.md).
