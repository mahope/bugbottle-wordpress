=== Bugbottle ===
Contributors: mahope
Tags: bug reports, feedback, screenshot, support, qa
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

In-app bug reports that arrive with the evidence attached: the page, the console errors, the element, and optionally a picture.

== Description ==

Error trackers catch what throws. They cannot catch what merely looks wrong,
and they never tell you what the person was doing when it did. "The save button
does nothing" is not a report anyone can act on.

Bugbottle puts a small report panel on your site and receives what it sends.
Every report arrives with the page and the viewport, the console errors from
just before, the element the reporter pointed at, a short timeline of what they
did, and optionally a picture of what they were looking at. Reports are stored
as a private post type in wp-admin and can be emailed on.

Nothing leaves your site. There is no vendor in the middle, no dashboard to log
into and no account to create: reports are rows in your database and files in
your uploads directory.

The panel speaks Danish, English, Swedish, Norwegian, German, Dutch, French and
Spanish, and follows your site language by default. Colour, position, brand
name and logo are on the settings screen.

= Screenshots and privacy =

A screenshot of your site contains whatever the reporter could see. The plugin
keeps screenshots out of the media library, writes them under a random name in
`wp-content/uploads/bugbottle/` with an `.htaccess` deny rule, and serves them
back only through a route that requires the `manage_options` capability.

The `.htaccess` rule only works on Apache. On nginx, add the deny yourself:

`location ~* /wp-content/uploads/bugbottle/ { deny all; }`

The panel tells the reporter what the picture may contain, next to the
checkbox. If you reword it, keep it saying so.

= Source =

This plugin is developed on GitHub at
https://github.com/mahope/bugbottle-wordpress and bundles the official build of
the bugbottle library (https://github.com/mahope/bugbottle), unmodified, as
`assets/bugbottle.js`.

== Installation ==

1. Download `bugbottle-wordpress.zip` from the latest GitHub release.
2. Plugins -> Add New -> Upload Plugin, choose the zip, install and activate.
3. Bug reports -> Settings: set an email recipient, or plan to read the reports
   in wp-admin.

To update, upload the newer zip over the old one. Reports and settings live in
the database and survive the replacement.

== Frequently Asked Questions ==

= Can visitors who are not logged in send reports? =

Not by default. Turn it on under Bug reports -> Settings; anonymous reports are
then rate-limited to ten an hour per IP address. Requiring people to be signed
in is worth considering: an anonymous screenshot is one nobody can be asked
about later.

= Where do the emails come from? =

`wp_mail`, so whatever SMTP plugin the site already has handles delivery. The
body is the report rendered as Markdown, ready to paste into an issue.

= Can I use my own button instead of the floating one? =

Yes. Put a CSS selector for it in "Trigger selector".

= Does it need Composer or a build step? =

No. Composer is used only to run PHPStan while developing; the shipped zip is
PHP and one JavaScript file.

== Changelog ==

= 0.1.0 =
* First release. REST endpoint, private `bugbottle_report` post type, admin list
  and detail screens, settings, email via `wp_mail`, screenshots behind an
  admin-only route, Danish and English.
