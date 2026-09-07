# Changelog

All notable changes to this plugin are documented here and in `readme.txt`
(which is what wordpress.org shows). This file exists for anyone reading the
repository directly; keep the two in step.

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
