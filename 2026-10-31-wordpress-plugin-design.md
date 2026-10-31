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
  includes/client.php     every call to games.chatforest.com
  includes/shortcode.php
  includes/connect.php    the paste-a-code and disconnect form handlers
  includes/rest.php       the proxy route
  assets/chalandria.css
  uninstall.php
```

Prefix every function, option, meta key, hook and handle with `chalandria_one_` (WordPress has
one global namespace), or use a PHP namespace for the functions.

### 1. Plugin header

A comment block at the top of `chalandria-one.php` makes it a plugin. Set `Requires at least: 6.5`
(for script modules, step 6) and `Requires PHP`. Guard the file with
`defined( 'ABSPATH' ) || exit;`.
Read: https://developer.wordpress.org/plugins/plugin-basics/header-requirements/ and
https://developer.wordpress.org/plugins/hooks/

### 2. Settings

One option, the base URL, defaulting to `https://games.chatforest.com`, so Rob can point a
test site at gamet. A page under Settings with the Settings API: `register_setting()` (with a
`sanitize_callback` that keeps `https://` URLs only, `esc_url_raw()`), `add_settings_section()`,
`add_settings_field()`, `add_options_page()`, and `current_user_can( 'manage_options' )`.
Read: https://developer.wordpress.org/plugins/settings/

### 3. The client (`client.php`)

Small functions around the HTTP API, used by steps 4, 5 and 7:

- `chalandria_one_redeem( $code, $host )` → `wp_remote_post()` to `do=redeem`.
- `chalandria_one_forward( $key, $method, $do, $params )` → `wp_remote_request()` to
  `chalandria/api.php`, with headers `X-Games-Key` and a `User-Agent` of
  `WordPress/{version}; {home_url}; chalandria-one-wp/{plugin version}`. `timeout` **60**: a turn can
  take 30 s and the default is 5.
- `chalandria_one_disconnect( $key )`.

Use `wp_remote_retrieve_response_code()` and `wp_remote_retrieve_body()`, check
`is_wp_error()`, and **never** set `sslverify` to false. Never log or echo a key.
Read: https://developer.wordpress.org/plugins/http-api/

### 4. Where the key lives

User meta, `chalandria_one_key`, one per WordPress user: `get_user_meta( $user_id, 'chalandria_one_key',
true )`, `update_user_meta()`, `delete_user_meta()`. Optionally keep the redeem answer's
`username` in `chalandria_one_handle`, to show "Playing as @rob".
Read: https://developer.wordpress.org/plugins/users/working-with-user-metadata/

### 5. The shortcode (`shortcode.php`)

`add_shortcode( 'chalandria_one', 'chalandria_one_render' )`. The callback
**returns** a string (echoing puts it at the top of the page). Three states:

1. **Not logged in to WordPress** (`is_user_logged_in()`): a sentence with two links,
   "Log in" to `wp_login_url( get_permalink() )`, and "or play at games.chatforest.com" to
   `{base}/chalandria/`.
2. **Logged in, no key:** a **Login to Chalandria** link to the connect page, opening a new
   tab (`target="_blank" rel="noopener"`), then a small form: "Paste the code here" with a
   text input and a Connect button (step 7).
3. **Connected:** the wrapper `div`, Chalandria's markup (the ids above), the `#boot` JSON,
   a "Playing as @handle · Disconnect" line (a form, step 7), and enqueue the stylesheet
   and the module (step 6).

Escape everything that goes out: `esc_url()`, `esc_attr()`, `esc_html()`. Build the boot
JSON with `wp_json_encode()` using the `JSON_HEX_TAG | JSON_HEX_AMP` flags, so nothing in it
can close the `<script>`.
Read: https://developer.wordpress.org/plugins/shortcodes/ and
https://developer.wordpress.org/apis/security/escaping/

### 6. Loading our module

`ui.js` is an ES module, so it needs `<script type="module">`. WordPress 6.5+ has the Script
Modules API: `wp_register_script_module( 'chalandria-one', "{base}/chalandria/0.26.0/ui.js" )`
and `wp_enqueue_script_module()` from inside the shortcode callback. Its own `import './state.js'`
resolves against our URL by itself. The stylesheet: `wp_enqueue_style()` with
`plugins_url( 'assets/chalandria.css', __FILE__ )` and the plugin's version as `ver`.
Read: https://make.wordpress.org/core/2024/03/04/script-modules-in-6-5/ and
https://developer.wordpress.org/reference/functions/wp_enqueue_style/

### 7. Connect and disconnect forms (`connect.php`)

Classic WordPress form handling through `admin-post.php`, which teaches nonces:

- The form posts to `admin_url( 'admin-post.php' )` with a hidden `action` of
  `chalandria_one_connect` (or `chalandria_one_disconnect`), `wp_nonce_field( 'chalandria_one_connect' )`, and
  a hidden `back` of the current page's URL.
- `add_action( 'admin_post_chalandria_one_connect', … )` (only logged-in users reach `admin_post_*`).
  The handler: `check_admin_referer( 'chalandria_one_connect' )`; read the code with
  `sanitize_text_field( wp_unslash( $_POST['code'] ) )` (WordPress adds slashes to `$_POST`);
  call `chalandria_one_redeem()`; on 200 store the key; then `wp_safe_redirect( $back )` and `exit`.
- Say what happened on the page after the redirect: a query arg added with
  `add_query_arg( 'chalandria_one', 'connected' | 'bad_code' | 'try_later', $back )`, read by the
  shortcode. A 400 means "That code didn't work. Codes last 10 minutes and work once.";
  429 means "Too many wrong codes. Wait 15 minutes."
- Disconnect: call `chalandria_one_disconnect()` (ignore its answer), then delete the meta.

Read: https://developer.wordpress.org/apis/security/nonces/ and
https://developer.wordpress.org/reference/hooks/admin_post_action/

### 8. The proxy route (`rest.php`)

```
add_action( 'rest_api_init', … register_rest_route( 'chalandria-one/v1', '/chalandria', [
    'methods'             => [ 'GET', 'POST' ],
    'callback'            => 'chalandria_one_proxy',
    'permission_callback' => 'is_user_logged_in',
] ) );
```

- **The nonce is what makes the user logged in here.** A REST request with the WP cookie but
  no valid `X-WP-Nonce` runs as user 0, so `is_user_logged_in()` is false and the route is
  401. That is WordPress's CSRF defence for REST, and why `ui.js` sends `boot.headers` on
  every call.
- `do` must be one of `view`, `cmd`, `describe` (else 400). Forward only the parameters that
  `do` uses: `after` for view; `line`, `after` for cmd; `x`, `y`, `z` for describe. Read them
  with `$request->get_param()`. Don't pass the whole request on.
- No key in meta: answer 401 `{"error":"key"}` without calling us.
- Our answer goes back with **our status code** and our JSON:
  `new WP_REST_Response( json_decode( $body, true ), $status )`. `ui.js` reads 401, 403 and
  503 itself.
- Our 401 `{"error":"key"}` means the key was revoked (the player disconnected on
  /profile/, changed password, or was banned): delete the meta too, so the next page load
  shows the Login button again.
- A `WP_Error` from the HTTP call (we're down, a timeout): 502 `{"error":"unreachable"}`.

Read: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
and https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/

### 9. Uninstall

`uninstall.php` (guarded by `defined( 'WP_UNINSTALL_PLUGIN' )`) deletes the option and every
user's `chalandria_one_key` and `chalandria_one_handle` (`delete_metadata( 'user', 0, 'chalandria_one_key', '',
true )`). It can't revoke the keys here, since it doesn't call us. Players can do that on
/profile/, so say so in the readme.
Read: https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/

## Order to build it in

Each step can be seen working before the next:

1. Skeleton: the header, activate it, and the shortcode returns "Hello".
2. Settings page, base URL saved and read back.
3. The shortcode's three states as plain text.
4. Connect form + redeem: paste a gamet code and see the key's handle on the page. gamet's
   Allow page names your WP host, and its `/profile/` then lists the site with your
   User-Agent.
5. The proxy: in the browser's devtools on the shortcode page,
   `fetch(boot.api + '?do=view&after=-1', {headers: boot.headers}).then(r => r.json())`.
6. The markup, the module and the CSS: play.
7. Disconnect, both ways: the button here, and Disconnect on gamet's /profile/ (the next
   poll gets 401 and the page says so; a reload shows the Login button).
8. Uninstall.

A local WordPress to build on: `npx @wordpress/env start` (Docker), or LocalWP. A site on
`localhost` connects fine: our side accepts `localhost` as a site name.

## Security checklist

- The key is only ever in user meta and in server-to-server headers. Not in HTML, JS, logs,
  or the REST response.
- Every form has a nonce, every REST call is nonce-checked by WordPress itself, and the route's
  `permission_callback` is never `__return_true`.
- Everything echoed is escaped; the boot JSON can't break out of its `<script>`.
- Only `view`, `cmd`, `describe` and their own parameters are forwarded.
- HTTPS only to our base, certificates verified.
- One WP user's key is never used for another: always `get_current_user_id()`.

## Our side, if the plugin needs more

Changes to Chalandria's page (`ui.js`) bump `\Version\Chalandria::SEMVER`, and the plugin's
constant should follow. Done so far for the plugin (b86ad40): `boot.headers`, the `&` join,
and the CORS header on Chalandria's `.js`.
