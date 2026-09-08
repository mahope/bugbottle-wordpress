=== Bugbottle ===
Contributors: mahope
Tags: bug report, feedback, screenshot, support, qa
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

In-app bug reports that arrive with the evidence attached: the page, the console errors, the element, and optionally a picture.

== Description ==

Error trackers catch what throws. They cannot catch what merely looks wrong, and they never tell you what the person was doing when it did. "The save button does nothing" is not a report anyone can act on.

Bugbottle puts a small report panel on your site and receives what it sends. Every report arrives with the page and the viewport, the console errors from just before, the element the reporter pointed at, a short timeline of what they did, and optionally a picture of what they were looking at. Reports are stored as a private post type in wp-admin and can be emailed on.

Nothing leaves your site. There is no vendor in the middle, no dashboard to log into and no account to create: reports are rows in your database and files in your uploads directory. The plugin makes no outbound HTTP requests of any kind, and the bundled JavaScript contacts nothing but your own site.

The panel speaks Danish, English, Swedish, Norwegian, German, Dutch, French and Spanish, and follows your site language by default. Colour, position, brand name and logo are on the settings screen.

= What a report contains =

* The URL, the page title and the viewport size.
* The browser and platform, from the user agent string.
* The browser language, the time zone, the screen size, the colour scheme, whether the browser thought it was online, and the connection type - each of them only when the browser had an answer.
* Console errors captured from just before the panel was opened, with up to ten stack frames on an uncaught error. A frame is a file and a position; no source text is ever read or sent.
* Requests that failed or were slow, as method, URL, status and duration. Never a body and never a header, in either direction.
* A CSS selector for the element the reporter pointed at.
* A short breadcrumb trail: clicks, navigations and form submits.
* Optionally, what the page cost - largest contentful paint, layout shift, interaction to next paint, time to first byte, the load events, long tasks and, in Chrome, the JavaScript heap.
* Optionally, the key names in localStorage and sessionStorage with the length of each value, and the names of the cookies. Names only, never values, and never a cookie value at all. Off unless you turn it on.
* The reporter's own description, and their name and email if they gave them.
* Optionally a screenshot, only when the reporter ticks the box.

None of the context facts says more about the person than the user agent already does, and nothing beyond that list is collected: no canvas fingerprint, no font enumeration, no device enumeration.

= Screenshots and privacy =

A screenshot of your site contains whatever the reporter could see. The plugin keeps screenshots out of the media library, writes them under a random name in `wp-content/uploads/bugbottle/` with an `.htaccess` deny rule, and serves them back only through a route that requires the `manage_options` capability.

The `.htaccess` rule only works on Apache. On nginx, add the deny yourself:

`location ~* /wp-content/uploads/bugbottle/ { deny all; }`

The panel tells the reporter what the picture may contain, next to the checkbox. If you reword it, keep it saying so.

= For developers =

Two filters, `bugbottle_show_panel` and `bugbottle_panel_config`, and one action, `bugbottle_report_stored`, fired after a report is saved. The REST route is `POST /wp-json/bugbottle/v1/report`.

= Source and licence =

This plugin is developed on GitHub at https://github.com/mahope/bugbottle-wordpress and is licensed GPL-2.0-or-later. See the panel itself at https://bugbottle.dev.

