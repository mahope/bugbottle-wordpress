/**
 * The round trip the offline queue exists for, on a site that signs.
 *
 * A report written while the endpoint was down is kept in `localStorage` and
 * delivered on a later page load — by the queue's own `fetch`, not by the
 * panel. Until 0.6.1 the mount script handed the queue only `X-WP-Nonce`, so
 * on a site with signing keys that later delivery arrived without
 * `X-Bugbottle-Signature` and `Signature::check()` refused it with 401:
 * exactly the reports the queue exists to save were the ones it lost.
 *
 * Nothing in `node:test` or in PHPUnit can see that. It only happens in a
 * browser, across two page loads, through the bundled library's own queue, so
 * this is a real Chrome against a real WordPress:
 *
 *   1. Configure a scratch install: panel on, anonymous allowed, queue on, one
 *      signing key.
 *   2. Serve it with `php -S`, ours and nobody else's, killed on the way out.
 *   3. Open the page, take the endpoint down by aborting every request to it,
 *      write a report and send it. It should be queued rather than lost.
 *   4. Put the endpoint back and reload. The queue flushes as it is created.
 *   5. The delivered request must carry a signature this script verifies
 *      itself — HMAC-SHA-256 over `<t>.<body>` against the raw bytes that went
 *      on the wire, with `t` inside the five-minute skew window — and the
 *      route must answer 201.
 *
 * Usage:
 *
 *   node tests/browser-queue-signature.mjs --wp-path "/path/to/scratch/wp"
 *
 * Options: `--wp` the wp-cli command (default `wp`), `--chrome` the browser
 * binary (default the Windows Chrome path, or `$CHROME`), `--port` the port
 * `php -S` listens on (default 8761), `--keep` to leave the browser open.
 *
 * It writes settings and stores a report, so point it at a scratch install and
 * never at a live site. Exits non-zero on a failure.
 *
 * Needs `puppeteer-core` (the global install is found automatically), a Chrome,
 * `php` and a working `wp` on PATH.
 */

import { createHmac } from "node:crypto";
import { spawn, spawnSync } from "node:child_process";
import { createRequire } from "node:module";
import path from "node:path";
import process from "node:process";

const require = createRequire(import.meta.url);

/** The one key the site is configured with and the one this script verifies against. */
const SIGN_KEY = "harness-key-3f9c1a";
/** `Signature::SKEW_MS`. A signature computed at enqueue would be outside it. */
const SKEW_MS = 5 * 60 * 1000;
const MESSAGE = "The save button did nothing while the network was down.";

function arg(name, fallback) {
  const at = process.argv.indexOf(`--${name}`);
  return at === -1 ? fallback : process.argv[at + 1];
}
const flag = (name) => process.argv.includes(`--${name}`);

const wpPath = arg("wp-path", "");
const wpCommand = arg("wp", "wp");
const port = Number(arg("port", "8761"));
const chromePath =
  arg("chrome", "") ||
  process.env.CHROME ||
  "C:/Program Files/Google/Chrome/Application/chrome.exe";

if (!wpPath) {
  console.error("--wp-path is required: the root of a scratch WordPress install.");
  process.exit(2);
}

let failures = 0;
let total = 0;

function check(name, expected, actual) {
  total += 1;
  if (expected === actual) {
    console.log(`pass  ${name}`);
    return;
  }
  failures += 1;
  console.log(`FAIL  ${name}`);
  console.log(`        expected: ${JSON.stringify(expected)}`);
  console.log(`        actual:   ${JSON.stringify(actual)}`);
}

function ok(name, condition, detail = "") {
  check(name, true, Boolean(condition));
  if (!condition && detail) console.log(`        ${detail}`);
}

/**
 * wp-cli, split on spaces so `--wp "php -d memory_limit=1G wp-cli.phar"` works.
 * A trailing `{ stdin }` is fed to the command rather than passed as an
 * argument, which is how the settings JSON gets through without a shell
 * rewriting its quotes. A non-zero exit is fatal: every one of these is a
 * precondition.
 *
 * Only a `.bat` or `.cmd` needs a shell; anything else is spawned directly, so
 * Node quotes the arguments rather than cmd.exe.
 */
