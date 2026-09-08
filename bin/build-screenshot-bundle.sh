#!/usr/bin/env bash
#
# Builds `assets/bugbottle-screenshot.js`, the screenshot renderer.
#
# `assets/bugbottle.js` is the library's one-script-tag build, and that build
# deliberately carries no renderer: a picture means `html-to-image`, which is
# larger than everything else in the bundle put together, and a page that only
# wants the panel should not pay for it. So the renderer is a second file,
# enqueued only when the Screenshots setting is on.
#
# What it is: the library's `bugbottle/html-to-image` entry — one expression,
# `toPng` with the mask filter and pixel ratio the panel passes — bundled with
# `html-to-image` itself as an IIFE that sets `window.bugbottleScreenshot`.
# `Assets::config_script()` then hands `window.bugbottleScreenshot.htmlToImage`
# to `mount()` as `screenshot`, and the panel shows the screenshot row and,
# because the bundled `mount()` already hands the annotator in, "Edit picture".
#
# Both versions are pinned. `LIB_VERSION` in `bugbottle.php` must equal
# `$LIB_VERSION` below, because the two bundles are enqueued with the same
# version string and have to come from the same library release.
#
# The output is committed. Nobody installing the plugin runs this; it is here
# so the bundle is reproducible from the pinned versions, and so the md5 in
# CHANGELOG.md can be checked against a fresh build.
#
# Usage: bin/build-screenshot-bundle.sh
# Needs: node and npm on PATH, and network access to the npm registry.
set -euo pipefail

LIB_VERSION="1.0.0"
HTML_TO_IMAGE_VERSION="1.11.13"
ESBUILD_VERSION="0.28.2"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="$root/assets/bugbottle-screenshot.js"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

cd "$work"
npm init -y >/dev/null
npm install --no-audit --no-fund --silent \
	"bugbottle@$LIB_VERSION" \
	"html-to-image@$HTML_TO_IMAGE_VERSION" \
	"esbuild@$ESBUILD_VERSION"

# One re-export. esbuild's `--global-name` wraps the module's exports in
# `var bugbottleScreenshot = (() => { ... })()`, which at the top level of a
# classic script is the global the mount script reads.
printf 'export { htmlToImage } from "bugbottle/html-to-image";\n' > entry.js

npx esbuild entry.js \
	--bundle \
	--minify \
	--format=iife \
	--global-name=bugbottleScreenshot \
	--target=es2020 \
	--legal-comments=none \
	--outfile=bundle.js

# The notice goes on the file rather than only in `readme.txt`, because this
# file travels on its own into a plugins directory.
cat > "$out" <<HEADER
/*!
 * bugbottle-screenshot — the screenshot renderer for the Bugbottle WordPress
 * plugin. Built by bin/build-screenshot-bundle.sh; do not edit by hand.
 *
 * Contains:
 *   bugbottle $LIB_VERSION (the bugbottle/html-to-image entry)
 *     https://github.com/mahope/bugbottle — MIT, Mads Holst Jensen
 *   html-to-image $HTML_TO_IMAGE_VERSION
 *     https://github.com/bubkoo/html-to-image — MIT, W.Y.
 *
 * Both are MIT-licensed. Permission is hereby granted, free of charge, to any
 * person obtaining a copy of this software and associated documentation files
 * (the "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to permit
 * persons to whom the Software is furnished to do so, subject to the following
 * conditions: the above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 *
 * MIT is GPL-compatible, so the plugin as a whole stays distributable under
 * the GPL. See assets/LICENSE-bugbottle.txt.
 */
HEADER
cat bundle.js >> "$out"

echo "built $out"
wc -c < "$out" | tr -d ' \n'
echo " bytes"
md5sum "$out" 2>/dev/null || md5 "$out"
