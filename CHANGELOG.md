# Changelog

All notable changes to this plugin are documented here and in `readme.txt`
(which is what wordpress.org shows). This file exists for anyone reading the
repository directly; keep the two in step.

## Unreleased

### Added

- **Signing key(s)**, one key per line. With at least one key set,
  `POST /wp-json/bugbottle/v1/report` requires the library's
  `X-Bugbottle-Signature` header — `t=<unix milliseconds>,v1=<hex>`, where the
  hex is `HMAC-SHA-256(key, "<t>.<body>")` — and refuses the report without it.
  `includes/class-signature.php` verifies it over the **raw** bytes
  (`WP_REST_Request::get_body()`, which `WP_REST_Server::serve_request()` fills
  from `php://input` before it parses anything), inside a five-minute window in
  **both** directions, with `hash_equals`, against every configured key so a
  key can be rotated, and refuses a digest already accepted inside the window —
  remembered in a transient that lives for twice the skew, because a timestamp
  five minutes ahead is still fresh five minutes from now. Missing, malformed,
  wrong, expired and replayed all answer `401 {"error":"Bad signature"}`
  alike: saying which part was wrong says how to get it right. The check runs
  before the anonymous rate limit, so a caller who cannot sign cannot spend
  somebody else's allowance trying. A site with no key configured verifies
  nothing and is unaffected.
- The settings screen says, beside the field, that **a key sent to the browser
  is public** — it is in the page source — and that signing is spam deterrence
  beside the rate limit rather than authentication. That text is load-bearing;
  do not soften it.
- `tests/test-signature.php`, the rules without WordPress (21 checks,
  `php tests/test-signature.php`), and `tests/test-rest-signature.php`, the
  REST route through `rest_do_request` against real settings and transients
  (14 checks, `wp eval-file`). The first pins a vector against Node's
  `crypto.createHmac` so the PHP and the library agree byte for byte:
  `hash_hmac("sha256", '1757260800000.{"a":1}', "bugbottle-test-key")` is
  `f358148d3bf2ba334ce21bbafb5c3cc088dbd007e9773682e9a8fdc9f2ccaf07`.
- `.distignore`, so the wordpress.org deploy — which ships everything not
  listed there — leaves `tests/`, `bin/`, the repo docs and the Composer files
  out of SVN trunk. `bin/build-zip.sh` works from an allowlist and never
  needed one; the deploy workflow always did.

### Still to do before this can be switched on

- **The bundled `assets/bugbottle.js` is bugbottle 0.6.0, which does not
  sign.** Signing ships in bugbottle 0.7.0 (`createSigner` from
  `bugbottle/sign`). The inline mount already passes the key through
  `api.createSigner`, guarded on the function existing, so it is inert on the
  0.6.0 bundle — which means a site that fills the setting in today will have
  every report the panel sends refused. Copy `dist/bugbottle.js` from bugbottle
  0.7.0 into `assets/`, bump `LIB_VERSION`, and drop the warning from the
  settings screen, the README and `readme.txt` in the same commit.

## 0.3.0 — 2026-09-08

Follows the library to bugbottle 0.6.0, and lets the settings screen turn on
what 0.6.0 added.

### Added

- Six optional facts on the report context — `language`, `timezone`, `screen`,
  `colorScheme`, `online` and `connection`. `Validator::context()` clips them
  to the ceilings the TypeScript `normaliseContext` uses (35, 64, 32 and 16
  characters), accepts only `dark` or `light` for the colour scheme, and keeps
  `online: false`, which is an answer rather than a missing value. Anything of
  the wrong type is dropped rather than stored.
- `console[].stack`: up to ten `{ file, line, col, fn? }` frames on an uncaught
  error or a rejection, validated by `Validator::stack()` with the library's
  `MAX_STACK_FRAMES` and `MAX_STACK_STRING_LENGTH`. A frame without a string
  `file` is dropped rather than failing the entry. A frame is a position, never
  source text, and none is accepted if it appears.
- `Validator::network()`, the port of `normaliseNetwork`: requests that failed
  or were slow, as `{ ts, method, url, status, ms, error? }`, thirty at most.
  Stored in its own `_bugbottle_network` meta key and rendered as the
  **Requests** table.