function wp(...args) {
  const last = args[args.length - 1];
  const stdin = typeof last === "object" && last !== null ? args.pop().stdin : undefined;
  const parts = wpCommand.split(" ").filter(Boolean);
  const run = spawnSync(parts[0], [...parts.slice(1), ...args, `--path=${wpPath}`], {
    encoding: "utf8",
    input: stdin,
    shell: /\.(bat|cmd)$/i.test(parts[0]),
  });
  if (run.status !== 0) {
    console.error(`wp ${args.join(" ")} failed:\n${run.stdout}${run.stderr}`);
    process.exit(1);
  }
  return run.stdout.trim();
}

/**
 * `puppeteer-core` from wherever it is. This repository has no `package.json`
 * and installs nothing, so the ordinary case is a global install, which is not
 * on the module path unless `NODE_PATH` says so. Ask npm where that is.
 */
async function loadPuppeteer() {
  try {
    return require("puppeteer-core");
  } catch {
    // One string rather than a command and an argument list: npm is a `.cmd` on
    // Windows and needs the shell, and a shell with a separate argument list is
    // what Node deprecated.
    const npm = spawnSync("npm root -g", { encoding: "utf8", shell: true });
    const root = (npm.stdout ?? "").trim();
    if (root === "") throw new Error("puppeteer-core is not installed (npm i -g puppeteer-core)");
    // Resolving from inside the global tree lets the package's own `exports`
    // pick the entry point, rather than this script guessing at a file path.
    return createRequire(path.join(root, "resolve-from-here.js"))("puppeteer-core");
  }
}

/** `Signature::digest()`, in the language this script happens to be written in. */
function digest(key, t, body) {
  return createHmac("sha256", key).update(`${t}.${body}`).digest("hex");
}

const settings = {
  enabled: true,
  logged_in_only: false,
  allow_anonymous: true,
  queue: true,
  screenshot: false,
  scrub: false,
  network_log: false,
  breadcrumbs: false,
  contact: "off",
  signing_keys: SIGN_KEY,
};

console.log("configuring the scratch install…");
// The value goes in on stdin, which wp-cli reads when none is given.
wp("option", "update", "bugbottle_settings", "--format=json", { stdin: JSON.stringify(settings) });
wp("plugin", "activate", "bugbottle");
// A report left over from an earlier run would make "one report arrived"
// meaningless, and a claimed signature would be refused as a replay.
const leftovers = wp(
  "post",
  "list",
  "--post_type=bugbottle_report",
  "--post_status=any",
  "--format=ids",
);
if (leftovers !== "") wp("post", "delete", ...leftovers.split(/\s+/), "--force");
wp("transient", "delete", "--all", "--quiet");

const base = `http://127.0.0.1:${port}`;
// `rest_url()` is built from `home_url()`, and a report posted to a host the
// page was not served from is a cross-origin request the browser blocks before
// the queue ever gets to sign it. So the scratch install is told where it is.
wp("option", "update", "home", base);
wp("option", "update", "siteurl", base);

// Default permalinks, so the route is `?rest_route=`, whose slashes the browser
// may or may not have encoded by the time the request is seen here. `php -S`
// serves the static files itself and needs no router.
const REPORT_ROUTE = /bugbottle(%2F|\/)v1(%2F|\/)report/;

console.log(`starting php -S 127.0.0.1:${port}…`);
const server = spawn("php", ["-S", `127.0.0.1:${port}`, "-t", path.resolve(wpPath)], {
  stdio: ["ignore", "ignore", "ignore"],
});
let browser = null;

/** Only the two processes this script started, and never by name. */
async function stop() {
  if (browser && !flag("keep")) await browser.close().catch(() => {});
  if (server.pid && server.exitCode === null) server.kill();
}
process.on("exit", () => {
  if (server.pid && server.exitCode === null) server.kill();
});

