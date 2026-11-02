# WordPress plugin: `[chalandria_one]`

Date: 2026-10-31. Rob builds it by hand, to learn WordPress. mg #794 (our side: #795-#799,
spec `2026-10-31-connected-sites-design.md`). This spec says **what** to build and which
WordPress pieces do it, with links; the code is Rob's.

## Goal

On any WordPress page, `[chalandria_one]` shows a working Chalandria, played as the
visitor's own games.chatforest.com account. No iframe: the page carries Chalandria's markup,
our `ui.js` runs in it, and every call goes through the WordPress site's own server.

```
browser ──(WP login cookie + X-WP-Nonce)──▶ /wp-json/chalandria-one/v1/chalandria?do=…
WP plugin ──(X-Games-Key, server to server)──▶ games.chatforest.com/chalandria/api.php?do=…
```

The key never reaches a browser. It lives in the WordPress user's meta, on the WP server.

## Not in v0.1

Gutenberg block (later, Rob), more than one game, more than one shortcode on a page (the
markup uses fixed ids), playing without a WordPress login, admin screens listing users' keys.

## What our side already gives you (live and on gamet)

| Request | Answer |
|---|---|
| browser opens `{base}/connect/?site={host}&game=chalandria` | login, Allow → a code `XXXX-XXXX` (10 min, one use) |
| `POST {base}/connect/api.php?do=redeem` body `code`, `site` | 200 `{"key":"gcf_…","site","scope","username","display_name"}`; 400 `{"error":"code"}`; 429 `{"error":"throttled"}` |
| `GET {base}/connect/api.php?do=whoami` + `X-Games-Key` | 200 `{"username","display_name","site","scope"}` |
| `POST {base}/connect/api.php?do=disconnect` + `X-Games-Key` | 200 `{"ok":true}` |
| `GET {base}/chalandria/api.php?do=view&after=N` + key | the world's view (JSON) |
| `POST {base}/chalandria/api.php?do=cmd` body `line`, `after` + key | the turn's answer; can take ~30 s (Haiku) |
| `POST {base}/chalandria/api.php?do=describe` body `x`, `y`, `z` + key | `{"ok":…}` |
| any keyed request with a bad, revoked or disabled key | 401 `{"error":"key"}` |
| Chalandria refusing the account | 403 `{"error":"adult"}` / `{"error":"banned"}`, 503 `{"error":"soon"}` |

- `{base}` is `https://games.chatforest.com`, or `https://gamet.chatforest.com` to test.
- `{host}` is the WP site's hostname: `wp_parse_url( home_url(), PHP_URL_HOST )`. Redeem
  must send the **same** host, or the code is refused.
- `{base}/chalandria/{version}/ui.js` and `state.js` are served with
  `Access-Control-Allow-Origin: *`, so the page can load them as modules from our server.
  `{version}` is `\Version\Chalandria::SEMVER`, **0.26.0** now. Any `N.N.N` is served the
  current file; the number only matters for caches. Keep it as a constant in the plugin.

`ui.js` reads one JSON blob, `<script type="application/json" id="boot">`:

```json
{ "api": "<your REST route URL>", "headers": { "X-WP-Nonce": "<wp_create_nonce('wp_rest')>" } }
```

It sends `headers` with **every** call, and adds `?do=…` to `api`, or `&do=…` when `api`
already has a `?` (plain permalinks give `/?rest_route=/chalandria-one/v1/chalandria`). GETs put
their parameters in the query; POSTs send `application/x-www-form-urlencoded` bodies.

It finds these elements by id, so the markup must have them: `scrollback` (an `<ol>`),
`prompt` (a `<form>`) holding the input `line`, and the paragraphs `levels`, `around`,
`tries`. Leave out `lives` (it links to our own lives page; `ui.js` copes). Copy the shape
from `templates/chalandria/index.tpl.php` in this repo.

Don't load our `style.css`: it is built on our site's theme tokens and sets the `body`
background, which would fight the WP theme. Ship a small stylesheet of your own, scoped
under one wrapper class (say `.chalandria-one`).

