# bugbottle for WordPress

The [bugbottle](https://github.com/mahope/bugbottle) panel and its receiving
endpoint, in one activation. Reports land as a private post type in wp-admin —
with the page, the viewport, the recent console errors, the element the
reporter pointed at, what they did just before, and optionally a picture of
what they were looking at — and are emailed on if you give it a recipient.

Error trackers catch what throws. They cannot catch what merely looks wrong,
and they never tell you what the person was doing when it did. *"The save
button does nothing"* is not a report anyone can act on.

- **One activation.** No endpoint to write, no service to sign up for. The
  panel and the route that receives it arrive together.
- **Nothing leaves your site.** Reports are rows in your database and files in
  your uploads directory. There is no vendor in the middle.
- **Your language, your brand.** Eight locales, following the site language by
  default; colour, position, name and logo on the settings screen.
- **No build step, no Composer.** The plugin is PHP and one JavaScript file.

Requires WordPress 6.4 and PHP 8.1.

## Install

There is no wordpress.org listing. Install from the GitHub release:

1. Download `bugbottle-wordpress.zip` from the
   [latest release](https://github.com/mahope/bugbottle-wordpress/releases/latest).
2. In wp-admin, go to **Plugins → Add New → Upload Plugin**, choose the zip,
   install and activate.
3. Go to **Bug reports → Settings** and set at least an email recipient, or
   plan to read the reports in wp-admin.

To update, upload the newer zip over the old one — WordPress will ask you to
confirm the replacement — or delete the plugin and install again. Your reports
and settings survive both: they live in the database, not in the plugin
directory.

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
| Scrubbing | Redacts email addresses, bearer tokens, JWTs, card numbers, IBANs and query values before the report is sent. On by default. |
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

## Please read this part

A screenshot of your site contains whatever the reporter could see. In a
clinical system that can mean a patient photograph; in a payroll tool, a
salary; in yours, perhaps somebody's inbox or a half-written message they had
not sent yet.

Three things follow. The plugin does the first two for you; the third it
cannot:

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
3. **Say so before the picture is taken.** The panel says it, in the note next
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
[bugbottle](https://github.com/mahope/bugbottle) package — **bugbottle 0.4.0**
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

## Development

```bash
composer install                 # dev only: PHPStan and the WordPress stubs
vendor/bin/phpstan analyse       # level 5, clean
wp i18n make-pot . languages/bugbottle.pot --domain=bugbottle --exclude=assets,vendor
wp i18n make-mo languages/
```

`vendor/` is not committed and never ships in the zip. Tagging `vX.Y.Z` builds
`bugbottle-wordpress.zip` and attaches it to a GitHub release.

## Licence

MIT.
