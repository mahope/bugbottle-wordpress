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
| Contact field | Whether the panel asks how the reporter can be reached: not at all, an optional field, or one it refuses to send without. **Off by default.** Nothing checks what is typed — "ring me on 12345678" is a good answer — and the line is stored with the report, shown on the report screen and rendered as a `Contact` row. It is personal data you asked for; read [The contact field](#the-contact-field). |
| Evidence | Breadcrumbs (clicks, navigations, submits) and the network log (requests that failed or were slow — method, URL, status and duration, never a body or a header). Both on. |
| Screenshots | Lets the reporter attach a picture of the page, and mark it before sending: a rectangle, an arrow, and a blur that really destroys what it covers. **Off by default**, and it is the setting to think hardest about — read [Please read this part](#please-read-this-part). Turning it on loads a second script, `assets/bugbottle-screenshot.js`, about 15 kB (6 kB over the wire), on every page the panel is on. |
| Offline queue | Keeps a report the browser could not send and delivers it when the connection is back. Reports wait in the browser for up to seven days. The queue signs what it delivers, with a signature computed at delivery rather than at the moment the report was written, so a site with signing keys accepts it. On by default. |
| Scrubbing | Redacts email addresses, bearer tokens, JWTs, card numbers, IBANs and query values before the report is sent. On by default. |
| Timings and storage snapshot | Records what the page cost — largest contentful paint, layout shift, interaction to next paint, time to first byte, the load events, long tasks, and the JS heap in Chrome — and lists the key names in `localStorage` and `sessionStorage` with the length of each value, plus the cookie names. **Names only, never values**, and never a cookie value at all. Off by default. |
| Shake to report | Opens the panel when the phone is shaken: three shakes inside a second, then a three-second pause. Off by default. On iPhone and iPad it also needs the visitor's permission, which only Safari can ask for and only from a button they pressed — see [Shake to report](#shake-to-report). |
| Signing key(s) | One key per line. With a key set, a report must arrive signed with one of them or it is refused, and the bundled panel signs what it sends. Empty by default. Read [Signing requests](#signing-requests) before you fill it in — a key that ships to the browser is public. |
| Email recipient | Where reports are emailed, through `wp_mail` — so an SMTP plugin handles delivery. The body is the report as Markdown, with a link to it in wp-admin; it never links the screenshot itself, because that route wants an administrator's session and would only answer 401 from an inbox. When the report carries a contact line that looks like an email address, the mail is sent with it as `Reply-To`. Empty means reports are only stored. |
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
configured key to `createSigner` from `bugbottle/sign`, which is in the bundle
this plugin ships. Filling the setting in is the whole of it. Anything
else that posts to the route — your own form, a script, a mobile app — has to
compute the same digest, or it will be refused along with the spam.

**The offline queue signs too**, which takes a little wiring the library does
not do for you. `createQueue` delivers a stored report with a `fetch` of its
own and knows nothing about the signer, so the mount script gives it one: a
`fetch` that signs the bytes on their way out. That the signature is computed
*then*, and not when the report was written, is the whole point — a report that
sat through an hour of outage would otherwise carry a timestamp an hour outside
the five-minute skew window and be refused as certainly as if it were unsigned.
`tests/browser-queue-signature.mjs` is the round trip that pins this.

## The contact field

**Contact field** is off by default, and that is a decision rather than
caution: asking somebody for an address is a promise to answer, and that
promise is the site's to make.

With it on, the panel puts one field under the message — "How can we reach
you?" — and nothing validates what goes in it. A phone number, a name in your
own chat, a typo: all of them are perfectly good answers to that question, and
all of them arrive as `contact` on the report, trimmed, with null bytes
stripped and clipped at 200 characters. Set to *ask, and refuse to send without
it* and the panel will not send an empty one, through the same inline error an
empty message gets; the field is a text input with `inputmode="email"` rather
than `type="email"`, so a phone number is not announced as invalid.

Where it goes: a `Contact` row in the Markdown summary, directly under the
type, which is where the library's `toMarkdown` puts it; a section of its own
on the report detail screen, a `mailto:` link when the line looks like an
address; and `Reply-To` on the notification email, again only when it looks
like an address. That test is deliberately permissive — a line is only refused
when it plainly is not an address — because the cost of getting it wrong one
way is a reply nobody can send, and the other way one bounced mail. A line
that is not an address is left in the body and never becomes a header.

**It is personal data you asked for.** Store it like one: it is kept where the
rest of the report is kept, it goes when the report goes, and everyone who can
read a report can read it. If reports leave wp-admin for somewhere more
public, the contact line goes with them.

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

Since 0.5.0 a report may also carry `contact`, the line the reporter typed when
**Contact field** asked for one — see [The contact field](#the-contact-field).
It is never there unless the setting asked for it: a report that arrives with a
contact line the settings never asked for is stored without it.

Since 0.6.0 a report may also carry `notes`: short lines the *library* wrote
about the report, never the reporter. There is one of them today. A browser
that is offline keeps the report in `localStorage` until it can be sent, and a
report with a picture on it can be larger than what is left there; rather than
losing the report, bugbottle 0.13.0 stores it without the picture and leaves a
line saying so. The report screen shows those lines above the evidence, and so
does the Markdown summary, because a reader who sees no picture should be told
why before they go looking for one. At most five notes survive, each clipped at
200 characters, and they are validated like every other field: a note arrives
from a browser, so nothing here trusts it for being ours.

One field the library can send is deliberately not kept. bugbottle 0.8.0 added
`replay`, up to a megabyte of rrweb events recording the last seconds before
the report. `wp_postmeta` is the wrong place for a megabyte of nested JSON —
every read of the row would carry it, and nothing in wp-admin can play a
recording back — so the route names the field and keeps none of it. The report
is stored without it and is never refused for carrying one. The bundled panel
never sends one either: rrweb is the application's own dependency and the
plugin ships no adapter for it. The one visible consequence is a `Replay` row
the library renders and this port does not, which
`tests/test-report-parity.php` pins.

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

The optional contact field is the other thing here that is personal data, and
it is personal data you asked for. Store it like one: it is kept where the rest
of the report is kept, it goes when the report goes, and everyone who can read
a report can read it. See [The contact field](#the-contact-field).

Deleting a report deletes its row. The screenshot file is left on disk on
purpose: an accidental delete is recoverable, and a directory nobody can reach
over HTTP is a smaller problem than an unrecoverable one. Clear the directory
yourself when you mean it.

## The bundled panel

`assets/bugbottle.js` is the official one-script-tag build,
`dist/bugbottle.js`, copied verbatim from the
[bugbottle](https://github.com/mahope/bugbottle) package — **bugbottle 0.13.0**
at the time of writing. It is not modified here and it is not built here.

Since 0.8.0 the library also publishes `dist/bugbottle.slim.js`, the same panel
without the annotator, the timings snapshot, the shake gesture and the network
log. The plugin keeps the full build on purpose: three of those four are
settings on this screen, and a site that ticks one should not need a different
file than a site that does not.

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

There are six test files, none of which needs a framework:

```bash
php tests/test-report-parity.php      # the validators and the Markdown, against the library
php tests/test-settings.php           # the settings sanitiser, and that Language is an allow-list
php tests/test-screenshot.php         # the PNG decoder and the Screenshots setting
php tests/test-signature.php          # the signature rules, no WordPress needed
php tests/test-email.php              # the notification body: no screenshot link, an admin link that works
wp eval-file tests/test-rest-signature.php   # the REST route, from a scratch install
```

The last writes settings and stores reports, so point it at a scratch install
and never at a live site. All six exit non-zero on a failure.

One thing none of them can see is what happens in a browser across two page
loads, which is where the offline queue lives:

```bash
node tests/browser-queue-signature.mjs --wp-path /path/to/scratch/wp
```

It configures that install (panel on, anonymous allowed, queue on, one signing
key), serves it with a `php -S` of its own, drives a real Chrome through
`puppeteer-core`, takes the endpoint down, writes a report, brings the endpoint
back, reloads, and then verifies the signature on the delivered request itself
before checking that the route answered `201` and the site stored the report.
It kills only the server and the browser it started. `--wp` sets the wp-cli
command, `--chrome` the browser binary, `--port` the port. It writes settings
and stores a report, so it too belongs on a scratch install and nowhere else.

Rebuilding the screenshot renderer needs node and npm, and nothing else:

```bash
bin/build-screenshot-bundle.sh   # writes assets/bugbottle-screenshot.js, prints its md5
```

`tests/test-report-parity.php` is what pins the PHP port to the library: it
holds one report carrying every section, including the contact line and the
notes, and the Markdown and the validated JSON that the library's own
`src/markdown.ts` and `src/report-core.ts` produce from it — v0.13.0 as of this
release. When either side moves, regenerate the two expectations by running
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