## The plugin

Suggested layout (a folder in `wp-content/plugins/`, its own git repo):

```
chalandria-one/
  chalandria-one.php      plugin header, constants, require the rest, add the hooks
  includes/settings.php
  includes/shortcode.php
  includes/client.php     every call to games.chatforest.com
  includes/connect.php    the paste-a-code and disconnect form handlers
  includes/rest.php       the proxy route
  assets/chalandria.css
  uninstall.php
```

Prefix every function, option, meta key, hook and handle with `chalandria_one_` (WordPress has
one global namespace), or use a PHP namespace for the functions.

The sections below are the build order. Finish each small milestone before moving on.

### 1. [o] Plugin header

A comment block at the top of `chalandria-one.php` makes it a plugin. Set `Requires at least: 6.5`
(for script modules, step 6) and `Requires PHP`. Guard the file with
`defined( 'ABSPATH' ) || exit;`.
Read: https://developer.wordpress.org/plugins/plugin-basics/header-requirements/ and
https://developer.wordpress.org/plugins/hooks/

### 2. [o] Settings

One option, `chalandria_one_base`, the base URL, defaulting to `https://games.chatforest.com`,
so Rob can point a test site at gamet. A page under Settings with the Settings API:
`register_setting()` (with a `sanitize_callback` that keeps `https://` URLs only,
`esc_url_raw()`), `add_settings_section()`, `add_settings_field()`, `add_options_page()`, and
`current_user_can( 'manage_options' )`.

Base URL change cleanup is deferred to milestone K in step 4, when user metadata exists.
Read: https://developer.wordpress.org/plugins/settings/

### 3. The shortcode first: A–E (`shortcode.php`)

Build the visible connection screen before making HTTP calls or storing credentials.

- **A. [o] Show a shortcode.** Register
  `add_shortcode( 'chalandria_one', 'chalandria_one_render' )` and put `[chalandria_one]`
  on a WordPress page. The callback **returns** a string; echoing puts it at the top of the page.
- **B. Detect WordPress login.** Use `is_user_logged_in()`. For a visitor who is not logged
  in, show "Log in" linking to `wp_login_url( get_permalink() )`, and
  "or play at games.chatforest.com" linking to `{base}/chalandria/`.
- **C. Show the server URL.** Read `chalandria_one_base` with the default from step 2 and
  show which base URL the plugin will talk to.
- **D. Link to Chatforest login.** For a logged-in WordPress user, show a **Login to
  Chalandria** link to `{base}/connect/?site={host}&game=chalandria`, opening a new tab
  (`target="_blank" rel="noopener"`). The player logs in there, allows the connection,
  and receives a short-lived code.
- **E. Show a code field.** Below the link, show "Paste the code here", a text input and a
  Connect button. Step 4 wires up submission; this milestone only renders the form.

Escape everything that goes out: `esc_url()`, `esc_attr()`, `esc_html()`.
`the_content` can run more than once per request (SEO plugins building a description,
excerpts, page builders), so the callback must do nothing but build its string and enqueue.
No HTTP calls, no writes.

User-facing strings go through `__()` / `esc_html__()` with the text domain
`chalandria-one`. No translations ship in v0.1, but the phpcs ruleset flags bare strings.
Read: https://developer.wordpress.org/plugins/shortcodes/ and
https://developer.wordpress.org/apis/security/escaping/

### 4. The client and connection: F–M (`client.php`, `connect.php`)

WordPress login and the Chatforest connection are separate states. Build the client and
form handler a little at a time, then add user metadata when there is a key to save.

#### F. Send any message

Make a server-to-server request to the configured base and observe its response.
Use the WordPress HTTP API, `wp_remote_retrieve_response_code()` and
`wp_remote_retrieve_body()`, and check `is_wp_error()`. Never set `sslverify` to false.
Never log or echo a key.
Read: https://developer.wordpress.org/plugins/http-api/

#### G. Send the code for verification

