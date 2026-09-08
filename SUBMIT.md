# Submitting Bugbottle to the WordPress.org plugin directory

Everything in the repository is ready. This is what is left for Mads to do
by hand, because the submission itself has to come from his wordpress.org
account.

## 1. Have a wordpress.org account

If you do not already have one: create it at https://wordpress.org/ (top
right, "Register"). The account you submit under becomes the plugin's
`Contributors:` entry — `readme.txt` already lists `mahope`, so register (or
log in) under that username, or update `Contributors:` in `readme.txt` to
match whichever username you use.

## 2. Build the release zip

From the repository root, on the tagged commit:

```bash
bin/build-zip.sh
```

This writes `bugbottle.zip` at the repository root — the same zip GitHub
attaches to the release (`.github/workflows/release.yml` runs the same
script). Do not upload a zip built from an untagged working tree; the
`Stable tag` in `readme.txt` and the `Version` in the plugin header must both
read the version you are submitting.

## 3. Submit for review

1. Go to https://wordpress.org/plugins/developers/add/.
2. Upload `bugbottle.zip`.
3. Fill in the form — the plugin name, description, etc. are read from
   `readme.txt`, so there is nothing to retype.
4. Submit.

## 4. What to expect from review

- The queue is usually a few days to a few weeks. There is no way to expedite
  it.
- A first submission almost always gets at least one round of review
  feedback by email, even for a plugin that already passes Plugin Check
  cleanly — reviewers look at things the automated tool cannot (real
  external calls, licensing edge cases, trademark in the name). Reply to
  their email; do not resubmit a new zip through the form.
- If it is rejected outright, the email says why. Fix it in this repository,
  re-tag, re-run `bin/build-zip.sh`, and reply to the same email thread with
  the new zip rather than starting a new submission, unless the reviewer
  says otherwise.
- Once approved, you get commit access to an SVN repository at
  `https://plugins.svn.wordpress.org/bugbottle/` and the plugin appears at
  `https://wordpress.org/plugins/bugbottle/` once something has been
  committed to `trunk`.

## 5. How the SVN repository works

wordpress.org does not read the GitHub repository or its releases directly.
Approval only creates the SVN repository; nothing is live until you (or CI)
commit to it. The layout SVN expects:

```
bugbottle/
├── trunk/          the current development version — this is what
│                    "Install now" on wordpress.org fetches for people who
│                    are not on a specific tagged version
├── tags/
│   ├── 0.2.0/       an exact snapshot of trunk at the moment 0.2.0 shipped
│   └── ...
└── assets/          banner, icon, screenshots — never installed on a site,
                     shown only on the plugin's wordpress.org page
```

`Stable tag: 0.2.0` in `readme.txt` (which lives in both `trunk/` and
`tags/0.2.0/`) is what tells wordpress.org which tag is the one people
actually get; it does not have to be `trunk`.

GitHub stays the canonical source. SVN is a deploy target, not somewhere to
develop — do not hand-edit files inside an SVN checkout.

## 6. Release checklist (every version after 0.2.0)

1. Bump `Version:` in `bugbottle.php`, `const VERSION` in `bugbottle.php`,
   `Stable tag:` in `readme.txt`, and add a changelog entry to both
   `readme.txt` (`== Changelog ==`) and `CHANGELOG.md`.
2. If the bundled library moved, replace **both** bundles from the same
   release: copy `dist/bugbottle.js` over `assets/bugbottle.js`, run
   `bin/build-screenshot-bundle.sh` with `LIB_VERSION` in it bumped to match,
   and put both md5s in `CHANGELOG.md`. They are enqueued with one version
   string, so they cannot come from different releases.
3. `composer install && vendor/bin/phpstan analyse` — clean, and the five test
   files in `tests/` green.
4. Re-sync a local WordPress install and re-run Plugin Check
   (`wp plugin install plugin-check --activate` once, then
   `wp plugin check bugbottle --format=table` every time) — no errors.
5. Commit, then tag and push:
   ```bash
   git tag vX.Y.Z
   git push origin main --tags
   ```
   Pushing the tag triggers `.github/workflows/release.yml`: it builds
   `bugbottle.zip` with `bin/build-zip.sh` and attaches it to a GitHub
   release.
6. Ship the same version to wordpress.org, either:
   - **Automatically** — once `SVN_USERNAME` and `SVN_PASSWORD` exist as
     repository secrets (Settings → Secrets and variables → Actions), the
     same tag push also runs `.github/workflows/deploy-wporg.yml`, which
     commits `trunk/`, a new `tags/X.Y.Z/`, and `assets/` (from
     `.wordpress-org/`) to SVN. Until those secrets exist, that workflow's
     `check-secrets` job finds them missing and skips the deploy — nothing
     fails, it is just a no-op.
   - **By hand** — `bin/deploy-wporg.sh X.Y.Z`, with `SVN_USERNAME` and
     `SVN_PASSWORD` set as environment variables. Needs `svn` on `PATH`.
7. Check `https://wordpress.org/plugins/bugbottle/` shows the new version
   (this can take a few minutes to a couple of hours to refresh) and that
   updating a real site's existing install offers it.

## Getting the SVN credentials into GitHub Actions

Your wordpress.org account password works for `svn` (there is no separate
SVN password), but using it directly in a repository secret means a leaked
secret is a leaked wordpress.org account. If wordpress.org offers an SVN
access token for your account by the time you set this up, prefer that.
Either way: repository → Settings → Secrets and variables → Actions → "New
repository secret" → `SVN_USERNAME` and `SVN_PASSWORD`.
