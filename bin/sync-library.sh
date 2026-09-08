#!/usr/bin/env bash
#
# Follows a bugbottle library release, in one command.
#
# Every library release used to cost an hour of the same careful, boring work:
# copy one bundle, rebuild the other against the same version, bump
# `LIB_VERSION` in two files, put both md5s in the changelog, fix the line in
# `readme.txt` that names the version, and then read the library's changelog to
# work out whether any of it needs a setting or a validator. This does the first
# six and prints the seventh as a checklist, because that part is a judgement
# and a script has no business making it.
#
# Usage:
#
#   bin/sync-library.sh <tag> [--source <path-or-git-url>] [--no-bundle]
#
# `<tag>` is `v0.15.0` or `0.15.0`; both mean the same release. `--source` is a
# local checkout of https://github.com/mahope/bugbottle (the default is
# `../bugbottle` when that is a git repository, and the public URL otherwise);
# a local checkout must already have the tag, since fetching into somebody
# else's repository is not this script's business. `--no-bundle` skips the
# screenshot rebuild, which is the only step that needs node, npm and the npm
# registry — useful for seeing what a sync would say before doing it, and never
# for a release, because then the two bundles come from different versions.
#
# It refuses to run on a dirty tree: it rewrites six files, and telling its
# changes apart from yours afterwards is the sort of thing that loses work.
#
# It is idempotent. Running it twice against the same tag leaves the same tree:
# the changelog stub lives between two markers and is replaced rather than
# appended, and every other edit is a substitution.
#
# What it does NOT do, on purpose:
#
#   - It does not regenerate the expectations in `tests/test-report-parity.php`.
#     Those are the library's own Markdown and validated JSON, produced by
#     running the fixture through the TypeScript; when `report-core.ts` or
#     `markdown.ts` moved, that is a job for a human with `node
#     --experimental-strip-types`. The checklist says so when it applies.
#   - It does not bump the plugin's own `VERSION` or `Stable tag`. Following the
#     library is not the same event as releasing the plugin.
#   - It does not write the prose. The changelog stub it leaves says what
#     changed in bytes; what that means to a WordPress site is the checklist's
#     question and yours to answer.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

die() { echo "error: $*" >&2; exit 1; }

tag=""
source_repo=""
build_bundle=1
while [ $# -gt 0 ]; do
	case "$1" in
		--source) source_repo="${2:-}"; shift 2 ;;
		--no-bundle) build_bundle=0; shift ;;
		-h|--help) sed -n '2,46p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,2\} \{0,1\}//'; exit 0 ;;
		-*) die "unknown option $1" ;;
		*) [ -z "$tag" ] || die "give one tag, not two"; tag="$1"; shift ;;
	esac
done
[ -n "$tag" ] || die "usage: bin/sync-library.sh <tag> [--source <path-or-git-url>] [--no-bundle]"

# `v0.15.0` and `0.15.0` are the same release said two ways. Everything below
# works in one of the two forms, so settle on both once.
version="${tag#v}"
tag="v$version"
echo "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+' || die "'$version' is not a version"

# A dirty tree first: the checks that follow are slower and it is rude to make
# somebody wait for a refusal.
[ -z "$(git -C "$root" status --porcelain)" ] || die "the tree is dirty; commit or stash first"

if [ -z "$source_repo" ]; then
	if [ -d "$root/../bugbottle/.git" ]; then
		source_repo="$(cd "$root/../bugbottle" && pwd)"
	else
		source_repo="https://github.com/mahope/bugbottle.git"
	fi
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Two ways in, one shape out: `$lib` ends up a git directory that has the tag,
# and everything after this reads it with `git show`.
if [ -d "$source_repo/.git" ] || [ -d "$source_repo/HEAD" ]; then
	lib="$source_repo"
	git -C "$lib" rev-parse -q --verify "refs/tags/$tag" >/dev/null ||
		die "$lib has no tag $tag (fetch it there first; this script will not)"
else
	echo "cloning $source_repo at $tag…"
	# Bare and blobless: the two files this needs are `dist/bugbottle.js` and
	# `CHANGELOG.md`, and a full history of a bundle is a lot of megabytes to
	# download for a version string.
	git clone --quiet --bare --filter=blob:none "$source_repo" "$work/lib.git" ||
		die "could not clone $source_repo"
	lib="$work/lib.git"
	git -C "$lib" rev-parse -q --verify "refs/tags/$tag" >/dev/null ||
		die "$source_repo has no tag $tag"