Add `chalandria_one_redeem( $code, $host )`, using `wp_remote_post()` to
`{base}/connect/api.php?do=redeem` with body fields `code` and `site`. The host must match
what the link in D sent. Submit the form through `admin-post.php`:

- The form posts to `admin_url( 'admin-post.php' )` with a hidden `action` of
  `chalandria_one_connect`,
  `wp_nonce_field( 'chalandria_one_connect' )`, and a hidden `back` of the current page's URL
  with any earlier result stripped: `remove_query_arg( 'chalandria_one', … )`, or the args
  pile up each time the form is used.
- `add_action( 'admin_post_chalandria_one_connect', … )` (only logged-in users reach
  `admin_post_*`). The handler: `check_admin_referer( 'chalandria_one_connect' )`; read the code
  with `isset( $_POST['code'] )` first, then `sanitize_text_field( wp_unslash( $_POST['code'] ) )`
  (WordPress adds slashes to `$_POST`); call `chalandria_one_redeem()`; handle the reply as below,
  then `wp_safe_redirect( $back )` and `exit`. `wp_safe_redirect()` refuses other hosts, so a
  forged `back` can't send the player off-site.

Read: https://developer.wordpress.org/apis/security/nonces/ and
https://developer.wordpress.org/reference/hooks/admin_post_action/

#### H. Handle a valid reply

Recognize a 200 response with valid JSON containing the expected key and account details.
Show a successful connection message without exposing the key. Saving it comes in K.

#### I. Handle a ダメ reply

Handle invalid, expired or already-used codes (400), and throttling (429).
Say what happened after the redirect: add a query arg with
`add_query_arg( 'chalandria_one', $result, $back )`, read by the shortcode:

| Redeem answer | `$result` | Shown |
|---|---|---|
| Valid 200 | `connected` | "Connected as @handle." |
| 400 | `bad_code` | "That code didn't work. Codes last 10 minutes and work once." |
| 429 | `try_later` | "Too many wrong codes. Wait 15 minutes." |
| anything else, malformed reply, or a `WP_Error` | `unreachable` | "The Games server didn't answer as expected. Try again in a minute." |

