#!/bin/sh
# Cuts a launchroom release: writes VERSION, commits, pushes main, then tags
# and pushes v<version>. The tag starts .github/workflows/release.yml, which
# waits for approval in the `release` environment. See RELEASING.md.
#
# Usage: ./release.sh 0.1.0-rc.1
set -eu

die() { echo "release: $*" >&2; exit 1; }

[ $# -eq 1 ] || die "usage: ./release.sh <version>   (e.g. 0.1.0 or 0.1.0-rc.1)"
version=${1#v}

# Same check as the workflow: a semantic version without build metadata.
echo "$version" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?$' ||
	die "'$version' is not a semantic version (e.g. 0.1.0 or 0.1.0-rc.1)"
tag="v$version"

cd "$(dirname "$0")"

[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || die "check out main first (the tagged commit must be on main)"
[ -z "$(git status --porcelain)" ] || die "the working tree has uncommitted changes; commit or stash them first"
git remote get-url origin >/dev/null 2>&1 || die "no 'origin' remote; add it with: git remote add origin git@github.com:vantezzen/launchroom.git"
! git rev-parse -q --verify "refs/tags/$tag" >/dev/null || die "tag $tag already exists locally"
! git ls-remote --exit-code --tags origin "refs/tags/$tag" >/dev/null 2>&1 || die "tag $tag already exists on origin"

echo "$version" > VERSION
git add VERSION
git commit -m "Release $tag"
git push origin main
git tag "$tag"
git push origin "$tag"

echo "Pushed $tag. Approve the run in GitHub → Actions → release."
