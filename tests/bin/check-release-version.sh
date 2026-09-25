#!/usr/bin/env bash
#
# When .plugin-data version changes between base and head commits, verify
# the release tag is unused and that every versioned file is updated consistently.
#
# Usage (from repository root):
#   ./tests/bin/check-release-version.sh
#     (default: merge-base of main|origin/main|master with HEAD → HEAD, for local runs)
#   BASE_SHA=<base> HEAD_SHA=<head> ./tests/bin/check-release-version.sh
#   ./tests/bin/check-release-version.sh <base_sha> <head_sha>
#
# GitHub Actions sets BASE_SHA and HEAD_SHA on the pull request; arguments override env.
#

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$SCRIPT_DIR/../.." && pwd)
cd "$ROOT"

PLUGIN_BOOTSTRAP="rankmath-sitemaps-for-polylang.php"

if [ $# -ge 2 ]; then
	BASE_SHA=$1
	HEAD_SHA=$2
fi

if [ -z "${BASE_SHA:-}" ] || [ -z "${HEAD_SHA:-}" ]; then
	if [ $# -eq 0 ] && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
		HEAD_SHA=$(git -C "$ROOT" rev-parse HEAD)
		base_ref=
		for r in main origin/main master origin/master; do
			if git -C "$ROOT" rev-parse -q --verify "$r" >/dev/null 2>&1; then
				base_ref=$r
				break
			fi
		done
		if [ -n "$base_ref" ] && MB=$(git -C "$ROOT" merge-base "$base_ref" HEAD 2>/dev/null); then
			BASE_SHA=$MB
			echo "Using ${base_ref}..HEAD: merge-base ${BASE_SHA:0:7} → head ${HEAD_SHA:0:7}" >&2
		fi
	fi
fi

if [ -z "${BASE_SHA:-}" ] || [ -z "${HEAD_SHA:-}" ]; then
	echo "Usage: BASE_SHA=<commit> HEAD_SHA=<commit> $0" >&2
	echo "   or: $0 <base_sha> <head_sha>" >&2
	echo "   or: $0  (in a git repo, compares merge-base of main|origin/main|... with HEAD)" >&2
	exit 1
fi

NEW_VER=$(jq -r .version .plugin-data)

if ! git cat-file -e "${BASE_SHA}:.plugin-data" 2>/dev/null; then
	echo "No .plugin-data at merge base. Skipping release and tag checks."
	exit 0
fi

OLD_VER=$(git show "${BASE_SHA}:.plugin-data" | jq -r .version)

if [ "$OLD_VER" = "$NEW_VER" ]; then
	echo "No version change in .plugin-data (${NEW_VER}). Skipping release and tag checks."
	exit 0
fi

echo "Version bump detected: ${OLD_VER} -> ${NEW_VER}"

if git show-ref --verify --quiet "refs/tags/${NEW_VER}"; then
	echo "::error::Tag '${NEW_VER}' already exists. Bump .plugin-data to a version that is not released yet."
	exit 1
fi

have_diff() {
	[ -n "$(git diff --name-only "${BASE_SHA}" "${HEAD_SHA}" -- "$1")" ]
}

file_at_head() {
	git cat-file -e "${HEAD_SHA}:$1" 2>/dev/null
}

missing=()
for f in \
	.plugin-data \
	CHANGELOG.md \
	readme.txt \
	"${PLUGIN_BOOTSTRAP}"
do
	if ! have_diff "$f"; then
		missing+=("$f")
	fi
done

if file_at_head "package.json"; then
	if ! have_diff "package.json"; then
		missing+=("package.json")
	fi
fi

if file_at_head ".wordpress-org/blueprints/blueprint.json"; then
	if ! have_diff ".wordpress-org/blueprints/blueprint.json"; then
		missing+=(".wordpress-org/blueprints/blueprint.json")
	fi
fi

while IFS= read -r -d '' f; do
	if ! have_diff "$f"; then
		missing+=("$f (block.json)")
	fi
done < <(find . -name block.json -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path '*/build/*' -print0 2>/dev/null)

if [ ${#missing[@]} -ne 0 ]; then
	echo "::error::This PR bumps the plugin version. The following required paths must be modified (diff vs base branch):"
	printf '  - %s\n' "${missing[@]}"
	exit 1
fi

fail=0

if file_at_head "package.json"; then
	if [ "$(jq -r .version package.json)" != "$NEW_VER" ]; then
		echo "::error::package.json version must equal ${NEW_VER} (from .plugin-data)"
		fail=1
	fi
fi

while IFS= read -r -d '' f; do
	if [ "$(jq -r .version "$f")" != "$NEW_VER" ]; then
		echo "::error::${f} version must equal ${NEW_VER} (from .plugin-data)"
		fail=1
	fi
done < <(find . -name block.json -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path '*/build/*' -print0 2>/dev/null)

if file_at_head ".wordpress-org/blueprints/blueprint.json"; then
	BP_REF=$(jq -r '.steps[] | select(.pluginData) | .pluginData.ref' .wordpress-org/blueprints/blueprint.json | head -1)
	if [ "$BP_REF" != "$NEW_VER" ]; then
		echo "::error::.wordpress-org/blueprints/blueprint.json pluginData.ref must be '${NEW_VER}' (got '${BP_REF}')"
		fail=1
	fi
fi

if ! grep -F "* Version:" "${PLUGIN_BOOTSTRAP}" | head -1 | grep -qF "$NEW_VER"; then
	echo "::error::${PLUGIN_BOOTSTRAP} plugin header Version must be ${NEW_VER}"
	fail=1
fi

if ! grep -qF "define( 'RMSP_VERSION', '${NEW_VER}' );" "${PLUGIN_BOOTSTRAP}"; then
	echo "::error::${PLUGIN_BOOTSTRAP} define RMSP_VERSION must be '${NEW_VER}'"
	fail=1
fi

if ! grep -E "^Stable tag:" readme.txt | grep -qF "$NEW_VER"; then
	echo "::error::readme.txt Stable tag must be ${NEW_VER}"
	fail=1
fi

if ! grep -qF "= ${NEW_VER} =" readme.txt; then
	echo "::error::readme.txt Changelog must include '= ${NEW_VER} =' section"
	fail=1
fi

if ! grep -qE "^##[[:space:]]+${NEW_VER}([[:space:]]|$)" CHANGELOG.md; then
	echo "::error::CHANGELOG.md must include a '## ${NEW_VER}' section header"
	fail=1
fi

exit "$fail"
