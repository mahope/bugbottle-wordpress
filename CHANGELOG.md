# Changelog

All notable changes to this plugin are documented here and in `readme.txt`
(which is what wordpress.org shows). This file exists for anyone reading the
repository directly; keep the two in step.

## Unreleased

## 0.5.0 — 2026-09-08

Follows the library to **bugbottle 0.9.0**, and the one thing that release
brings a WordPress site is a way of answering the person who wrote the report.
"The save button does nothing" is worth a reply, and until now there was
nobody to send it to.

Also here: the email fix that was sitting unreleased since 0.4.1, and a
decision written down — the plugin drops the session replay bugbottle 0.8.0
added rather than storing it.

### Added

- **A Contact field setting**, off by default, with three states rather than a
  checkbox because that is the shape the library's option has: *Do not ask*,
  *Ask, but let them send without it*, and *Ask, and refuse to send without
  it*. On, the panel puts one field under the message; required, it refuses to
  send an empty one through the same inline error an empty message gets.
  Nothing checks what is typed — "ring me on 12345678" is a good answer to
  "how do we reach you" — and the line arrives as `contact` on the report,
  trimmed, null bytes stripped, clipped at 200 characters, exactly as
  `normaliseContact` does it in the library. The setting says plainly that the
  contact field is personal data you asked for: it is kept where the rest of
  the report is kept, it goes when the report goes, and everyone who can read
  a report sees it.
- The contact line is a `Contact` row in the Markdown summary, directly under
  the type, which is where `toMarkdown` puts it — so an issue pasted from the
  email looks like every other bugbottle report. It is also a section of its
  own on the report detail screen, as a `mailto:` link when it looks like an
  address and as plain text when it does not, with a note saying nobody
  checked it.
- **The notification email sets `Reply-To`** when the contact line looks like
  an email address, so replying to the mail answers the reporter rather than
  the site. The test is the permissive one the library uses — a line is only
  refused when it plainly is not an address — ported as
  `Validator::looks_like_email()` rather than delegated to `is_email()`, so
  both sides agree. A phone number is left in the body and never becomes a
  header: `Reply-To: ring me on 12345678` is a malformed header, and Resend,
  which the library sends through, refuses one outright.
- `tests/test-report-parity.php` grew nineteen checks: the contact line rule by
  rule, `looks_like_email` against eight inputs, and the replay divergence
  below. `tests/test-email.php` grew eleven, pinning the `Reply-To`, the `Contact`
  row, and the reason a contact line carrying a CR or an LF cannot become a
  second header — `looks_like_email` excludes whitespace in every character
  class, so such a line never matches. `Validator::contact` deliberately does
  not strip newlines, because it is a byte-for-byte port of `normaliseContact`
  and the library does not either; the defence is where the address is chosen,
  and it is now a test rather than a property somebody has to notice. The parity fixture now carries a contact line and was
  regenerated against bugbottle v0.9.0's `src/markdown.ts` and
  `src/report-core.ts` with `node --experimental-strip-types`; `diff -u`
  against the PHP output is empty for both the Markdown and the validated JSON.

### Changed

- A contact line of exactly `"0"` is kept. `empty( '0' )` is true in PHP, so
  `Storage::insert` skipped the meta write for a line that is one character
  long and a real answer — an extension, a room number — and the report then
  lost its `Contact` row and its `Reply-To` as well, because the email re-reads
  what was stored. Found in review before the release and pinned in
  `tests/test-email.php`.

- **`assets/bugbottle.js` is bugbottle 0.9.0**, 65,103 bytes (md5
  `d081721b55b18de4b473ef40c4152820`), copied verbatim from that release's
  `dist/bugbottle.js`. `LIB_VERSION` is `0.9.0`, which is what busts the cache
  on both enqueued files.