The 429 is currently counted per WordPress *server*, not per player, so one player's wrong
codes can lock out the whole site (mg #800, our side).

#### J. Handle connection failures (suggested)

Handle timeouts, HTTP errors and unexpected or malformed replies with the retry message
above. Do not treat these as proof that an existing saved key is invalid.

#### K. Save the long string

User meta, `chalandria_one_key`, one per WordPress user:
`get_user_meta( $user_id, 'chalandria_one_key', true )`, `update_user_meta()`,
`delete_user_meta()`. Optionally keep the redeem answer's `username` in
`chalandria_one_handle`, to show "Playing as @rob".

A key belongs to the base that issued it: a gamet key is a 401 on games. When adding this
metadata, also handle base URL changes: forget every stored key and handle
(`add_action( 'update_option_chalandria_one_base', … )` calling the same `delete_metadata()`
as uninstall, step 8). Players reconnect against the new base. The old keys stay live on the
old base until revoked there on /profile/.
Read: https://developer.wordpress.org/reference/hooks/update_option_option/

Don't `register_meta()` these keys. Registering with `show_in_rest` is the one way user meta
leaks out through the core REST API (`/wp/v2/users/me`); unregistered meta never does.

Optional: `add_action( 'delete_user', … )` calling `chalandria_one_disconnect()` with that
user's key, so deleting a WP user also revokes their key on our side. WordPress deletes the
meta by itself.
Read: https://developer.wordpress.org/plugins/users/working-with-user-metadata/

If the user already has a key (a second tab, a double submit), call
`chalandria_one_disconnect()` on the old one before storing the new one, so it isn't left
live on our side with nothing holding it. Add that client helper using a POST to
`{base}/connect/api.php?do=disconnect` with the `X-Games-Key` header.

Verify the deferred settings cleanup now: changing the base URL must clear the stored
keys and handles, so the shortcode shows D and E again.

#### L. Detect a valid Chatforest connection

Add a client helper for `GET {base}/connect/api.php?do=whoami`, sending the saved key in
`X-Games-Key`. A valid reply confirms the connection; show "Playing as @handle".
Run this check outside the shortcode render callback, which must remain free of HTTP calls
and writes. The playable connected screen is added in step 6.

#### M. Detect a rejected Chatforest connection

An upstream 401 means the key is invalid or revoked. Clear that user's saved key and handle
and show the link and code field from D and E again. A timeout or unexpected reply should
show a retry message and preserve the credentials. Step 5 applies the same rule to game calls.

### 5. The proxy route (`rest.php`)

Add `chalandria_one_forward( $key, $method, $do, $params )` using
`wp_remote_request()` to `{base}/chalandria/api.php`, with `X-Games-Key` and a `User-Agent`
of `WordPress/{version}; {home_url}; chalandria-one-wp/{plugin version}`.
Set `timeout` to **60**: a turn can take 30 seconds and the default is 5.
GET parameters go on the URL with `add_query_arg()`; `rawurlencode()` each value first.
Keep certificate verification enabled and handle `WP_Error` as in step 4.

Register the route:

```
add_action( 'rest_api_init', … register_rest_route( 'chalandria-one/v1', '/chalandria', [
    'methods'             => [ 'GET', 'POST' ],
    'callback'            => 'chalandria_one_proxy',
    'permission_callback' => 'is_user_logged_in',
    'args'                => [
        'do' => [ 'required' => true, 'enum' => [ 'view', 'cmd', 'describe' ] ],
    ],
] ) );
```

- **The nonce is what makes the user logged in here.** A REST request with the WP cookie but
  **no** `X-WP-Nonce` runs as user 0, so `is_user_logged_in()` is false and the route is 401.
  That is WordPress's CSRF defence for REST, and why `ui.js` sends `boot.headers` on every
  call.
- **An expired or wrong nonce is a 403, not a 401**, and WordPress sends it before your
  callback runs: `{"code":"rest_cookie_invalid_nonce",…}`. A `wp_rest` nonce lasts 12–24
  hours, and logging out of WordPress in another tab kills it too. `ui.js` stops on any 403
  with "You can no longer play here.", which reads like a ban. Turn it into a 401 on your
  route, so `ui.js` says "You have been logged out. Log in again to play." (a reload mints a
  fresh nonce). Use the `rest_post_dispatch` filter, which still runs after a failed
  authentication: if `$request->get_route()` starts with `/chalandria-one/v1/`, the status is
  403 and the error code is `rest_cookie_invalid_nonce`, return
  `new WP_REST_Response( [ 'error' => 'nonce' ], 401 )`.
- The `args` `enum` makes WordPress answer 400 itself for any other `do`. Forward only the
  parameters that `do` uses: `after` for view; `line`, `after` for cmd; `x`, `y`, `z` for
  describe. Read them with `$request->get_param()`. Don't pass the whole request on.
- No key in meta: answer 401 `{"error":"key"}` without calling us.
- Our answer goes back with **our status code** and our JSON:
  `new WP_REST_Response( json_decode( $body, true ), $status )`. `ui.js` reads 401, 403 and
  503 itself.
- If our body doesn't decode (`json_decode()` gives `null`: an HTML error page from the host,
  a 504 from a proxy), answer 502 `{"error":"unreachable"}` instead of passing on a `null`.
- Our 401 `{"error":"key"}` means the key was revoked (the player disconnected on
  /profile/, changed password, or was banned): delete the meta too, so the next page load
  shows the Login button again. Only *our* 401 does this, never WordPress's own.
