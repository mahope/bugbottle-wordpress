<?php
/**
 * Parity with the library: the same report, validated and rendered by the PHP
 * port, must come out byte for byte the way `bugbottle` itself renders it.
 *
 * The fixture below carries every section a report can have, including the
 * `perf` and `storage` blocks bugbottle 0.7.0 added, the `contact` line
 * 0.8.0 added and the `notes` line 0.13.0 added, and several entries that are meant to be dropped or clipped — a
 * stack frame past the third, a `length` that is not a number, a cookie name
 * that is not a string, a negative duration. The expected Markdown and the
 * expected validated JSON are not written by hand: they came out of
 * `src/markdown.ts` and `src/report-core.ts` of bugbottle v0.13.0, run over this
 * same fixture with `node --experimental-strip-types`, and `diff -u` against
 * the PHP output was empty. Regenerate them the same way whenever either side
 * moves.
 *
 * One field is deliberately absent from the fixture: `replay`. The plugin
 * drops a session replay at the door rather than storing a megabyte of nested
 * JSON in post meta, so the library would render a `Replay` row here that the
 * PHP port never can. That divergence is pinned by the last three checks in
 * this file rather than left to be rediscovered.
 *
 * The JSON is compared with the flags JavaScript's `JSON.stringify` uses —
 * slashes unescaped, UTF-8 left alone — because what is under test is the
 * validators and not the two encoders' default taste in escaping.
 *
 * Usage: php tests/test-report-parity.php
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../includes/class-validator.php';
require_once __DIR__ . '/../includes/class-markdown.php';

use Bugbottle\Markdown;
use Bugbottle\Validator;

$failures = 0;
$total    = 0;

/**
 * @param mixed $expected Expected value.
 * @param mixed $actual   Actual value.
 */