- Five settings, each wired into the inline mount config: **Keyboard
  shortcut** (`mod+shift+b` by default, empty for none, passed as `shortcut`),
  **Open on error** (off, `openOnError`), **Offline queue** (on,
  `createQueue({ endpoint, headers })` so a queued report still carries the
  REST nonce), **Network log** (on, `initNetwork({ endpoint })`) and
  **Breadcrumbs** (on, `initBreadcrumbs()`). Breadcrumbs and the network log
  had no switch before; the panel simply started them.
- The report detail screen shows the timeline and the requests as tables of
  their own, beside the Markdown summary — the textarea is for pasting, not
  for reading.
- Danish for every new setting, label and column.

### Changed

- `assets/bugbottle.js` is the unmodified `dist/bugbottle.js` from bugbottle
  0.6.0, and `LIB_VERSION` says so.
- `Markdown::render()` matches the 0.6.0 `toMarkdown` exactly: **Screen**
  between Viewport and Browser, then **Language**, **Time zone**, **Colour
  scheme**, **Online** (`yes`/`no`) and **Connection** after it; the
  **Requests** table between the timeline and the console; and the top three
  frames of a stack indented under their console entry. Verified by rendering
  a fixture carrying all of it through both `src/markdown.ts` (with
  `node --experimental-strip-types`) and the PHP port and diffing the two —
  byte-identical, as is the validated JSON each side produces.

## 0.2.0 — 2026-09-07

Ready for the WordPress.org plugin directory.

### Changed

- Relicensed from MIT to **GPL-2.0-or-later**, in the plugin header,
  `LICENSE`, `composer.json` and `readme.txt`. The bundled
  `assets/bugbottle.js` stays MIT-licensed — its notice now lives in
  `assets/LICENSE-bugbottle.txt` — which is GPL-compatible, so the plugin as a
  whole is distributable under the GPL.
- `Author URI` changed from `mahope.dk` to `mahoje.dk`.
- The plugin directory (and the zip it ships in) is now `bugbottle`, matching
  the reserved wordpress.org slug. If you installed 0.1.0 from a GitHub zip it
  landed in `bugbottle-wordpress`; delete that copy after installing this one,
  or reports will be split across two entries in the plugin list.
- `readme.txt` rewritten: a fuller description, a "For developers" section,
  an FAQ with the three screenshot-privacy rules, a screenshots section, and
  `Tested up to: 7.1`.
- `bin/build-zip.sh` replaces the inline rsync step in the release workflow,
  building the same `slug/` layout locally and in CI from an explicit
  allowlist of shipped files.

### Fixed

- The official Plugin Check now passes with zero errors and zero warnings:
  - The inline mount script in `includes/class-assets.php` is assembled from
    an array of single-quoted lines instead of a heredoc, which Plugin Check
    forbids outright.
  - `$_GET['paged']`, `$_GET['status']` (`includes/class-reports-list-table.php`)
    and `$_SERVER['REMOTE_ADDR']` (`includes/class-rest.php`) are unslashed
    before sanitising.
  - The `load_plugin_textdomain()` call is removed: WordPress.org loads
    translations for hosted plugins automatically, so the manual call was
    flagged as discouraged and no longer does anything useful.

### Added

- `.wordpress-org/` directory assets: banner (1544×500 and 772×250) and icon
  (256×256 and 128×128), plus four screenshots (front-end panel, admin list,
  report detail, settings).
- `SUBMIT.md`: the submission steps for wordpress.org, and the release
  checklist that keeps GitHub and the plugin SVN repository in step.
- `.github/workflows/deploy-wporg.yml` and `bin/deploy-wporg.sh`: ship a
  tagged release to the wordpress.org SVN repository, once `SVN_USERNAME` and
  `SVN_PASSWORD` exist as repository secrets.

## 0.1.0 — first release

REST endpoint, private `bugbottle_report` post type, admin list and detail
screens, settings, email via `wp_mail`, screenshots behind an admin-only
route, Danish and English.
