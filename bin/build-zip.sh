#!/usr/bin/env bash
#
# Builds the distributable plugin zip.
#
# The archive unpacks into `bugbottle/`, which is the WordPress.org slug and
# therefore the directory the plugin is installed as. Anything that only exists
# to develop the plugin is left out: the Plugin Check rejects most of it
# (hidden files, compressed files, vendor trees) and none of it is useful on a
# live site.
#
# Usage: bin/build-zip.sh [output-dir]
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="${1:-$root}"
slug="bugbottle"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/$slug"

# The allowlist is deliberate. A denylist grows a hole every time the repo
# grows a directory.
for path in \
	bugbottle.php \
	readme.txt \
	LICENSE \
	includes \
	assets \
	languages
do
	cp -R "$root/$path" "$stage/$slug/"
done

# Compiled catalogues ship; the sources they were compiled from do not need to.
find "$stage/$slug/languages" -name '*.po' -delete

mkdir -p "$out"
rm -f "$out/$slug.zip"
( cd "$stage" && zip -rq "$out/$slug.zip" "$slug" -x '*.DS_Store' )

echo "built $out/$slug.zip"