- **`assets/bugbottle-screenshot.js` was rebuilt** against `bugbottle@0.9.0`
  with `bin/build-screenshot-bundle.sh`, whose `LIB_VERSION` is pinned to match:
  14,999 bytes, md5 `9affad9a82bded0f0bddb5b5773f5a7a`. The only byte that
  changed is the version in the licence notice — the library's
  `bugbottle/html-to-image` entry is one expression and has not moved since
  0.7.0 — but the two bundles are enqueued with one version string and must
  come from one release, so it is rebuilt rather than left.
- 0.8.0 added a second script-tag build, `dist/bugbottle.slim.js`, without the
  annotator, the timings snapshot, the shake gesture and the network log. The
  plugin keeps the full build: three of those four are settings here, and a
  site that turns one on should not need a different file. `bin/build-zip.sh`
  ships one bundle, and it is the one that can do everything the settings
  screen offers.
- The 0.9.0 renames — `onError` for `onFailure`, `maxEntries` for `maxItems`,
  `store` for the three store names — touch nothing in the mount script, which
  passes none of the three. The names the mount call does use (`endpoint`,
  `headers`, `queue`, `shortcut`, `openOnError`, `scrub`, `sign`, `shake`,
  `screenshot`, `extra`) are unchanged in 0.9.0, and `contact` is new beside
  them.

### Fixed