fi

previous="$(sed -n "s/^const LIB_VERSION = '\\([^']*\\)';\$/\\1/p" "$root/bugbottle.php")"
[ -n "$previous" ] || die "could not read LIB_VERSION from bugbottle.php"
echo "bugbottle $previous → $version"

md5of() {
	if command -v md5sum >/dev/null 2>&1; then md5sum "$1" | cut -d' ' -f1
	else md5 -q "$1"
	fi
}

# `65942` reads as `65,942` in the changelog, which is how every entry before
# this script wrote it.
grouped() { echo "$1" | sed -E ':a;s/([0-9])([0-9]{3})($|,)/\1,\2\3/;ta'; }

# ---- the panel bundle, copied verbatim

git -C "$lib" show "$tag:dist/bugbottle.js" > "$work/bugbottle.js" ||
	die "$tag has no dist/bugbottle.js"
[ -s "$work/bugbottle.js" ] || die "dist/bugbottle.js at $tag is empty"
cp "$work/bugbottle.js" "$root/assets/bugbottle.js"
panel_bytes="$(wc -c < "$root/assets/bugbottle.js" | tr -d ' ')"
panel_md5="$(md5of "$root/assets/bugbottle.js")"
echo "assets/bugbottle.js: $panel_bytes bytes, md5 $panel_md5"

# ---- the version, in the two places it is pinned
#
# They are enqueued with one version string, so a mismatch here is two bundles
# from two releases on one page.

sed -i.bak "s/^const LIB_VERSION = '[^']*';\$/const LIB_VERSION = '$version';/" "$root/bugbottle.php"
sed -i.bak "s/^LIB_VERSION=\"[^\"]*\"\$/LIB_VERSION=\"$version\"/" "$root/bin/build-screenshot-bundle.sh"

# ---- the screenshot bundle, rebuilt against the same version

if [ "$build_bundle" -eq 1 ]; then
	echo "rebuilding assets/bugbottle-screenshot.js against bugbottle@$version…"
	"$root/bin/build-screenshot-bundle.sh" >/dev/null ||
		die "bin/build-screenshot-bundle.sh failed (node, npm and the registry?)"
else
	echo "skipping the screenshot rebuild (--no-bundle); the two bundles may now disagree"
fi
shot_bytes="$(wc -c < "$root/assets/bugbottle-screenshot.js" | tr -d ' ')"
shot_md5="$(md5of "$root/assets/bugbottle-screenshot.js")"
echo "assets/bugbottle-screenshot.js: $shot_bytes bytes, md5 $shot_md5"

# ---- the one line in readme.txt that names the version

sed -i.bak "s/^The bundled library is bugbottle .*\$/The bundled library is bugbottle $version./" "$root/readme.txt"
grep -q "^The bundled library is bugbottle $version\.\$" "$root/readme.txt" ||
	die "could not find the 'The bundled library is bugbottle …' line in readme.txt"

# ---- and the one in README.md

sed -i.bak "s/\*\*bugbottle [0-9][0-9.]*\*\*/**bugbottle $version**/" "$root/README.md"

find "$root" -maxdepth 2 -name '*.bak' -delete

# ---- the changelog stub
#
# Between markers so a second run replaces it instead of leaving two. The prose
# around it is yours; only what is between the markers belongs to this script.

stub="$work/stub.md"
{
	echo "<!-- sync-library: replaced on every run; the prose around it is not -->"
	echo "- **\`assets/bugbottle.js\` is bugbottle $version**, $(grouped "$panel_bytes") bytes (md5"
	echo "  \`$panel_md5\`), copied verbatim from that release's \`dist/bugbottle.js\`."
	echo "  \`LIB_VERSION\` is \`$version\`, which is what busts the cache on both"
	echo "  enqueued files."
	echo "- **\`assets/bugbottle-screenshot.js\` was rebuilt** against"
	echo "  \`bugbottle@$version\` with \`bin/build-screenshot-bundle.sh\`, whose"
	echo "  \`LIB_VERSION\` is pinned to match: $(grouped "$shot_bytes") bytes, md5 \`$shot_md5\`."
	echo "<!-- /sync-library -->"
} > "$stub"

py="$(command -v python3 || command -v python || true)"
[ -n "$py" ] || die "python is needed to rewrite CHANGELOG.md"

CHANGELOG="$root/CHANGELOG.md" STUB="$stub" "$py" - <<'PY'
import io, os, re

