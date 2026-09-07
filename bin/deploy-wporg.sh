#!/usr/bin/env bash
#
# Ships a tagged release to the wordpress.org SVN repository by hand.
#
# The plugin directory review has to happen before this script is ever run:
# there is no wordpress.org SVN repository until Mads has submitted the
# plugin from https://wordpress.org/plugins/developers/add/ and it has been
# approved. See SUBMIT.md. After that this script — or the
# deploy-wporg.yml GitHub Action that wraps it — is the release mechanism.
#
# What it does:
#   1. Builds the plugin zip from the current working tree (bin/build-zip.sh).
#   2. Checks out (or updates) a working copy of the plugin's SVN repository.
#   3. Replaces svn/trunk with the zip's contents.
#   4. Copies trunk to svn/tags/<version>, so the tag is an exact copy of what
#      trunk was at release time — the SVN convention every wordpress.org
#      plugin follows.
#   5. Syncs svn/assets/ from .wordpress-org/ (banner, icon, screenshots).
#      Assets live outside trunk/tags on purpose: they are not part of any
#      installed copy of the plugin.
#   6. Commits once, with a message naming the version.
#
# Usage:
#   SVN_USERNAME=mahope SVN_PASSWORD=... bin/deploy-wporg.sh 0.2.0
#
# Nothing here touches the GitHub repository. Tag and push there yourself
# first — the version argument should match a real git tag.
set -euo pipefail

version="${1:?usage: bin/deploy-wporg.sh <version>}"
slug="bugbottle"
svn_url="https://plugins.svn.wordpress.org/${slug}/"

: "${SVN_USERNAME:?SVN_USERNAME is not set}"
: "${SVN_PASSWORD:?SVN_PASSWORD is not set}"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

echo "==> Building the plugin zip"
bash "$root/bin/build-zip.sh" "$work"

echo "==> Checking out the SVN working copy (this can take a while the first time)"
svn checkout --username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive \
	"$svn_url" "$work/svn"

echo "==> Unpacking the zip over trunk"
rm -rf "${work:?}/svn/trunk"
mkdir -p "$work/svn/trunk"
unzip -q "$work/$slug.zip" -d "$work/unzipped"
cp -R "$work/unzipped/$slug/." "$work/svn/trunk/"

echo "==> Tagging svn/tags/$version"
rm -rf "${work:?}/svn/tags/${version:?}"
mkdir -p "$work/svn/tags/$version"
cp -R "$work/svn/trunk/." "$work/svn/tags/$version/"

echo "==> Syncing svn/assets from .wordpress-org/"
mkdir -p "$work/svn/assets"
# Deliberately not `rsync --delete`: an old asset (a retired screenshot, a
# previous banner) is left in place rather than guessed at by a script. Prune
# it by hand when it is actually stale.
cp -R "$root/.wordpress-org/." "$work/svn/assets/"

cd "$work/svn"
svn add --force trunk "tags/$version" assets --auto-props --parents -q
# `svn status` lists deletions too (a file removed from trunk since the last
# release); stage those explicitly, since `add` only ever adds.
svn status | awk '/^!/ {print $2}' | xargs -r svn rm --force -q

echo "==> Committing"
svn commit --username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive \
	-m "Release $version"

echo "Deployed $version to $svn_url"