It bundles the official build of the bugbottle library (https://github.com/mahope/bugbottle), unmodified, as `assets/bugbottle.js`. That file is MIT-licensed; the notice is in `assets/LICENSE-bugbottle.txt`. MIT is GPL-compatible, so the plugin as a whole is distributable under the GPL.

== Installation ==

1. In wp-admin, go to **Plugins -> Add New**, search for "Bugbottle", then install and activate.
2. Or upload the zip: **Plugins -> Add New -> Upload Plugin**, choose the file, install and activate.
3. Go to **Bug reports -> Settings** and set an email recipient, or plan to read the reports in wp-admin instead.

There is no build step and no Composer requirement. The plugin is PHP and one JavaScript file.

Updating replaces the plugin directory. Reports and settings live in the database and survive it.

== Frequently Asked Questions ==

= Does the plugin send anything to a third party? =

No. There is no outbound HTTP request anywhere in the plugin, no analytics, no telemetry, no phone-home and no account. The bundled `assets/bugbottle.js` posts reports to your own site's REST route and contacts nothing else. Reports are rows in your database; screenshots are files in your uploads directory.

= What are the three privacy rules for screenshots? =

1. **Screenshots are kept out of the media library.** They are written to `wp-content/uploads/bugbottle/` under a random name, never registered as attachments, and the directory ships with an `.htaccess` deny rule. That rule only works on Apache; on nginx, add `location ~* /wp-content/uploads/bugbottle/ { deny all; }` yourself.
2. **They are served back only through an authenticated route.** `GET /wp-json/bugbottle/v1/screenshot/<id>` requires `manage_options`. The `<id>` is the report, and the file name is looked up from that row rather than taken from the request, so an id cannot be used to walk the directory.
3. **Say so before the picture is taken.** The panel says it, in the note next to the checkbox, not in a policy nobody opens. If you reword that text, keep it saying it.

= What happens when I delete a report? =

The row is deleted. The screenshot file is left on disk on purpose: an accidental delete is recoverable, and a directory nobody can reach over HTTP is a smaller problem than an unrecoverable one. Clear the directory yourself when you mean it.

= Can visitors who are not logged in send reports? =

Not by default. Turn it on under **Bug reports -> Settings**; anonymous reports are then rate-limited to ten an hour per IP address. Requiring people to be signed in is worth considering: an anonymous screenshot is one nobody can be asked about later.

= Can I make the endpoint refuse reports that were not signed? =

Yes, but read what it is first. Put one or more keys in "Signing key(s)" under **Bug reports -> Settings** and the route then requires an `X-Bugbottle-Signature` header computed over the raw request body with one of them. **A key that is sent to the browser is public** - it is in the page source, so anyone who wants it has it. Signing raises the cost of posting junk from a script that has not read your page. It is spam deterrence beside the rate limit, it is not authentication, and it does not secure the endpoint.

One key per line is how a key is rotated: add the new one, wait for cached pages carrying the old one to expire, then remove the old one.

The bundled panel signs what it sends, so filling the setting in is all there is to it. Anything else that posts to the route - your own form, a script - has to compute the same digest or it is refused too.

= What is the "Timings and storage snapshot" setting? =

Two things a report can carry, both off by default. The timings are the ones a Web Vitals report shows: largest contentful paint, cumulative layout shift, interaction to next paint, time to first byte, the load events, long tasks and, on Chrome, the JavaScript heap. Two of them are simplifications and the library says so: the layout shift is the sum of the shifts, and the interaction figure is the worst interaction rather than a percentile.

The storage snapshot is the part to read twice. It lists the **key names** in localStorage and sessionStorage with the **length** of each value, and the **names** of the cookies. It never records a value, and never a cookie value at all. That is still not nothing - a key called `impersonating_user` is a fact about the visit - so turn it on when you are debugging state and leave it off the rest of the time.

= Can people report by shaking their phone? =

Yes, with "Shake to report" turned on: three shakes inside a second open the panel, and there is a three-second pause afterwards so one gesture opens one panel. It is off by default.

On iPhone and iPad, Safari reports no motion until the visitor has agreed to it, and it will only ask from a button the visitor pressed. This plugin never puts that prompt up for you. If you want the gesture on iOS, call `window.bugbottle.requestShakePermission()` from a button of your own; everywhere else the gesture works as soon as you tick the box.

= Where do the emails come from? =

`wp_mail`, so whatever SMTP plugin the site already has handles delivery. The body is the report rendered as Markdown, ready to paste into an issue.

= Can I use my own button instead of the floating one? =

Yes. Put a CSS selector for it in "Trigger selector" on the settings screen. Leave it empty for the floating button.

= Does it need Composer or a build step? =

No. Composer is used only to run PHPStan while developing; the shipped plugin is PHP and one JavaScript file.

== Screenshots ==

1. The report panel on the front end, open, with the console errors and the element the reporter pointed at already collected.
2. The reports list in wp-admin, filtered by status.
3. A single report: the summary, the environment, the console errors and the screenshot.
4. The settings screen: language, colour, position, branding, the keyboard shortcut, the evidence switches, the timings and storage snapshot, shake to report, signing keys and the email recipient.

== Changelog ==

= 0.4.0 =
* Updated the bundled panel to bugbottle 0.7.0.
* New "Signing key(s)" setting, and the panel now signs. With a key set, `POST /wp-json/bugbottle/v1/report` requires the library's `X-Bugbottle-Signature` header - `t=<unix ms>,v1=<hmac-sha256 over "<t>.<raw body>">` - verified over the raw request body, within five minutes of the server clock in either direction, in constant time, and refused if the same digest has already been accepted inside that window. Missing, malformed, wrong, expired and replayed all answer `401 Bad signature` alike. One key per line, so a key can be rotated. A site with no key set is unaffected.
* A key that is sent to the browser is public. Signing is spam deterrence beside the rate limit and is not authentication; the settings screen says so beside the field.
* New "Timings and storage snapshot" setting, off by default. Records what the page cost, and lists the key names in localStorage and sessionStorage with the length of each value, plus the cookie names - names only, never values, and never a cookie value at all.
* New "Shake to report" setting, off by default: shaking the phone opens the panel. On iPhone and iPad the visitor has to agree first, and only a button on your own page can ask; the plugin enables the gesture and explains it, and never puts up the prompt itself.
* The report detail screen shows the timings and the storage snapshot as tables of their own, and both are stored with the report.
* The bundled panel carries the screenshot annotator - rectangle, arrow and a blur that really destroys what it covers - wherever there is a picture to mark, and it needed nothing here.
* Danish for every new setting, label and screen.

= 0.3.0 =
* Updated the bundled panel to bugbottle 0.6.0. Reports now carry the browser language, time zone, screen size, colour scheme, online state and connection type; uncaught errors carry up to ten stack frames; and requests that failed or were slow are recorded as their own section. The PHP validator caps every one of them to the same limits the library does.
* New settings: a keyboard shortcut (`mod+shift+b`, empty for none), opening the panel by itself on an uncaught error (off), an offline queue that keeps a report the browser could not send (on), the network log (on) and breadcrumbs (on).
* The report detail screen shows the timeline and the requests as tables, next to the Markdown summary.
* Danish for every new setting and screen.

= 0.2.0 =
* Relicensed to GPL-2.0-or-later for the WordPress.org plugin directory. The bundled `assets/bugbottle.js` stays MIT; see `assets/LICENSE-bugbottle.txt`.
* The plugin directory is now `bugbottle`, matching the WordPress.org slug. If you installed 0.1.0 from a GitHub zip it landed in `bugbottle-wordpress`; delete that copy after installing this one, or your reports will be split across two entries in the plugin list.
* Passes the official Plugin Check with no errors: the inline mount script is no longer built with a heredoc, and `$_GET['paged']`, `$_GET['status']` and `$_SERVER['REMOTE_ADDR']` are unslashed before sanitising.
* Tested against WordPress 7.1.

= 0.1.0 =
* First release. REST endpoint, private `bugbottle_report` post type, admin list and detail screens, settings, email via `wp_mail`, screenshots behind an admin-only route, Danish and English.

== Upgrade Notice ==

= 0.4.0 =
Updates the bundled panel to bugbottle 0.7.0. Signing works now, so the "Signing key(s)" setting can be used in earnest; two new settings, both off by default, add the page timings with a storage snapshot and shake-to-report. Existing reports are untouched.

= 0.3.0 =
Updates the bundled panel to bugbottle 0.6.0: stack frames, a wider page context, a network log and an offline queue, with settings for each. Existing reports are untouched.

= 0.2.0 =
Relicensed to GPL-2.0-or-later and renamed to the `bugbottle` directory. If you installed 0.1.0 from a GitHub zip, delete the old `bugbottle-wordpress` copy after updating.