async function waitFor(predicate, timeoutMs, what) {
  const until = Date.now() + timeoutMs;
  for (;;) {
    if (await predicate()) return true;
    if (Date.now() > until) throw new Error(`timed out waiting for ${what}`);
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
}

try {
  await waitFor(
    async () => {
      try {
        const response = await fetch(base, { redirect: "manual" });
        return response.status > 0;
      } catch {
        return false;
      }
    },
    20_000,
    "php -S to answer",
  );

  const puppeteer = await loadPuppeteer();
  browser = await puppeteer.launch({
    executablePath: chromePath,
    headless: true,
    args: ["--no-sandbox", "--disable-dev-shm-usage"],
  });
  const page = await browser.newPage();

  let endpointDown = true;
  /** Every POST that reached the server, with the bytes and headers it carried. */
  const delivered = [];
  let aborted = 0;

  await page.setRequestInterception(true);
  page.on("request", (request) => {
    if (REPORT_ROUTE.test(request.url()) && request.method() === "POST") {
      if (endpointDown) {
        aborted += 1;
        void request.abort("failed");
        return;
      }
      delivered.push({ headers: request.headers(), body: request.postData() ?? "" });
    }
    void request.continue();
  });
  page.on("response", (response) => {
    if (REPORT_ROUTE.test(response.url()) && response.request().method() === "POST") {
      const last = delivered[delivered.length - 1];
      if (last && last.status === undefined) last.status = response.status();
    }
  });

  console.log("\n— the outage —");
  await page.goto(base, { waitUntil: "domcontentloaded" });
  await page.waitForSelector('div[data-bugbottle="ui"]', { timeout: 20_000 });
  ok("the panel mounts", true);

  // The panel lives in a shadow root, so every reach into it starts at the host.
  await page.evaluate(() => {
    document
      .querySelector('div[data-bugbottle="ui"]')
      .shadowRoot.querySelector(".trigger")
      .click();
  });
  await waitFor(
    () =>
      page.evaluate(
        () =>
          !document.querySelector('div[data-bugbottle="ui"]').shadowRoot.querySelector(".panel")
            .hidden,
      ),
    5_000,
    "the panel to open",
  );

  await page.evaluate((message) => {
    const root = document.querySelector('div[data-bugbottle="ui"]').shadowRoot;
    const textarea = root.querySelector("#bb-message");
    textarea.value = message;
    textarea.dispatchEvent(new Event("input", { bubbles: true }));
    root.querySelector(".form .send").click();
  }, MESSAGE);

  await waitFor(() => Promise.resolve(aborted > 0), 10_000, "the send to fail");
  ok("the endpoint being down aborts the send", aborted > 0, `aborted ${aborted}`);

  const queuedRaw = await page.evaluate(() => window.localStorage.getItem("bugbottle:queue"));
  const queued = JSON.parse(queuedRaw ?? "null");
  check("the report is kept rather than lost", true, Array.isArray(queued) && queued.length === 1);
  check("it is the report that was written", MESSAGE, queued?.[0]?.body?.message);
  check("nothing was delivered during the outage", 0, delivered.length);

  console.log("\n— the endpoint comes back —");
  endpointDown = false;
  const reloadedAt = Date.now();
  await page.reload({ waitUntil: "domcontentloaded" });

  await waitFor(
    () => Promise.resolve(delivered.length > 0 && delivered[0].status !== undefined),
    20_000,
    "the queue to deliver",
  );
  check("one report is delivered", 1, delivered.length);

  const sent = delivered[0];
  const header = sent.headers["x-bugbottle-signature"] ?? "";
  ok("the delivery carries X-Bugbottle-Signature", header !== "", "the header was absent");
  ok(
    "the nonce travels with it too",
    (sent.headers["x-wp-nonce"] ?? "") !== "",
    "X-WP-Nonce was absent",
  );

  const parts = /^t=([0-9]{1,20}),v1=([0-9a-f]{64})$/.exec(header);
  ok("the header has the shape the route parses", parts !== null, `header was ${JSON.stringify(header)}`);
  if (parts) {
    const t = Number(parts[1]);
    check("the digest is over the bytes that went on the wire", digest(SIGN_KEY, parts[1], sent.body), parts[2]);
    // The point of the whole fix: the timestamp is from delivery, not from
    // when the report was written, so it is inside the skew window.
    ok(
      "the timestamp is fresh, not the one the report was queued with",
      Math.abs(Date.now() - t) <= SKEW_MS && t >= reloadedAt - 1000,
      `t=${t}, reloaded at ${reloadedAt}, now ${Date.now()}`,
    );
  }

  check("the route accepts it", 201, sent.status);

  await waitFor(
    async () => {
      const left = await page.evaluate(() => window.localStorage.getItem("bugbottle:queue"));
      return left === null || JSON.parse(left).length === 0;
    },
    10_000,
    "the queue to empty",
  );
  ok("the queue is empty afterwards", true);

  const stored = wp(
    "post",
    "list",
    "--post_type=bugbottle_report",
    "--post_status=any",
    "--format=count",
  );
  check("the site stored exactly one report", "1", stored);
} catch (error) {
  failures += 1;
  total += 1;
  console.log(`FAIL  ${error instanceof Error ? error.message : String(error)}`);
} finally {
  await stop();
}

console.log(`\n${total} checks, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