- **The notification email's link to the screenshot always answered `401`.**
  It pointed straight at `GET /wp-json/bugbottle/v1/screenshot/<id>`, which
  wants `manage_options` through a cookie session plus a REST nonce — neither
  of which an inbox has, so the link was broken for every recipient, signed in
  or not, and had been since 0.1.0. The email no longer carries it. A report
  that has a picture says so as a fact ("attached, in admin") and the "In
  admin" link, which has always worked, is how the picture is reached: the
  same capability guards both. Attaching the PNG to the email is a possible
  setting later, but the picture would then leave the site, which is exactly
  what [Please read this part](README.md#please-read-this-part) warns about,
  so it needs that text beside the box. (Issue #5.)
- `tests/test-email.php` is new and pins this: the body carries no REST URL,
  and the admin link resolves to that report's own detail screen.
  (Both landed after 0.4.1 was tagged and ship here.)

### Not stored

- **The session replay bugbottle 0.8.0 added is dropped at the door.**
  `bugbottle/rrweb` lets an application attach the last thirty seconds of an
  rrweb recording as `replay`, capped at a megabyte. Post meta is the wrong
  place for a megabyte of nested JSON: every read of the row would carry it,
  `wp_postmeta` is not a blob store, and nothing in wp-admin can play a
  recording back. So `Rest::receive_report()` names `replay` as a known field
  and keeps none of it — the report is stored without it and is never refused
  for carrying one. The bundled panel never sends one either: the plugin ships
  no rrweb adapter, and rrweb is the application's own dependency. The
  consequence for the Markdown is one row the library renders and this port
  does not, which `tests/test-report-parity.php` pins rather than leaves to be
  rediscovered.

## 0.4.1 — 2026-09-08

**The panel had never taken a screenshot.** Not in 0.1.0, not in 0.4.0. The
bundled `assets/bugbottle.js` is the library's one-script-tag build, and that
build carries no renderer on purpose — a picture means `html-to-image`, which
is larger than the rest of the panel put together. Without a renderer the panel
hides the screenshot row, so no report the panel sent ever had a picture on it,
however plainly the readme, the intro and the privacy section said otherwise.
The route accepted and stored one all along, which is why nothing looked
broken: a picture only ever arrived from a form somebody wrote themselves.

This release ships the renderer and makes it a setting, so the plugin does what
it says. (Reported as issue #4.)

### Added

- **`assets/bugbottle-screenshot.js`**, 14,999 bytes (md5
  `7f729536c46c40e2c7f95b356ddc01f8`), about 6 kB over the wire. It is the
  library's `bugbottle/html-to-image` entry — one expression, `toPng` with the
  mask filter and pixel ratio the panel passes — bundled together with
  `html-to-image` 1.11.13 by esbuild 0.28.2 as an IIFE that sets
  `window.bugbottleScreenshot`. `bin/build-screenshot-bundle.sh` is the exact
  command, with all three versions pinned; running it twice gives the same
  bytes, so the md5 above is checkable. Both bundled files are MIT, and the
  notice is now in the file header, in `assets/LICENSE-bugbottle.txt` and in
  `readme.txt`'s source-and-licence section.
- **Screenshots**, a setting, **off by default**. The renderer is enqueued only
  when it is on, with the panel bundle as its dependency, so a site that leaves
  it off downloads nothing extra and no report can carry a picture at all —
  which is a complete answer to the privacy question, and the reason the
  default is off. When it is on, `Assets::config_script()` passes
  `window.bugbottleScreenshot.htmlToImage` as `screenshot`, the panel shows the
  row, and because the bundled `mount()` already hands `createAnnotator` in,
  "Edit picture" — rectangle, arrow, and a blur that reads the region back out
  of the canvas so the original pixels leave with it — appears under the
  preview by itself. Verified end to end in a real Chrome against a real
  WordPress served by `php -S`: with the setting on the report carried a
  1280×1358 PNG that `getimagesize` accepts, stored under a random name in the
  uploads directory; with it off there was no second script tag, no
  `window.bugbottleScreenshot`, no screenshot row and no picture on the report.
- The settings text says what a picture of the page contains, that everyone who
  can read a report sees it, that field values are bulleted before the render,
  and that `data-bugbottle-mask` and `data-bugbottle-block` are how anything
  else is kept out of it. That text is load-bearing; do not soften it.
- `.gitattributes`, marking both bundles `-text` and the compiled catalogue
  binary. Without it a checkout on Windows rewrites the line endings of a file
  whose md5 this changelog asks you to verify.
- `tests/test-screenshot.php`: fifteen checks over `decode_screenshot()` and
  the setting. The decoder was already correct — it reads the PNG signature out
  of the **decoded bytes** rather than trusting the `data:` label, exactly as
  the library's TypeScript does, so nothing needed porting — but nothing pinned
  it. A JPEG wearing a PNG label, an HTML login page returned in a renderer's
  place, a buffer shorter than the signature, and a data URL past the ceiling
  are all refused, and screenshots stay off through a form that does not
  mention them.

### Fixed

- **The screenshot on the report detail screen never loaded.** An `<img>` is
  fetched by the browser and carries no `X-WP-Nonce` header, and
  `rest_cookie_check_errors()` treats a cookie request with no nonce as
  anonymous — so `may_read_screenshot()` refused an administrator looking at
  their own report, and the picture came back `401`. `Rest::screenshot_src()`
  puts a REST nonce in the query string, where WordPress looks second. The
  `manage_options` check is untouched: the nonce authorises nothing, it only
  keeps the request from being thrown away before the check runs.
- The picture on that screen has alt text now, naming the report it belongs to,
  rather than `alt=""`.

### Changed

- The prose that described a picture the plugin could not take: the README
  intro, its "Please read this part" (which grew a fourth rule — what is hidden
  before the render — and now opens with the setting being off), "What a report
  carries", "The bundled panel", `readme.txt`'s intro, its screenshot list, its
  privacy section and the FAQ. The 0.4.0 annotator line in both changelogs now
  points here rather than implying there was ever a picture to mark.

## 0.4.0 — 2026-09-08

Follows the library to bugbottle 0.7.0. That is the release signing arrives
in, so the signing key setting below — written and tested against the server
side first — works from this version on: the bundled panel signs what it
sends. Two more things 0.7.0 added are settings here, both off by default, and
one of them — marking the screenshot before it is sent — needed nothing here at
all, though there was no picture to mark until 0.4.1.

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
- **Timings and storage snapshot**, a setting, off by default. It starts the
  library's `initPerf`, so a report carries `perf` — largest contentful paint,
  cumulative layout shift, interaction to next paint, time to first byte,
  `DOMContentLoaded`, load, the count and total of long tasks and, on Chromium,
  the JavaScript heap — and `storage`, which lists the key names in
  `localStorage` and `sessionStorage` with the **length** of each value, and the
  **names** of the cookies. Never a value, and never a cookie value at all. The
  setting text says so, because a key name is still a fact about the visit and
  the person ticking the box should know what they are turning on.
- `Validator::perf()` and `Validator::storage()`, the ports of the library's
  `normalisePerf` and `normaliseStorage`, with the same ceilings
  (`MAX_STORAGE_KEYS` 50, `MAX_COOKIE_NAMES` 100, `MAX_STORAGE_KEY_LENGTH` 100,
  `MAX_STORAGE_VALUE_LENGTH` 200, `MAX_STORAGE_VALUES` 20, `MAX_PERF_MS` an
  hour) and the same "a section with nothing usable in it is null, not an empty
  object" rule. `Markdown::render()` grew the **Performance** table and the
  collapsed **Storage** block to match `toMarkdown`, and both blocks are stored
  in meta of their own (`_bugbottle_perf`, `_bugbottle_storage`) and rendered as
  tables on the report detail screen.
- **Shake to report**, a setting, off by default: `mountBugbottle` is handed the
  library's `onShake`, so shaking the phone opens the panel. iOS is the reason
  this is two sentences on the settings screen rather than one: Safari reports
  no motion until the visitor has agreed, and it will only ask from a button the
  visitor pressed. The plugin **enables the gesture and explains it, and never
  puts the prompt up itself** — a permission dialog nobody asked for is worse
  than a missing feature. A theme that wants it on iOS calls
  `window.bugbottle.requestShakePermission()` from a button of its own.
- `tests/test-report-parity.php`: one report carrying every section, and the
  Markdown and the validated JSON the library produces from it, pinned. Both
  expectations came out of bugbottle v0.7.0's own `src/markdown.ts` and
  `src/report-core.ts` under `node --experimental-strip-types`, and `diff -u`
  against the PHP output was empty — 2002 bytes of Markdown, identical, and the
  validated JSON with it. 20 checks, `php tests/test-report-parity.php`.
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

### Changed

- `assets/bugbottle.js` is the unmodified `dist/bugbottle.js` from bugbottle
  0.7.0 (md5 `94bda51f99a6a4bee5973a39012234a2`), and `LIB_VERSION` says so.
- **The signing setting works now.** The mount script hands the first
  configured key straight to `api.createSigner`; the `typeof` guard that kept
  it inert on the 0.6.0 bundle is gone, and so are the three warnings — on the
  settings screen, in the README and in `readme.txt` — that said it could not
  be switched on yet. The caveat that stays is the one that matters: a key in
  the browser is public, and this is spam deterrence, not authentication.
  Verified end to end in a real Chrome against the shipped bundle: the panel
  signs, the route accepts, a tampered body is refused with `401 Bad
  signature`, and so is a replay of the untouched bytes.
- The mount still calls `window.bugbottle.mount()` rather than the library's
  `mountBugbottle()`, and that now matters for a second reason. In 0.7.0 the
  panel takes the annotator as a function you hand in, so an application that
  never marks a picture does not ship a canvas editor; the script-tag build's
  `mount()` is the wrapper that hands it in. Calling it is what puts "Edit
  picture" — rectangle, arrow, and a blur that reads the region back out of the
  canvas so the original pixels leave with it — in the panel wherever there is a
  picture to mark, with nothing added here. There was none in this release: the
  one-script-tag build carries no `html-to-image`, so the panel hid the
  screenshot row. 0.4.1 is where the renderer arrives.
- The panel's Escape handling and its annotator fixes came with the bundle and
  needed no change in the plugin.

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