path = os.environ["CHANGELOG"]
stub = io.open(os.environ["STUB"], encoding="utf-8").read().rstrip("\n")
text = io.open(path, encoding="utf-8").read()
newline = "\r\n" if "\r\n" in text else "\n"
text = text.replace("\r\n", "\n")

marked = re.compile(
    r"<!-- sync-library:.*?-->\n.*?<!-- /sync-library -->", re.S)
if marked.search(text):
    text = marked.sub(lambda _: stub, text, count=1)
else:
    # Under `## Unreleased`, in a `### Changed` if there already is one, and in
    # a new one if there is not. Anything a human wrote there stays where it is.
    at = text.index("## Unreleased")
    end = text.index("\n## ", at + 1)
    section = text[at:end]
    changed = re.search(r"\n### Changed\n\n", section)
    if changed:
        cut = at + changed.end()
        text = text[:cut] + stub + "\n\n" + text[cut:]
    else:
        cut = at + len("## Unreleased") + 1
        text = text[:cut] + "\n### Changed\n\n" + stub + "\n" + text[cut:]

io.open(path, "w", encoding="utf-8", newline=newline).write(text)
PY

echo "CHANGELOG.md: stub written under Unreleased"

# ---- the part a script does not get to decide
#
# Every `## x.y.z` section of the library's changelog strictly after the version
# that was bundled and up to and including the new one. That is the whole of
# what a reader has to think about, and no more.

echo
echo "════════════════════════════════════════════════════════════════════"
echo " What changed in the library between $previous and $version"
echo "════════════════════════════════════════════════════════════════════"
echo

if git -C "$lib" show "$tag:CHANGELOG.md" > "$work/lib-changelog.md" 2>/dev/null; then
	PREVIOUS="$previous" VERSION="$version" LIBLOG="$work/lib-changelog.md" "$py" - <<'PY'
import io, os, re, sys

# The library's changelog is full of em dashes and arrows, and a Windows
# console is cp1252 until told otherwise, where printing one is an exception
# that would take the rest of the checklist down with it.
try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

previous = os.environ["PREVIOUS"]
version = os.environ["VERSION"]
text = io.open(os.environ["LIBLOG"], encoding="utf-8").read().replace("\r\n", "\n")

def key(v):
    return tuple(int(part) for part in re.findall(r"\d+", v)[:3])

# `## 0.15.0 — 2026-09-07` and `## 0.15.0` alike.
heads = [(m.start(), m.group(1)) for m in
         re.finditer(r"^## v?(\d+\.\d+\.\d+)\b.*$", text, re.M)]
bounds = [(start, heads[i + 1][0] if i + 1 < len(heads) else len(text), v)
          for i, (start, v) in enumerate(heads)]

wanted = [b for b in bounds if key(previous) < key(b[2]) <= key(version)]
if not wanted:
    print("(no changelog sections between those two tags - nothing to read)")
else:
    for start, end, v in wanted:
        print(text[start:end].strip())
        print()
PY
else
	echo "(the library at $tag has no CHANGELOG.md)"
fi

cat <<CHECKLIST
────────────────────────────────────────────────────────────────────
Now decide, for each of those sections:

  [ ] Does any of it need a setting? Every library option a site should be
      able to reach is a field in includes/class-settings.php, a line in
      the mount script, and a sentence in README.md and readme.txt.
  [ ] Did a new field appear on the report? Then includes/class-validator.php
      needs a rule for it, includes/class-storage.php a meta key,
      includes/class-markdown.php a section, and the report screen a place
      to show it.
  [ ] Did report-core.ts or markdown.ts move? Then the expectations in
      tests/test-report-parity.php are stale. Regenerate them by running the
      fixture through the TypeScript with node --experimental-strip-types,
      and update the version README.md names beside that paragraph.
  [ ] Did any function the mount script calls change or disappear? It calls
      mount, initConsoleBuffer, initBreadcrumbs, initNetwork, initPerf,
      createQueue, resolveLocale, scrubReport, createSigner and onShake.
  [ ] Write the prose. The stub in CHANGELOG.md has the bytes; readme.txt
      needs the same entry in its own words, and neither says what any of
      it means to a site.

Then: vendor/bin/phpstan analyse, the test files in tests/, and Plugin
Check on a scratch install. git diff --stat to see what this changed.
────────────────────────────────────────────────────────────────────
CHECKLIST