function check( string $name, $expected, $actual ): void {
	global $failures, $total;
	++$total;
	if ( $expected === $actual ) {
		echo "pass  $name\n";
		return;
	}
	++$failures;
	echo "FAIL  $name\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * `json_encode` without the escaping JavaScript does not do.
 *
 * @param array<string, mixed> $value The validated sections.
 */
function encode_like_js( array $value ): string {
	return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/** The report every check below is about. */
const FIXTURE_JSON = <<<'JSON'
{
  "type": "bug",
  "message": "The save button does nothing\nI clicked it three times.",
  "contact": "  anna@example.test  ",
  "context": {
    "url": "/checkout/step-2?coupon=SPRING",
    "viewport": "1280x720",
    "userAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36",
    "language": "da-DK",
    "timezone": "Europe/Copenhagen",
    "screen": "2560x1440@2x",
    "colorScheme": "dark",
    "online": false,
    "connection": "4g"
  },
  "console": [
    { "ts": "2026-09-08T09:12:00.000Z", "level": "warn", "message": "Deprecated API in use" },
    {
      "ts": "2026-09-08T09:12:03.500Z",
      "level": "error",
      "message": "TypeError: cannot read properties of undefined (reading 'total')",
      "stack": [
        { "file": "https://example.test/app.js", "line": 42, "col": 9, "fn": "submitOrder" },
        { "file": "https://example.test/app.js", "line": 118, "col": 3 },
        { "file": "https://example.test/vendor.js", "line": 9001, "col": 17, "fn": "dispatch" },
        { "file": "https://example.test/vendor.js", "line": 9100, "col": 1, "fn": "notPrinted" }
      ]
    }
  ],
  "elements": [
    {
      "selector": "#checkout > button.primary",
      "tag": "button",
      "text": "Gem ordre",
      "rect": { "x": 220, "y": 640, "width": 160, "height": 44 },
      "attributes": { "id": "save", "class": "primary", "data-testid": "save-order" }
    }
  ],
  "breadcrumbs": [
    { "ts": "2026-09-08T09:11:40.000Z", "kind": "navigation", "from": "/checkout/step-1", "to": "/checkout/step-2" },
    { "ts": "2026-09-08T09:11:55.000Z", "kind": "click", "target": "#checkout > button.primary", "text": "Gem ordre" },
    { "ts": "2026-09-08T09:12:01.000Z", "kind": "submit", "target": "form#checkout" },
    { "ts": "2026-09-08T09:12:02.000Z", "kind": "visibility", "to": "hidden" }
  ],
  "network": [
    { "ts": "2026-09-08T09:12:02.100Z", "method": "POST", "url": "/api/orders", "status": 500, "ms": 1240 },
    { "ts": "2026-09-08T09:12:03.000Z", "method": "GET", "url": "/api/orders/42", "status": 0, "ms": 30000, "error": true }
  ],
  "perf": {
    "lcp": 2431.6,
    "cls": 0.12349,
    "inp": 312.4,
    "ttfb": 180,
    "domContentLoaded": 940.2,
    "load": 1811.9,
    "longTasks": { "count": 7, "totalMs": 613.7 },
    "memory": { "usedMB": 84.6, "limitMB": 4096 },
    "nonsense": "dropped",
    "negative": -5
  },
  "storage": {
    "local": [
      { "key": "cart", "length": 1842 },
      { "key": "impersonating_user", "length": 6 },
      { "key": "no-length" },
      { "notakey": 1 }
    ],
    "session": [{ "key": "step", "length": 1 }],
    "cookies": ["wordpress_logged_in_abc", "woocommerce_cart_hash", 7],
    "values": { "step": "2", "cart": "dropped-not-allowlisted-server-side-is-fine" }
  },
  "notes": [
    "Screenshot dropped: it did not fit in the offline queue.",
    "   ",
    7,
    "  Queued while offline; delivered on the next visit.  "
  ],
  "screenshotDataUrl": "data:image/png;base64,iVBORw0KGgo="
}
JSON;

/** `toMarkdown( fixture )`, from bugbottle v0.13.0. */
const EXPECTED_MARKDOWN = <<<'MARKDOWN'
## Bug: The save button does nothing

The save button does nothing
I clicked it three times.

| | |
|---|---|
| Type | Bug |
| Contact | anna@example.test |
| Page | `/checkout/step-2?coupon=SPRING` |
| Viewport | 1280x720 |
| Screen | 2560x1440@2x |
| Browser | Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 |
| Language | da-DK |
| Time zone | Europe/Copenhagen |
| Colour scheme | dark |
| Online | no |
| Connection | 4g |
| Last console entry | 2026-09-08T09:12:03.500Z |
| Screenshot | attached |

> Screenshot dropped: it did not fit in the offline queue.
> Queued while offline; delivered on the next visit.

### Element pointed at

- `#checkout > button.primary` — "Gem ordre" (class="primary" data-testid="save-order") at 220,640 160×44

### What happened before

- 2026-09-08T09:11:40.000Z navigated `/checkout/step-1` → `/checkout/step-2`
- 2026-09-08T09:11:55.000Z clicked `#checkout > button.primary` — "Gem ordre"
- 2026-09-08T09:12:01.000Z submitted `form#checkout`
- 2026-09-08T09:12:02.000Z page hidden

### Requests

| Method | URL | Status | ms |
|---|---|---|---|
| POST | `/api/orders` | 500 | 1240 |
| GET | `/api/orders/42` | failed | 30000 |

### Performance

| | |
|---|---|
| Largest contentful paint | 2432 ms |
| Cumulative layout shift | 0.123 |
| Interaction to next paint | 312 ms |
| Time to first byte | 180 ms |
| DOM content loaded | 940 ms |
| Load | 1812 ms |
| Long tasks | 7 (614 ms total) |
| JS heap | 85 MB of 4096 MB |

<details><summary>Storage</summary>

- localStorage: `cart` (1842), `impersonating_user` (6), `no-length` (0)
- sessionStorage: `step` (1)
- Cookies: `wordpress_logged_in_abc`, `woocommerce_cart_hash`
- `step` = 2
- `cart` = dropped-not-allowlisted-server-side-is-fine

</details>

<details><summary>Console (2 entries)</summary>

```text
2026-09-08T09:12:00.000Z [warn] Deprecated API in use
2026-09-08T09:12:03.500Z [error] TypeError: cannot read properties of undefined (reading 'total')
    at submitOrder https://example.test/app.js:42:9
    at https://example.test/app.js:118:3
    at dispatch https://example.test/vendor.js:9001:17
```

</details>
MARKDOWN;

/** The validated sections, as `JSON.stringify` writes them. */
const EXPECTED_JSON = <<<'JSON'
{"contact":"anna@example.test","context":{"url":"/checkout/step-2?coupon=SPRING","viewport":"1280x720","userAgent":"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36","language":"da-DK","timezone":"Europe/Copenhagen","screen":"2560x1440@2x","colorScheme":"dark","online":false,"connection":"4g"},"console":[{"ts":"2026-09-08T09:12:00.000Z","level":"warn","message":"Deprecated API in use"},{"ts":"2026-09-08T09:12:03.500Z","level":"error","message":"TypeError: cannot read properties of undefined (reading 'total')","stack":[{"file":"https://example.test/app.js","line":42,"col":9,"fn":"submitOrder"},{"file":"https://example.test/app.js","line":118,"col":3},{"file":"https://example.test/vendor.js","line":9001,"col":17,"fn":"dispatch"},{"file":"https://example.test/vendor.js","line":9100,"col":1,"fn":"notPrinted"}]}],"elements":[{"selector":"#checkout > button.primary","tag":"button","text":"Gem ordre","rect":{"x":220,"y":640,"width":160,"height":44},"attributes":{"id":"save","class":"primary","data-testid":"save-order"}}],"breadcrumbs":[{"ts":"2026-09-08T09:11:40.000Z","kind":"navigation","from":"/checkout/step-1","to":"/checkout/step-2"},{"ts":"2026-09-08T09:11:55.000Z","kind":"click","target":"#checkout > button.primary","text":"Gem ordre"},{"ts":"2026-09-08T09:12:01.000Z","kind":"submit","target":"form#checkout"},{"ts":"2026-09-08T09:12:02.000Z","kind":"visibility","to":"hidden"}],"network":[{"ts":"2026-09-08T09:12:02.100Z","method":"POST","url":"/api/orders","status":500,"ms":1240},{"ts":"2026-09-08T09:12:03.000Z","method":"GET","url":"/api/orders/42","status":0,"ms":30000,"error":true}],"perf":{"lcp":2432,"inp":312,"ttfb":180,"domContentLoaded":940,"load":1812,"cls":0.123,"longTasks":{"count":7,"totalMs":614},"memory":{"usedMB":85,"limitMB":4096}},"storage":{"local":[{"key":"cart","length":1842},{"key":"impersonating_user","length":6},{"key":"no-length","length":0}],"session":[{"key":"step","length":1}],"cookies":["wordpress_logged_in_abc","woocommerce_cart_hash"],"values":{"step":"2","cart":"dropped-not-allowlisted-server-side-is-fine"}},"notes":["Screenshot dropped: it did not fit in the offline queue.","Queued while offline; delivered on the next visit."]}
JSON;

$raw = json_decode( FIXTURE_JSON, true );
check( 'the fixture parses', true, is_array( $raw ) );

// A heredoc has no trailing newline; `render()` always ends with one.
check(
	'the Markdown is the one the library renders, byte for byte',
	EXPECTED_MARKDOWN . "\n",
	Markdown::render( $raw )
);

check(
	'the validated JSON is the one the library produces, byte for byte',
	EXPECTED_JSON,
	encode_like_js(
		array(
			'contact'     => Validator::contact( $raw['contact'] ?? null ),
			'context'     => Validator::context( $raw['context'] ?? null ),
			'console'     => Validator::console( $raw['console'] ?? null ),
			'elements'    => Validator::elements( $raw['elements'] ?? null ),
			'breadcrumbs' => Validator::breadcrumbs( $raw['breadcrumbs'] ?? null ),
			'network'     => Validator::network( $raw['network'] ?? null ),
			'perf'        => Validator::perf( $raw['perf'] ?? null ),
			'storage'     => Validator::storage( $raw['storage'] ?? null ),
			'notes'       => Validator::notes( $raw['notes'] ?? null ),
		)
	)
);

// The two blocks 0.7.0 added, checked rule by rule as well: a diff of one long
// string says "they differ" and never which rule moved.
$perf = Validator::perf( $raw['perf'] ?? null );
check( 'a millisecond figure is rounded to a whole one', 2432, $perf['lcp'] ?? null );
check( 'layout shift keeps three decimals', 0.123, $perf['cls'] ?? null );
check( 'a field that is not a number is left out', false, array_key_exists( 'nonsense', (array) $perf ) );
check( 'a negative duration is left out', false, array_key_exists( 'negative', (array) $perf ) );
check(
	'long tasks survive as a count and a total',
	array(
		'count'   => 7,
		'totalMs' => 614,
	),
	$perf['longTasks'] ?? null
);
check( 'a snapshot with nothing usable in it is null', null, Validator::perf( array( 'lcp' => 'soon' ) ) );
check( 'a missing snapshot is null', null, Validator::perf( null ) );
check( 'an array is not a snapshot', null, Validator::perf( array( 1, 2 ) ) );
check( 'a figure past the ceiling is clipped', 3600000, Validator::perf( array( 'load' => 99999999 ) )['load'] );

$storage = Validator::storage( $raw['storage'] ?? null );
check( 'a key whose length is missing becomes zero', 0, $storage['local'][2]['length'] ?? null );
check( 'an entry without a string key is dropped', 3, count( $storage['local'] ?? array() ) );
check( 'a cookie name that is not a string is dropped', 2, count( $storage['cookies'] ?? array() ) );
check( 'an empty snapshot is null', null, Validator::storage( array( 'local' => array() ) ) );
check(
	'a cookie name is clipped',
	100,
	mb_strlen( Validator::storage( array( 'cookies' => array( str_repeat( 'k', 300 ) ) ) )['cookies'][0] )
);
check(
	'an allow-listed value is clipped hard',
	200,
	mb_strlen( Validator::storage( array( 'values' => array( 'k' => str_repeat( 'v', 900 ) ) ) )['values']['k'] )
);
check(
	'a null byte never survives a key',
	'ab',
	Validator::storage( array( 'cookies' => array( 'a' . chr( 0 ) . 'b' ) ) )['cookies'][0]
);
check( 'a cookie value is never accepted, because none is ever sent', null, Validator::storage( array( 'cookies' => array( array( 'name' => 'a', 'value' => 'b' ) ) ) ) );

// The contact line, rule by rule. It is treated exactly as the message is —
// trimmed, null bytes gone, clipped at 200 — and nothing checks its format,
// because "ring me on 12345678" is a good answer to "how do we reach you".
check( 'the contact line is trimmed', 'anna@example.test', Validator::contact( '  anna@example.test  ' ) );
check( 'an empty contact line is null', null, Validator::contact( '   ' ) );
check( 'a contact line that is not a string is null', null, Validator::contact( array( 'a' ) ) );
check( 'a missing contact line is null', null, Validator::contact( null ) );
check( 'a contact line is clipped at 200 characters', 200, mb_strlen( (string) Validator::contact( str_repeat( 'k', 400 ) ) ) );
check( 'a null byte never survives a contact line', 'ab', Validator::contact( 'a' . chr( 0 ) . 'b' ) );
check( 'a phone number is a perfectly good contact line', 'ring me on 12345678', Validator::contact( 'ring me on 12345678' ) );
check(
	'no Contact row is rendered when there is no contact line',
	false,
	str_contains( Markdown::render( array( 'type' => 'bug', 'message' => 'x' ) ), '| Contact |' )
);

// `looks_like_email` is the permissive port of the library's `looksLikeEmail`,
// and it is the only thing that decides whether the notification email gets a
// Reply-To. It has to agree with the library rather than with `is_email()`.
check( 'a plain address looks like an email', true, Validator::looks_like_email( 'anna@example.test' ) );
check( 'surrounding whitespace does not stop it', true, Validator::looks_like_email( '  anna@example.test  ' ) );
check( 'a subdomain address looks like an email', true, Validator::looks_like_email( 'a@b.co.uk' ) );
check( 'a phone number does not look like an email', false, Validator::looks_like_email( 'ring me on 12345678' ) );
check( 'a name beside an address does not look like an email', false, Validator::looks_like_email( 'Anna <anna@example.test>' ) );
check( 'a bare hostname does not look like an email', false, Validator::looks_like_email( 'anna@localhost' ) );
check( 'two addresses do not look like an email', false, Validator::looks_like_email( 'a@b.dk,c@d.dk' ) );
check( 'something that is not a string never looks like an email', false, Validator::looks_like_email( null ) );

// The library's own notes about the report, rule by rule. They are validated
// exactly as a message is — trimmed, null bytes gone — but clipped at 200 and
// capped at five, and anything that is not a non-empty string is dropped. A
// note arrives from a browser like every other field, so nothing here trusts it
// for being ours.
check( 'a note is trimmed', array( 'kept' ), Validator::notes( array( '  kept  ' ) ) );
check( 'an empty note is dropped', array(), Validator::notes( array( '   ' ) ) );
check( 'a note that is not a string is dropped', array(), Validator::notes( array( 7, null, array( 'a' ) ) ) );
check( 'notes that are not a list are no notes', array(), Validator::notes( array( 'a' => 'b' ) ) );
check( 'a missing notes field is no notes', array(), Validator::notes( null ) );
check( 'a note is clipped at 200 characters', 200, mb_strlen( Validator::notes( array( str_repeat( 'k', 400 ) ) )[0] ) );
check( 'a null byte never survives a note', 'ab', Validator::notes( array( 'a' . chr( 0 ) . 'b' ) )[0] );
check( 'at most five notes survive', 5, count( Validator::notes( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' ) ) ) );
check(
	'the notes are the first five, not the last',
	array( 'a', 'b', 'c', 'd', 'e' ),
	Validator::notes( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' ) )
);
check(
	'a dropped note does not use up one of the five',
	5,
	count( Validator::notes( array( 'a', '  ', 'b', 7, 'c', null, 'd', 'e', 'f' ) ) )
);
// The whole rendering of a minimal report carrying one note, so the position
// of the quote is pinned and not only its presence: it sits under the facts
// table and above where the evidence would be.
check(
	'a note is rendered as a quote under the facts table',
	"## Bug: x

x

| | |
|---|---|
| Type | Bug |

> the picture would not fit
",
	Markdown::render( array( 'type' => 'bug', 'message' => 'x', 'notes' => array( 'the picture would not fit' ) ) )
);
check(
	'no quote is rendered when there are no notes',
	false,
	str_contains( Markdown::render( array( 'type' => 'bug', 'message' => 'x' ) ), '> ' )
);

// The one place the PHP port deliberately parts company with the library: a
// session replay is dropped rather than stored, so no `Replay` row is ever
// rendered. The library prints one for exactly this report.
$with_replay = array(
	'type'    => 'bug',
	'message' => 'x',
	'replay'  => array(
		'events'  => array(
			array(
				'type'      => 2,
				'timestamp' => 1757326320000,
			),
			array(
				'type'      => 3,
				'timestamp' => 1757326350000,
			),
		),
		'seconds' => 30,
	),
);
check( 'a replay renders no row', false, str_contains( Markdown::render( $with_replay ), 'Replay' ) );
check( 'a replay is not smuggled in as a fact', false, str_contains( Markdown::render( $with_replay ), '30 s' ) );
check( 'a report carrying a replay still renders', true, str_contains( Markdown::render( $with_replay ), '| Type | Bug |' ) );

echo "\n$total checks, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