- A `WP_Error` from the HTTP call (we're down, a timeout): 502 `{"error":"unreachable"}`.

**Cost.** `ui.js` polls `view` every 2.5 s (15 s in a hidden tab), and each poll here loads
all of WordPress and then makes an HTTPS call to us. Fine for a few players. On shared
hosting with many, it's the first thing to get slow. Not a v0.1 problem, but watch it.

Read: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/,
https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/ and
https://developer.wordpress.org/reference/hooks/rest_post_dispatch/

Verify from the shortcode page's browser devtools once the boot JSON is present in step 6:
`boot = JSON.parse(document.getElementById('boot').textContent)`, then
`fetch(boot.api + (boot.api.includes('?') ? '&' : '?') + 'do=view&after=-1', {headers: boot.headers}).then(r => r.json())`.
The same call with `headers: {'X-WP-Nonce': 'nope'}` must return 401, not 403.

### 6. The playable screen, module and CSS

Extend the shortcode's connected state with the wrapper `div`, Chalandria's markup
(the ids listed above), the `#boot` JSON, and a "Playing as @handle · Disconnect" line
(the disconnect form is wired up in step 7). Keep B's WordPress login screen and D/E's
connection screen for users who are not connected.

Build the boot JSON with `wp_json_encode()` using `JSON_HEX_TAG | JSON_HEX_AMP` so nothing
can close the `<script>`. Its `api` is `rest_url( 'chalandria-one/v1/chalandria' )`, which
works with pretty and plain permalinks alike. Include the `X-WP-Nonce` header shown above.

`ui.js` is an ES module, so it needs `<script type="module">`. WordPress 6.5+ has the Script
Modules API: `wp_register_script_module( 'chalandria-one', "{base}/chalandria/0.26.0/ui.js" )`
and `wp_enqueue_script_module()` from inside the shortcode callback. Its own `import './state.js'`
resolves against our URL by itself. The stylesheet: `wp_enqueue_style()` with
`plugins_url( 'assets/chalandria.css', __FILE__ )` and the plugin's version as `ver`.
Read: https://make.wordpress.org/core/2024/03/04/script-modules-in-6-5/ and
https://developer.wordpress.org/reference/functions/wp_enqueue_style/

### 7. Disconnect, both ways

Add a Disconnect form to the connected shortcode screen, using the form pattern from G
with action `chalandria_one_disconnect` and a matching nonce. Its logged-in
`admin_post_chalandria_one_disconnect` handler checks the nonce, calls
`chalandria_one_disconnect()` with the current user's key (ignore its answer), deletes
that user's key and handle, then safely redirects back. The shortcode shows D and E again.

Also test Disconnect on the Games server's /profile/: the next game poll receives 401,
the proxy clears the metadata, and a page reload shows D and E again.

### 8. Uninstall

`uninstall.php` (guarded by `defined( 'WP_UNINSTALL_PLUGIN' )`) deletes the option and every
user's `chalandria_one_key` and `chalandria_one_handle`
(`delete_metadata( 'user', 0, 'chalandria_one_key', '', true )`). It can't revoke the
keys here, since it doesn't call us. Players can do that on
/profile/, so say so in the readme.
Read: https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/

A local WordPress to build on: `npx @wordpress/env start` (Docker), or LocalWP. A site on
`localhost` connects fine: our side accepts `localhost` as a site name.

## Security checklist

- The key is only ever in user meta and in server-to-server headers. Not in HTML, JS, logs,
  or the REST response. Its meta is never registered with `show_in_rest`.
- Every form has a nonce, every REST call is nonce-checked by WordPress itself, and the route's
  `permission_callback` is never `__return_true`.
- Everything echoed is escaped; the boot JSON can't break out of its `<script>`.
- Only `view`, `cmd`, `describe` and their own parameters are forwarded.
- HTTPS only to our base, certificates verified.
- One WP user's key is never used for another: always `get_current_user_id()`.

## Our side, if the plugin needs more

Changes to Chalandria's page (`ui.js`) bump `\Version\Chalandria::SEMVER`, and the plugin's
constant should follow. Done so far for the plugin (b86ad40): `boot.headers`, the `&` join,
and the CORS header on Chalandria's `.js`. Open: mg #800, the redeem throttle counts per
WordPress server instead of per player.
