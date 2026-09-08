# bugbottle for WordPress

The [bugbottle](https://github.com/mahope/bugbottle) panel — see it at
[bugbottle.dev](https://bugbottle.dev) — and its receiving
endpoint, in one activation. Reports land as a private post type in wp-admin —
with the page, the viewport, the recent console errors and their stack frames,
the requests that failed, the element the reporter pointed at, what they did
just before, and — when you turn screenshots on — a picture of what they were
looking at, which they can mark up first. Reports are emailed on if you give it
a recipient.

Error trackers catch what throws. They cannot catch what merely looks wrong,
and they never tell you what the person was doing when it did. *"The save
button does nothing"* is not a report anyone can act on.

- **One activation.** No endpoint to write, no service to sign up for. The
  panel and the route that receives it arrive together.
- **Nothing leaves your site.** Reports are rows in your database and files in
  your uploads directory. There is no vendor in the middle.
- **Your language, your brand.** Eight locales, following the site language by
  default; colour, position, name and logo on the settings screen.
- **No build step, no Composer.** The plugin is PHP and two JavaScript files,
  the second of which is only loaded when screenshots are on.

Requires WordPress 6.4 and PHP 8.1.

## Install

1. In wp-admin, go to **Plugins → Add New**, search for "Bugbottle", then
   install and activate. Or download `bugbottle.zip` from the
   [latest GitHub release](https://github.com/mahope/bugbottle-wordpress/releases/latest)
   and upload it under **Plugins → Add New → Upload Plugin**.
2. Go to **Bug reports → Settings** and set at least an email recipient, or
   plan to read the reports in wp-admin.

GitHub stays the canonical source and where releases are tagged; the zip on
wordpress.org is built from the same tag. To update, use the in-admin updater,
or upload the newer zip over the old one — WordPress will ask you to confirm
the replacement. Your reports and settings survive either: they live in the
database, not in the plugin directory.

## Settings

**Bug reports → Settings**

| Setting | What it does |
|---|---|
| Show the report panel on the front end | Whether the panel is rendered at all. Off leaves the REST route working, so your own form can still post to it. |
| Only for logged-in users | Hides the panel and refuses reports from visitors who are not signed in. |
| Accept reports from visitors who are not logged in | Anonymous reports, rate-limited to 10 per hour per IP address. Off by default. |
| Language | The language the panel speaks: `auto` follows the site language, or pick one of Danish, English, Swedish, Norwegian, German, Dutch, French, Spanish. |
| Primary colour | The accent: trigger button, primary action, focus ring. |
| Position | Which corner the floating button sits in. |
| Brand name, Logo URL | Shown in the panel header and on the trigger. |
| Trigger selector | A CSS selector for your own button, e.g. `#report-a-bug`. Empty means the floating button. |
| Keyboard shortcut | The combination that opens the panel. `mod` is Command on a Mac and Ctrl everywhere else; `mod+shift+b` by default. Empty means no shortcut. |
| Open on error | Opens the panel by itself when the page throws an uncaught error. Off by default — it shows the panel to whoever is on the page, customers included. |
| Evidence | Breadcrumbs (clicks, navigations, submits) and the network log (requests that failed or were slow — method, URL, status and duration, never a body or a header). Both on. |
| Screenshots | Lets the reporter attach a picture of the page, and mark it before sending: a rectangle, an arrow, and a blur that really destroys what it covers. **Off by default**, and it is the setting to think hardest about — read [Please read this part](#please-read-this-part). Turning it on loads a second script, `assets/bugbottle-screenshot.js`, about 15 kB (6 kB over the wire), on every page the panel is on. |
| Offline queue | Keeps a report the browser could not send and delivers it when the connection is back. Reports wait in the browser for up to seven days. On by default. |
| Scrubbing | Redacts email addresses, bearer tokens, JWTs, card numbers, IBANs and query values before the report is sent. On by default. |
| Timings and storage snapshot | Records what the page cost — largest contentful paint, layout shift, interaction to next paint, time to first byte, the load events, long tasks, and the JS heap in Chrome — and lists the key names in `localStorage` and `sessionStorage` with the length of each value, plus the cookie names. **Names only, never values**, and never a cookie value at all. Off by default. |
| Shake to report | Opens the panel when the phone is shaken: three shakes inside a second, then a three-second pause. Off by default. On iPhone and iPad it also needs the visitor's permission, which only Safari can ask for and only from a button they pressed — see [Shake to report](#shake-to-report). |
| Signing key(s) | One key per line. With a key set, a report must arrive signed with one of them or it is refused, and the bundled panel signs what it sends. Empty by default. Read [Signing requests](#signing-requests) before you fill it in — a key that ships to the browser is public. |
| Email recipient | Where reports are emailed, through `wp_mail` — so an SMTP plugin handles delivery. Empty means reports are only stored. |
| Email on submit | Send the email as soon as a report arrives. |

### For developers

Two filters, both in `includes/class-assets.php`:

```php
// Hide the panel on some pages.
add_filter( 'bugbottle_show_panel', fn( $show ) => ! is_cart() );

// Add fields to every report — an app version, a tenant id.
add_filter( 'bugbottle_panel_config', function ( $config ) {
	$config['extra'] = array( 'theme' => wp_get_theme()->get( 'Version' ) );
	return $config;
} );
```

And one action, after a report is stored:

```php
add_action( 'bugbottle_report_stored', function ( int $post_id, array $report ) {
	// Post it to Slack, open an issue, whatever you like.
}, 10, 2 );
```

The REST route is `POST /wp-json/bugbottle/v1/report`. It takes the ordinary
bugbottle JSON body, and a logged-in caller identifies itself with the standard
`X-WP-Nonce` header. Everything in the body is validated exactly as
`bugbottle/server` validates it — the rules are ported to PHP in
`includes/class-validator.php`, and `includes/class-markdown.php` is the same
port of the library's `toMarkdown`, so the summary in wp-admin and in the email
is the rendering you already know.

## Signing requests

**A key that is sent to the browser is public.** It is in the page source, so
anyone who wants it has it. Signing raises the cost of posting junk to the
endpoint from a script that has not read your page; it is spam deterrence
beside the rate limit, and it is not authentication. Nothing here secures the
endpoint, and it must not be described as if it did.

With **Signing key(s)** filled in, the route requires the header the library
sends:

```
X-Bugbottle-Signature: t=1757260800000,v1=<64 lowercase hex characters>
```

`t` is a unix timestamp in milliseconds and `v1` is
`HMAC-SHA-256(key, "<t>.<body>")` over the raw request body. The plugin
verifies over the bytes as they arrived — `WP_REST_Request::get_body()`, which
WordPress fills from `php://input` before it parses anything — and never over
`json_decode` followed by `json_encode`, which would move key order, spacing
and number formatting and take the digest with them.

A request is refused with `401` and `Bad signature` when the header is missing,
malformed, signed with a key that is not listed, more than five minutes away
from the server clock in either direction, or carries a digest already
accepted inside that window. The reason is deliberately not narrowed down:
telling a caller which part they got wrong tells them how to get it right.

Several lines is how a key is rotated. Add the new key, leave the old one until
the last cached page carrying it has expired, then delete the old one. The
first line is the key the panel is given; every line is a key the route
accepts.

The bundled panel signs what it sends: the mount script hands the first
configured key to `createSigner` from `bugbottle/sign`, which is in the 0.7.0
bundle this plugin ships. Filling the setting in is the whole of it. Anything
else that posts to the route — your own form, a script, a mobile app — has to
compute the same digest, or it will be refused along with the spam.

## Timings and storage

**Timings and storage snapshot** is off by default, and worth understanding
before you turn it on.

The timings are the ones a Web Vitals report shows — largest contentful paint,
cumulative layout shift, interaction to next paint, time to first byte,
`DOMContentLoaded`, load, the count and total duration of long tasks, and on
Chromium the JavaScript heap. Two of them are simplifications, and the library
documents them as such rather than hiding them: the layout shift is the sum of
the shifts rather than the worst session window, and the interaction figure is
the worst interaction rather than the 98th percentile.

The storage snapshot is the part to read twice. It lists **the key names** in
`localStorage` and `sessionStorage` with **the length of each value**, and
**the names of the cookies**. It never records a value, and never a cookie
value at all. That is still not nothing: a key called `impersonating_user` is
a fact about the visit, and a length is a hint about a value. Turn it on when
you are debugging state, read the report screen before you forward one, and
leave it off the rest of the time.

## Shake to report

**Shake to report** is off by default. With it on, shaking the phone opens the
panel — three shakes inside a second, with a three-second pause afterwards so
one gesture opens one panel, and the listener comes off when the tab goes to
the background.

On iPhone and iPad, Safari reports no motion at all until the visitor has
agreed to it, and it will only ask from a button the visitor pressed. **This
plugin never puts that prompt up for you**: a permission dialog nobody asked
for is worse than a missing feature. If you want the gesture on iOS, call it
from a button of your own:

```html
<button type="button" id="enable-shake">Ryst for at rapportere</button>
<script>
document.getElementById( 'enable-shake' ).addEventListener( 'click', function () {
	window.bugbottle.requestShakePermission();
} );
</script>
```

It resolves to `granted`, `denied` or `unsupported`; everywhere but iOS it is
`unsupported` and the gesture already works.

## What a report carries

The page, the viewport and the user agent, as before. Since 0.3.0 also the
browser language, the time zone, the screen size with its pixel ratio, the
colour scheme, whether the browser believed it was online and the connection
type — each only when the browser had an answer, and each capped on the way in
(35, 64, 32 and 16 characters; `dark` or `light` and nothing else). An uncaught
error carries up to ten stack frames, and a frame is a file and a position:
no source text is ever read or sent, so resolving one stays with whoever has
the maps. The network log records requests that failed or were slow as method,
URL, status and duration — never a body and never a header, in either
direction, because that is where tokens and personal data live.

Since 0.4.0, and only when **Timings and storage snapshot** is on, a report
also carries `perf` — the timings above — and `storage`, the key names and
value lengths described in [Timings and storage](#timings-and-storage). Both
are capped on the way in: fifty keys per store, a hundred cookie names, a
hundred characters per name, and an hour as the longest duration any figure
may claim.

A picture of the page is there only when **Screenshots** is on, the report is
one the box was ticked for, and the reporter left it ticked. Before 0.4.1 there
was never one at all: the plugin shipped no renderer, so the panel hid the row.

None of that says more about the person than the user agent already does, and
nothing beyond that list is collected: no canvas fingerprint, no font
enumeration, no device enumeration.

## Please read this part

A screenshot of your site contains whatever the reporter could see. In a
clinical system that can mean a patient photograph; in a payroll tool, a
salary; in yours, perhaps somebody's inbox or a half-written message they had
not sent yet.

That is why **Screenshots is off until you turn it on**. Leaving it off is a
complete answer: no renderer is loaded, the panel does not offer a picture, and
no report can carry one.

Four things follow if you do turn it on. The plugin does the first three for
you; the fourth it cannot:

1. **Screenshots are kept out of the media library.** They are written to
   `wp-content/uploads/bugbottle/` under a random name, never registered as
   attachments, and the directory ships with an `.htaccess` deny rule. **That
   rule only works on Apache.** On nginx, add the deny yourself:

   ```nginx
   location ~* /wp-content/uploads/bugbottle/ { deny all; }
   ```

2. **They are served back through an authenticated route.**
   `GET /wp-json/bugbottle/v1/screenshot/<id>` requires `manage_options`. The
   `<id>` is the report, and the file name is looked up from that row rather
   than taken from the request, so an id cannot be used to walk the directory.
3. **What was typed is hidden before the picture is taken.** Every `input`,
   `textarea` and `contenteditable` is replaced with bullets for the length of
   one render and put straight back, so the picture shows a filled-in form of
   the right shape without the values. Mark anything else that must not be in
   it with `data-bugbottle-mask` (its text is bulleted) or
   `data-bugbottle-block` (it is covered entirely). Nothing else is hidden: a
   name printed in a heading is a name in the picture.
4. **Say so before the picture is taken.** The panel says it, in the note next
   to the checkbox — not in a policy nobody opens. If you reword it, keep it
   saying it.

Requiring people to be signed in is worth considering too. An anonymous
screenshot is one nobody can be asked about later, and nobody can be told has
been deleted.

Deleting a report deletes its row. The screenshot file is left on disk on
purpose: an accidental delete is recoverable, and a directory nobody can reach
over HTTP is a smaller problem than an unrecoverable one. Clear the directory
yourself when you mean it.

## The bundled panel

`assets/bugbottle.js` is the official one-script-tag build,
`dist/bugbottle.js`, copied verbatim from the
[bugbottle](https://github.com/mahope/bugbottle) package — **bugbottle 0.7.0**
at the time of writing. It is not modified here and it is not built here.

When bugbottle publishes a new release, updating the panel is two lines:

```bash
cp ../bugbottle/dist/bugbottle.js assets/bugbottle.js
# then bump LIB_VERSION in bugbottle.php to match
```

`LIB_VERSION` is what the enqueued URL is versioned by, so bumping it is what
makes browsers fetch the new file. The plugin's own `VERSION` tracks the
plugin.

The plugin does not use the bundle's `data-*` auto-mount. It enqueues the file
in the footer and follows it with a small inline script that calls
`window.bugbottle.mount()` explicitly, because the mount needs the REST nonce
in a header and a nonce does not belong in an attribute that a cached page
would serve to the next visitor.

`mount()` rather than the library's own `mountBugbottle()`: since 0.7.0 the
panel takes the annotator as a function you hand in, so nobody pays for a
canvas editor they never open, and `mount()` is the script-tag build's wrapper
that hands it in. Calling it is what puts "Edit picture" — the rectangle, the
arrow and the blur that really destroys what it covers — in the panel wherever
there is a picture to mark, with nothing added here.

### The renderer

Taking a picture needs a renderer, and the one-script-tag build deliberately
carries none: a picture means `html-to-image`, which is larger than everything
else in the bundle put together, and a page that only wants the panel should
not download it. Versions 0.1.0 to 0.4.0 shipped no renderer at all, so the
panel hid the screenshot row and the plugin never took a picture — whatever the
prose said.

Since 0.4.1 there is a second file for it. `assets/bugbottle-screenshot.js` is
the library's `bugbottle/html-to-image` entry bundled together with
`html-to-image` as an IIFE that sets `window.bugbottleScreenshot`, and it is
enqueued only when **Screenshots** is on, with the panel bundle as its
dependency. The mount script then passes
`window.bugbottleScreenshot.htmlToImage` as `screenshot`, which is what makes
the panel show the row — and, because `mount()` has already handed the
annotator in, what puts "Edit picture" under the preview.

`bin/build-screenshot-bundle.sh` builds that file and is the only thing that
should: it pins bugbottle, `html-to-image` and esbuild by exact version, writes
the MIT notice into the header, and prints the md5 that `CHANGELOG.md` records.
Both bundles are enqueued with the same `LIB_VERSION`, so they must come from
the same library release.

## Development

```bash
composer install                 # dev only: PHPStan and the WordPress stubs
vendor/bin/phpstan analyse       # level 5, clean
wp i18n make-pot . languages/bugbottle.pot --slug=bugbottle --exclude=vendor,tests,bin,.wordpress-org,assets
wp i18n update-po languages/bugbottle.pot languages/bugbottle-da_DK.po
wp i18n make-mo languages/bugbottle-da_DK.po languages/
```

There are four test files, none of which needs a framework:

```bash
php tests/test-report-parity.php      # the validators and the Markdown, against the library
php tests/test-screenshot.php         # the PNG decoder and the Screenshots setting
php tests/test-signature.php          # the signature rules, no WordPress needed
wp eval-file tests/test-rest-signature.php   # the REST route, from a scratch install
```

The last writes settings and stores reports, so point it at a scratch install
and never at a live site. All four exit non-zero on a failure.

Rebuilding the screenshot renderer needs node and npm, and nothing else:

```bash
bin/build-screenshot-bundle.sh   # writes assets/bugbottle-screenshot.js, prints its md5
```

`tests/test-report-parity.php` is what pins the PHP port to the library: it
holds one report carrying every section, and the Markdown and the validated
JSON that the library's own `src/markdown.ts` and `src/report-core.ts` produce
from it. When either side moves, regenerate the two expectations by running
the fixture through the TypeScript with `node --experimental-strip-types` and
diffing the output.

`vendor/` is not committed and never ships in the zip. Tagging `vX.Y.Z` builds
`bugbottle.zip` and attaches it to a GitHub release. See `SUBMIT.md` for how a
tagged release also ships to the wordpress.org SVN repository.

## Licence

GPL-2.0-or-later. The two bundled JavaScript files stay MIT-licensed —
`assets/bugbottle.js` is the bugbottle library, `assets/bugbottle-screenshot.js`
is that library's renderer together with
[html-to-image](https://github.com/bubkoo/html-to-image); see
`assets/LICENSE-bugbottle.txt`.
