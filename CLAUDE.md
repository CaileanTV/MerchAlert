# CCMerch Alert — Project Context

This file is the permanent source of truth for this project.
Read it in full before writing, editing, or planning any code change.

---

## Project Vision

CCMerch Alert is a WordPress plugin that provides real-time merchandise and WooCommerce store alerts.
Its long-term goal is to become the WooCommerce equivalent of StreamAlerts for Shopify — a first-class,
professional alert platform built specifically for the WordPress ecosystem.

**Core commitments:**

- Single edition only. No Premium, Pro, or paid tier. Every feature ships to every user.
- Unlimited alerts. No caps, no throttling based on plan.
- Every alert type is an independent, self-contained module.
- New alert types and channels can be added without modifying existing classes.
- Integration with WooCommerce is the foundation. All other integrations are additive.

---

## Architecture Principles

### Language and standards

- PHP 8.1 minimum (readonly properties, never return type, fibers, enums).
- WordPress 6.6 minimum.
- WordPress Coding Standards throughout: tabs for indentation, snake_case for functions and variables,
  PascalCase for class names, SCREAMING_SNAKE_CASE for constants.
- All class files named `class-{name}.php`, interfaces named `interface-{name}.php`.
- Every file guarded with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.

### Object-oriented design

- OOP only. No procedural logic outside of the bootstrap file (`ccmerch-alert.php`).
- All classes must be `final` unless explicitly designed for extension.
- SOLID principles: single responsibility, open/closed, Liskov substitution, interface segregation,
  dependency inversion.
- Small, focused classes. If a class does two things, it should be two classes.
- Dependency injection throughout. No class should reach for a global singleton without being given it.
- Separation of business logic and presentation at all times.

### Namespace rules

- Root namespace: `CCMerchAlert`.
- Sub-namespaces mirror the folder structure exactly:
  - `CCMerchAlert\Admin` → `includes/admin/`
  - `CCMerchAlert\Engine` → `includes/engine/`
  - `CCMerchAlert\Engine\Contracts` → `includes/engine/contracts/`
  - `CCMerchAlert\Integrations` → `includes/integrations/`
  - `CCMerchAlert\Models` → `includes/models/`
  - `CCMerchAlert\OrderLog` → `includes/order-log/`
  - `CCMerchAlert\Overlay` → `includes/overlay/`
- `CCMerchAlert\Auth` → `includes/auth/`
- `CCMerchAlert\Audio` → `includes/audio/`
- `CCMerchAlert\Checkout` → `includes/checkout/`
- `CCMerchAlert\Creators` → `includes/creators/`
- `CCMerchAlert\Fonts` → `includes/fonts/`
- `CCMerchAlert\Layout` → `includes/layout/`
- `CCMerchAlert\Releases` → `includes/releases/`
- `CCMerchAlert\Api` → `includes/api/`
- `CCMerchAlert\Translations` → `includes/translations/`
- `CCMerchAlert\Chatbot` → `includes/chatbot/`
- `CCMerchAlert\UI` → `includes/ui/`
- `CCMerchAlert\UI\Components` → `includes/ui/components/`
- PHP built-in functions (`time`, `class_exists`, `is_array`, `array_merge`, `dirname`) must be called
  with the `\` prefix (e.g. `\time()`) to enable compile-time resolution and make namespace intent explicit.
- WordPress functions (`add_action`, `do_action`, `register_setting`, etc.) must NOT be prefixed with `\`.
  Reason: the IDE has no WordPress stubs installed. Adding `\` to WordPress functions escalates PHP0417
  warnings into P1010 errors (a worse diagnostic). WordPress functions reach the global namespace correctly
  at runtime via PHP's namespace fallback. This is not a bug.

### No autoloader

- No Composer autoloading. All class files are loaded explicitly via `require_once` in
  `Plugin::load_files()`, in dependency order.
- Load order: contracts → engine classes → integrations → models → order-log → admin.
- The Order Log installer (`class-order-installer.php`) is the only exception: it is loaded directly
  in `ccmerch-alert.php` (not in `load_files()`) so it is available before `plugins_loaded` fires,
  which is required for `register_activation_hook()` to work correctly.
- If a new file is added, it must be added to `Plugin::load_files()` in the correct position.

### Extension points

- `ccmerch_alert_init` (WordPress action) — fires when the engine is ready for component registration.
  Third-party code and future built-in modules register triggers and channels here.
- `ccmerch_alert_dispatch` (WordPress action) — fires every time a trigger produces an Alert_Context.
  Future versions will evaluate alert configurations and route to channels here.

### Component-First Development

Every piece of UI that is used in more than one place must be extracted into a shared component before a
second usage is written. There must never be two PHP methods, two JS functions, or two HTML blocks that
produce the same output.

**Mandatory rules:**
1. **Refactor before duplicate.** If you need to render the same UI on a new page, extract it first.
   Open a PR that creates the component. Only then add the second usage.
2. **Single source of truth.** Every named UI element — card, form section, canvas, button group — has
   exactly one PHP renderer, one JS handler, and one CSS block.
3. **Asset ownership.** All plugin asset URLs live in `Asset_Loader`. No class may build an asset URL
   from `CCMERCH_ALERT_URL` directly. Call `Asset_Loader::admin_css()`, `::utils_js()`, etc.
4. **Data-attribute AJAX.** AJAX buttons must carry all parameters as `data-ccmerch-*` attributes so
   `ccmerchSendTestRequest()` in `ccmerch-utils.js` handles the request. Per-page inline JS for AJAX
   is not permitted.
5. **Component discoverability.** Register every shared component in `Plugin::load_components()` via
   `Component_Registry::register()`. This is the canonical list of all reusable UI pieces.

**Future development checklist** (run through this before adding any new admin UI):
- Does this UI element already exist as a component? Check `Component_Registry::ids()`.
- Does it appear on more than one page? If yes, it must live in `includes/ui/components/`.
- Does it fire an AJAX request? The button must use `data-ccmerch-test-action` + shared JS handler.
- Does it load an asset? Use `Asset_Loader` — never build a URL from `CCMERCH_ALERT_URL` inline.
- Did you register the new component in `Plugin::load_components()`?

### Creator Save Architecture

The Creator Portal must never use `admin-post.php` for any form submission. WordPress returns HTTP 400
for `admin_post_{action}` requests from unauthenticated users (which all creators are, from WordPress's
perspective) when no `admin_post_nopriv_{action}` variant is registered.

**All Creator Portal write operations use `admin-ajax.php` via `Creator_Save_Service`.**

Rules:
- `Creator_Save_Service` is the **single source of truth** for all creator portal AJAX write operations.
  No other class may register `admin_post_` or `wp_ajax_` handlers for portal settings saves.
- Every creator portal AJAX handler must register BOTH `wp_ajax_nopriv_*` AND `wp_ajax_*` variants.
  The non-nopriv hook fires when a logged-in WordPress admin visits the portal for testing.
- Authentication is via `Auth_Context::resolve_creator()` (creator access token, 256-bit). No WP nonce
  is checked — the token is the authentication credential.
- The portal settings form submits via `fetch()` (no page reload). Success and error feedback are
  shown inline in `#ccmerch-portal-save-feedback`.
- After a successful AJAX save, `ccmerch-utils.js` dispatches a non-cancelable synthetic `submit`
  event on the form so `layout-editor.js` resets `dirty = false` without triggering navigation.
- Overlay ID generation also uses `Creator_Save_Service::AJAX_GENERATE_ID` via `admin-ajax.php`.
  The JS handler (`ccmerchHandleGenerateOverlayId`) updates the DOM in-place — no page reload.
- All responses must be `wp_send_json_success()` or `wp_send_json_error(['message', 'reason', 'step'])`.
  `wp_die(-1)` or any non-JSON response is not permitted.

### Browser Source Runtime

The Browser Source is a persistent runtime client, not a page that polls and refreshes.

**Transport:** Server-Sent Events (SSE) is the primary transport. `overlay.js` opens a single long-lived
`EventSource` connection to the SSE stream endpoint on page load and listens for pushed events. AJAX
polling is used only as a fallback when `EventSource` is unavailable (non-OBS environments).

**Unified alert pipeline:** All alert types — test alerts, replay, and live WooCommerce orders — are
delivered through the same event pipeline and received over the same SSE connection. There are no
separate code paths for test vs. real alerts in `overlay.js`.

**`overlay.js` internal architecture (Beta 1.3.1+):**

`overlay.js` is an event-driven, module-based IIFE. All mutable state lives in `RuntimeState` (one
place). All inter-module signals flow through `EventBus` (named constants). No module calls another
module's internals directly.

| Module | Responsibility |
|---|---|
| `EventBus` | Internal pub/sub. All signals flow here. |
| `RuntimeState` | Single source of truth for connection state, cursor, queue, and live config values. |
| `ConnectionManager` | SSE lifecycle. The only code that creates `EventSource`. |
| `QueueManager` | Alert display pipeline: receive → enqueue → animate sequentially. |
| `OverlayStateService` | Applies live `config` SSE events to the DOM without a page reload. |
| `AudioManager` | Alert sound playback. No external dependencies. |
| `DebugPanel` | Observability overlay (`?debug=1`). Subscribes to EventBus; never writes state. |
| `PollFallback` | AJAX polling fallback. Shares `QueueManager.receive()` with the SSE path. |

**Named internal events (`EVENTS` constants):**

| Constant | Value | When emitted |
|---|---|---|
| `CONNECTION_OPENING` | `connection:opening` | `connectSSE()` called |
| `CONNECTION_OPENED` | `connection:opened` | Server `connected` event received |
| `CONNECTION_CLOSING` | `connection:closing` | `_close()` called on an open source |
| `CONNECTION_LOST` | `connection:lost` | Unplanned disconnect or timeout |
| `CONNECTION_RECOVERED` | `connection:recovered` | First success after one or more failures |
| `ALERT_RECEIVED` | `alert:received` | SSE `alert` event parsed |
| `ALERT_QUEUED` | `alert:queued` | Item pushed to RuntimeState.alertQueue |
| `ALERT_DISPLAYED` | `alert:displayed` | Animation cycle started |
| `ALERT_COMPLETE` | `alert:complete` | Animation cycle finished |
| `OVERLAY_UPDATED` | `overlay:updated` | `OverlayStateService.applyConfig()` completed |
| `HEARTBEAT_RECEIVED` | `heartbeat:received` | SSE heartbeat event parsed |
| `HEARTBEAT_TIMEOUT` | `heartbeat:timeout` | Watchdog fired — no event for 25 s |

**SSE endpoint design (PHP):**
- Registered as `wp_ajax_nopriv_` + `wp_ajax_` (same auth model as the poll endpoint).
- Auth: overlay ID from `?overlay_id=` query parameter, validated via `hash_equals()`.
- Loop runs every 100 ms. Checks transient (test/replay) first, then DB events (rate-limited to 1/s).
- Maximum connection duration **25 seconds**, then emits `reconnect` event and exits.
  (Reduced from 55 s to stay safely below common PHP-FPM `request_terminate_timeout` values.)
- `EventSource` auto-reconnects immediately using `Last-Event-ID` header (= last since timestamp).
- PHP reads `Last-Event-ID` header on reconnect; falls back to `?since=` for the initial connection.
- `X-Accel-Buffering: no` — disables nginx `proxy_buffering` for this response.
- `Content-Encoding: identity` — tells CDNs and reverse proxies the body is raw; prevents them
  from buffering the stream to decompress/re-encode it.
- `ini_set('zlib.output_compression', '0')` — disables PHP-level gzip which would buffer all output.

**SSE event types:**

| Event | When emitted | Payload |
|---|---|---|
| `connected` | Immediately on connection | `{ server_time }` |
| `alert` | When new events are ready | `{ queue: [...], server_time }` |
| `heartbeat` | Every 15 seconds (proxy keepalive) | `{ server_time }` |
| `reconnect` | Before planned connection close | `{ server_time }` |
| `error` | Auth failure | `{ reason }` |

**`Overlay_Settings_Contract` stream routing:**
- `get_stream_action()` is the SSE equivalent of `get_poll_action()`.
- `Overlay_Settings::get_stream_action()` → `'ccmerch_overlay_stream'` (global overlay).
- `Creator_Overlay_Profile::get_stream_action()` → `'ccmerch_creator_overlay_stream'` (creator overlay).
- `Overlay_Renderer::render_page()` injects `streamAction` into the `ccmerchOverlay` JS config.

**Auto-reconnect behaviour:**
- Planned close (`reconnect` event): `ConnectionManager` closes the EventSource and reconnects within
  100 ms — no backoff, no delay. This is a healthy, expected server event.
- Unplanned disconnect (network error): exponential backoff starting at 1 s, doubling each attempt
  (1 s → 2 s → 4 s → 8 s → 16 s → 30 s max). The counter resets on every successful `connected` event.
- Both reconnect paths produce a fresh EventSource with the current `since` value so events are never
  replayed or missed.
- OBS timer throttling: when an OBS scene is not active, `setTimeout` delays can be unreliable. The
  `visibilitychange` DOM event fires reliably when the scene becomes active again and immediately calls
  `ConnectionManager.connect()` if the connection is not already in the `connected` state.

**Future transport upgrade (SSE → WebSocket):**
- `QueueManager.receive(queue, serverTime)` in `overlay.js` is the single entry point for all
  incoming alert events regardless of transport.
- To add WebSocket support: add a WebSocket client that calls `QueueManager.receive()` on message.
  The queue, animation, and audio code are entirely transport-agnostic and require no changes.

### Alert Stream Runtime Stability

The Browser Source uses a persistent real-time connection (SSE). The system must maintain this
connection automatically at all times — the Browser Source must never remain permanently stuck in
"Connecting to alert stream..." and must never require a manual page refresh.

**Connection state machine (`overlay.js`):**

| State | When entered |
|---|---|
| `disconnected` | Initial state before `startSSE()` runs |
| `connecting` | `connectSSE()` called; EventSource created; waiting for `connected` event |
| `connected` | `connected` SSE event received; stream is live |
| `reconnecting` | Connection lost or timed out; waiting for next `connectSSE()` call |

**Connection timeout (prevents permanent "Connecting..." state):**

When `connectSSE()` creates the EventSource, an 8-second timer starts immediately. If the server's
`connected` event does not arrive within 8 seconds (server-side buffering issue, proxy timeout,
slow response), the EventSource is closed and `scheduleReconnect()` is called with backoff. This
guarantees the Browser Source never waits indefinitely in the connecting state.

**Heartbeat watchdog (catches silently-dead connections):**

After a `connected` event, a 25-second watchdog timer is maintained. Every received SSE event
(`connected`, `alert`, `heartbeat`, `config`) resets this timer. If no event arrives for 25 seconds
(server sends heartbeats every 15 s, so this is a 10 s margin), the connection is assumed stale and
`scheduleReconnect()` is triggered. This catches TCP connections that appear live to the browser
but have stopped delivering server data.

**Exponential backoff (prevents hammering a failing server):**

On unexpected disconnects, reconnect delays follow: 1 s → 2 s → 4 s → 8 s → 16 s → 30 s (max).
The counter resets to 0 on every successful `connected` event. Planned server reconnects (`reconnect`
SSE event) use a fixed 100 ms delay with no backoff increment.

**Single connection enforcement:**

`ConnectionManager.connect()` (which internally calls `connectSSE()`) always calls `_close()` before
creating a new EventSource. The SSE `error` handler guards with `if (!_source) return` to prevent
double-reconnect scheduling when multiple code paths (timeout, watchdog, error event) converge on the
same closed connection.

**OBS scene visibility recovery:**

OBS Browser Source (Chromium) throttles `setTimeout`/`setInterval` in inactive scenes. If a
reconnect timer was scheduled while the scene was inactive, it may fire late or not at all. A
`visibilitychange` listener inside `ConnectionManager` fires reliably when OBS switches back to the
scene; if the connection is not in the `connected` state, `connectSSE()` is called immediately.

**Rules that must never be violated:**

- The Browser Source must never require a manual page refresh to restore the alert stream.
- The Browser Source must never remain in `connecting` state for more than 8 seconds without a retry.
- The heartbeat watchdog must remain active for the entire lifetime of a `connected` session.
- `ConnectionManager` is the only module that creates an EventSource. No other code path may call
  `new EventSource()`.
- `scheduleReconnect()` (inside `ConnectionManager`) is the only function that schedules reconnect
  timers. No hardcoded `setTimeout(connectSSE, N)` calls are permitted outside `ConnectionManager`.
- `RuntimeState` is the only place that holds mutable runtime state. Modules must not declare their
  own state variables — use RuntimeState accessors.
- `EventBus` is the only inter-module communication channel. Modules must not call each other's
  internal functions directly (exception: `QueueManager.receive()` called by `ConnectionManager`
  from inside the SSE `alert` handler, which is an intentional tight coupling).
- Do NOT add `sleep(1)` back to either PHP SSE loop — the 100 ms `usleep(100_000)` interval
  is required for test alert latency targets (see `### Test Alert Performance`).

### Overlay Runtime Synchronization

Browser Sources are long-lived persistent connections (hours or days). Overlay configuration must
propagate to them automatically whenever settings are saved — no page reload, no cache-busting, no
manual intervention ever required.

**Mechanism: SSE `config` event**

When overlay settings are saved:

1. PHP bumps a version marker atomically: `OPTION_VERSION_KEY` for the global overlay (via
   `Overlay_Settings::on_settings_updated()`); `updated_at` in `ccmerch_creator_overlay_settings`
   for creator overlays (updated by every `save_settings()` call).

2. Both SSE loops (`Overlay_Controller::handle_stream()` and
   `Creator_Overlay_Controller::handle_stream()`) check the version marker on every iteration via
   a direct `$wpdb->get_var()` call. This bypasses the WordPress object cache and the alloptions
   in-process cache, which are frozen at SSE connection-open time and never reflect concurrent
   admin saves.

3. On version mismatch the PHP loop: (a) invalidates the in-process settings cache via
   `reload_from_db()`, (b) reads fresh settings from the DB, (c) emits a `config` SSE event with
   a structured payload of CSS custom properties, element inline styles, and JS timing values.

4. `overlay.js` receives the `config` event and calls `OverlayStateService.applyConfig(data)`,
   which applies all three payload sections to the live DOM without any page reload.

**`config` event payload structure:**

```json
{
  "css_vars": {
    "anim": "0.5s", "font_family": "...", "font_size": "32px",
    "font_weight": "700", "text_color": "#ffffff", "text_shadow": "...",
    "text_align": "center", "img_border_radius": "4px"
  },
  "elements": {
    "product_image":  "position:fixed;left:40px;top:880px;width:120px;height:120px;",
    "main_text":      "position:fixed;left:176px;top:895px;width:700px;height:80px;...",
    "secondary_text": "position:fixed;...;display:none;"
  },
  "js": {
    "display_duration": 5000,
    "anim_duration": 500,
    "has_secondary": false
  }
}
```

**Rules that must never be violated:**

- `Alert_Event` must never contain a copy of overlay configuration (template, font, colors,
  layout). Configuration is always resolved at render time from the current settings.
- `Overlay_Renderer::get_config_payload()` is the single implementation that serializes settings
  into the `config` event payload. No other class may duplicate this.
- `OverlayStateService.applyConfig()` in `overlay.js` is the single implementation for applying
  live config updates. No per-page JS may re-implement this logic.
- The `get_config_version()` and `reload_from_db()` methods on settings classes must always use
  direct `$wpdb->get_var()` calls — never `get_option()`. The WordPress object cache and alloptions
  cache are frozen for the lifetime of an SSE PHP process.
- Animation class state (`.ccmerch-overlay-el--hidden/visible/hiding`) is class-based, not
  inline-style-based. `applyConfig()` preserves `el.className` when replacing `el.style.cssText`
  so in-progress animations are never interrupted by a config update.

### Preview Data Provider

The Overlay Designer canvas preview and the Test Alert system share a single Preview Data Provider:
`Creator_Service::build_preview_data(int $creator_id): array`. No other class may implement
product selection or name generation for previews or test alerts.

**Rules that must never be violated:**

- The preview must only display products from the creator's own assigned WooCommerce categories.
- The preview must never expose products from another creator's categories.
- The preview must never use real WooCommerce customer information.
- The preview must never query WooCommerce order or customer tables.
- `Creator_Service::TEST_FIRST_NAMES` is the **single source of truth** for the predefined name list.
  Both the Test Alert system and the Preview Data Provider read from this constant — never define
  or duplicate the list elsewhere.
- `Creator_Service::build_preview_data()` is the **single implementation** of the Preview Data
  Provider. It calls `get_random_product_for_creator()` for product scoping and `TEST_FIRST_NAMES`
  for name generation. No other class may replicate this logic.
- The return shape is `{first_name, product, quantity, image_url, mock}` — identical to the JS
  `preview` object in `ccmerchLayout` consumed by `layout-editor.js`.
- `ccmerchRefreshPreview(btn)` in `ccmerch-utils.js` is the **only** JS function that fetches
  updated preview data from the server. It dispatches a `ccmerch:previewData` custom DOM event
  on success; `layout-editor.js` listens for this event and calls `renderAll()`.
- The Refresh Preview button uses `data-ccmerch-refresh-preview="1"` with AJAX parameters as data
  attributes. The delegated listener in `ccmerch-utils.js` handles all clicks automatically. No
  per-page JS may re-implement this interaction.
- The Refresh Preview button is rendered by `Overlay_Config_Renderer::render_designer()` only
  when the `$refresh_btn` parameter is non-empty. The admin overlay page passes nothing (no button).
  The Creator Portal passes the full button config (action, auth_field, auth_value, ajax_url).

**Preview Data Provider endpoint:**
- Action: `Creator_Overlay_Controller::AJAX_PREVIEW_DATA` = `'ccmerch_creator_preview_data'`
- Auth: creator portal access token (same model as test alert and portal save).
- Response: `wp_send_json_success(build_preview_data($creator->id))` — the `data` key holds the
  preview object dispatched as `ccmerch:previewData`.

### Test Alert Performance

Test Alerts are optimized for rapid iteration. Clicking "Send Test Alert" should trigger a visible
animation in OBS within ~150–200 ms on a local installation.

**How the latency budget is spent:**

| Step | Target | Notes |
|---|---|---|
| AJAX request (button → PHP → browser) | <100 ms | Auth, product lookup, render, transient write |
| SSE loop detects transient | <100 ms | Loop runs every 100 ms (`usleep(100_000)`) |
| Browser Source renders | ~0 ms | EventSource stream is already open |

**SSE loop design (100 ms check interval):**

The SSE loop wakes every 100 ms and checks for the test-alert transient immediately on each
iteration. Live-event DB queries and config-version checks are rate-limited to once per second
using `microtime(true)` float timestamps — so DB load is unchanged from the original 1 s design.

| Check | Frequency | Cost |
|---|---|---|
| Transient (test/replay) | Every 100 ms | Single `SELECT` by option_name — cheap primary-key lookup |
| Live Alert Events (DB) | Every 1 s | `ccmerch_alert_events` query — same as before |
| Config version | Every 1 s | Direct `$wpdb` query — same as before |
| Heartbeat | Every 15 s | No DB queries |

**Product lookup optimization:**

`Creator_Service::get_random_product_for_creator()` fetches exactly one random product
(`posts_per_page=1, orderby=rand, no_found_rows=true`). This eliminates:
- `ORDER BY RAND() LIMIT 50` → `ORDER BY RAND() LIMIT 1` (less data for MySQL to sort)
- Separate `get_post()` call (WP_Post already in memory from `get_posts()`)
- `SQL_CALC_FOUND_ROWS` count (`no_found_rows=true`)

**`test=true` marker:**

Test transients carry `['queue' => [...], 'test' => true]`. The SSE loop forwards this flag
in the emitted alert event payload. This is a forward-compatibility marker — future server-side
processing can use it to bypass production-only work (logging, analytics, counters) without
changing the queue structure or the JS rendering path.

**What is NOT skipped:**

Test alerts still run through the full rendering pipeline: `render_items()` → transient →
SSE emit → `handleAlertBatch()` → `showAlert()`. Audio, animations, and template variable
substitution all work identically to live alerts. The `test=true` flag is data, not a bypass.

**Rules that must never be violated:**

- The SSE loop must never use `sleep(1)` again for either the global or creator overlay.
  Use `usleep(100_000)` with `microtime(true)`-based interval guards for all 1 s checks.
- Live-event DB query frequency must not increase beyond once per second per SSE connection.
- Config-version check frequency must not increase beyond once per second per SSE connection.
- The `test=true` flag must be stored in the transient by both `handle_test()` /
  `handle_portal_test()` (creator) and `handle_test_alert()` (global overlay).

### Test Alert System

Test alerts for creator overlays must respect the Creator Isolation Model exactly as live orders do.
A creator's test alert may only use products from that creator's own assigned WooCommerce categories.

**Rules that must never be violated:**

- `Creator_Overlay_Controller::handle_portal_test()` and `handle_test()` must NEVER call
  `Order_Repository::get_recent()` or any unscoped order/product query. Both methods must route
  through `Creator_Service::get_random_product_for_creator()`.
- `Creator_Service::get_random_product_for_creator()` is the **single implementation** for selecting
  a test product scoped to a creator. No other class may replicate this product selection logic.
- Test alerts must NEVER use real customer data. The first name must always be randomly selected from
  `Creator_Service::TEST_FIRST_NAMES`. No WooCommerce customer tables may be queried.
- Test alerts must NEVER create WooCommerce orders or insert into WooCommerce tables.
- The simulated `Order_Log_Entry` + `Order_Log_Item` must be built using the real product's ID,
  name, featured image attachment ID, and creator-owned category term IDs so that audio isolation,
  image rendering, and template variables (`$Product`, `$Category`) resolve correctly.
- If `get_random_product_for_creator()` returns null (no categories assigned, WooCommerce inactive,
  or no products in assigned categories), the endpoint must return a structured JSON error. No
  transient must be written. The overlay must be left undisturbed.
- The simulated alert must flow through the exact same pipeline as live orders:
  `render_items()` → transient → SSE loop → `handleAlertBatch()` → `showAlert()`. No shortcut paths.

**Predefined first names** (defined as `public const TEST_FIRST_NAMES` on `Creator_Service`):
`Alex`, `Emma`, `Oliver`, `Sofia`, `Lucas`, `Isabella`, `Noah`, `Freja`, `William`, `Ella`

**Product selection** (`Creator_Service::get_random_product_for_creator()`):
1. Gate on `WooCommerce::is_active()` — return null if inactive.
2. Fetch creator's assigned `product_cat` term IDs via `get_category_ids()`.
3. `get_posts(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 50,
   'orderby' => 'rand', 'tax_query' => [['taxonomy' => 'product_cat', 'terms' => $term_ids]]])`.
4. Pick one via `array_rand()` on the returned ID list.
5. Resolve `featured_image_attachment_id` via `get_post_thumbnail_id()` — null when 0.
6. Resolve `category_term_ids` via `get_the_terms()`, filtered to creator-owned term IDs only.

### Expandability principle

Design for expandability before optimising for performance. A module added correctly today should require
zero changes to existing files.

### Audio runtime execution

- `audio_enabled` (`FIELD_AUDIO_ENABLED`) defaults to `'0'` (disabled). The creator or admin must
  explicitly enable audio and save settings before any sound plays.
- **`sound_url` is the live gate.** `resolve_sound_url()` (in `Overlay_Renderer`) returns `''`
  when `audio_enabled !== '1'`. An empty `sound_url` in the poll response means "no audio" —
  no second JS gate is needed or permitted.
- **`ccmerchOverlay.audioEnabled` must NOT be used as an audio gate in `AudioManager.play()`.**
  This flag is injected at page load time and goes stale if the creator enables audio while OBS is
  open. The `! url` check in `AudioManager.play()` is the only JS gate. The backend is the single
  point where the enabled state is checked.
- Audio files are stored as WordPress attachment IDs, never as URLs. `wp_get_attachment_url()` is
  called at render time so URLs survive CDN or media library moves.
- Audio upload to WP media library ≠ audio configured in overlay settings. Uploading creates the
  attachment and updates the hidden form field in JS. The DB is NOT updated until "Save Settings"
  is clicked. Creators must save after uploading.
- `AudioManager` uses native `HTMLAudioElement`. OBS Browser Source (Chromium) permits autoplay
  without a user gesture. Autoplay policy errors in regular browsers are caught via `console.warn()`
  — the visual alert is never blocked.
- `Alert_Event` has no `audio_attachment_id` field. Audio is resolved at render time from the
  creator's `FIELD_AUDIO_CAT_MAP` / `FIELD_AUDIO_DEFAULT` settings using the event's
  `category_term_ids`. This allows the creator to change their audio file and have the next poll
  reflect it without recreating existing events.

### Versioning

The plugin follows a phase-based version scheme:

- **Alpha** — internal development; no stability guarantees.
- **Beta** — feature-complete for the current scope; stable for testing in real environments.
- **Stable (1.x.x)** — production-ready; full backwards compatibility within the major version.

Current phase: **Beta**. Current version: **Beta 2.0.2**.

The `CCMERCH_ALERT_VERSION` constant in `ccmerch-alert.php` is the single source of truth for
the version string. It must be updated whenever a release entry is added to the Project Memory
changelog. No other file should hardcode the version number.

### Customer Name Privacy

The Creator Portal Alert History includes an optional **Anonymize Customer Names** mode that
allows creators to safely display their alert history on stream without exposing customer names.

The setting is presented as a modern toggle switch (CSS flip switch) and is stored locally in
the browser. Customer names are formatted through the shared `CustomerNameFormatter` component,
ensuring consistent privacy handling across future modules without modifying stored alert data.

**Rules that must never be violated:**

- This feature only affects the Creator Portal UI. It must never modify stored alert data,
  WooCommerce order data, replay functionality, live alerts, or overlay rendering.
- `CustomerNameFormatter` in `assets/js/ccmerch-utils.js` is the **single implementation** of
  customer name formatting and anonymization. No other JS may duplicate this logic.
- Every module that displays a customer name must read the raw value from a
  `data-ccmerch-customer-name` attribute and call `CustomerNameFormatter.format()`. Never
  set `textContent` directly from a raw server value if the element must respect privacy mode.
- The anonymize preference is persisted client-side in `localStorage` under the key
  `ccmerch_anonymize_names`. No server-side storage is used.
- Anonymization replaces every character in the name with `*`, preserving length. No partial
  names, initials, or truncation are permitted.
- `ccmerchApplyAnonymization()` is the **only** function that updates existing DOM name elements
  when the toggle fires. It walks all `[data-ccmerch-customer-name]` elements in the document.
- The toggle switch (`#ccmerch-anonymize-toggle`, `data-ccmerch-anonymize-toggle="1"`) is a
  visually-hidden `<input type="checkbox">` paired with a CSS `.ccmerch-history__toggle-track`
  `<span>` that produces the flip-switch appearance. It is rendered in PHP inside the Alert
  History section (`class-creator-portal-renderer.php`) so it is never wiped by JS re-renders
  of the history container on pagination.

### Changelog System

The Creator Portal includes a lightweight in-page changelog modal rendered by
`Changelog_Modal_Component` (`includes/ui/components/class-changelog-modal-component.php`).

- A "Changelog" button in the portal header opens the modal.
- The modal lists plugin versions and release notes, newest first.
- Entries are stored as a static array (`$entries`) inside the component class.
- `ccmerch-utils.js` handles open/close (click trigger, X button, backdrop click, Escape key) —
  no per-page JavaScript is required.
- Styles live in `creator-portal.css` under the `.ccmerch-modal` block.

**To add a new changelog entry:** prepend a new array item to `$entries` in
`Changelog_Modal_Component`. Do not modify any other file.

**Future compatibility:** the static array can be replaced with a database-backed or
API-driven feed without changing the component's public API or any caller.

---

### Customer Username Checkout Field

An optional WooCommerce checkout field that lets customers enter their streaming or community
username during checkout. The value is saved as WooCommerce order meta and exposed as the
`$UserName` template variable in all overlay types.

**The feature is disabled by default.** Enable it in the plugin Settings page under "Checkout":
- **Enable Customer Username Field** — checkbox, default off.
- **Checkout Field Label** — text input, default `'Stream Username'`.

**`$UserName` vs `$FirstName`:**
- `$FirstName` comes from WooCommerce billing details (not editable by the customer at checkout).
- `$UserName` comes from the optional checkout field (only present when the feature is enabled).
- Both variables are independent; both can appear in the same alert template simultaneously.
- When the feature is disabled or a customer leaves the field blank, `$UserName` resolves to
  `'Anonymous'` — the variable always produces a displayable string.

**Rules that must never be violated:**

- `Customer_Username_Field::META_KEY = '_ccmerch_username'` is the **only** order meta key used
  for the stream username. No other class may store or read the username under a different key.
- `Customer_Username_Field::save_field()` is the **only** place that writes to this meta key.
  No other class may call `update_post_meta()` with `META_KEY`.
- `Customer_Username_Resolver::register_on_engine()` is the **only** place that registers the
  `$UserName` template variable. It must be called on every `Overlay_Template_Engine` instance
  that renders alert output (global overlay engine, creator overlay engine, replay engine).
- The empty-to-`'Anonymous'` fallback is enforced at two independent layers: in `save_field()`
  at checkout time, and in the resolver callback for any order where meta is absent. Both layers
  are required.
- `Customer_Username_Resolver` is a pure static utility — it must never be instantiated and
  must never register WordPress hooks of its own.

---

## Existing Folder Structure

```
MerchAlert/
├── CLAUDE.md                          ← This file
└── ccmerch-alert/                     ← Plugin root
    ├── ccmerch-alert.php              ← Bootstrap: constants, version check, activation hook, plugins_loaded
    ├── uninstall.php                  ← Cleanup: deletes all plugin options on uninstall
    ├── readme.txt                     ← WordPress.org plugin readme
    ├── assets/
    │   ├── css/admin.css              ← Admin-only CSS (grid, cards, status, recent orders, overlay admin)
    │   ├── css/overlay.css            ← Standalone overlay page CSS (no WordPress theme)
    │   ├── js/admin.js                ← Admin-only JS (dashboard polling, countdown, filter tabs)
    │   ├── js/overlay.js              ← Overlay browser-source JS (poll + animate)
    │   ├── js/ccmerch-utils.js        ← Shared utilities (clipboard, AJAX helpers, CustomerNameFormatter, etc.)
    │   ├── js/ccmerch-fonts.js        ← Shared Google Fonts system (FontPreview lazy-loader, FontSelector widget)
    │   └── js/layout-editor.js        ← Layout Editor drag-and-drop canvas
    └── includes/
        ├── class-plugin.php           ← Central orchestrator (singleton)
        ├── class-settings.php         ← WordPress Settings API + Options API wrapper
        ├── admin/
        │   ├── class-admin.php        ← Admin menu registration + page renderers
        │   ├── class-assets.php       ← CSS/JS enqueueing (scoped to plugin page only)
        │   ├── class-dashboard-orders.php  ← Recent Orders dashboard section renderer
        │   ├── class-dashboard-ajax.php    ← AJAX handler for dashboard sync
        │   ├── class-overlay-admin.php     ← Overlay submenu page renderer
        │   ├── class-layout-admin.php      ← Layout Editor submenu page renderer
        │   ├── class-translations-admin.php ← Translation Manager submenu page (view/edit/reset all UI strings)
        │   └── class-fonts-admin.php       ← Font Library submenu page (sync catalog, approve/disable fonts)
        ├── engine/
        │   ├── class-alert-context.php           ← Immutable event value object
        │   ├── class-alert-registry.php          ← Typed store for all engine components
        │   ├── class-alert-engine.php            ← Boot + dispatch logic
        │   ├── class-alert-event.php             ← Immutable per-item alert snapshot (routing + product data)
        │   ├── class-alert-event-installer.php   ← dbDelta table creation (loaded early in bootstrap)
        │   ├── class-alert-event-repository.php  ← Queue storage: enqueue + get_pending_for_overlay
        │   ├── class-alert-router.php            ← Per-item routing engine (order item → creator queue)
        │   └── contracts/
        │       ├── interface-trigger.php      ← Contract for event sources
        │       ├── interface-condition.php    ← Contract for business-logic gates
        │       ├── interface-filter.php       ← Contract for entity-based scoping
        │       ├── interface-action.php       ← Contract for alert responses
        │       └── interface-channel.php      ← Contract for notification delivery
        ├── integrations/
        │   └── class-woocommerce.php      ← WC detection, status labels, inactive notice
        ├── models/
        │   └── class-orders.php           ← WC order data access (placeholder)
        ├── order-log/
        │   ├── class-order-installer.php  ← dbDelta table creation (loaded early in bootstrap)
        │   ├── class-order-log-entry.php  ← Read-model value object: one logged order
        │   ├── class-order-log-item.php   ← Read-model value object: one logged line item
        │   ├── class-order-repository.php ← All SQL for ccmerch_order_log tables
        │   └── class-order-logger.php     ← WC hook subscriber; writes to repository
        └── overlay/
    │       ├── class-overlay-settings.php        ← Overlay option keys, field registration, sanitization
    │       ├── interface-overlay-settings-contract.php ← Interface for Overlay_Settings + Creator_Overlay_Profile
    │       ├── class-overlay-config-renderer.php  ← Shared form body renderer (appearance fields, designer, audio)
    │       ├── class-overlay-template-engine.php  ← $Variable placeholder substitution
    │       ├── class-overlay-renderer.php         ← Full page + alert fragment HTML output
    │       └── class-overlay-controller.php       ← Rewrite rule, template_redirect, AJAX, admin-post
    ├── creators/
    │   ├── class-creator-installer.php            ← dbDelta table creation (loaded early in bootstrap)
    │   ├── class-creator-profile.php              ← Immutable value object: one creator profile
    │   ├── class-creator-repository.php           ← All SQL for creator profile tables
    │   ├── class-creator-overlay-repository.php   ← All SQL for creator overlay settings table
    │   ├── class-creator-overlay-profile.php      ← Implements Overlay_Settings_Contract; per-creator overlay settings
    │   ├── class-creator-service.php              ← Business-logic layer (uniqueness, WC gating, tokens, routing)
    │   ├── class-creator-portal-controller.php    ← Rewrite rule, query var, template_redirect routing
    │   ├── class-creator-portal-renderer.php      ← Standalone HTML portal + overlay config UI
    │   ├── class-creator-overlay-controller.php   ← Rewrite rule, nopriv poll, portal test, admin test/replay
    │   └── class-creator-save-service.php         ← AJAX save + generate-ID for Creator Portal (nopriv + priv)
    ├── auth/
    │   └── class-auth-context.php                 ← Dual-context AJAX auth (creator token | admin nonce+cap)
    ├── checkout/
    │   ├── class-customer-username-field.php      ← Optional WooCommerce checkout field; saves stream username as order meta
    │   └── class-customer-username-resolver.php   ← Registers $UserName template variable on Overlay_Template_Engine instances
    ├── audio/
    │   ├── class-audio-upload-component.php       ← Shared audio upload UI widget (admin + portal)
    │   └── class-audio-upload-controller.php      ← AJAX upload/delete for creator portal + admin
    ├── fonts/
    │   ├── class-google-fonts-installer.php       ← dbDelta table creation for ccmerch_google_fonts
    │   ├── class-google-fonts-provider.php        ← Google Fonts API sync + catalog DB access
    │   └── class-font-library.php                 ← Approved-font read layer; guards creator visibility
    ├── layout/
    │       └── (empty — layout data merged into Overlay_Settings as FIELD_LAYOUT sub-key in 2.0.0)
    ├── releases/
    │   ├── class-release-installer.php            ← dbDelta table creation for ccmerch_releases
    │   ├── class-release.php                      ← Immutable value object: one release entry
    │   ├── class-feature-tag-registry.php         ← Predefined feature tag slugs + labels
    │   └── class-release-repository.php           ← All SQL for ccmerch_releases table
    ├── api/
    │   └── class-releases-controller.php          ← REST API: GET /wp-json/ccmerch/v1/releases
    ├── translations/
    │   ├── class-string-registry.php              ← Boot-time string key registry (no DB reads)
    │   ├── class-translation-store.php            ← Lazy-loading persistent store (ccmerch_translations option)
    │   ├── class-translation-service.php          ← Resolution façade + ccmerch_translate() backend
    │   └── class-string-definitions.php           ← Single source of all 21 registered string keys
    ├── chatbot/
    │   ├── class-chatbot-installer.php            ← dbDelta table creation for ccmerch_creator_chatbot + ccmerch_creator_channel_auth
    │   ├── class-chatbot-message-log-installer.php ← dbDelta table creation for ccmerch_chatbot_messages
    │   ├── class-chatbot-message-log-repository.php ← All SQL for ccmerch_chatbot_messages (creator+order_item → logged message)
    │   ├── class-chatbot-creator-settings.php     ← Immutable value object: one creator chatbot row
    │   ├── class-chatbot-repository.php           ← All SQL for ccmerch_creator_chatbot table
    │   ├── class-chatbot-scope-manager.php        ← Static scope registry: required/optional Twitch scopes; reauth detection
    │   ├── class-twitch-auth-service.php          ← Twitch OAuth 2.0 flow; encrypted token storage; refresh
    │   ├── class-twitch-chat-service.php          ← Sends messages via Twitch Helix chat API; health monitor integration
    │   ├── interface-chat-transport.php           ← ChatTransportInterface: send() + get_transport_label()
    │   ├── class-helix-chat-transport.php         ← HelixChatTransport: production adapter wrapping Twitch_Chat_Service
    │   ├── class-chatbot-settings-service.php     ← Business-logic façade: connected?, template defaults, channel lookup
    │   ├── class-chatbot-message-service.php      ← WC order hook (priority 20); channel auth gate; routes to per-creator chat sends
    │   ├── class-chatbot-portal-controller.php    ← Creator portal AJAX: save settings, preview message
    │   ├── class-chatbot-admin.php                ← Admin submenu: Bot Connection, Channel Mapping, Runtime Status, Channel Auth Overview, OAuth Scopes
    │   ├── class-chatbot-health-monitor.php       ← Tracks bot status, token refresh history, per-channel message stats
    │   ├── class-chatbot-token-manager.php        ← Proactive token expiry tracking; transient-cached validity check
    │   ├── class-chatbot-runtime.php              ← WP-Cron orchestrator; schedules hourly token maintenance
    │   ├── class-creator-channel-auth.php         ← Immutable value object: one creator channel authorization record
    │   ├── class-creator-channel-auth-repository.php ← All SQL for ccmerch_creator_channel_auth; AES-256-CBC token encryption
    │   └── class-creator-channel-auth-service.php ← Creator-side Twitch OAuth flow; AJAX endpoints for auth URL + revoke
    └── ui/
            ├── class-component-registry.php           ← Static registry: maps string IDs to render callables
            ├── class-asset-loader.php                 ← Single source of truth for all plugin asset URLs + enqueue helpers
            └── components/
                    ├── class-test-controls-component.php  ← Shared Test Controls card (admin + portal)
                    ├── class-audio-upload-component.php   ← Shared audio upload widget (admin + portal)
                    ├── class-changelog-modal-component.php ← Changelog button + modal; body fetched from REST API
                    └── class-font-loader-component.php    ← Builds Google Fonts CSS2 URLs + link tags for overlay injection
```

---

## Existing Classes

### `CCMerchAlert\Plugin` (`includes/class-plugin.php`)

**Responsibility:** Central orchestrator. Loads all module files in the correct order, instantiates every
module with its dependencies, and exposes shared instances via accessor methods.

**Accessors:** `settings()`, `registry()`, `engine()`, `woocommerce()`, `orders()`, `order_repository()`, `admin()`.

**Must never:** Contain business logic. Must never query the database. Must never render HTML.
Must never grow beyond orchestration.

---

### `CCMerchAlert\Settings` (`includes/class-settings.php`)

**Responsibility:** Owns all option keys, page slug, and section IDs as `public const` constants.
Registers the option, section, and fields with the WordPress Settings API on `admin_init`.
Provides `get(key)`, `set(key, value)`, `all()`, and `static delete_all()`.

**Constants:**
- `OPTION_KEY = 'ccmerch_alert_settings'` — single serialised array in wp_options
- `PAGE = 'ccmerch-alert-settings'` — must match the submenu slug in Admin
- `SECTION_GENERAL = 'ccmerch_alert_general'`
- `FIELD_ENABLE_ALERTS = 'enable_alerts'`
- `FIELD_REFRESH_INTERVAL = 'refresh_interval'` — dashboard auto-refresh interval, stored in seconds; sanitized from a number + unit (seconds/minutes) form pair; default 15; clamped to [5, 3600]

**Must never:** Render admin pages. Must never contain routing or menu logic.
Must never store keys that belong to a different module.

---

### `CCMerchAlert\Admin\Admin` (`includes/admin/class-admin.php`)

**Responsibility:** Registers the top-level admin menu and the Settings submenu via `admin_menu`.
Renders `render_main_page()` (dashboard with 4 status cards + Recent Orders section) and
`render_settings_page()` (settings form shell; field HTML delegated to Settings API).
Exposes `get_page_hook()` for the Assets module. Delegates Recent Orders rendering to `Dashboard_Orders`.

**Constructor:** `(WooCommerce, Settings, Orders, Dashboard_Orders)`

**Must never:** Enqueue CSS or JS (that is Assets' job). Must never contain business logic.
Must never query the database directly. Must never grow to handle multiple subsystems.

---

### `CCMerchAlert\Admin\Assets` (`includes/admin/class-assets.php`)

**Responsibility:** Enqueues `assets/css/admin.css` and `assets/js/admin.js` on the plugin's admin page
only, by comparing `$hook_suffix` to `Admin::get_page_hook()` on `admin_enqueue_scripts`.
After enqueueing the script, calls `wp_localize_script()` to inject `ccmerchDashboard` config
(ajaxUrl, nonce, refreshInterval, initialTime) into the page. `refreshInterval` is read in seconds
via `Dashboard_Ajax::get_polling_interval()` — not hardcoded.

**Constructor:** `(Admin, Dashboard_Ajax)`.

**Must never:** Output HTML. Must never contain business logic. Must never enqueue globally.

---

### `CCMerchAlert\Engine\Alert_Context` (`includes/engine/class-alert-context.php`)

**Responsibility:** Immutable value object representing a single alert event. Created by a trigger
when its underlying WooCommerce hook fires. Carries `trigger_id`, `payload` (arbitrary event data),
and `fired_at` (Unix timestamp; defaults to `\time()` on first access when constructed as 0).

**API:** `trigger_id(): string`, `payload(): array`, `fired_at(): int`, `get(key, default): mixed`.

**Must never:** Be mutated after construction. Must never contain dispatch logic.
Must never reference the engine or registry. One instance per event.

---

### `CCMerchAlert\Engine\Alert_Registry` (`includes/engine/class-alert-registry.php`)

**Responsibility:** Typed key-value store for all engine components. Components are keyed by their `id()`
return value and overwrite on duplicate. Stores four typed arrays: triggers, channels, conditions, filters.
Provides registration methods (`register_trigger`, `register_channel`, `register_condition`,
`register_filter`) and both bulk (`triggers()`, `channels()`, etc.) and single-item lookups
(`trigger(id)`, `channel(id)`, etc., returning null if absent).

**Must never:** Contain dispatch logic. Must never instantiate components. Must never persist to storage.

---

### `CCMerchAlert\Engine\Alert_Engine` (`includes/engine/class-alert-engine.php`)

**Responsibility:** Boot and dispatch. `init()` fires `ccmerch_alert_init` (registration window) then
calls `register([$this, 'dispatch'])` on every registered trigger. `dispatch(Alert_Context)` fires
`ccmerch_alert_dispatch` and will (in a future iteration) evaluate saved alert configurations against
the context and route to channels.

**Must never:** Store alert configurations (future storage layer responsibility).
Must never hold a reference to itself inside a trigger (use callable injection instead).

---

### `CCMerchAlert\Engine\Alert_Event` (`includes/engine/class-alert-event.php`)

**Responsibility:** Immutable value object representing one fully-resolved alert event. Created by
`Alert_Router` for each WooCommerce order line item that matches a creator's category assignment.
All routing data (creator_id, creator_uuid, overlay_id) and product data (product_id, variation_id,
product_name, image, categories, quantity) are resolved once at creation time and stored verbatim.

**Design principle:** "Resolve once. Store once. Render many." The overlay poll endpoint reads
`Alert_Event` rows and renders them directly — no routing, no category lookups, no WooCommerce API
calls at render time. Cross-creator contamination is impossible: each event stores the exact
overlay_id it belongs to, and `Alert_Event_Repository::get_pending_for_overlay()` filters strictly
by that value.

**Must never:** Be mutated after construction. Must never trigger WooCommerce or WordPress API calls.
Must never be created by anything other than `Alert_Router`. One instance per order line item.

---

### `CCMerchAlert\Engine\Alert_Event_Installer` (`includes/engine/class-alert-event-installer.php`)

**Responsibility:** Creates and upgrades the `{prefix}ccmerch_alert_events` table via `dbDelta()`.
Called via `register_activation_hook()` in the bootstrap and via `maybe_install()` on `plugins_loaded`
so file-only deployments (no reactivate cycle) receive the schema automatically.

**Constants:** `DB_VERSION_KEY = 'ccmerch_alert_events_db_version'`, `DB_VERSION = '1.0'`.

**Load note:** Loaded in `ccmerch-alert.php` AND in `Plugin::load_files()` (double-loading is safe —
PHP `require_once` is idempotent). Follows the same `activate()` + `maybe_install()` pattern as
`Creator_Installer`.

**Must never:** Run outside of an activation or version-mismatch context.
Must never be used for day-to-day queries.

---

### `CCMerchAlert\Engine\Alert_Event_Repository` (`includes/engine/class-alert-event-repository.php`)

**Responsibility:** All SQL for the `{prefix}ccmerch_alert_events` table.

**Write API:** `enqueue(array $data): bool` — inserts one fully-resolved alert event. Called by
`Alert_Router` once per matched order item.

**Read API:** `get_pending_for_overlay(string $overlay_id, int $since_timestamp): Alert_Event[]` —
returns events for the given overlay created after the timestamp, ordered oldest-first (ASC) so the
JS queue plays in purchase sequence without client-side reversal. Capped at 50 per poll.

**Storage conventions (match Order_Log_Item):**
- `variation_id == 0` → `null` on read.
- `featured_image_attachment_id == 0` → `null` on read.
- `category_term_ids` stored as JSON text; decoded to `int[]` on read.
- Nullable profile IDs (`template_id`, `audio_profile_id`, `animation_profile_id`) stored as
  `NULL`; reserved for future per-creator profile assignments.

**Must never:** Contain business or routing logic.
Must never perform WooCommerce API calls. Must never be called from admin rendering classes.

---

### `CCMerchAlert\Engine\Alert_Router` (`includes/engine/class-alert-router.php`)

**Responsibility:** Routes each WooCommerce order item to its creator's alert event queue immediately
after the order is saved to the Order Log. Called by `Order_Logger` via `process_order()`.

**Per-item routing algorithm (in `process_item()`):**
1. Iterate the item's `category_ids` in order.
2. Call `Creator_Repository::find_by_category_term_id()` on each. First non-null result wins.
3. If no creator matched, skip the item silently — it belongs to the global overlay or no overlay.
4. Fetch the creator's overlay row via `Creator_Overlay_Repository::find_by_creator_id()`.
5. If the overlay_id is empty (not yet generated), skip — OBS URL not set up.
6. Call `Alert_Event_Repository::enqueue()` with the fully-resolved snapshot.

**Category matching rule:** First matching category wins. If a product has multiple categories
mapped to different creators, only ONE Alert_Event is created (first match). This prevents duplicate
alerts for multi-category products. Future versions can add explicit priority rules.

**Constructor:** `(Creator_Repository, Creator_Overlay_Repository, Alert_Event_Repository)`.

**Must never:** Be called from the overlay poll path. Must never perform WooCommerce API calls
(all product data arrives pre-resolved in the `$items_data` array from `Order_Logger`).
Must never render HTML. Must never query the Order Log.

---

### `CCMerchAlert\Engine\Contracts\Trigger_Interface` (`includes/engine/contracts/interface-trigger.php`)

**Contract:** `id(): string`, `label(): string`, `register(callable $dispatch): void`.

`register()` receives the engine's `dispatch` method as a callable. The trigger stores it and calls it
when the underlying WordPress/WooCommerce hook fires, passing an `Alert_Context` instance.
This avoids a circular dependency (trigger holding an engine reference).

---

### `CCMerchAlert\Engine\Contracts\Condition_Interface` (`includes/engine/contracts/interface-condition.php`)

**Contract:** `id(): string`, `label(): string`, `evaluate(Alert_Context $context): bool`.

Business-logic gate. All conditions on an alert must return `true` for the alert to proceed to dispatch.

---

### `CCMerchAlert\Engine\Contracts\Filter_Interface` (`includes/engine/contracts/interface-filter.php`)

**Contract:** `id(): string`, `label(): string`, `passes(Alert_Context $context): bool`.

Entity-based scoping. Filters narrow which products, customers, or order types an alert applies to,
applied before conditions.

---

### `CCMerchAlert\Engine\Contracts\Action_Interface` (`includes/engine/contracts/interface-action.php`)

**Contract:** `id(): string`, `label(): string`, `execute(Alert_Context $context): void`.

Base for anything that happens when an alert fires. All channels implement this interface.

---

### `CCMerchAlert\Engine\Contracts\Channel_Interface` (`includes/engine/contracts/interface-channel.php`)

**Contract:** Extends `Action_Interface`. Adds `send(Alert_Context $context): void`.

Channels are specialised actions that deliver notifications. `execute()` is called by the engine pipeline;
`send()` is available for direct invocation (connection testing, template preview). This hierarchy means
channels appear in generic action lists and can also be discovered specifically as delivery mechanisms.

---

### `CCMerchAlert\Integrations\WooCommerce` (`includes/integrations/class-woocommerce.php`)

**Responsibility:** Single source of truth for WooCommerce detection. `is_active()` checks via
`\class_exists('WooCommerce')`. `connection_label()` returns translated status strings.
`render_inactive_notice()` outputs an admin notice when WC is absent (guarded by `activate_plugins`
capability). Attaches `admin_notices` hook only when WC is absent and `is_admin()` is true.

**Must never:** Run WooCommerce order or product queries (that belongs in Models).
Must never contain admin page rendering. Must never be bypassed by other modules calling WC directly.

---

### `CCMerchAlert\Models\Orders` (`includes/models/class-orders.php`)

**Responsibility:** Data access layer for WooCommerce orders relevant to alert monitoring.
Currently a placeholder: `get_monitored_count(): int` returns 0. Future: attach WooCommerce
order hooks and run queries here. Depends on `WooCommerce::is_active()` before attaching any hooks.

**Must never:** Contain admin rendering. Must never register menus or enqueue assets.
Must never bypass the WooCommerce integration module.

---

### `CCMerchAlert\OrderLog\Order_Installer` (`includes/order-log/class-order-installer.php`)

**Responsibility:** Creates and upgrades the two Order Log database tables via `dbDelta()`.
Called once via `register_activation_hook()` in the bootstrap. Stores the installed schema version
in `ccmerch_alert_db_version` option. Increment `DB_VERSION` constant whenever the schema changes.

**Tables created:**
- `{prefix}ccmerch_order_log` — one row per logged order (order_id has UNIQUE KEY)
- `{prefix}ccmerch_order_log_items` — one row per line item; linked to log table via log_id

**Load note:** Loaded in `ccmerch-alert.php`, NOT in `Plugin::load_files()`. This is intentional:
activation hooks must be registered before `plugins_loaded` fires.

**Must never:** Run outside an activation context. Must never be used for day-to-day queries.

---

### `CCMerchAlert\OrderLog\Order_Log_Entry` (`includes/order-log/class-order-log-entry.php`)

**Responsibility:** Immutable read-model value object for a single logged order. Populated by
`Order_Repository::get_recent()`. Carries: `id`, `order_id`, `order_number`, `order_date`
(`\DateTimeImmutable`, UTC), `first_name`, `items` (`Order_Log_Item[]`).

**Must never:** Be written to directly. Must never call WooCommerce or WordPress APIs.

---

### `CCMerchAlert\OrderLog\Order_Log_Item` (`includes/order-log/class-order-log-item.php`)

**Responsibility:** Immutable read-model value object for a single logged line item. Properties:
`id`, `log_id`, `product_id`, `variation_id` (null = simple product), `product_name`, `product_sku`,
`quantity`, `image_attachment_id` (null = no image), `category_ids` (int[]).

**Storage conventions (enforced by repository):**
- `variation_id` and `image_attachment_id` stored as 0 in DB; converted to null on read.
- `category_ids` stored as JSON text in DB; decoded to int[] on read.

**Must never:** Store URLs, category names, or variation attribute labels — only IDs.

---

### `CCMerchAlert\OrderLog\Order_Repository` (`includes/order-log/class-order-repository.php`)

**Responsibility:** All SQL for the two Order Log tables. Write API: `exists(order_id)` and
`save(order_data, items_data)`. Read API: `get_recent(limit)`, `get_filtered(filter, limit)`,
`get_since(since_timestamp, filter)`, `count_today()`, `count_filtered(filter)`.
Private helpers: `filter_range(filter)` (UTC date range), `rows_to_entries(rows)` (row→value-object),
`get_items_for_log(log_id)` (items for one entry).
Filter constants: `FILTER_TODAY`, `FILTER_LAST_24`, `FILTER_ALL`.

**Duplicate prevention:** `exists()` checks before insert. UNIQUE KEY on `order_id` is secondary safety net.

**Must never:** Be called from admin rendering classes directly — go through `Dashboard_Orders`.
Must never contain display or formatting logic.

---

### `CCMerchAlert\OrderLog\Order_Logger` (`includes/order-log/class-order-logger.php`)

**Responsibility:** Subscribes to WooCommerce order status hooks and persists new orders to the log.
Hooks: `woocommerce_order_status_processing` and `woocommerce_order_status_completed`.
Calls `Order_Repository::exists()` first; returns early if already logged.
Reads order data from `WC_Order` object, item data from `WC_Order_Item_Product`, and resolves
product SKU, featured image ID, category IDs, and WC order item ID (`item_id`) before writing to
the repository. After a successful `Order_Repository::save()`, calls
`Alert_Router::process_order()` to fan out one `Alert_Event` per matched order item.

**Constructor:** `(WooCommerce, Order_Repository, ?Alert_Router)` — `Alert_Router` is optional
(`null` default) for backwards compatibility; always injected in production.

**Must never:** Render HTML. Must never query the log tables directly (use repository).
Must never attach hooks when WooCommerce is inactive.
Must never call `Alert_Router::process_order()` when `Order_Repository::save()` returns false.

---

### `CCMerchAlert\Admin\Dashboard_Orders` (`includes/admin/class-dashboard-orders.php`)

**Responsibility:** Renders the "Recent Orders" section on the plugin dashboard. Reads from
`Order_Repository` and resolves all display data at render time:
- Thumbnails via `wp_get_attachment_image()` from stored attachment ID.
- Category names via `get_term()` from stored category term IDs.
- Variation attributes via `wc_get_product()` + `get_variation_attributes()` + `get_term_by()`.

Exposes `count_today(): int` (today-count delegation) and `render_orders_html(entries): string`
(output-buffer render for AJAX responses).

Instantiated admin-side only. Injected into `Admin` and `Dashboard_Ajax` constructors.

**Must never:** Write to the database. Must never store resolved display data.
Must never be instantiated on the front end.

---

### `CCMerchAlert\Admin\Overlay_Admin` (`includes/admin/class-overlay-admin.php`)

**Responsibility:** Registers and renders the CCMerch Alert → Overlay admin submenu page. Displays
the browser-source URL with Copy / Open buttons, the Generate New Overlay ID form, the "Preview Latest
Order" button (fires `ccmerch_overlay_preview` AJAX, renders result inline), and the overlay settings
form rendered via `do_settings_sections()`. Enqueues `admin.css` on its own page hook. Inline
`<script>` in the page handles Copy and Preview interactions — no separate JS file.

**Constructor:** `(Overlay_Settings, Order_Repository, Overlay_Renderer)`.

**Must never:** Write to the database. Must never output overlay page HTML.
Must never duplicate sanitization logic (that belongs in Overlay_Settings).

---

### `CCMerchAlert\Overlay\Overlay_Settings` (`includes/overlay/class-overlay-settings.php`)

**Responsibility:** Stores all overlay-specific options under `ccmerch_overlay_settings` in wp_options.
Registers the option, settings sections, and field definitions with the WordPress Settings API on
`admin_init`. Provides generic field renderer methods (`render_textarea_field`, `render_text_field`,
`render_number_field`, `render_color_field`, `render_select_field`) called by the Settings API.
`generate_id()` creates a 32-char hex ID via `bin2hex(random_bytes(16))` and persists it.
`get_overlay_url()` returns the public browser-source URL or empty string if no ID exists.

**Shared Layout Editor helper:**
`public static render_layout_hidden_inputs(array $layout): void` — outputs the hidden `<input>`
fields for all element positions (x, y, w, h, visible, lock_aspect, border_radius) and canvas
options (snap_grid, grid_size) used by the Layout Editor JS. This is the **single implementation**
called by both `Overlay_Admin` (global overlay) and `Creator_Portal_Renderer` (creator portal).
No other class should duplicate this HTML fragment.

**Option key:** `ccmerch_overlay_settings` (separate from the plugin's main `ccmerch_alert_settings`).

**Settings fields:**
- `overlay_id` — NOT in the form; set only via `generate_id()`; preserved by sanitize on form saves.
- `template` — textarea; default `'$Product was purchased by $FirstName!'`
- `font_family`, `font_size`, `font_weight`, `text_color`, `text_shadow`
- `image_size`
- `bg_color`, `bg_opacity` (0–100), `border_radius`, `padding`
- `anim_duration` (0–5000 ms), `display_duration` (1000–30000 ms)
- `layout` — nested array of element positions managed by the Layout Editor canvas

**double-sanitize safety:** The sanitize callback is idempotent for all appearance fields.
`overlay_id` is taken from `$input` if present (second-pass call from `update_option`) or from
the currently stored value (first-pass call from the form submission).

**Must never:** Render admin pages. Must never contain routing or AJAX logic.
Must never: have a second implementation of `render_layout_hidden_inputs()` anywhere in the codebase.

---

### `CCMerchAlert\Overlay\Overlay_Config_Renderer` (`includes/overlay/class-overlay-config-renderer.php`)

**Responsibility:** Shared static renderer for the overlay configuration form body. Both `Overlay_Admin`
(global overlay) and `Creator_Portal_Renderer` (per-creator overlay) call these methods so the
Overlay Designer, hidden appearance fields, and Audio section have a single PHP implementation.

**Methods:**
- `render_appearance_fields(values, option_key)` — outputs the hidden `#ccmerch-overlay-appearance-fields` div with all appearance inputs (template, secondary_template, font, color, timing, etc.). Not visible on screen; read/written by `layout-editor.js` via `getFormVal()` / `applyAppearanceProp()`.
- `render_designer(layout)` — heading, snap-to-grid toolbar, `.ccmerch-layout-editor` two-column container (canvas + properties panel), and `Overlay_Settings::render_layout_hidden_inputs()` for position inputs.
- `render_audio_section(values, option_key, with_media_picker, allowed_term_ids)` — full Audio card: Enable checkbox, Default Sound field, category sound mapping table. `with_media_picker=true` (admin): `wp.media()` Select/Remove buttons. `with_media_picker=false` (portal): number inputs for attachment IDs. `allowed_term_ids=null` (admin): all WC product categories shown. `allowed_term_ids=int[]` (portal): only the creator's assigned categories shown — audio isolation enforcement.
- `resolve_preview_data(Order_Repository)` — canvas preview data from `get_recent(1)`; falls back to mock data. No WooCommerce queries. Shared by admin and portal.

**Key rule:** The form option key prefix is always `Overlay_Settings::OPTION_KEY` in both contexts. No other class may implement these form sections — this is the single source of truth for the overlay configuration form body.

**Must never:** Be instantiated. Must never register WordPress hooks. Must never contain business logic or database access (except `resolve_preview_data`). Must never be duplicated.

---

### `CCMerchAlert\Overlay\Overlay_Template_Engine` (`includes/overlay/class-overlay-template-engine.php`)

**Responsibility:** Replaces `$Variable` placeholders in an overlay template string with resolved values
from an `Order_Log_Entry`. Each variable is backed by a resolver callable registered via `register()`.
New variables can be added without modifying any existing code.

**`render(string $template, Order_Log_Entry $entry): string`** — resolves all registered variables and
HTML-escapes each resolved value before substitution. The returned string is safe for use as innerHTML.

**Built-in variables:** `$FirstName`, `$OrderNumber`, `$Product`, `$Quantity`, `$Category`, `$Variation`.
- `$Category` — resolves term IDs via `get_term()` at render time; returns comma-separated names.
- `$Variation` — resolves via `wc_get_product()` if WooCommerce is active; returns empty string otherwise.

**Extension:** call `register('$NewVar', fn(Order_Log_Entry $e): string => ...)` on the engine instance
before `render()` to add custom variables without modifying this class.

**Must never:** Output HTML. Must never read from the database.

---

### `CCMerchAlert\Overlay\Overlay_Renderer` (`includes/overlay/class-overlay-renderer.php`)

**Responsibility:** Produces all HTML output for the overlay module.
- `render_page(): never` — outputs a complete standalone HTML document (`<!DOCTYPE html>` through `</html>`)
  injecting CSS custom properties from settings, then calls `exit`. Called inside `template_redirect`.
- `render_alert(Order_Log_Entry $entry): array{image_html, text_html}` — builds the two HTML fragments
  returned in AJAX payloads for poll and preview responses.

Settings are injected as CSS custom properties in a `<style>` block and as a `ccmerchOverlay` JS config
object so the page requires no secondary AJAX calls to read appearance settings.
Background color + opacity are combined into an `rgba()` value via `hex_to_rgba()`.
Image position is converted to a CSS `flex-direction` value via `flex_direction()`.

**Must never:** Read from the database. Must never contain routing or AJAX logic.

---

### `CCMerchAlert\Overlay\Overlay_Controller` (`includes/overlay/class-overlay-controller.php`)

**Responsibility:** Handles all overlay routing, AJAX endpoints, and admin actions.

**Constants:**
- `QUERY_VAR = 'ccmerch_overlay_id'` — WordPress query variable carrying the ID from the rewrite rule.
- `REWRITE_RULE` / `REWRITE_QUERY` — the `^ccmerch-alert/overlay/([^/]+)/?$` rewrite pair.
- `AJAX_POLL = 'ccmerch_overlay_poll'` — nopriv action; overlay ID is the access token.
- `AJAX_PREVIEW = 'ccmerch_overlay_preview'` — logged-in only; nonce-protected.
- `NONCE_PREVIEW = 'ccmerch_overlay_preview'` — nonce action for preview requests.
- `ACTION_GENERATE_ID = 'ccmerch_generate_overlay_id'` — `admin_post_` action for the generate form.

**Hooks registered:**
- `init` → `register_rewrite_rules()`: adds the rewrite rule on every request.
- `query_vars` → `register_query_vars()`: exposes `ccmerch_overlay_id`.
- `template_redirect` → `maybe_render_overlay()`: validates overlay ID, renders page or 404s.
- `wp_ajax_nopriv_ccmerch_overlay_poll` + `wp_ajax_ccmerch_overlay_poll` → `handle_poll()`.
- `wp_ajax_ccmerch_overlay_preview` → `handle_preview()`.
- `admin_post_ccmerch_generate_overlay_id` → `handle_generate_id()`.

**Poll security:** No nonce (OBS browser sources cannot carry nonces). The overlay ID (32 random hex
chars) serves as the sole access token. `hash_equals()` prevents timing attacks.

**Constructor:** `(Overlay_Settings, Order_Repository, Overlay_Renderer)`.
Instantiated always (not inside `is_admin()`) so the rewrite rule and poll endpoint are active on
front-end requests where the overlay page and OBS browser source operate.

**Must never:** Output HTML directly. Must never query the database. Must never render admin pages.

---

### `CCMerchAlert\Admin\Dashboard_Ajax` (`includes/admin/class-dashboard-ajax.php`)

**Responsibility:** Handles the `wp_ajax_ccmerch_dashboard_sync` endpoint. Verifies nonce and
`manage_options` capability, reads `filter` and `last_sync_timestamp` from POST, and returns a JSON
payload containing order card HTML, the filtered order count, and a server timestamp for the next sync.

**Constants:** `NONCE_ACTION = 'ccmerch_dashboard_sync'`. (`POLLING_INTERVAL` removed — interval is now stored in `Settings::FIELD_REFRESH_INTERVAL` and read via `get_polling_interval()`.)

**`get_polling_interval(): int`** — reads `Settings::FIELD_REFRESH_INTERVAL`; called by Assets for `wp_localize_script()` and included in every AJAX response so the frontend adopts interval changes without a page reload.

**AJAX response includes `refresh_interval`** — current interval in seconds; JS updates its local state on every successful sync.

**Two modes:**
- `last_sync_timestamp > 0` → poll mode: calls `get_since()` + returns cards to prepend.
- `last_sync_timestamp == 0` → full-reload mode: calls `get_filtered()` + returns cards to replace the list.

**Constructor:** `(Order_Repository, Dashboard_Orders, Settings)`.

**Must never:** Query the database directly. Must never render HTML that is not order-card markup.
Must never run on the front end (registered via `wp_ajax_*`, not `wp_ajax_nopriv_*`).

---

## WordPress Constants

Defined in `ccmerch-alert.php` before any class is loaded:

| Constant | Value |
|---|---|
| `CCMERCH_ALERT_VERSION` | `'Beta 2.0.2'` |
| `CCMERCH_ALERT_FILE` | Absolute path to `ccmerch-alert.php` |
| `CCMERCH_ALERT_DIR` | Plugin directory path with trailing slash |
| `CCMERCH_ALERT_URL` | Plugin URL with trailing slash |

---

## IDE Diagnostics Reference

The project does not have WordPress PHP stubs installed. As a result, the IDE will show false-positive
warnings for every WordPress function call inside namespaced files.

| Code | Severity | Cause | Action |
|---|---|---|---|
| PHP0417 | Warning | IDE has no WordPress stubs | Ignore — runtime is correct |
| P1010 | Error | Same, but triggered on `\function()` form | Do NOT add `\` to WP functions |
| PHP6616 | Hint | `defined()` / `is_array()` in namespace | Add `\` to PHP built-ins only |
| PHP6612 | Hint | Missing `public` on class constants | Add `public` to all class constants |
| PHP7104 | Warning | "property declared but not used" | False positive on accessor methods |

**To permanently resolve WP function diagnostics:** install `php-stubs/wordpress-stubs` as a
Composer dev-dependency. Until then, accept PHP0417 as expected.

---

## Coding Rules

1. Prefer extending existing architecture over replacing it.
2. Never rewrite working code unless the current implementation is provably incorrect.
3. Never rename classes, files, or namespaces without a strong reason. Document it if you do.
4. Never move files without updating `Plugin::load_files()` and all `use` statements.
5. Always search for an existing class or service before creating a new one.
6. Never duplicate business logic across classes.
7. Avoid side effects. A class should do what its name says and nothing more.
8. Maintain backwards compatibility within the project at all times.
9. All echoed HTML output must be escaped with the appropriate WP function (`esc_attr`, `esc_html`,
   `esc_url`, `esc_html_e`, `wp_kses_post`). No exceptions, even for hardcoded values.
10. All public class constants must have an explicit `public` visibility modifier.

---

## Refactoring Policy

Before changing any existing code:

1. Read the entire file being modified.
2. Read every file that directly depends on it or is depended on by it.
3. Search for every usage of the class, method, or constant being changed.
4. Understand the full impact before touching anything.
5. Preserve existing behaviour. If behaviour must change, document it explicitly.
6. Make the smallest possible change that achieves the goal.
7. Prefer extension (new class, new method) over modification (editing existing behaviour).

If a refactor would affect multiple systems simultaneously, stop, explain the full scope, and wait for
confirmation before proceeding.

---

## Admin JavaScript Conventions

### Clipboard copy helper

Any admin page that needs a "Copy to Clipboard" button must use the `ccmerchCopyToClipboard(btn, url)` pattern defined inline in the page's `<script>` block. Do not call `navigator.clipboard` directly.

**Why:** The Clipboard API (`navigator.clipboard`) is `undefined` in non-secure contexts (HTTP). WordPress admin served over plain HTTP — common in local development and some hosts — causes an uncaught `TypeError` when the API is accessed without a guard.

**Pattern:**

```javascript
function ccmerchCopyToClipboard( btn, url ) {
    var originalText = btn.textContent;
    var successText  = btn.getAttribute( 'data-copied' )   || '✓ Copied!';
    var failText     = btn.getAttribute( 'data-copy-fail' ) || 'Unable to copy.';

    function onSuccess() {
        btn.textContent = successText;
        setTimeout( function() { btn.textContent = originalText; }, 2000 );
    }
    function onFailure() {
        btn.textContent = failText;
        setTimeout( function() { btn.textContent = originalText; }, 3000 );
        window.prompt( 'Copy this link manually:', url );
    }

    // Clipboard API requires secure context (HTTPS / localhost).
    if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText ) {
        navigator.clipboard.writeText( url ).then( onSuccess, onFailure );
        return;
    }

    // Legacy fallback: hidden textarea + execCommand.
    try {
        var ta = document.createElement( 'textarea' );
        ta.value = url;
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;opacity:0;';
        document.body.appendChild( ta );
        ta.focus();
        ta.select();
        var ok = document.execCommand( 'copy' );
        document.body.removeChild( ta );
        if ( ok ) { onSuccess(); } else { onFailure(); }
    } catch ( e ) {
        onFailure();
    }
}
```

**Button markup conventions:**

- Always pass `this` as the first argument: `onclick="ccmerchCopyToClipboard( this, '<?php echo esc_js( $url ); ?>' )"`
- Add `data-copied` attribute: the success label (e.g. `"✓ Copied!"` or `"✓ Link copied to clipboard"`)
- Add `data-copy-fail` attribute: the failure label (e.g. `"Unable to copy automatically."`)
- The helper restores the original `textContent` after 2 s (success) or 3 s (failure)

**Copy method selection:**
1. Clipboard API — used when `window.isSecureContext && navigator.clipboard?.writeText` — Chrome, Edge, Firefox, Safari on HTTPS.
2. `execCommand('copy')` fallback — used on HTTP or when Clipboard API is absent — Chrome, Edge, Firefox, Safari (all versions).
3. `window.prompt()` last resort — fires when both methods fail — the user can manually copy from the native dialog.

**Current uses:** `Overlay_Admin` page, `Creator_Admin` list page (`ccmerchCopyPortalUrl`), `Creator_Admin` edit page (`ccmerchCopyLink`), and `Creator_Portal_Renderer` (standalone page). All load `ccmerch-utils.js` from `Asset_Loader`. Per-page inline definitions are no longer permitted.

---

### `CCMerchAlert\UI\Component_Registry` (`includes/ui/class-component-registry.php`)

**Responsibility:** Static registry that maps string IDs to render callables. Any class can register a
component with `Component_Registry::register('my-id', callable)`. Any caller can invoke it with
`Component_Registry::render('my-id', ...$args)` without knowing the implementing class.

**API:** `register(id, callable)`, `render(id, ...$args)`, `has(id): bool`, `ids(): array`.

**All five shared UI components are registered in `Plugin::load_components()`:**
`browser-source-card`, `appearance-fields`, `overlay-designer`, `audio-section`, `test-controls`.

**Must never:** Contain rendering logic. Must never be instantiated. Must never throw on a valid ID.
Must never be used as a dependency-injection substitute — components are UI, not services.

---

### `CCMerchAlert\UI\Asset_Loader` (`includes/ui/class-asset-loader.php`)

**Responsibility:** Single source of truth for all plugin asset file paths. Named URL methods
(`admin_css()`, `layout_css()`, `overlay_css()`, `portal_css()`, `utils_js()`, `layout_js()`,
`overlay_js()`) return base URLs. `versioned(url)` appends `?ver=CCMERCH_ALERT_VERSION`.

**Two usage modes:**
- Admin pages: call `enqueue_overlay_config()` or `enqueue_creator_admin()` inside an
  `admin_enqueue_scripts` callback that has already verified the page hook. These are the only two
  enqueue presets; add a new preset if a new page group needs a different asset combination.
- Standalone HTML pages (Creator Portal, Overlay page): call `versioned(url())` to get a versioned
  URL, then output `<link>`/`<script>` tags manually in the HTML document.

**Rule:** No class outside `Asset_Loader` may construct an asset URL from `CCMERCH_ALERT_URL` directly.

**Must never:** Output HTML. Must never contain business logic. Must never be instantiated.

---

### `CCMerchAlert\UI\Components\Test_Controls_Component` (`includes/ui/components/class-test-controls-component.php`)

**Responsibility:** Renders the Test Controls card used on the Overlay Configuration page (admin) and
the Creator Portal (public). The card contains a heading, description, one or more action buttons,
and a shared feedback `<div>`.

**All AJAX parameters are encoded as data attributes on each button:**
- `data-ccmerch-test-action` — WordPress AJAX action name
- `data-ccmerch-auth-field` — POST field name for auth (e.g. `_ajax_nonce` or `creator_token`)
- `data-ccmerch-auth-value` — auth value (WP nonce or creator access token)
- `data-ccmerch-ajax-url` — admin-ajax.php URL
- `data-ccmerch-sending/success/error/net-error` — feedback labels

The delegated listener in `ccmerch-utils.js` catches clicks on `[data-ccmerch-test-action]` buttons
and calls `ccmerchSendTestRequest(btn)`. No per-page JavaScript is required for the AJAX interaction.

**Must never:** Register WordPress hooks. Must never contain business logic. Must never be instantiated.
Must never be rendered without `ccmerch-utils.js` loaded on the page.

---

### `CCMerchAlert\Auth\Auth_Context` (`includes/auth/class-auth-context.php`)

**Responsibility:** Resolves the authentication context for dual-context AJAX endpoints — those that
serve both the Creator Portal (token-based, no WP login) and the WordPress admin (nonce + capability).

**Two static factory methods:**
- `resolve_creator(Creator_Service $service): self` — validates `$_POST['creator_token']`, looks up
  the matching Creator_Profile, and returns a context with `creator_id` and `overlay_id` populated.
  WordPress nonces are intentionally NOT checked: the portal page may be served from a caching layer
  (invalidating nonce freshness), and creators are not WordPress users. The 256-bit access token
  provides the authentication guarantee.
- `resolve_admin(string $nonce_action): self` — calls `check_ajax_referer()` and `current_user_can()`.
  Returns a context with `creator_id = 0` and `overlay_id = ''`.

**Properties:** `context` ('creator' | 'admin'), `creator_id` (int), `overlay_id` (string).

**Error model:** Both methods call `wp_send_json_error()` with structured `{message, reason, step}`
on any failure and terminate. Callers either receive a valid context or never continue. The declared
return type is `self` because PHP cannot express "returns self OR terminates".

**Endpoint registration rule:** Creator portal AJAX actions must register BOTH `wp_ajax_nopriv_*`
AND `wp_ajax_*` hooks pointing to the same handler. The nopriv variant fires for unauthenticated
creators; the non-nopriv variant fires when a logged-in WordPress admin visits the creator portal
to test or configure it. Both routes authenticate via creator_access_token — WP session is ignored.

**Must never:** Contain business logic. Must never be instantiated directly (factory-only). Must never
check WordPress user capabilities in the creator context. Must never be bypassed — all AJAX endpoints
serving both contexts must call the appropriate factory method as their first action.

---

### `CCMerchAlert\Checkout\Customer_Username_Field` (`includes/checkout/class-customer-username-field.php`)

**Responsibility:** Registers and handles the optional customer username WooCommerce checkout field.
When `Settings::FIELD_USERNAME_ENABLED` is truthy, adds an optional text field to WooCommerce checkout
so customers can enter their streaming or community username. Saves the value as WooCommerce order meta
under `META_KEY = '_ccmerch_username'`. Stores `'Anonymous'` when the customer leaves the field blank
so the `$UserName` template variable always resolves to a displayable string.

**Constants:** `META_KEY = '_ccmerch_username'` — underscore-prefixed, treated as protected meta by WooCommerce.

**Hooks (registered only when enabled):**
- `woocommerce_checkout_fields` → `add_field(array): array` — appends the field to `$fields['order']` at priority 110.
- `woocommerce_checkout_update_order_meta` → `save_field(int, array): void` — trims, sanitizes, stores the value; empty → `'Anonymous'`.

**Constructor:** `(Settings)`.

**Must never:** Render admin pages. Must never query the database directly.
Must never be instantiated more than once.

---

### `CCMerchAlert\Checkout\Customer_Username_Resolver` (`includes/checkout/class-customer-username-resolver.php`)

**Responsibility:** Registers the `$UserName` template variable on any `Overlay_Template_Engine` instance.
Reads `_ccmerch_username` from WooCommerce order meta via `get_post_meta()`. Returns `'Anonymous'` when
the meta is absent — covering orders placed before the feature was enabled, orders that predate the
feature, and any edge cases where the field meta is missing.

**API:** `public static register_on_engine(Overlay_Template_Engine): void` — single static factory.
Must be called on every `Overlay_Template_Engine` instance used for rendering: global overlay,
creator overlay, and replay engine.

**Relationship to `$FirstName`:** `$UserName` is independent of `$FirstName`. Creators choose which
to display; both can appear in the same template simultaneously.

**Must never:** Be instantiated. Must never register WordPress hooks.
Must never be duplicated — this is the single implementation for `$UserName` resolution.

---

### `CCMerchAlert\Creators\Creator_Save_Service` (`includes/creators/class-creator-save-service.php`)

**Responsibility:** Single source of truth for all Creator Portal write operations over AJAX.
Registers and handles two endpoints: settings save and overlay ID generation. Both use `admin-ajax.php`
with both `wp_ajax_nopriv_*` and `wp_ajax_*` hooks so they work regardless of WordPress login state.

**Constants:**
- `AJAX_SAVE = 'ccmerch_creator_save_settings'` — saves `ccmerch_overlay_settings` POST data
- `AJAX_GENERATE_ID = 'ccmerch_creator_generate_overlay_id'` — generates a new 32-char hex overlay ID

**`register(): void`** — hooks both AJAX actions. Called from `Plugin::load_components()` (unconditionally,
not inside `is_admin()`) so creator endpoints are reachable on front-end requests.

**`handle_save(): never`** — authenticates via `Auth_Context::resolve_creator()`, fetches the creator's
`Creator_Overlay_Profile`, sanitizes POST data via `$profile->sanitize()` (with `get_category_ids()`
filter for audio isolation), persists via `Creator_Service::save_overlay_settings()`.

**`handle_generate_id(): never`** — authenticates via `Auth_Context::resolve_creator()`, calls
`Creator_Service::reset_overlay_id()`, returns the new overlay URL in JSON for JS DOM update.

**Constructor:** `(Creator_Service)`.

**Why this class exists (not `Creator_Overlay_Controller`):**
`Creator_Overlay_Controller` registered `admin_post_*` handlers which WordPress routes through
`admin-post.php`. Unauthenticated requests (all creators are unauthenticated from WP's perspective)
to `admin_post_{action}` without a matching `admin_post_nopriv_{action}` receive HTTP 400. Routing
save operations through `Creator_Save_Service` on `admin-ajax.php` eliminates the 400 entirely.

**Must never:** Register `admin_post_*` hooks. Must never check WP nonces for creator auth.
Must never contain rendering logic. Must never be instantiated more than once.

---

### `CCMerchAlert\Releases\Release` (`includes/releases/class-release.php`)

**Responsibility:** Immutable value object for a single plugin release entry. Carries `id`, `version`,
`stage`, `title`, `description`, `feature_tags` (`string[]` of tag slugs), `created_at`, `updated_at`.

**API:** `stage_label(): string` — maps stage slug to human-readable label (alpha→Alpha, beta→Beta,
production→Production). `to_api_array(): array` — canonical REST API shape; includes `feature_labels`
(resolved from `Feature_Tag_Registry`) alongside raw `feature_tags`.

**Must never:** Be mutated after construction. Must never contain repository or routing logic.

---

### `CCMerchAlert\Releases\Feature_Tag_Registry` (`includes/releases/class-feature-tag-registry.php`)

**Responsibility:** Predefined registry of feature tag slugs and their human-readable labels.
Tags are a closed vocabulary — no free-text tags are permitted.

**Constants:** `public const TAGS = ['alert_templates' => 'Alert Templates', ...]` — full list of
valid slugs.

**API:** `is_valid(string): bool`, `label(string): string`, `filter_valid(array): array` (strips
unknown slugs from an array before persistence).

**Must never:** Be instantiated. Must never accept free-text tags. Must never be modified at runtime.

---

### `CCMerchAlert\Releases\Release_Repository` (`includes/releases/class-release-repository.php`)

**Responsibility:** All SQL for the `{prefix}ccmerch_releases` table.

**Write API:** `create(array): int|false`, `update(int, array): bool`, `delete(int): bool`.

**Read API:** `get_all(?string $stage, ?string $feature_tag): Release[]` — stage filter in SQL;
feature-tag filter in PHP (JSON column). `get_by_id(int): ?Release`, `get_latest(): ?Release`.

**Must never:** Contain business or routing logic. Must never be called from overlay or portal
rendering paths.

---

### `CCMerchAlert\Releases\Release_Installer` (`includes/releases/class-release-installer.php`)

**Responsibility:** Creates and upgrades the `{prefix}ccmerch_releases` table via `dbDelta()`.
Follows the same `activate()` + `maybe_install()` pattern as other installers.

**Constants:** `DB_VERSION_KEY = 'ccmerch_releases_db_version'`, `DB_VERSION = '1.0'`.

**`seed_if_empty()`:** Called from `maybe_install()` on first install; inserts Beta 1.0.0 and
Beta 1.0.1 seed entries so the changelog modal is populated without manual admin entry.

**Must never:** Run outside an activation or version-mismatch context.
Must never be used for day-to-day queries.

---

### `CCMerchAlert\Api\Releases_Controller` (`includes/api/class-releases-controller.php`)

**Responsibility:** Registers and handles `GET /wp-json/ccmerch/v1/releases`.

**Constants:** `REST_NAMESPACE = 'ccmerch/v1'`, `ROUTE = '/releases'`.

**Authentication:** Public (`permission_callback => '__return_true'`). Changelog data is
informational and safe to expose without credentials.

**Query parameters:** `?stage=` (sanitize_key) and `?feature=` (sanitize_key), both optional.

**Must never:** Write to the database. Must never require authentication for GET requests.
Must never be instantiated more than once.

---

### `CCMerchAlert\Admin\Changelog_Admin` (`includes/admin/class-changelog-admin.php`)

**Responsibility:** Registers and renders the CCMerch Alert → Changelog admin submenu page.
Provides create/edit/delete for release entries. Follows the Post/Redirect/Get pattern for all
mutations.

**Constants:** `PAGE = 'ccmerch-alert-changelog'`, `ACTION_SAVE`, `ACTION_DELETE`, `NONCE_SAVE`.

**Screens:** List (all releases, newest first) and Form (create or edit a single release).

**Validation:** Version must match `/^\d+\.\d+\.\d+$/`. Stage must be in `VALID_STAGES`. Title
is required. Feature tags are filtered through `Feature_Tag_Registry::filter_valid()` before
persistence.

**Constructor:** `(Release_Repository)`.

**Must never:** Output HTML outside of the render methods. Must never query the database directly.
Must never bypass `Feature_Tag_Registry::filter_valid()` when persisting tags.

---

### `CCMerchAlert\Chatbot\Chatbot_Installer` (`includes/chatbot/class-chatbot-installer.php`)

**Responsibility:** Creates and upgrades the `{prefix}ccmerch_creator_chatbot` table via `dbDelta()`.
Follows the `activate()` + `maybe_install()` pattern used by all other installers.

**Constants:** `DB_VERSION_KEY = 'ccmerch_chatbot_db_version'`, `DB_VERSION = '1.0'`.

**Must never:** Run outside an activation or version-mismatch context. Must never be used for day-to-day queries.

---

### `CCMerchAlert\Chatbot\Chatbot_Message_Log_Installer` (`includes/chatbot/class-chatbot-message-log-installer.php`)

**Responsibility:** Creates and upgrades the `{prefix}ccmerch_chatbot_messages` table via `dbDelta()`.
Follows the `activate()` + `maybe_install()` pattern used by all other installers.

**Constants:** `DB_VERSION_KEY = 'ccmerch_chatbot_message_log_db_version'`, `DB_VERSION = '1.0'`.

**Must never:** Run outside an activation or version-mismatch context. Must never be used for day-to-day queries.

---

### `CCMerchAlert\Chatbot\Chatbot_Message_Log_Repository` (`includes/chatbot/class-chatbot-message-log-repository.php`)

**Responsibility:** All SQL for the `{prefix}ccmerch_chatbot_messages` table — one row per (creator, WooCommerce
order item) that has ever had a chatbot message generated for it, live or via replay. `order_item_id` is the
same identifier `Alert_Event` uses, so this table corresponds 1:1 to `Alert_Event` rows per creator.

**API:** `exists(creator_id, order_item_id): bool`, `record(creator_id, order_id, order_item_id, product_name): bool`
(no-op success when a row already exists; the `UNIQUE KEY creator_item (creator_id, order_item_id)` is the
durable dedup guard beneath the application-layer `exists()` check).

**Consumed exclusively by:** `Chatbot_Message_Service::send_for_events()` — see that class for the backfill
behaviour this table enables.

**Must never:** Contain business logic. Must never be called from rendering classes directly. Must never be
written to by any class other than `Chatbot_Message_Service`.

---

### `CCMerchAlert\Chatbot\Chatbot_Creator_Settings` (`includes/chatbot/class-chatbot-creator-settings.php`)

**Responsibility:** Immutable value object for a single creator's chatbot row. Carries `id`, `creator_id`,
`twitch_channel`, `enabled` (bool), and `template`.

**Must never:** Be mutated after construction. Must never contain repository or routing logic.

---

### `CCMerchAlert\Chatbot\Chatbot_Repository` (`includes/chatbot/class-chatbot-repository.php`)

**Responsibility:** All SQL for the `{prefix}ccmerch_creator_chatbot` table.

**Write API:** `save(creator_id, enabled, template): bool` — upsert on `creator_id` UNIQUE KEY.
`set_channel(creator_id, channel): bool` — upsert only the channel column.

**Read API:** `find_by_creator_id(creator_id): ?Chatbot_Creator_Settings`, `get_all(): array`.

**Must never:** Contain business logic. Must never be called from rendering classes directly.

---

### `CCMerchAlert\Chatbot\Twitch_Auth_Service` (`includes/chatbot/class-twitch-auth-service.php`)

**Responsibility:** Manages the Twitch OAuth 2.0 authorization code flow for the central bot account.
Stores and retrieves encrypted access/refresh tokens from `wp_options`.

**Constants:** `OPTION_KEY = 'ccmerch_chatbot_twitch_tokens'`, `OPTION_APP_KEY = 'ccmerch_chatbot_twitch_app'`,
`AJAX_CALLBACK = 'ccmerch_twitch_oauth_callback'`, `OAUTH_SCOPES = 'chat:read chat:edit'`.

**Security:** Tokens encrypted with AES-256-CBC using a key derived from `wp_salt('auth')`. IV prepended
to ciphertext before `base64_encode()`. `get_tokens()` returns DECRYPTED values. `store_tokens()`
stores ENCRYPTED values. Twitch credentials (Client ID / Client Secret) are stored in `wp_options` and
never exposed to any browser context.

**OAuth state:** Stored as a transient (600 s TTL), verified via `\hash_equals()` on callback.

**Must never:** Expose credentials or raw tokens to any browser request. Must never skip state verification.
Must never be instantiated more than once.

---

### `CCMerchAlert\Chatbot\Twitch_Chat_Service` (`includes/chatbot/class-twitch-chat-service.php`)

**Responsibility:** Sends messages to Twitch channels via the Helix Chat Messages API
(`POST /helix/chat/messages`). Handles token refresh on 401 with single-retry loop prevention.

**Constants:** `CHAT_URL = 'https://api.twitch.tv/helix/chat/messages'`,
`BROADCASTER_TRANSIENT_PREFIX = 'ccmerch_twitch_uid_'`.

**Method:** `send_message(channel, message, retry = true): bool`. On 401: calls `refresh_tokens()`.
If refresh succeeds, recurses with `$retry = false`. Broadcaster ID cached in transients (TTL 3600 s).

**Must never:** Retry more than once per send call. Must never expose tokens in log output.
Must never throw — all errors caught and returned as `false`.

---

### `CCMerchAlert\Chatbot\ChatTransportInterface` (`includes/chatbot/interface-chat-transport.php`)

**Responsibility:** Contract that all Twitch chat delivery implementations must satisfy.
`Chatbot_Message_Service` depends only on this interface — not on any concrete transport class.

**Methods:** `send(channel, message): bool`, `get_transport_label(): string`.

`get_transport_label()` returns a human-readable string used exclusively in admin diagnostics.
It must never be used for routing or conditional logic.

**Must never:** Contain any delivery logic, state, or WordPress API calls.

---

### `CCMerchAlert\Chatbot\HelixChatTransport` (`includes/chatbot/class-helix-chat-transport.php`)

**Responsibility:** Thin adapter implementing `ChatTransportInterface`. Delegates all Helix API
communication to `Twitch_Chat_Service` so token management, broadcaster-ID caching, and retry
logic remain in one place. This is the sole production chat transport.

**`send(channel, message): bool`** — calls `Twitch_Chat_Service::send_message()`.

**`get_transport_label(): string`** — returns `'Helix Chat API'`.

**Constructor:** `(Twitch_Chat_Service)`.

**Must never:** Contain direct HTTP logic. Must never be instantiated more than once.
Must never be bypassed — `Chatbot_Message_Service` must use `ChatTransportInterface`.

---

### `CCMerchAlert\Chatbot\Chatbot_Settings_Service` (`includes/chatbot/class-chatbot-settings-service.php`)

**Responsibility:** Business-logic façade over `Chatbot_Repository` and `Twitch_Auth_Service`.

**Constants:** `DEFAULT_TEMPLATE = '🔔 $Name just bought $Product!'`.

**Methods:** `is_connected()`, `get_settings(creator_id): Chatbot_Creator_Settings` (backfills empty template
with DEFAULT_TEMPLATE), `save_creator_settings(creator_id, enabled, template): bool`,
`set_creator_channel(creator_id, channel): bool`, `get_channel(creator_id): string`,
`is_enabled(creator_id): bool`, `get_all_settings(): array`, `get_template(creator_id): string`.

**Must never:** Contain rendering logic. Must never be instantiated more than once.

---

### `CCMerchAlert\Chatbot\Chatbot_Message_Service` (`includes/chatbot/class-chatbot-message-service.php`)

**Responsibility:** Hooks WooCommerce order status transitions at priority 20 (after `Order_Logger` at
priority 10) and sends chatbot notifications to each matched creator's Twitch channel.

**One message per product (Beta 2.0.2+):** An order with multiple line items sends one *separate*
chatbot message per `Alert_Event` — never a single message combining several products. Public method
`send_for_events( int $creator_id, \WC_Order $order, array $events ): void` is the single shared
implementation of this rule: it resolves `$Name`/`$UserName`/`$Amount` once from `$order`, then loops
`$events` and sends one `render_message()` result per event. It is called both from `send_for_creator()`
(the live-order path, via `handle_order()`) and directly from
`Creator_Overlay_Controller`'s replay handlers, so live orders and replayed orders can never diverge
in how many messages they send.

**Legacy-order backfill via `Chatbot_Message_Log_Repository`:** before sending each event's message,
`send_for_events()` checks `Chatbot_Message_Log_Repository::exists( creator_id, event->order_item_id )`.
A line item that has never been logged (typically one from an order placed before the one-message-
per-product fix, where only the first item was ever actually sent) gets a log row created via
`record()` at this point. A line item that already has a log row is not logged again, but is still
sent every time `send_for_events()` runs for it — replay always (re)announces every product on the
order regardless of what was logged previously.

**Rate-limit-safe spacing (Beta 2.0.2+):** `send_for_events()` pauses
`MESSAGE_SEND_DELAY_MICROSECONDS` (1.5 s) between consecutive sends to the same channel — paced to
Twitch's documented 20-messages-per-30-seconds-per-channel limit for a regular (non-moderator,
non-VIP) account, applied unconditionally since the bot's elevated status in any given channel is
not tracked. Because this can add up to several seconds for a multi-product order, live orders never
send inline: `handle_order()` only schedules a `CRON_DISPATCH_HOOK` WP-Cron event per creator (a fast
DB write), and the actual paced sending happens in `run_scheduled_dispatch()`, fired via WordPress's
non-blocking cron spawn — fully decoupled from the WooCommerce checkout request. Replay
(`Creator_Overlay_Controller`'s handlers) is a creator-initiated action, not customer checkout, so it
calls `send_for_events()` directly and synchronously — both paths share the exact same spacing
implementation and only differ in when it runs.

**Non-blocking contract:** All WooCommerce hook handling is wrapped in `try { ... } catch ( \Throwable $e )`
with `error_log()` on failure. The chatbot must never break or delay WooCommerce checkout flow.

**Routing:** `get_creator_ids_for_order($order_id)` queries `DISTINCT creator_id` from
`ccmerch_alert_events` WHERE `order_id = %d` — resolves creator assignments from the events that
`Alert_Router` already wrote at priority 10.

**Message variables (per message):** `$Name` (billing first name), `$UserName` (stream username,
`'Anonymous'` if empty), `$Product` (that single event's product name), `$Amount` (WC order formatted total).

**Must never:** Call `Order_Repository` or WooCommerce order API directly for routing.
Must never let failures surface to the end user or disrupt checkout. Must never be instantiated
more than once. Must never combine multiple `Alert_Event` rows into one message — `send_for_events()`
is the only place that turns events into chatbot messages. Must never send live-order messages
inline on `handle_order()` — that must remain a schedule-only entry point so
`MESSAGE_SEND_DELAY_MICROSECONDS` spacing can never delay checkout.

---

### `CCMerchAlert\Chatbot\Chatbot_Portal_Controller` (`includes/chatbot/class-chatbot-portal-controller.php`)

**Responsibility:** Creator portal AJAX endpoints for chatbot settings save and message preview.
Registers BOTH `wp_ajax_nopriv_*` AND `wp_ajax_*` for all endpoints (same auth model as all
other portal endpoints).

**Constants:** `AJAX_SAVE = 'ccmerch_creator_chatbot_save'`, `AJAX_PREVIEW = 'ccmerch_creator_chatbot_preview'`.

**Auth:** `Auth_Context::resolve_creator()` on every request. No WP nonces checked in creator context.

**Preview sample data:** StreamUser42, Alex, Supporter Hoodie, $24.99. Returns rendered message string.

**Must never:** Register `admin_post_*` hooks. Must never check WP nonces for creator auth.
Must never be instantiated more than once.

---

### `CCMerchAlert\Admin\Chatbot_Admin` (`includes/admin/class-chatbot-admin.php`)

**Responsibility:** Registers and renders the CCMerch Alert → Chatbot admin submenu page.
Six cards: Bot Connection (Twitch OAuth flow + connected/disconnected status), Creator Channel
Mapping (assign Twitch channel per creator), Diagnostics (last-message diagnostics per channel),
Bot Runtime Status (WP-Cron health, token validity, aggregate send/fail counters),
Channel Authorization Overview (per-creator auth status table with Granted Scopes column), and
OAuth Scopes (required scopes as locked badges, optional scopes as configurable toggles).

**Constants:** `PAGE = 'ccmerch-alert-chatbot'`, `NONCE_ACTION = 'ccmerch_chatbot_admin'`.

**Auth:** All admin mutations use `check_admin_referer()` + `current_user_can('manage_options')`.

**Constructor:** `(Twitch_Auth_Service, Chatbot_Settings_Service, Creator_Repository,
?Twitch_Chat_Service, ?Chatbot_Health_Monitor, ?Chatbot_Token_Manager,
?Creator_Channel_Auth_Service)`. All nullable parameters default to null.

**Must never:** Output HTML outside of the render methods. Must never handle creator portal requests.
Must never be instantiated more than once.

---

### `CCMerchAlert\Chatbot\Chatbot_Health_Monitor` (`includes/chatbot/class-chatbot-health-monitor.php`)

**Responsibility:** Persistent in-process health tracker for the chatbot subsystem. Reads from and
writes to `'ccmerch_chatbot_health'` in `wp_options`. Maintains an in-process cache (`private ?array $data`)
invalidated by `invalidate()` so callers never read stale data within the same request.

**Constants:** `OPTION_KEY = 'ccmerch_chatbot_health'`; `STATUS_CONNECTED`, `STATUS_DISCONNECTED`,
`STATUS_RECONNECTING`, `STATUS_UNKNOWN`.

**Read API:** `get_bot_status(): string`, `get_last_refresh_at(): ?int`, `last_refresh_ok(): bool`,
`get_total_sent(): int`, `get_total_failed(): int`, `get_channel_stats(): array`,
`get_channel_stat(channel): array`.

**Write API:** `set_bot_status(status)`, `record_token_refresh(success)`,
`record_message_sent(channel)`, `record_message_failed(channel)`, `invalidate()`.

**Must never:** Be instantiated more than once. Must never contain business logic.
Must never be used as a persistence layer for non-health data.

---

### `CCMerchAlert\Chatbot\Chatbot_Token_Manager` (`includes/chatbot/class-chatbot-token-manager.php`)

**Responsibility:** Proactive token expiry tracking for the bot account. Caches the current token
validity state in a transient (`TRANSIENT_STATUS`, TTL 3000 s) so the validity check is a single
`get_transient()` call on hot paths. `run_maintenance()` deletes the transient, re-validates via
the Twitch token introspection endpoint, and proactively refreshes if `expires_in < 3600`.

**Constants:** `TRANSIENT_STATUS = 'ccmerch_chatbot_token_status'`, `STATUS_TTL = 3000`.

**API:** `is_token_valid(): bool` (checks transient first; falls through to live validate only if
the transient is absent), `run_maintenance(): bool` (always hits Twitch; updates health monitor
and transient).

**Constructor:** `(Twitch_Auth_Service, Chatbot_Health_Monitor)`.

**Must never:** Be instantiated more than once. Must never refresh tokens outside of `run_maintenance()`.
Must never expose raw token values.

---

### `CCMerchAlert\Chatbot\Chatbot_Runtime` (`includes/chatbot/class-chatbot-runtime.php`)

**Responsibility:** WP-Cron orchestrator for the chatbot subsystem. Schedules a recurring hourly
cron event (`ccmerch_chatbot_runtime_token_check`) that calls `Chatbot_Token_Manager::run_maintenance()`.
`static deactivate()` is registered as the plugin deactivation hook and calls
`wp_clear_scheduled_hook()` to prevent orphaned cron entries.

**Constants:** `HOOK_TOKEN_CHECK = 'ccmerch_chatbot_runtime_token_check'`.

**API:** `init(): void` — registers the cron action callback and calls `maybe_schedule()`;
`static deactivate(): void` — removes the cron event; `run_token_check(): void` — cron callback
(wrapped in `try/catch \Throwable`); private `maybe_schedule(): void` — schedules hourly if not
already scheduled.

**Constructor:** `(Chatbot_Token_Manager)`.

**Load note:** `class-chatbot-runtime.php` is required early in `ccmerch-alert.php` (before
`plugins_loaded`) so `register_deactivation_hook()` can reference the class. `init()` is called
from `Plugin::load_components()` after all dependencies are wired.

**Must never:** Be instantiated more than once. Must never perform network I/O directly — delegate
all Twitch calls to `Chatbot_Token_Manager`. Must never swallow failures silently (always logs).

---

### `CCMerchAlert\Chatbot\Creator_Channel_Auth` (`includes/chatbot/class-creator-channel-auth.php`)

**Responsibility:** Immutable value object representing one creator's Twitch channel authorization
record. Carries `id`, `creator_id`, `channel_login`, `scope`, `authorized_at`, `updated_at`.

**API:** `is_authorized(): bool` — returns true when `channel_login` is non-empty (indicating the
broadcaster has completed the OAuth flow and granted `channel:bot`).

**Must never:** Be mutated after construction. Must never contain repository or routing logic.
Must never expose raw encrypted tokens — those are handled exclusively by the repository.

---

### `CCMerchAlert\Chatbot\Creator_Channel_Auth_Repository` (`includes/chatbot/class-creator-channel-auth-repository.php`)

**Responsibility:** All SQL for the `{prefix}ccmerch_creator_channel_auth` table. Stores and retrieves
per-creator broadcaster OAuth credentials with AES-256-CBC encryption. The encryption key is derived
from `wp_salt('auth')`. The IV is prepended to the ciphertext before `base64_encode()`.

**Write API:** `save(creator_id, channel_login, access_token, refresh_token, scope): bool` —
upsert on `creator_id` UNIQUE KEY; tokens encrypted before storage.
`update_tokens(creator_id, access_token, refresh_token): bool` — updates only the token columns.
`delete_by_creator_id(creator_id): bool`.

**Read API:** `find_by_creator_id(creator_id): ?Creator_Channel_Auth` — returns the value object
with decrypted token values stripped (use `get_tokens()` separately for token access).
`get_tokens(creator_id): ?array{access_token, refresh_token}` — returns decrypted tokens.
`get_all(): Creator_Channel_Auth[]`.

**Security:** `encrypt()` and `decrypt()` are private. Raw token values are never stored in plain
text. The key is never logged or returned in any response.

**Must never:** Return raw encrypted strings to callers. Must never be called from rendering classes
directly. Must never expose the encryption key or raw ciphertext.

---

### `CCMerchAlert\Chatbot\Chatbot_Scope_Manager` (`includes/chatbot/class-chatbot-scope-manager.php`)

**Responsibility:** Single source of truth for all Twitch OAuth scope definitions used by the plugin.
Static-only final class — never instantiated. Loaded before `Twitch_Auth_Service` in `Plugin::load_files()`.

**Constants:**
- `OPTION_KEY = 'ccmerch_chatbot_scopes'` — wp_options key for persisted optional scope config.
- `REQUIRED_BOT_SCOPES = ['user:write:chat', 'user:bot']` — always included; cannot be disabled.
- `REQUIRED_CREATOR_SCOPES = ['channel:bot']` — always requested in the creator channel OAuth flow.
- `OPTIONAL_BOT_SCOPES = []` — currently empty; future optional scopes added here as `slug => label`.

**API:**
- `get_bot_scopes(): string` — space-separated scope string for the bot account OAuth URL; includes required + any enabled optional scopes.
- `get_creator_scopes(): string` — space-separated scope string for the creator channel OAuth URL.
- `validate_bot_scopes(array $granted): bool` — checks all `REQUIRED_BOT_SCOPES` are present in the granted list.
- `validate_creator_scopes(array $granted): bool` — checks all `REQUIRED_CREATOR_SCOPES` are present.
- `bot_needs_reauth(array $granted_scopes): bool` — returns true when desired scopes differ from granted (reauth required).
- `save_scope_config(array $config): void` — persists optional scope enable/disable state; strips unknown keys.

**Must never:** Be instantiated. Must never contain OAuth flow logic. Must never be the place that
sends tokens or API requests. Any addition to `REQUIRED_BOT_SCOPES` or `REQUIRED_CREATOR_SCOPES`
requires a forced re-authorization of the respective account — document this in a changelog entry.

---

### `CCMerchAlert\Chatbot\Creator_Channel_Auth_Service` (`includes/chatbot/class-creator-channel-auth-service.php`)

**Responsibility:** Manages the creator-side Twitch OAuth 2.0 flow. Creators independently authorize
the bot to join their Twitch channel by completing a separate OAuth flow that grants the bot the
`channel:bot` scope. This is independent of the admin bot account OAuth (managed by `Twitch_Auth_Service`).

**Constants:** `AJAX_GET_AUTH_URL = 'ccmerch_creator_get_channel_auth_url'`,
`AJAX_CALLBACK = 'ccmerch_creator_channel_auth_callback'`,
`AJAX_REVOKE = 'ccmerch_creator_revoke_channel_auth'`,
`CHANNEL_SCOPE = 'channel:bot'`.

**`register(): void`** — hooks all three AJAX actions with both `wp_ajax_nopriv_*` and `wp_ajax_*` variants.

**`handle_get_auth_url(): never`** — authenticated via `Auth_Context::resolve_creator()`; generates an
OAuth state transient keyed `ccmerch_cca_state_{state}` (TTL 600 s) carrying `creator_id` and
`portal_url`; returns JSON `{ url: string }`.

**`handle_callback(): never`** — validates state transient via `hash_equals()`; exchanges code for
tokens via Twitch API; fetches broadcaster's `user_id` and `login` via Helix Users endpoint; stores
in repository; redirects back to the creator portal URL from the state transient with
`?ccmerch_notice=channel_auth_success` or `channel_auth_failed`.

**`handle_revoke(): never`** — authenticated via `Auth_Context::resolve_creator()`; calls `revoke(creator_id)`;
returns JSON success.

**Public API:** `get_authorization(creator_id): ?Creator_Channel_Auth`,
`is_authorized(creator_id): bool`, `get_all(): Creator_Channel_Auth[]`, `revoke(creator_id): bool`.

**Constructor:** `(Creator_Channel_Auth_Repository, Twitch_Auth_Service, Creator_Service)`.

**Must never:** Register `admin_post_*` hooks. Must never check WP nonces for creator auth.
Must never store or return raw OAuth tokens to the browser. Must never be instantiated more than once.

---

## AJAX Architecture

The plugin supports two AJAX authentication contexts that must never be conflated:

| Context | Hook variant | Auth mechanism | WP login required |
|---|---|---|---|
| WordPress admin | `wp_ajax_*` | nonce + `manage_options` | Yes |
| Creator portal | `wp_ajax_nopriv_*` AND `wp_ajax_*` | creator_access_token | No |

**Why both `wp_ajax_` and `wp_ajax_nopriv_` for creator endpoints:**
WordPress only fires `wp_ajax_nopriv_*` for unauthenticated requests. When a logged-in WordPress
user (e.g. the site admin) visits the creator portal to test it, WordPress fires `wp_ajax_*`.
Without the non-nopriv registration, the endpoint is silently unhandled and WordPress returns `-1`
(not valid JSON), causing the JS fetch to fail in the catch block.

**Authentication rules:**
- Creator portal endpoints: authenticate via `Auth_Context::resolve_creator()` only. Never call
  `check_ajax_referer()`, `current_user_can()`, or `is_user_logged_in()` in creator context.
- Admin endpoints: authenticate via `Auth_Context::resolve_admin()`. Never accept creator tokens.
- Nonce validation must NOT block creator portal requests. The creator access token (256-bit entropy)
  is the security boundary.

**Structured error responses:** Every AJAX endpoint must return `wp_send_json_error(['message' => ...,
'reason' => ..., 'step' => ...])` for all failure modes. `wp_die(-1)` without JSON is not permitted
— it breaks the JS fetch handler which always calls `.json()` on the response.

**`admin-post.php` is prohibited for creator portal operations.** WordPress returns HTTP 400 for
`admin_post_{action}` requests from unauthenticated users (creators are not WP users) when no
`admin_post_nopriv_{action}` is registered. Use `admin-ajax.php` with both hook variants instead.

---

## Future Modules

These modules are planned but not yet implemented. New code must fit into this design.
Do not implement any of these modules without being explicitly asked.

### Alert Configurations (storage layer)

Persists user-created alert configurations (which trigger + which conditions + which channels).
Will be its own class in `includes/models/` or `includes/storage/`. The engine's `dispatch()` method
is the correct place to load and evaluate these configurations.

### Trigger implementations (`includes/triggers/`)

Each trigger is a concrete class implementing `Trigger_Interface`. Examples:

- `New_Order_Trigger` — fires on `woocommerce_checkout_order_processed`
- `High_Value_Order_Trigger` — fires on `woocommerce_payment_complete` when order total exceeds threshold
- `Low_Stock_Trigger` — fires on `woocommerce_low_stock`
- `Out_Of_Stock_Trigger` — fires on `woocommerce_no_stock`
- `Product_Sold_Trigger` — fires on `woocommerce_order_item_add_order_note`
- `Customer_Register_Trigger` — fires on `user_register` with WC customer role
- `Order_Refund_Trigger` — fires on `woocommerce_order_refunded`
- `Order_Failed_Trigger` — fires on `woocommerce_order_status_failed`

Triggers register themselves on `ccmerch_alert_init`. No existing class changes when a trigger is added.

### Notification Channels (`includes/channels/`)

Each channel implements `Channel_Interface`. Examples:

- `Discord_Channel` — posts to a Discord webhook URL
- `Slack_Channel` — posts to a Slack incoming webhook
- `Email_Channel` — sends via `wp_mail()`
- `Webhook_Channel` — HTTP POST to a custom URL
- `Browser_Channel` — browser push notification (future)

Channels register themselves on `ccmerch_alert_init`. New channels do not modify any existing class.

### Conditions (`includes/conditions/`)

Each condition implements `Condition_Interface`. Examples:

- `Order_Value_Condition` — passes when order total ≥ threshold
- `Customer_Country_Condition` — passes when billing country matches
- `Product_Category_Condition` — passes when an order contains a product from a given category

### Filters (`includes/filters/`)

Each filter implements `Filter_Interface`. Examples:

- `Product_Category_Filter` — scopes alert to specific product categories
- `Customer_Role_Filter` — scopes alert to specific user roles
- `Order_Status_Filter` — scopes alert to specific order statuses

### Products module (`includes/models/class-products.php`)

Data access layer for WooCommerce products. Depends on `WooCommerce::is_active()`.
Never accessed directly by admin or engine — always via this class.

### Inventory module (`includes/models/class-inventory.php`)

Data access layer for stock levels. Depends on `WooCommerce::is_active()`.

### Logs module (`includes/logs/`)

Persists a history of dispatched alerts. Will have its own storage class and admin UI table.

### Templates module (`includes/templates/`)

Manages notification message templates per channel. Each channel will define its default template.
Users can override templates from the admin UI.

### Scheduler (`includes/scheduler/`)

Handles time-based triggers (e.g. daily digest, recurring low-stock report).
Will use WordPress Cron (`wp_schedule_event`).

### REST API (`includes/api/`)

**Partially implemented (Beta 1.0.1).** `Releases_Controller` provides `GET /wp-json/ccmerch/v1/releases`
(public, no authentication). Future endpoints for alert status and history will require authentication
and live in the same `includes/api/` namespace.

### Webhooks (`includes/webhooks/`)

Inbound webhook support — allows external systems to trigger alerts.

### Module interactions

```
WordPress/WooCommerce hooks
        │
        ▼
  Trigger_Interface  ──creates──▶  Alert_Context
        │                                │
        ▼                                ▼
  Alert_Engine::dispatch()  ──reads──▶  Alert_Registry
        │
        ├── Filter_Interface::passes()
        ├── Condition_Interface::evaluate()
        └── Channel_Interface::send()
                │
                ├── Discord_Channel
                ├── Slack_Channel
                ├── Email_Channel
                └── Webhook_Channel
```

The engine never imports concrete trigger or channel classes. All coupling is through interfaces.

---

## Development Workflow

For every future request, follow this sequence:

1. **Read CLAUDE.md** (this file) first.
2. **Read every file** that will be touched or that touches the area of change.
3. **Understand the current implementation** before forming a plan.
4. **Reuse existing architecture.** Check whether an existing class already handles the concern.
5. **Make the smallest safe change.** New class over larger existing class. New method over new class.
6. **Explain conflicts.** If the request conflicts with the architecture, say so and propose an alternative
   before writing any code.

Never generate code based only on the current prompt. Always use the complete project context.

---

## Runtime Debugging Principles

Before implementing any fix for a runtime synchronization or delivery problem, complete
this investigation sequence. Do NOT write code until the root cause is confirmed.

### 1. Trace the complete data flow

Map every step from source to destination. For a config-sync problem:

```
Admin save → update_option() → hook fires → version key bumped in DB
    ↓
SSE loop reads version key from DB → detects change → reload_from_db() → emit config event
    ↓
Browser EventSource receives config event → applyConfig() → CSS vars / inline styles updated
```

Identify the exact step where the chain breaks before proposing a fix.

### 2. Identify the single authoritative source of truth

There must be exactly ONE authoritative configuration object at runtime:

- **DB** — canonical storage (always correct, but stale after PHP process starts)
- **PHP in-memory** — one instance, shared by controller and renderer via PHP object handle
- **JS live state** — `document.documentElement.style` (CSS vars) + `el.style.cssText` (element styles) + `config.*` (timing values)

Any additional copy (snapshot in a queue, baked value in a template, cached at request time)
is a stale state candidate. Eliminate it before fixing the delivery mechanism.

### 3. Confirm each inter-process step with evidence

Long-running SSE PHP processes and admin PHP processes are separate OS processes.
They share state only through the database. Confirm:

- `on_settings_updated()` actually fires (add `error_log()` if uncertain — never assume hooks work)
- The version key actually exists in `wp_options` (query `SELECT option_value FROM wp_options WHERE option_name = 'ccmerch_overlay_settings_version'` directly)
- `sse_emit()` actually flushes to the browser (check OBS devtools → Network → SSE stream)
- `applyConfig()` is actually called (check OBS devtools → Console for `[CCMerch Runtime]` logs)

### 4. Use the debug panel before modifying code

`overlay.js` contains `DebugPanel` — activated by appending `?debug=1` to the Browser Source URL.
It displays: Overlay ID, SSE connection state, queue depth, last alert time, last config push time,
and config version. Check this panel to confirm which step in the pipeline is failing before making
any PHP or JS changes.

### 5. Distinguish failure modes

| Symptom | Likely cause |
|---|---|
| Config never updates | `update_option_*` hook not firing; or version key row doesn't exist yet |
| Config updates once then stops | Version comparison fires correctly but subsequent saves produce same `time()` value |
| Config updates in admin but not OBS | `flush()` buffering; or OBS is showing a creator overlay URL while global settings were saved |
| All SSE events delayed by 55 s | `flush()` is a no-op; all output is buffered until connection close |
| Test alerts slow | False-positive config change detection running extra DB queries; or DB query latency on shared hosting |

### 6. Verify the correct overlay type

Global overlay (`/ccmerch-alert/overlay/{id}/`) uses `Overlay_Settings` and `OPTION_VERSION_KEY`.
Creator overlay (`/ccmerch-alert/creator-overlay/{id}/`) uses `Creator_Overlay_Profile` and
`updated_at` in `ccmerch_creator_overlay_settings`. Saving the wrong overlay's settings does not
trigger config sync for the other. Always confirm which URL OBS is using before investigating.

### 7. Do not add caching, workarounds, or extra polling

Workarounds that violate the architecture constraints:
- Forcing Browser Source page reloads
- Adding cache-busting query strings
- Clearing browser cache programmatically
- Adding a second polling loop alongside the SSE stream
- Duplicating configuration into the alert event payload

Fix the inter-process communication mechanism. Do not paper over it.

---

## Safety Rules

Before modifying any file:

- Read the entire file.
- Read all files it imports and all files that import it.
- Search for every usage of the classes, methods, or constants being changed.
- Understand the full impact before making any edit.

Never overwrite existing functionality based on assumptions about what a class does.
If information is missing, read the codebase instead of guessing.

When implementing a feature, modify as little existing code as possible.
Prefer adding new classes over making existing classes larger.

When unsure about the impact of a change, ask before proceeding.

---

## Project Memory

Living changelog. Update this section whenever code is added or modified.

---

### 1.0.0 — Initial build

**New files:**
- `ccmerch-alert.php` — bootstrap, constants, minimum version check
- `uninstall.php` — deletes `ccmerch_alert_settings` option on uninstall
- `assets/css/admin.css` — dashboard grid, cards, status indicator, stat display
- `assets/js/admin.js` — placeholder admin script
- `includes/class-plugin.php` — singleton orchestrator
- `includes/class-settings.php` — Settings API wrapper, `enable_alerts` checkbox
- `includes/admin/class-admin.php` — top-level menu, Settings submenu, dashboard + settings renderers
- `includes/admin/class-assets.php` — scoped asset enqueueing
- `includes/engine/class-alert-context.php` — immutable event value object
- `includes/engine/class-alert-registry.php` — typed component store
- `includes/engine/class-alert-engine.php` — boot + dispatch
- `includes/engine/contracts/interface-trigger.php`
- `includes/engine/contracts/interface-condition.php`
- `includes/engine/contracts/interface-filter.php`
- `includes/engine/contracts/interface-action.php`
- `includes/engine/contracts/interface-channel.php`
- `includes/integrations/class-woocommerce.php` — WC detection + inactive notice
- `includes/models/class-orders.php` — order data access (placeholder)

**Architecture established:**
- Registry + hook extension pattern (`ccmerch_alert_init`, `ccmerch_alert_dispatch`)
- Callable injection on triggers to avoid circular engine dependency
- `Channel_Interface extends Action_Interface` hierarchy
- Manual `require_once` load order in `Plugin::load_files()` (no Composer autoloader)
- Sub-namespaces mirror folder structure

**Known placeholders (not yet implemented):**
- `Orders::get_monitored_count()` always returns 0
- `Alert_Engine::dispatch()` does not yet evaluate alert configurations or call channels
- No concrete trigger or channel implementations exist

**Future TODOs:**
- Implement alert configuration storage and admin UI
- Implement first concrete trigger (New_Order_Trigger recommended)
- Implement first notification channel (Discord or Email recommended)
- Add Products and Inventory models
- Install `php-stubs/wordpress-stubs` as Composer dev-dependency to resolve IDE diagnostics

---

### 1.1.0 — Order Log system

**New files:**
- `includes/order-log/class-order-installer.php` — creates `ccmerch_order_log` + `ccmerch_order_log_items` tables via dbDelta on activation
- `includes/order-log/class-order-log-entry.php` — immutable read-model: one logged order
- `includes/order-log/class-order-log-item.php` — immutable read-model: one logged line item
- `includes/order-log/class-order-repository.php` — all DB read/write for Order Log tables
- `includes/order-log/class-order-logger.php` — subscribes to WC status hooks, writes to repository
- `includes/admin/class-dashboard-orders.php` — renders "Recent Orders" section on dashboard

**Modified files:**
- `ccmerch-alert.php` — added `require_once` for installer + `register_activation_hook()`
- `includes/class-plugin.php` — added `Order_Repository` + `Order_Logger` instantiation; added `order_repository()` accessor; added `Dashboard_Orders` instantiation (admin-only); updated `Admin` constructor call
- `includes/admin/class-admin.php` — added `Dashboard_Orders` property + constructor parameter; calls `$this->dashboard_orders->render()` in `render_main_page()` inside the wrap div
- `assets/css/admin.css` — added Recent Orders section, order card, order item styles

**Database tables created on activation:**

`{prefix}ccmerch_order_log`:
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| order_id | BIGINT UNSIGNED | UNIQUE KEY — prevents duplicate logs |
| order_number | VARCHAR(64) | WC order number (may differ from ID) |
| order_date | DATETIME | UTC, from WC_Order::get_date_created() |
| first_name | VARCHAR(255) | Billing first name |
| logged_at | DATETIME | UTC timestamp when the log entry was created |

`{prefix}ccmerch_order_log_items`:
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| log_id | BIGINT UNSIGNED | FK to ccmerch_order_log.id (KEY) |
| product_id | BIGINT UNSIGNED | Parent product (not variation) |
| variation_id | BIGINT UNSIGNED DEFAULT 0 | 0 = simple product |
| product_name | VARCHAR(255) | Name at purchase time |
| product_sku | VARCHAR(100) | SKU at purchase time |
| quantity | INT | Units purchased |
| image_attachment_id | BIGINT UNSIGNED DEFAULT 0 | 0 = no image; resolved dynamically at display |
| category_ids | TEXT | JSON-encoded int[] of term IDs; decoded at display |

**Data flow:**
```
WC order reaches "processing" or "completed" status
    │
    ▼
Order_Logger::log_order( $order_id )
    │  checks Order_Repository::exists() — skips if already logged
    │  reads WC_Order, WC_Order_Item_Product objects
    │  resolves: SKU, get_post_thumbnail_id(), wp_get_post_terms()
    │
    ▼
Order_Repository::save( $order_data, $items_data )
    │  inserts row in ccmerch_order_log
    │  inserts N rows in ccmerch_order_log_items (one per line item)
    │  stores category_ids as JSON text
    │
    ▼  (on dashboard load)
Order_Repository::get_recent( 10 )
    │  returns Order_Log_Entry[] with nested Order_Log_Item[]
    │
    ▼
Dashboard_Orders::render()
    │  calls wp_get_attachment_image() for thumbnails
    │  calls get_term() for category names
    │  calls wc_get_product() + get_variation_attributes() + get_term_by() for variations
    │  escapes all output; never trusts stored strings as safe HTML
```

**Design decisions:**
- Custom tables chosen over CPTs (avoids postmeta join overhead), wp_options (unqueryable),
  and serialized arrays (can't filter individual fields at query time).
- Category IDs stored as JSON text — a third junction table adds complexity without query benefit
  at the scale this plugin targets.
- `variation_id = 0` and `image_attachment_id = 0` store "absent" as integers, avoiding the
  `$wpdb->insert()` NULL-with-format issue.
- The Order Log is intentionally decoupled from the Alert Engine. The engine will eventually
  read from `Order_Repository` instead of re-querying WooCommerce at dispatch time.

**Extension points for the future Alert Engine:**
- `Plugin::order_repository()` exposes the repository — the engine can call `get_recent()` or
  new query methods (e.g. `get_by_order_id()`) to evaluate alert conditions without touching WC.
- New query methods (e.g. `count_today()`, `find_by_product()`) can be added to `Order_Repository`
  without changing any other class.

---

### 1.2.0 — Dashboard UI and Orders Today counter

**Modified files:**
- `includes/order-log/class-order-repository.php` — added `count_today(): int`
- `includes/admin/class-dashboard-orders.php` — added public `count_today(): int` delegation method
- `includes/admin/class-admin.php` — `render_main_page()` now calls `$this->dashboard_orders->count_today()` instead of the placeholder `Orders::get_monitored_count()`
- `assets/css/admin.css` — `.ccmerch-orders` now has `max-height: 480px` and `overflow-y: auto`

**Orders Today counter:**
- `Order_Repository::count_today()` runs a single `COUNT(*)` query against `ccmerch_order_log`
  filtered by a UTC timestamp range derived from the site's configured timezone (`wp_timezone()`).
- "Today" is computed as midnight→midnight in the site timezone and converted to UTC, so the count
  always matches what the site owner expects regardless of server offset. No `CONVERT_TZ` or MySQL
  timezone tables needed — the comparison is a pure datetime string range on the `logged_at` column.
- Duplicate prevention is unchanged: `Order_Logger::log_order()` calls `exists()` before inserting,
  so each WooCommerce order can only appear once in the log.
- `Dashboard_Orders::count_today()` is a thin public delegation method that routes the call from
  `Admin` to the repository, avoiding a new `Order_Repository` injection into `Admin`'s constructor.

**Scrollable order list:**
- `.ccmerch-orders` gains `max-height: 480px` and `overflow-y: auto`, giving roughly 4–5 orders
  visible before the list scrolls. `padding-right: 4px` prevents card borders from being clipped
  by the browser's native scrollbar gutter.
- No JavaScript, no external libraries. Renders correctly inside WordPress admin at all viewport widths.
- The layout (order cards with item rows) is unchanged — only the container is now scrollable.

---

### 1.3.0 — Filter system and real-time polling

**New files:**
- `includes/admin/class-dashboard-ajax.php` — AJAX handler for dashboard sync requests

**Modified files:**
- `includes/order-log/class-order-repository.php` — filter constants + 5 new methods (see below)
- `includes/admin/class-dashboard-orders.php` — updated `render()` with filter tabs + DOM IDs; new `render_orders_html()`
- `includes/admin/class-assets.php` — added `Dashboard_Ajax` constructor param; added `wp_localize_script()`
- `includes/admin/class-admin.php` — `id="ccmerch-orders-count"` on stat span; card label → "Orders Monitored"; sublabel → "orders in selected range"
- `includes/class-plugin.php` — added `Dashboard_Ajax` use + require_once; wired into admin block
- `assets/js/admin.js` — full polling + filter-switching implementation
- `assets/css/admin.css` — section header flex layout; filter tab styles; `.ccmerch-hidden` utility

**Order_Repository additions:**

| Method | Signature | Purpose |
|---|---|---|
| (constants) | `FILTER_TODAY`, `FILTER_LAST_24`, `FILTER_ALL` | Canonical filter slugs shared across PHP and JS |
| `filter_range()` | `private(string): array{string\|null, string\|null}` | Computes UTC [start, end] for a filter; end=null means open-ended |
| `rows_to_entries()` | `private(?array): Order_Log_Entry[]` | Maps raw $wpdb rows to value objects; used by get_filtered() + get_since() |
| `count_filtered()` | `public(string): int` | COUNT(*) with filter-derived WHERE clause |
| `get_filtered()` | `public(string, int): Order_Log_Entry[]` | Full filtered list, newest-first, limit 1–100 |
| `get_since()` | `public(int, string): Order_Log_Entry[]` | Orders logged after a UTC timestamp AND within filter, newest-first, limit 50 |

`count_today()` is now a one-liner delegating to `count_filtered(FILTER_TODAY)`.

**Filter system:**

Three filter slugs: `today` (midnight→midnight in site timezone), `last_24h` (rolling 86 400-second window), `all_time` (no constraint). The UTC range is computed once per request via `filter_range()` and applied consistently to both count and list queries.

**Real-time polling architecture:**

```
Browser (admin.js)                          Server (Dashboard_Ajax::handle)
    │                                               │
    ├── page load ──────────────────────────────────┤  (PHP renders initial list)
    │                                               │
    ├── startPolling() sets setTimeout(15 000ms)    │
    │                                               │
    ├── every 15 s: POST admin-ajax.php ───────────▶│  check_ajax_referer()
    │    action=ccmerch_dashboard_sync               │  current_user_can('manage_options')
    │    filter=today                                │  get_since(last_sync, filter)
    │    last_sync_timestamp=<unix ts>               │  count_filtered(filter)
    │                                               │
    │◀── JSON { orders_html, count, has_new, ... } ─┤
    │                                               │
    ├── prepend new cards to #ccmerch-orders-list   │
    └── updateCount(#ccmerch-orders-count)          │
    
Filter tab click:
    ├── set last_sync_timestamp = 0
    ├── POST with new filter ───────────────────────▶│  get_filtered(filter, 10)
    │◀── full list HTML + count ─────────────────────┤
    └── replace #ccmerch-orders-list innerHTML       │
```

**AJAX payload:**

| Key | Type | Description |
|---|---|---|
| `orders_html` | string | HTML for new (poll) or all (reload) order cards |
| `count` | int | Order count matching the active filter |
| `has_new` | bool | Whether `orders_html` has content to render |
| `is_empty` | bool | Whether the filter result set is completely empty |
| `server_time` | int | UTC Unix timestamp; stored as `lastSyncTime` for next poll |

**Frontend behavior:**

- `sync(fullReload=false)` — poll mode: prepend only new cards (newest-first from server).
- `sync(fullReload=true)` — replace mode: replaces entire list innerHTML (filter change).
- `#ccmerch-orders-empty` / `#ccmerch-orders-list` are always present; `ccmerch-hidden` toggles visibility.
- `#ccmerch-orders-count` is updated on every response regardless of mode.
- Scroll position is preserved on poll (prepend does not force scroll).
- Filter tabs are `<button type="button">` with `data-filter` attribute. Active state toggled via `ccmerch-filter--active` class.

**Security:**
- `check_ajax_referer()` dies with -1 on invalid nonce.
- `current_user_can('manage_options')` checked before any data is read or returned.
- Filter value is whitelisted in `sanitize_filter()` — unmapped slugs fall back to `FILTER_TODAY`.

**Performance:**
- Poll path never loads the full history — `get_since()` uses a `logged_at > %s` index-friendly predicate capped at 50 rows.
- Filter-change path loads at most 10 rows via `get_filtered()`.
- No WooCommerce object is instantiated in any query — all data comes from the local Order Log tables.

**Extension points for the future Alert Engine:**
- `get_since(timestamp, filter)` is the natural feed point for real-time alert evaluation.
- The refresh interval is stored in `Settings` — the Alert Engine can honour it when deciding notification cadence.
- The AJAX action name (`ccmerch_dashboard_sync`) is a class constant — future code can hook `wp_ajax_ccmerch_dashboard_sync` to extend or override behavior.

---

### 1.4.0 — Configurable refresh interval and countdown timer

**Modified files:**
- `includes/class-settings.php` — added `FIELD_REFRESH_INTERVAL` constant; new "Dashboard Auto Refresh Interval" field (number + unit select); `sanitize_refresh_interval()` private helper; updated `defaults()` and `sanitize()`
- `includes/admin/class-dashboard-ajax.php` — removed `POLLING_INTERVAL` constant; added `Settings` dependency; new `get_polling_interval()` accessor; `refresh_interval` added to AJAX JSON response
- `includes/class-plugin.php` — passes `$this->settings` to `Dashboard_Ajax` constructor
- `includes/admin/class-assets.php` — `wp_localize_script()` now uses `refreshInterval` (seconds from `get_polling_interval()`) instead of the removed constant
- `includes/admin/class-dashboard-orders.php` — added `<span class="ccmerch-refresh-timer">` countdown element to section header
- `assets/js/admin.js` — rewrote polling: replaced `setTimeout` with a `setInterval`-based 1-second countdown; countdown display (`#ccmerch-countdown`); interval updated dynamically from AJAX response
- `assets/css/admin.css` — added `.ccmerch-refresh-timer` and `#ccmerch-countdown` styles; `.ccmerch-filters` loses `margin-left: auto` (timer takes that role)

**Refresh interval setting:**

Stored as a single integer (seconds) under `ccmerch_alert_settings[refresh_interval]`.
Form posts two sub-fields (`_value` and `_unit`); `sanitize_refresh_interval()` combines them.
Display: if stored value is a whole-minute multiple ≥ 60, the renderer shows it as minutes.
Clamped to [5, 3600] seconds (5 s minimum prevents admin-ajax flood; 3 600 s = 1 h maximum).
Default: 15 seconds.

**Countdown timer:**

`startCountdown()` sets `remainingSeconds = refreshInterval` and runs `setInterval(onCountdownTick, 1000)`.
Each tick decrements the counter and updates `#ccmerch-countdown` with `Xs` text.
When the counter reaches 0: `stopCountdown()` is called and `sync(false)` fires.
After any sync (success or failure), `startCountdown()` resets the interval.
On filter tab switch: `stopCountdown()` fires immediately, then `sync(true)` triggers a full reload,
and `startCountdown()` resets after the response.

**Dynamic interval update:**

Every AJAX response includes `refresh_interval` (int, seconds).
If the admin changes the setting mid-session, the JS adopts the new value on the next sync response
without requiring a page reload. The local `refreshInterval` variable is updated before `startCountdown()`.

**Data flow (settings → countdown → AJAX → UI):**

```
Admin saves Settings page
        │  Settings::sanitize() clamps value to [5, 3600]
        │  stored as ccmerch_alert_settings[refresh_interval]
        │
        ▼ (next page load or next AJAX response)
Assets::enqueue_admin_assets()
        │  calls Dashboard_Ajax::get_polling_interval()
        │  reads Settings::FIELD_REFRESH_INTERVAL
        │  passes refreshInterval (seconds) via wp_localize_script()
        │
        ▼
admin.js: startCountdown()
        │  remainingSeconds = refreshInterval
        │  setInterval(onCountdownTick, 1000)
        │  updates #ccmerch-countdown each second
        │
        ▼ (countdown reaches 0)
admin.js: sync(false)
        │  POST admin-ajax.php → Dashboard_Ajax::handle()
        │  returns { orders_html, count, has_new, is_empty, server_time, refresh_interval }
        │
        ▼
onSyncSuccess()
        │  updates refreshInterval from response
        │  prepends new order cards (or replaces list on full reload)
        │  updateCount() updates #ccmerch-orders-count
        │
        ▼
startCountdown() — resets and repeats
```

**Performance:**
- Countdown display: 1-second `setInterval` with a single DOM text-node write — negligible cost.
- No additional AJAX endpoints or PHP hooks added.
- `refresh_interval` in the AJAX response adds one integer to an existing JSON payload.

**Extension points:**
- `Settings::FIELD_REFRESH_INTERVAL` can be read by the future Alert Engine to govern notification batching cadence.
- `Dashboard_Ajax::get_polling_interval()` is the single source of truth for the interval — any module needing the value should call this method, not read options directly.

---

### 1.5.0 — Overlay Module (OBS browser-source output)

**New files:**
- `includes/overlay/class-overlay-settings.php` — stores overlay options under `ccmerch_overlay_settings`; owns field constants, Settings API registration, `generate_id()`, `get_overlay_url()`
- `includes/overlay/class-overlay-template-engine.php` — replaces `$Variable` placeholders with resolved values from an `Order_Log_Entry`; extensible via `register()`
- `includes/overlay/class-overlay-renderer.php` — produces all HTML for the overlay: standalone page (`render_page()`) and alert fragments (`render_alert()`)
- `includes/overlay/class-overlay-controller.php` — registers rewrite rule, query var, `template_redirect`, poll/preview AJAX endpoints, and generate-ID admin-post action
- `includes/admin/class-overlay-admin.php` — admin submenu page for the overlay (URL display, Generate ID form, Live Preview, settings form)
- `assets/css/overlay.css` — standalone overlay page styles; transparent background for OBS; CSS custom-property-driven appearance
- `assets/js/overlay.js` — polls for new orders, animates in → hold → animate out cycle; no external dependencies

**Modified files:**
- `ccmerch-alert.php` — second `register_activation_hook()` adds rewrite rule + flushes permalinks so the overlay URL works immediately after activation
- `includes/class-plugin.php` — added use statements, properties, and `require_once` calls for all 5 overlay/overlay-admin files; overlay modules instantiated before `is_admin()` block; `Overlay_Admin` instantiated inside `is_admin()` block
- `assets/css/admin.css` — added `.ccmerch-overlay-url`, `.ccmerch-overlay-url__actions`, `.ccmerch-overlay-preview`, `.ccmerch-overlay-preview-error` styles
- `CLAUDE.md` — updated namespace rules, folder structure, and Existing Classes section

**Architecture:**

The overlay module is a fully self-contained output module. It does not use the Alert Engine dispatch pipeline. It reads directly from `Order_Repository` via a nopriv poll endpoint and renders a standalone HTML page bypassing the WordPress theme entirely.

```
OBS Browser Source
        │  GET /ccmerch-alert/overlay/{overlay_id}/
        ▼
Overlay_Controller::maybe_render_overlay()
        │  hash_equals(stored_id, request_id) — 404 on mismatch
        │
        ▼
Overlay_Renderer::render_page()
        │  standalone <!DOCTYPE html> page
        │  CSS custom properties injected in <head>
        │  ccmerchOverlay JS config object injected in <head>
        │  links overlay.css + overlay.js
        │  exit
        │
        └── overlay.js: startPolling()
                │  every N seconds: POST admin-ajax.php
                │  action=ccmerch_overlay_poll
                │  overlay_id=<id>  since=<unix_ts>
                │
                ▼
        Overlay_Controller::handle_poll()
                │  validates overlay_id (hash_equals)
                │  Order_Repository::get_since(since, FILTER_ALL)
                │  Overlay_Renderer::render_alert(newest_entry)
                │  returns { has_new, image_html, text_html, server_time }
                │
                ▼
        overlay.js: showAlert(imageHtml, textHtml)
                │  inject content → add --visible → hold displayDuration ms
                │  add --hiding → after animDuration ms → add --hidden → clear
```

**Security decisions:**

- The overlay ID (32 hex chars from `random_bytes(16)`, 128 bits entropy) is the sole access token for the poll endpoint. OBS browser sources cannot carry WordPress nonces.
- `hash_equals()` is used for all ID comparisons to prevent timing attacks.
- Generating a new ID via the admin form immediately invalidates any previously issued URL.
- The preview endpoint (`ccmerch_overlay_preview`) is nonce-protected and requires `manage_options`.
- The generate-ID form uses `check_admin_referer()` + `manage_options` capability check.
- The overlay ID is NOT part of the overlay settings form — it is preserved across saves by the sanitize callback reading `$input['overlay_id']` (second sanitize pass) or `$this->get()` (first pass).

**Double-sanitize handling:**

WordPress calls the registered `sanitize_callback` twice per `update_option()` call — once from `options.php` with raw POST data, once from inside `update_option()` with the already-sanitized output. All appearance field sanitizers (`sanitize_hex_color`, `intval`, etc.) are naturally idempotent. The overlay ID is explicitly preserved by preferring `$input['overlay_id']` when present over `$this->get()`, ensuring the ID survives both passes without being regenerated.

**Rewrite rule bootstrap:**

`Overlay_Controller::register_rewrite_rules()` runs on `init`, which fires after `plugins_loaded`. Activation hooks fire before `plugins_loaded`, so the rewrite rule would not be registered in time for `flush_rewrite_rules()`. The bootstrap file therefore duplicates the `add_rewrite_rule()` call inline inside a second `register_activation_hook()` callback and immediately calls `flush_rewrite_rules()`. This is the standard WordPress pattern for rewrite-dependent plugins.

**Template variable system:**

| Variable | Resolved from |
|---|---|
| `$FirstName` | `Order_Log_Entry::$first_name` |
| `$OrderNumber` | `Order_Log_Entry::$order_number` |
| `$Product` | First `Order_Log_Item::$product_name` |
| `$Quantity` | First `Order_Log_Item::$quantity` |
| `$Category` | `get_term()` on first item's `category_ids` at render time |
| `$Variation` | `wc_get_product()` + `get_variation_attributes()` + `get_term_by()` if WC active |

All resolved values are passed through `esc_html()` before substitution. The template itself is stored raw and escaped at render time — never at storage time.

**Appearance settings:**

| Field | Type | Default | Notes |
|---|---|---|---|
| `template` | textarea | `'$Product was purchased by $FirstName!'` | Supports `$Variable` placeholders |
| `font_family` | text | `''` | Empty = browser default |
| `font_size` | number (px) | `32` | 8–200 px |
| `font_weight` | select | `700` | 100–900 |
| `text_color` | color | `#ffffff` | |
| `text_shadow` | text | `2px 2px 4px rgba(0,0,0,0.8)` | Raw CSS value |
| `image_size` | number (px) | `120` | 40–400 px |
| `image_position` | select | `left` | left / right / top / bottom → CSS flex-direction |
| `bg_color` | color | `#000000` | Combined with bg_opacity → rgba() |
| `bg_opacity` | number (0–100) | `80` | 0 = fully transparent |
| `border_radius` | number (px) | `8` | |
| `padding` | number (px) | `16` | |
| `anim_duration` | number (ms) | `500` | 0–5000 |
| `display_duration` | number (ms) | `5000` | 1000–30000 |

**Extension points for the future Alert Engine:**

- `Overlay_Template_Engine::register()` allows new `$Variable` placeholders without modifying any existing class.
- `Overlay_Controller::AJAX_POLL` and `NONCE_PREVIEW` are class constants — external code can hook the same AJAX actions if needed.
- The poll endpoint returns `server_time` (Unix timestamp). Future queue-based architecture can replace the JS `since`-tracking with server-side state.
- Image is stored as `image_attachment_id` (never a URL) in `Order_Log_Item` and resolved via `wp_get_attachment_image()` at render time — survives CDN or media library URL changes.
- The overlay module is intentionally decoupled from the Alert Engine. A future integration point would be: Alert_Engine fires `ccmerch_alert_dispatch` → a listener writes a pending-alert queue → the overlay poll reads from that queue instead of `Order_Repository::get_since()`.

**Known limitations (not bugs):**

- The overlay displays the most recent single order from `get_since()`. If two orders arrive between polls, only the newest is shown. A future queue system would address this.
- `$Variation` requires WooCommerce to be active at render time. If WC is deactivated after orders are logged, it renders an empty string.
- The overlay page has no authentication beyond the overlay ID in the URL — do not use this ID as a secret for anything beyond overlay access.

---

### 1.6.0 — Overlay Audio System

**Modified files:**
- `includes/overlay/class-overlay-settings.php` — added `SECTION_AUDIO` constant; three new field constants (`FIELD_AUDIO_ENABLED`, `FIELD_AUDIO_DEFAULT`, `FIELD_AUDIO_CAT_MAP`); registered Audio section and three fields in `register()`; added to `defaults()` and `sanitize()`; added `render_checkbox_field()`, `render_audio_attachment_field()`, `render_audio_category_map_field()`, `sanitize_category_map()` private helper
- `includes/overlay/class-overlay-renderer.php` — added `audioEnabled` to JS config in `render_page()`; extended `render_alert()` return to include `sound_url`; added private `resolve_sound_url()` method
- `includes/overlay/class-overlay-controller.php` — `handle_poll()` and `handle_preview()` both now pass `sound_url` in their JSON response
- `assets/js/overlay.js` — added `AudioManager` IIFE (play/stop); updated `showAlert()` to accept `soundUrl` parameter and call `AudioManager.play()` at the moment the alert becomes visible; updated poll handler to pass `d.sound_url`
- `includes/admin/class-overlay-admin.php` — `enqueue_assets()` now calls `wp_enqueue_media()` for the audio attachment pickers; inline `<script>` extended with `openMediaPicker()` helper and delegated `click` handler for `.ccmerch-audio-select-btn` / `.ccmerch-audio-clear-btn`
- `assets/css/admin.css` — added `.ccmerch-audio-filename` and `.ccmerch-audio-cat-table` styles

**New settings (stored under `ccmerch_overlay_settings`):**

| Field | Type | Default | Notes |
|---|---|---|---|
| `audio_enabled` | checkbox | `'0'` | Master switch; JS `audioEnabled` config reflects this |
| `audio_default_sound` | attachment ID | `'0'` | Fallback sound; 0 = none |
| `audio_category_map` | JSON string | `'{}'` | `{"term_id": att_id}` pairs; empty categories excluded |

**Sound resolution order (in `Overlay_Renderer::resolve_sound_url()`):**
1. If `audio_enabled` ≠ `'1'` → return `''` immediately.
2. Check each category ID on the first line item against `audio_category_map`. Return URL of first match.
3. Fall back to `audio_default_sound` attachment URL.
4. Return `''` when nothing is configured.

**Audio lifecycle (JS `showAlert()`):**
```
inject content → AudioManager.play(soundUrl) → classList --visible → hold → classList --hiding → clear
```
Sound fires at exactly the same moment as the CSS fade-in transition. If the URL is empty or `audioEnabled` is false, `AudioManager.play()` is a silent no-op.

**`AudioManager` design:**
- Uses native `new Audio(url)` — no external libraries.
- `play()` calls `stop()` first to prevent overlap from the same alert source.
- `Promise.catch()` on `audio.play()` swallows autoplay policy errors silently — visual alert is never affected.
- OBS Browser Source (Chromium) allows autoplay without a user gesture.

**Category map admin UI:**
- `render_audio_category_map_field()` queries `product_cat` taxonomy via `get_terms()`. If WooCommerce is not active (taxonomy absent), shows a notice and returns early.
- Each category row stores its attachment ID as a hidden input: `ccmerch_overlay_settings[audio_category_map][{term_id}]`.
- `sanitize_category_map()` handles both the first sanitize pass (array from form POST) and the second pass (JSON string from `update_option`). Categories with attachment ID = 0 are excluded from stored JSON.
- Media picker buttons open `wp.media()` filtered to `type: ['audio']` — only MP3/WAV/OGG files are shown.

**Storage conventions:**
- Audio attachment IDs stored as integers (same pattern as `image_attachment_id` in Order_Log_Item).
- URLs never stored — resolved at render time via `wp_get_attachment_url()`.
- Category map stored as compact JSON string in the existing `ccmerch_overlay_settings` option — no new DB tables or options.

**Extension points:**
- `Overlay_Settings::FIELD_AUDIO_CAT_MAP` can be extended with additional mapping levels (e.g., per-profile) by adding new JSON keys without breaking existing data.
- `AudioManager.stop()` is exposed on the module so future queue logic can call it explicitly before starting the next alert's sound.
- The `sound_url` key in the poll/preview AJAX response is a stable contract — future server-side changes (queue system, priority logic) only need to update `resolve_sound_url()`.

**Known limitations (not bugs):**
- `render_audio_category_map_field()` queries `product_cat` on every settings page load. This is acceptable at admin frequency; no caching needed.
- If a sound file is deleted from the media library, `wp_get_attachment_url()` returns `false` and `resolve_sound_url()` silently falls back to the next option. No stale-URL errors in the browser.

---

### 1.7.0 — Text Alignment + Test Controls

**Modified files:**
- `includes/overlay/class-overlay-settings.php` — added `FIELD_TEXT_ALIGN` constant; registered field in Appearance section (between Font Weight and Text Color); added `render_radio_field()` public method; added `sanitize_text_align()` private helper; added to `defaults()` and `sanitize()`
- `assets/css/overlay.css` — added `text-align: var(--ccmerch-text-align, center)` to `.ccmerch-overlay__text`
- `includes/overlay/class-overlay-renderer.php` — extracts `$text_align` in `render_page()`; injects `--ccmerch-text-align` into `:root` CSS custom properties
- `includes/overlay/class-overlay-controller.php` — added `use` statements for `Order_Log_Entry` and `Order_Log_Item`; added `AJAX_TEST_ALERT`, `AJAX_REPLAY_LATEST`, `NONCE_TEST` constants; registered two new `wp_ajax_` hooks; added `handle_test_alert()` and `handle_replay_latest()` methods; modified `handle_poll()` to check and consume a pending transient before normal `get_since()` path
- `includes/admin/class-overlay-admin.php` — added "Test Controls" card (visible only when overlay ID exists); added `sendTestRequest()` JS helper with loading state, success/failure feedback, double-click prevention

**Text alignment setting:**

| Field | Constant | Options | Default |
|---|---|---|---|
| `text_align` | `FIELD_TEXT_ALIGN` | `left` / `center` / `right` | `center` |

- Rendered as radio buttons via new `render_radio_field()` method (3 options, inline-flex layout).
- Applied via CSS custom property `--ccmerch-text-align` injected into the overlay page's `:root` block at render time.
- Affects only `.ccmerch-overlay__text` — image position is controlled separately by `flex-direction` on the container.
- `sanitize_text_align()` whitelists against the three allowed values; defaults to `center` for any unrecognised input.

**Test Controls architecture:**

The two new admin-only AJAX endpoints write a pre-rendered alert payload into a WordPress transient keyed by the overlay ID:

```
Transient key:  ccmerch_overlay_pending_{overlay_id}
Transient TTL:  300 seconds
Transient value: [ 'image_html' => ..., 'text_html' => ..., 'sound_url' => ... ]
```

`handle_poll()` checks for this transient immediately after ID validation and before the normal `get_since()` path. If found, it deletes the transient and returns the payload as a regular `has_new: true` poll response. The overlay JS receives it identically to a real order — full animation, audio, and display duration apply.

**`handle_test_alert()`:**
- Tries `get_recent(1)` from Order Repository.
- If an order exists, renders it via `render_alert()`.
- If the log is empty, creates a synthetic `Order_Log_Entry` + `Order_Log_Item` with placeholder data (product name "Test Product", first name "Demo") and renders that through the template engine so `$Product` / `$FirstName` variables resolve naturally.
- Stores rendered payload in the transient; returns a success message.

**`handle_replay_latest()`:**
- Tries `get_recent(1)` from Order Repository.
- Returns a JSON error if the log is empty.
- Renders the real entry and stores it in the transient.
- Behaves identically to a real incoming order — same template, image, audio, animation.

**New AJAX endpoints:**

| Constant | Action | Auth |
|---|---|---|
| `AJAX_TEST_ALERT` | `ccmerch_overlay_test_alert` | `wp_ajax_` + `manage_options` + `NONCE_TEST` |
| `AJAX_REPLAY_LATEST` | `ccmerch_overlay_replay_latest` | `wp_ajax_` + `manage_options` + `NONCE_TEST` |

Both use the shared `NONCE_TEST = 'ccmerch_overlay_test'` nonce action. The nonce is created once in the admin page and reused by both buttons.

**Test Controls UI behavior:**
- Both buttons are hidden when no overlay ID exists (card rendered only when `$has_id` is true).
- Click handler captures the original button label before replacing it with "Sending…".
- Button is disabled during the request to prevent double-click spam.
- On success: feedback div shows the server's message in green (`#00a32a`).
- On error: feedback div shows the error message in red (`#d63638`).
- `finally` block restores the original label and re-enables the button.

**Differences between Test Alert and Replay:**

| Behaviour | Test Alert | Replay Latest Order |
|---|---|---|
| No orders in log | Uses synthetic mock entry | Returns error |
| Alert content | Latest real order OR placeholder | Latest real order only |
| Log impact | No writes | No writes |
| WooCommerce dependency | None at render time | None at render time |

**Known limitations (not bugs):**
- A test/replay alert queued while the overlay is mid-animation is skipped — `overlay.js` skips the poll when `animating === true`. The transient is consumed and the queued alert is lost. This is consistent with the existing behaviour for real orders.
- The transient TTL is 300 seconds. If the OBS browser source is not open, the queued alert expires silently. The admin sees "Queued! Watch your overlay." which accurately reflects the fire-and-forget nature.
- `render_radio_field()` does not set a `label_for` — there is no single associated input. Accessibility is handled by the `<fieldset>` wrapper and the `<label>` wrapping each `<input type="radio">`.

---

### 1.8.0 — Live Preview canvas

**Modified files:**
- `assets/css/admin.css` — replaced `.ccmerch-overlay-preview` block with `.ccmerch-livepreview` canvas styles and all sub-element/animation-state classes
- `includes/admin/class-overlay-admin.php` — replaced "Alert Preview" card with "Live Preview" card (PHP + HTML); replaced old preview-button JS block with new `LivePreview` IIFE

**New UI component:**

The "Alert Preview" card (a simple dark strip) is replaced by a "Live Preview" card containing a `.ccmerch-livepreview` canvas — a `4:3 aspect-ratio` container styled to simulate an OBS scene background. The alert widget sits at `bottom:40px; left:40px` inside the canvas, identical to the real overlay positioning.

**Settings injection (PHP → JS):**

`render_page()` reads `$this->settings->all()` and serialises the 15 appearance/timing fields into a JSON string stored in `data-settings` on the canvas element. The Live Preview IIFE parses this on page load and calls `applySettings()` immediately, so the CSS custom properties are in place before the user clicks anything.

**Settings resolution on trigger (form fields take precedence):**

`resolveSettings()` reads form field values first via `querySelector('[name="ccmerch_overlay_settings[field]"]')` and falls back to `savedConfig` (the parsed `data-settings`) only when a field is absent. This means:
- Saved settings are reflected on page load.
- Unsaved form changes (font, colour, alignment, etc.) are reflected when "Trigger Preview Alert" is clicked without requiring a save first.
- The rendered text content comes from the server (`handle_preview()` uses saved template), but all visual appearance reflects the current form state.

**CSS custom property bridge:**

`applySettings()` writes `--ccmerch-anim`, `--ccmerch-font-family`, `--ccmerch-font-size`, `--ccmerch-font-weight`, `--ccmerch-text-color`, `--ccmerch-text-shadow`, `--ccmerch-text-align`, `--ccmerch-image-size` as CSS custom properties on the canvas element. `.ccmerch-livepreview__text` and `.ccmerch-livepreview__image img` consume them via `var()`, identical to `overlay.css`. Background colour+opacity, border-radius, padding, and flex-direction are applied as inline styles on the alert container.

**Animation state machine:**

`runAnimation()` mirrors the `--hidden / --visible / --hiding` cycle in `overlay.js`:
1. Set `image_html` / `text_html` as `innerHTML`.
2. Remove all state classes, add `--hidden`.
3. Force reflow (`void alertEl.offsetWidth`).
4. Remove `--hidden`, add `--visible` — CSS transition plays.
5. `setTimeout(displayDuration)` → remove `--visible`, add `--hiding`.
6. `setTimeout(animDuration)` → remove `--hiding`, add `--hidden`; clear content; reset `animating` flag.

The `animating` boolean prevents overlapping sequences. A double-click or a second trigger while animating is silently ignored — same guard as `overlay.js`.

**Audio badge:**

If `sound_url` is returned non-empty from `handle_preview()`, `#ccmerch-livepreview-audio` receives `ccmerch-livepreview__audio--visible` (display:flex) at the start of the animation and loses it when the hide phase begins. No audio actually plays in the admin preview — the badge is the sole indicator, consistent with section 7 of the implementation spec ("visually indicated only").

**AJAX endpoint used:**

`AJAX_PREVIEW` (`ccmerch_overlay_preview`) — the existing nonce-protected, `manage_options`-gated endpoint that calls `Order_Repository::get_recent(1)` and `Overlay_Renderer::render_alert()`. It does NOT write a transient, so the OBS browser source is completely unaffected. The "Test Controls" buttons (`AJAX_TEST_ALERT`, `AJAX_REPLAY_LATEST`) are separate and DO write transients — the Live Preview button never calls them.

**Isolation from live overlay (how enforced):**
- The "Trigger Preview Alert" button calls `AJAX_PREVIEW` only.
- `AJAX_PREVIEW` returns rendered HTML fragments without touching transients.
- The animation runs entirely within `#ccmerch-livepreview-canvas` in the admin DOM.
- No rewrite rule, query var, or overlay page URL is involved.
- The OBS browser source polls `AJAX_POLL` independently; no preview action touches that endpoint.

**Known limitations (not bugs):**
- Template content in the preview reflects saved settings only (the server renders the template using the stored option). Unsaved template changes are not previewed until the settings form is saved.
- `audioEnabled` in the preview reflects the saved value (from `data-settings`), not the current form checkbox state, because audio checkbox reads require separate handling. Badge only shows when server returns a non-empty `sound_url`.
- If an overlay ID has not been generated, the entire Live Preview card is hidden (same `$has_id` guard as Test Controls).

---

### 1.9.0 — Layout Editor (1920×1080 drag-and-drop canvas)

**New files:**
- `includes/layout/class-layout-settings.php` — stores element positions, sizes, and styling under `ccmerch_layout_settings` in wp_options; coordinates always in 1920×1080 space
- `includes/layout/class-layout-controller.php` — handles `ccmerch_save_layout` AJAX endpoint; validates and persists layout JSON
- `includes/admin/class-layout-admin.php` — registers "Layout Editor" submenu; passes layout + preview data to JS via `wp_localize_script`; resolves preview content from Order Log (no WooCommerce queries)
- `assets/css/layout-editor.css` — two-column editor layout, canvas checkerboard background, element overlay styles, resize handle positions, properties panel grid
- `assets/js/layout-editor.js` — full drag-and-drop implementation: canvas scale, element overlays, drag/resize state machine, alignment guides, snap-to-grid, selection + properties panel, save

**Modified files:**
- `includes/class-plugin.php` — added use statements for `Layout_Settings`, `Layout_Controller`, `Layout_Admin`; added require_once for all three files after overlay module; instantiates `Layout_Settings` + `Layout_Controller` always (AJAX must be reachable); instantiates `Layout_Admin` inside `is_admin()` block
- `CLAUDE.md` — this entry

**Namespace:** `CCMerchAlert\Layout` → `includes/layout/`

**Layout data structure (stored in `ccmerch_layout_settings`):**

```json
{
  "elements": {
    "product_image":  { "x":40,  "y":880, "w":120, "h":120, "visible":true,  "lock_aspect":true, "border_radius":4, "box_shadow":"" },
    "main_text":      { "x":176, "y":895, "w":700, "h":80,  "visible":true,  "font_family":"", "font_size":48, "font_weight":"700", "color":"#ffffff", "text_align":"left", "line_height":1.3 },
    "secondary_text": { "x":176, "y":985, "w":700, "h":50,  "visible":false, "font_family":"", "font_size":24, "font_weight":"400", "color":"#cccccc", "text_align":"left", "line_height":1.3 }
  },
  "canvas": { "snap_grid":false, "grid_size":10 }
}
```

**Canvas scaling:**
- `Layout_Admin` renders a container div (`#ccmerch-layout-canvas-wrap`).
- `layout-editor.js` creates the inner `.ccmerch-layout-canvas` div at `containerWidth × containerWidth*(1080/1920)` px.
- `scale = containerWidth / 1920`. All stored coordinates multiplied by `scale` for display; mouse offsets divided by `scale` to convert back to 1920×1080 space.
- On `window.resize`, `computeScale()` is re-called and all overlays re-rendered.

**Drag and drop architecture:**
- `onMousedown` on `.ccmerch-layout-el__handle` → starts `resizeState` (stores origin + direction).
- `onMousedown` on `.ccmerch-layout-el` → starts `dragState` (stores origin position).
- `onMousemove` (on `document`) → applies delta / scale to `layout.elements[id].x/y` (drag) or `w/h` (resize).
- `onMouseup` → clears both state objects, hides alignment guides.
- All three listeners use `document` for mousemove/mouseup so dragging outside the canvas does not lose the pointer.

**Alignment guides:**
- When dragging, the element's center-X/Y is compared to canvas center (960, 540).
- If within 8 canvas-space pixels, the guide line appears and the element snaps to true center.
- Guides (`--h`, `--v`) are `position:absolute` divs inside the canvas, shown/hidden via `display`.

**Properties panel:**
- `select(id)` populates `#ccmerch-props-panel` with HTML built from the element's current data.
- Inputs have `data-prop` attributes. `bindPropInputs()` wires `change`/`input` listeners that call `applyProp()`.
- Position/size inputs (X/Y/W/H) are updated live during drag/resize via `syncPropsPosition()`.
- `product_image` shows: lock_aspect checkbox, border_radius.
- `main_text` / `secondary_text` show: font_family, font_size, font_weight, color, text_align, line_height.

**Save flow:**
- "Save Layout" → `onSave()` → POST `action=ccmerch_save_layout` with `layout=<JSON>`.
- `Layout_Controller::handle_save()` → `check_ajax_referer()` → `current_user_can()` → `Layout_Settings::save_layout()` → `wp_send_json_success()`.
- `Layout_Settings::save_layout()` calls `sanitize()` before `update_option()`.
- `dirty` flag is set on every mutation; `beforeunload` fires a browser confirmation dialog if there are unsaved changes.

**Preview content:**
- `Layout_Admin::resolve_preview_data()` calls `Order_Repository::get_recent(1)`. No WooCommerce queries.
- If an order exists: uses real `first_name`, `product_name`, and resolves `image_url` via `wp_get_attachment_image_src()`.
- If log is empty: returns mock data (`{ mock:true, first_name:"Demo", product:"Test Product" }`).
- JS renders text as `"$product was purchased by $first_name!"` directly in the canvas overlay div.

**Isolation from overlay runtime:**
- The layout editor reads and writes `ccmerch_layout_settings` only.
- The live overlay (`Overlay_Renderer`, `Overlay_Controller`, `overlay.js`) is completely unmodified.
- No animation, no poll endpoint, no transient writes, no audio.
- The connection to the runtime is a future step: `Overlay_Renderer::render_page()` will eventually read `Layout_Settings::get_layout()` to position the `.ccmerch-overlay` container using the stored X/Y/W/H values instead of the current fixed CSS.

**Extension points:**
- New draggable element types can be added by: adding a key to `Layout_Settings::defaults()['elements']`, adding its label to the `LABELS` object in `layout-editor.js`, and adding a `renderContent()` branch for it.
- `Layout_Controller::AJAX_SAVE` is a class constant — future endpoints (load snapshot, duplicate scene, reset to defaults) follow the same pattern.
- `Layout_Admin::PAGE = 'ccmerch-alert-layout'` is a stable slug for external links.

**Known limitations (not bugs):**
- The layout editor positions elements but the live overlay runtime still uses `bottom:40px; left:40px` CSS. The runtime mapping is a planned future step and is explicitly not part of this release.
- Resize with aspect lock constrains both axes only for diagonal handles; edge handles lock the perpendicular dimension for square results only when the original was square. This matches standard image editors.
- Font sizes in the properties panel are in 1920×1080 space (e.g., `48` means 48 px at full resolution), displayed at `48 * scale` px in the canvas. The numbers will appear large relative to typical screen font sizes.
- The canvas checkerboard uses CSS `background-image` gradients — it does not render in very old browsers (IE11).

---

### 2.4.0 — Creator Portal access system

**Goal:** Each Creator Profile has a secure public URL — the Creator Portal — accessible without a WordPress login. The portal is a v1 placeholder that shows the creator's name, status, and description, with a message that full configuration features are coming. The foundation is designed so future versions can add alert designer, overlay designer, audio settings, order history, and more without rearchitecting.

**New files:**
- `includes/creators/class-creator-portal-controller.php` — registers the `/ccmerch-alert/creator/{token}/` rewrite rule; validates access tokens on `template_redirect`; 404s on invalid/unknown tokens or disabled profiles
- `includes/creators/class-creator-portal-renderer.php` — outputs a complete standalone HTML document (no WordPress theme); displays creator name as page heading, status badge, optional description, and a "coming soon" placeholder card
- `assets/css/creator-portal.css` — standalone portal page styles; responsive; no external dependencies

**Modified files:**
- `includes/creators/class-creator-installer.php` — added `creator_access_token VARCHAR(64) NOT NULL DEFAULT ''` column with `UNIQUE KEY`; bumped `DB_VERSION` to `'1.1'`; added `backfill_access_tokens()` called from `maybe_install()` to generate tokens for any existing profiles created before 2.4.0
- `includes/creators/class-creator-profile.php` — added `creator_access_token` as the third constructor parameter (between `creator_uuid` and `creator_name`)
- `includes/creators/class-creator-repository.php` — `create()` now accepts `creator_uuid` and `creator_access_token` as explicit parameters (generated by service layer, not the repository); added `find_by_access_token(string): ?Creator_Profile`; added `update_access_token(int, string): bool`; updated `row_to_profile()` to map the new column
- `includes/creators/class-creator-service.php` — added `generate_token(): string` private helper (`bin2hex(random_bytes(32))` — 256 bits); added `reset_access_token(int): string|false`; added `get_portal_url(Creator_Profile): string` (uses `home_url()`); added `get_creator_by_token(string): ?Creator_Profile`; updated `create_creator()` to generate UUID and token before calling `repository->create()`
- `includes/admin/class-creator-admin.php` — added `ACTION_RESET_TOKEN` and `NONCE_RESET_TOKEN` constants; registered `admin_post_ccmerch_creator_reset_token` hook; added "Creator Portal" column to list table with Copy Link and Reset buttons; added `render_portal_access_section()` to edit page showing the portal URL and a Reset form; added `handle_reset_token()` PRG handler; added `token_reset` notice to `render_notices()`; added `render_portal_cell()` helper
- `includes/class-plugin.php` — added `use` statements for `Creator_Portal_Controller` and `Creator_Portal_Renderer`; added property `$creator_portal_ctrl`; added `require_once` for both new files in `load_files()`; instantiates `Creator_Portal_Renderer` + `Creator_Portal_Controller` in `load_components()` (always, not admin-only)
- `ccmerch-alert.php` — version bumped to Alpha 2.4.0

**Security model:**

| Concern | Implementation |
|---|---|
| Token entropy | `random_bytes(32)` → 64 hex chars → 256-bit keyspace |
| Token storage | UNIQUE column in DB; never in URLs as GET params or cookies |
| Token comparison | `hash_equals()` after DB lookup prevents timing attacks |
| Access validation | `find_by_access_token()` → null? 404. `status ≠ 'active'`? 404. Same response for both cases — no information leakage |
| Token reset | New token generated and stored; previous URL immediately dead |
| Admin mutations | All token resets go through `check_admin_referer()` + `current_user_can('manage_options')` |
| Portal page | `noindex, nofollow` meta tag; no WordPress session data exposed |

**Routing architecture:**

```
GET /ccmerch-alert/creator/{64-char-hex-token}/
    │
    ▼ WP rewrite rule (registered by Creator_Portal_Controller on init)
    │  ccmerch_creator_token query var carries the token
    │
    ▼ template_redirect → Creator_Portal_Controller::maybe_render_portal()
    │  1. query var present? — else not our route, return
    │  2. find_by_access_token($token) — null? wp_die(404)
    │  3. hash_equals(stored, request) — mismatch? wp_die(404)
    │  4. status === 'active' — else wp_die(404)
    │
    ▼ Creator_Portal_Renderer::render_page(Creator_Profile)
       standalone HTML: creator name, status badge, description, coming-soon card
       links creator-portal.css; no WordPress theme; no WordPress session
       exit
```

**Rewrite rule activation:**
The `Creator_Portal_Controller::register_rewrite_rules()` runs on `init` every request, keeping the rule in the in-memory `WP_Rewrite` object. The persistent rewrite cache is rebuilt by the existing `Overlay_Controller::maybe_flush_rewrite()` on `wp_loaded` after activation — no second activation hook was added because the flush already fires after all `init` hooks (including the portal rule registration) have run.

**Access token backfill:**
`Creator_Installer::maybe_install()` now calls `backfill_access_tokens()` when `DB_VERSION` is outdated. `dbDelta()` adds the column with `DEFAULT ''`; the backfill then generates a fresh 64-char hex token for every row where `creator_access_token = ''`. This runs exactly once per schema upgrade; subsequent requests hit the version-match fast-return path.

**Admin UI:**

List table "Creator Portal" column:
- **Copy Link** — calls `navigator.clipboard.writeText()` via a small inline script.
- **Reset** — submits `admin_post_ccmerch_creator_reset_token` with a per-creator nonce and JS `confirm()` dialog; redirects back to the list with a success notice.

Edit page "Creator Portal Access" section (below the save button):
- Read-only URL input + **Copy Link** + **Open** (opens in new tab).
- **Reset Creator Access Link** button with confirmation dialog; redirects back to the edit page with `?ccmerch_notice=token_reset`.

**Future Creator Portal expansion:**
The portal is designed as a long-lived foundation. Future versions can add:
- Alert Designer (per-creator templates, triggers)
- Overlay Designer (per-creator overlay settings)
- Browser Source URLs (per-creator stream alert URL)
- Audio Settings (per-creator alert sounds)
- Alert Queue (live queue view)
- Statistics (orders, alert counts)
- Order History (creator-scoped order log)
- Creator Branding (custom logo, colours)
- Theme Selection
- Media Uploads

All future features are additive: add new endpoints in `Creator_Portal_Controller`, new render methods in `Creator_Portal_Renderer`, new service methods in `Creator_Service`. No existing code changes.

**Design decisions:**
- The `creator_uuid` remains a stable internal identifier (UUID v4) used for future REST API and inter-system references. The `creator_access_token` is a separate credential specifically for portal URL access — they serve different purposes and must not be merged.
- Token generation (`generate_token()`) lives in `Creator_Service`, not `Creator_Repository`, because token creation is a business decision (entropy requirements, format, rotation policy), not a data concern.
- The repository's `create()` signature now accepts `creator_uuid` and `creator_access_token` as explicit parameters. The service generates both before calling the repository. This inverts from the original design (repository generated the UUID) to keep all generation logic in one place.
- `Creator_Portal_Controller` does NOT consume the `ccmerch_alert_flush_rewrite` flag — that is `Overlay_Controller`'s responsibility. The creator portal rule is registered on `init` before the flush fires on `wp_loaded`, so it is always included.

**Known limitations (not bugs):**
- The portal page currently has no authentication beyond the access token. Any holder of the URL can view the portal. This is by design for v1 — the portal is informational only. Future versions with write capabilities will need additional per-action authentication.
- Token reset from the list table does not provide visual feedback of the new URL — the admin is redirected to the list with a success notice. To see and copy the new URL, the admin should navigate to the edit page. This matches the "minimum viable" scope for v1.

---

### 2.5.0 — Creator-specific Overlay Configuration

**Goal:** Each Creator Profile now owns its own Overlay Profile — independent browser source URL, layout, audio, templates, and appearance — accessible from the Creator Portal without a WordPress admin login. The existing Overlay runtime (`Overlay_Renderer`, `Overlay_Controller`, `overlay.js`) is reused entirely via interface extraction. The Creator Portal placeholder is replaced with a full overlay configuration UI.

**New files:**
- `includes/overlay/interface-overlay-settings-contract.php` — new interface defining the minimum surface that `Overlay_Renderer` requires from any settings source (`get()`, `all()`, `get_layout()`); implemented by both `Overlay_Settings` and `Creator_Overlay_Profile`
- `includes/creators/class-creator-overlay-repository.php` — all SQL for `{prefix}ccmerch_creator_overlay_settings`; `find_by_creator_id()`, `find_creator_id_by_overlay_id()`, `create()`, `save_settings()`, `update_overlay_id()`, `delete_by_creator_id()`; stores settings as a JSON blob
- `includes/creators/class-creator-overlay-profile.php` — implements `Overlay_Settings_Contract`; wraps one DB row; provides `get()`, `all()`, `get_layout()`, `get_overlay_id()`, `get_poll_action()`, `get_overlay_url()`, `sanitize()`; all field keys and defaults match `Overlay_Settings`
- `includes/creators/class-creator-overlay-controller.php` — rewrite rule `/ccmerch-alert/creator-overlay/{id}/`; `template_redirect` → renders overlay via `new Overlay_Renderer($profile, $engine)`; nopriv AJAX poll; nopriv portal-test AJAX; admin test/replay AJAX; `admin_post_` generate-ID; `admin_post_` save settings from portal form

**Modified files:**
- `includes/overlay/class-overlay-settings.php` — added `implements Overlay_Settings_Contract` to class declaration
- `includes/overlay/class-overlay-renderer.php` — constructor type hint changed from `Overlay_Settings` to `Overlay_Settings_Contract`; `render_page()` reads overlay_id and poll action via contract methods (not from the settings array) — see 2.5.1 for the fix to those two lines
- `includes/creators/class-creator-installer.php` — `DB_VERSION` bumped `'1.1'` → `'1.2'`; `maybe_install()` now also calls `backfill_overlay_profiles()`; `create_tables()` now creates third table `ccmerch_creator_overlay_settings`
- `includes/creators/class-creator-service.php` — added `Creator_Overlay_Repository` as second constructor parameter; `create_creator()` now calls `overlay_repository->create()` after successful insert; `delete_creator()` now calls `overlay_repository->delete_by_creator_id()` before deleting; new public methods: `get_overlay_profile_id_by_overlay_id()`, `get_overlay_profile()`, `reset_overlay_id()`, `save_overlay_settings()`; new private `generate_overlay_id()` helper
- `includes/creators/class-creator-portal-renderer.php` — complete rewrite from placeholder to full overlay configuration UI; loads `admin.css`, `layout-editor.css`, `creator-portal.css`, `layout-editor.js`; renders Browser Source URL card, Test Controls card, overlay settings form with hidden appearance fields, Overlay Designer canvas; uses `Creator_Service` and `Order_Repository`
- `includes/class-plugin.php` — added use/require/property/instantiation for all new classes; `Creator_Service` constructor now receives `Creator_Overlay_Repository`; `Creator_Portal_Renderer` now receives `(Creator_Service, Order_Repository)`; `Creator_Overlay_Controller` instantiated always (not admin-only) with `(Creator_Service, Order_Repository, Overlay_Template_Engine)`
- `assets/css/creator-portal.css` — added `.ccmerch-portal--config` wider max-width for layout editor canvas; `.ccmerch-portal__notice` + success variant; WordPress-style button resets (admin CSS not loaded on portal); `form-table` compatibility; `.ccmerch-overlay-url` styles; responsive additions
- `uninstall.php` — added `DROP TABLE IF EXISTS {prefix}ccmerch_creator_overlay_settings`
- `ccmerch-alert.php` — version bumped to Alpha 2.5.0

**New database table (`{prefix}ccmerch_creator_overlay_settings`):**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| creator_id | BIGINT UNSIGNED | UNIQUE KEY — one overlay profile per creator |
| overlay_id | VARCHAR(32) | 32-char hex browser-source token; KEY for routing |
| settings | LONGTEXT | JSON blob with all `Overlay_Settings::FIELD_*` keys |
| created_at | DATETIME | UTC |
| updated_at | DATETIME | UTC |

**Interface extraction pattern:**

`Overlay_Renderer` was typed to the concrete `Overlay_Settings`. To support per-creator overlay rendering without duplication, `Overlay_Settings_Contract` was extracted as a thin interface. Only the `Overlay_Renderer` constructor type hint changed (`Overlay_Settings` → `Overlay_Settings_Contract`). Both `Overlay_Settings` and `Creator_Overlay_Profile` implement the interface. The renderer is instantiated fresh per-request by `Creator_Overlay_Controller` with the creator's profile, so each overlay page renders with the correct creator's settings.

**Security model:**

| Concern | Implementation |
|---|---|
| Overlay access token | `random_bytes(16)` → 32 hex chars → 128-bit keyspace (same as global overlay) |
| Overlay poll auth | overlay_id in POST body; `hash_equals()` comparison; no nonce (OBS can't carry nonces) |
| Portal form saves | `creator_access_token` in POST + `wp_verify_nonce('ccmerch_portal_action_' . $token)` |
| Portal test AJAX | nopriv; `hash_equals()` on creator_access_token; status === 'active' check |
| Generate ID (portal) | same token + nonce validation; creates new overlay_id, previous URL immediately dead |
| Admin test/replay | `check_ajax_referer(NONCE_TEST)` + `current_user_can('manage_options')` |
| Isolation | creator can only trigger alerts on their own overlay; never exposes other creator data |

**URL routing:**

| URL pattern | Handler |
|---|---|
| `/ccmerch-alert/creator/{token}/` | `Creator_Portal_Controller::maybe_render_portal()` → portal HTML |
| `/ccmerch-alert/creator-overlay/{id}/` | `Creator_Overlay_Controller::maybe_render_overlay()` → overlay HTML |
| `admin-ajax.php?action=ccmerch_creator_overlay_poll` | `handle_poll()` — nopriv, overlay_id token |
| `admin-ajax.php?action=ccmerch_creator_portal_test` | `handle_portal_test()` — nopriv, creator_token |
| `admin-ajax.php?action=ccmerch_creator_overlay_test` | `handle_test()` — manage_options + nonce |
| `admin-ajax.php?action=ccmerch_creator_overlay_replay` | `handle_replay()` — manage_options + nonce |
| `admin-post.php?action=ccmerch_creator_generate_overlay_id` | `handle_generate_id()` — creator_token + nonce |
| `admin-post.php?action=ccmerch_creator_overlay_save` | `handle_save()` — creator_token + nonce |

**Auto-create / auto-delete lifecycle:**

- `Creator_Service::create_creator()` → after successful DB insert → `overlay_repository->create(creator_id, generate_overlay_id())` — every new creator immediately has an overlay profile with default settings.
- `Creator_Service::delete_creator()` → `overlay_repository->delete_by_creator_id(id)` before deleting profile — no orphaned rows.
- `Creator_Installer::backfill_overlay_profiles()` — called from `maybe_install()` on schema version mismatch — LEFT JOIN to find creators without overlay rows, generates overlay_id for each, inserts with empty JSON settings. Handles existing creators from 2.4.0 without requiring reactivation.

**Creator Portal page (rewritten):**

The v1 placeholder (creator name + coming-soon card) is replaced with a full configuration page. No WordPress admin login required — all authentication is via the creator's 256-bit access token in the URL.

Sections:
1. Header — creator name
2. Notices — `?ccmerch_notice=settings_saved` / `overlay_id_generated`
3. Browser Source URL card — read-only URL, Copy URL, Open in Browser, Generate New Overlay ID form
4. Test Controls card (visible only when overlay_id exists) — Send Test Alert button
5. Overlay configuration form:
   - Audio section (visible form fields — checkbox + attachment ID number input)
   - Hidden appearance fields (template, font, color, text_align, bg_color, etc.) — read/written by layout-editor.js properties panel
   - Overlay Designer canvas (same 1920×1080 drag-and-drop editor as admin page)
   - Save Settings button

The form posts to `admin-post.php?action=ccmerch_creator_overlay_save`. `Creator_Overlay_Controller::handle_save()` validates the token + nonce, calls `$profile->sanitize($_POST[OPTION_KEY])`, and saves via `Creator_Service::save_overlay_settings()`.

**Audio limitation (v1):**
The portal audio section uses a plain number input for attachment IDs because `wp.media()` requires WordPress admin JS not available on public pages. The admin can look up the attachment ID from the WordPress Media Library. Per-category audio mapping is not available in the portal (requires admin JS). Both limitations are documented.

**Rewrite rule registration:**
`Creator_Overlay_Controller::register_rewrite_rules()` fires on `init`. The existing `Overlay_Controller::maybe_flush_rewrite()` on `wp_loaded` flushes the rewrite cache after activation, which captures the creator overlay rule (registered before flush fires). No second activation hook added.

**Future category routing (prepared, not implemented):**

The data model is designed for the future routing path:
```
WooCommerce order item
    │  product category term ID
    ▼
Creator_Service::find_creator_for_category(term_id) → Creator_Profile
    │
    ▼
Creator_Service::get_overlay_profile(creator_id) → Creator_Overlay_Profile
    │  implements Overlay_Settings_Contract
    ▼
new Overlay_Renderer($profile, $engine) → render_alert() / render_items()
```
`find_creator_for_category()` already exists in `Creator_Service` and calls `Creator_Repository::find_by_category_term_id()`. The only future step is wiring the Alert Engine dispatch path to this lookup. No existing class changes when routing is implemented.

**Known limitations (not bugs):**
- Audio category map is not configurable from the portal (requires admin JS for the media picker). The default sound is configurable via attachment ID number input.
- Template text in the layout editor canvas preview reflects saved settings (the `resolve_preview_data()` call uses `Order_Repository::get_recent(1)`). Unsaved template edits are not previewed until saved.
- The creator overlay poll uses the same `overlay.js` that runs on the global overlay page. The JS is not per-creator — it reads its config from the `ccmerchOverlay` inline JS object injected by `Overlay_Renderer::render_page()`, which IS per-creator.

---

### 2.3.1 — Creator table deferred-install fix

**Root cause:** `register_activation_hook()` only fires when the plugin is activated via the WordPress plugins page. Deploying 2.3.0 files to an already-active installation does not trigger activation hooks, so the two Creator tables were never created. Every call to `Creator_Repository::create()` failed with a MySQL "table doesn't exist" error, which the service layer propagated as the `'db_fail'` error code.

**Modified files:**
- `includes/creators/class-creator-installer.php` — added `public static maybe_install(): void`; checks `get_option(DB_VERSION_KEY) === DB_VERSION`; runs `create_tables()` + `update_option()` only when the versions differ (first install or future schema upgrades).
- `ccmerch-alert.php` — `plugins_loaded` callback now calls `Creator_Installer::maybe_install()` before `Plugin::get_instance()->init()`. One `get_option()` call per request; `dbDelta()` runs only once per schema version.
- `includes/creators/class-creator-repository.php` — `create()` now logs `$wpdb->last_error` via `error_log()` when an insert fails, guarded by `WP_DEBUG_LOG`. The frontend still receives the generic `'db_fail'` error code — no raw SQL is exposed.

**Installation / update flow (after this fix):**

```
Plugin first installed → activation hook → Creator_Installer::activate() → tables created
                                                                          → DB_VERSION_KEY option set

Plugin files updated without reactivation → no activation hook fires
    │
    ▼ plugins_loaded fires
Creator_Installer::maybe_install()
    │  get_option(DB_VERSION_KEY) !== DB_VERSION  → tables created (or upgraded)
    │  get_option(DB_VERSION_KEY) === DB_VERSION  → no-op (cheap path)
    ▼
Plugin::get_instance()->init() continues normally
```

**No migration step required.** `dbDelta()` creates tables that do not exist and upgrades columns that do — it never drops data. Existing 2.3.0 installs that had the tables from a fresh activation are unaffected (version check hits the fast-return branch).

**Pattern note:** Every future installer (`Order_Installer` included) should add a `maybe_install()` method following this pattern so the plugin is robust against file-only deployments.

---

### 2.3.0 — Creator Profile Management System

**Goal:** Administrative foundation for creator-based alert routing. Every WooCommerce product category can be assigned to exactly one Creator Profile. The system is architected for future multi-category, multi-overlay, and multi-creator expansion — no routing or overlay integration is implemented in this version.

**New files:**
- `includes/creators/class-creator-installer.php` — dbDelta table creation for both Creator tables; loaded in bootstrap like `Order_Installer`
- `includes/creators/class-creator-profile.php` — immutable value object (id, creator_uuid, creator_name, description, status, created_at, updated_at)
- `includes/creators/class-creator-repository.php` — all SQL (CRUD + category assignment + is_category_taken + find_by_category_term_id)
- `includes/creators/class-creator-service.php` — business-logic layer: uniqueness enforcement, WC-active gating, CRUD orchestration, future routing hook
- `includes/admin/class-creator-admin.php` — admin submenu page with list/create/edit screens and admin_post_ handlers

**Modified files:**
- `ccmerch-alert.php` — added Creator_Installer require + activation hook; version bumped to Alpha 2.3.0
- `includes/class-plugin.php` — added Creator_Repository, Creator_Service (always), Creator_Admin (admin-only) instantiation
- `uninstall.php` — drops both Creator tables and deletes `ccmerch_creator_db_version` option on uninstall
- `assets/css/admin.css` — added `.ccmerch-creator-status` badge styles (active = green, disabled = grey)

**Database tables created on activation:**

`{prefix}ccmerch_creator_profiles`:
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| creator_uuid | CHAR(36) | UNIQUE — secure public identifier (UUID v4) |
| creator_name | VARCHAR(255) | Display name |
| description | TEXT | Optional internal notes |
| status | VARCHAR(20) DEFAULT 'active' | 'active' or 'disabled' |
| created_at | DATETIME | UTC |
| updated_at | DATETIME | UTC |

`{prefix}ccmerch_creator_category_assignments`:
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| creator_id | BIGINT UNSIGNED | FK to creator_profiles.id (KEY) |
| wc_category_term_id | BIGINT UNSIGNED | WooCommerce product_cat term ID (KEY) |
| created_at | DATETIME | UTC |

**Architecture decisions:**

- **No UNIQUE KEY on wc_category_term_id**: The one-category-per-creator rule is enforced at the application layer via `Creator_Service::create_creator()` / `update_creator()`, not via a DB constraint. When the restriction is later removed to allow multiple categories per creator, only the service code changes — no schema migration needed.
- **Term IDs only — never category names**: `Creator_Profile` contains no category data. Category names are resolved via `get_term()` at render time. This matches the Order Log pattern (`category_ids` stored as term IDs) and survives WooCommerce category renames without stale data.
- **Creator_UUID**: Each profile receives a `wp_generate_uuid4()` UUID at creation time. Used as a stable public identifier for future browser-source URLs and REST API endpoints.
- **Service layer owns business rules**: `Creator_Repository` is pure data access. `Creator_Service` owns uniqueness checks, WC-active gating, and the create/update/delete orchestration sequence.

**Admin UI:**

Three screens dispatch from a single `render_page()` callback via `?action=` GET param:
- `(none)` → list table with Creator Name, Status badge, Assigned Category (resolved from term ID), Created, Last Updated, Edit/Delete actions.
- `create` → form for Creator Name (required), WooCommerce Category (required single-select), Description (optional textarea), Status (Active/Disabled select).
- `edit` → same form pre-filled; changing category removes old assignment and inserts new one.

Mutations use admin_post_ actions with Post/Redirect/Get pattern. Error codes are passed via URL query params (`?ccmerch_error=cat_taken`) and mapped to translatable messages in `error_message()`. Delete requires per-creator nonce (`ccmerch_creator_delete_{id}`) and a JS `confirm()` dialog.

**WooCommerce inactive handling:**
- Create button is hidden on list page.
- Category select replaced with a notice on create/edit forms.
- `Creator_Service::create_creator()` and `update_creator()` return `'wc_inactive'` error code.

**Future compatibility (architecture prepared, not implemented):**
- `Creator_Repository::find_by_category_term_id(int $term_id)` is the routing lookup entry point. Future Alert Engine calls `Creator_Service::find_creator_for_category($term_id)` for order item routing.
- Assignments table has no UNIQUE constraint on `wc_category_term_id` — supports future many-to-many.
- `Creator_Profile` contains no overlay, audio, or template references — future overlay/audio profile IDs can be added as FK columns without breaking existing data.
- `Creator_Service` is the stable contract for all future creator operations. The admin and engine talk only to this layer, never to the repository directly.

---

### 2.2.0 — Per-item alert queue

**Goal:** Every purchased product in an order generates its own independent alert, played sequentially. An order with 3 line items fires 3 alerts in purchase sequence, each with its own image, audio, and template rendering.

**Alert event model changed from Order → Order Item.**

**Modified files:**
- `includes/overlay/class-overlay-renderer.php` — new public `render_items(Order_Log_Entry): array[]` method; expands one order into one alert fragment per line item, using a single-item projection of the entry so `render_alert()` is reused unchanged. Order-level fields (`$FirstName`, `$OrderNumber`) are preserved on every item.
- `includes/overlay/class-overlay-controller.php` — `handle_poll()` now returns `{ has_new, queue: [...], server_time }` instead of flat alert fields. Entries from `get_since()` are reversed (newest-first → oldest-first) before expanding so the queue plays in chronological order. Transient format changed from `{ image_html, ... }` to `{ queue: [...] }`. `handle_test_alert()` and `handle_replay_latest()` both call `render_items()` and store the queue format.
- `assets/js/overlay.js` — added `alertQueue` array; added `processQueue()` function; removed `if (animating) { return; }` guard from `poll()` so polling continues while alerts play; poll response now pushes items to `alertQueue` and calls `processQueue()`; both early-return and animation-end paths in `showAlert()` call `processQueue()` to drain the queue automatically.

**Alert queue architecture:**

```
WooCommerce order placed (e.g. Hoodie + T-Shirt + Mug)
    │
    ▼ Order_Logger writes one ccmerch_order_log row + 3 items rows
    │
    ▼ overlay.js poll fires → handle_poll()
    │  get_since(since, FILTER_ALL) → [Order_Log_Entry (3 items)]
    │  array_reverse → oldest-first order
    │  render_items(entry) → [{ image, text, sound }, { … }, { … }]
    │  returns { has_new: true, queue: [3 alerts], server_time }
    │
    ▼ overlay.js receives queue
    │  alertQueue.push( queue[0], queue[1], queue[2] )
    │  processQueue() → animating=false → showAlert(Hoodie alert)
    │
    ▼ Hoodie alert plays (animating=true during display + fade)
    │  poll continues running every 5s — new arrivals queued, not skipped
    │
    ▼ Hoodie animation completes → animating=false → processQueue()
    │  showAlert(T-Shirt alert)
    │
    ▼ T-Shirt completes → processQueue() → showAlert(Mug alert)
    │
    ▼ Mug completes → processQueue() → alertQueue empty → done
```

**Category routing per product:**
Each alert is rendered from a single-item projection (`render_items()` builds `new Order_Log_Entry(..., [$item])`). This means `resolve_sound_url()`, `$Category`, `$Variation`, `$Product`, `$Quantity`, and the image are all resolved from that specific item's data. Category-based audio mapping is per-product, not inherited from the first item.

**Quantity handling:**
One line item → one alert regardless of quantity. The `$Quantity` variable in the template reflects the purchased count (e.g. "3"). Users can write templates like `"$FirstName purchased $Quantity × $Product"`.

**Test Controls (`handle_test_alert`, `handle_replay_latest`):**
Both now call `render_items()` and store `{ queue: [...] }` in the transient. When the order has multiple items, the Test button fires one alert per item. The synthetic mock entry (used when the log is empty) has a single item, so it fires one alert.

**Transient format change (non-breaking):**
Old format: `{ image_html, text_html, secondary_html, sound_url }`
New format: `{ queue: [{ image_html, text_html, secondary_html, sound_url }, ...] }`
Any transient written before this version will fail the `$pending['queue']` check and be treated as having an empty queue (`has_new: false`). The old transient expires naturally (300s TTL).

**Performance:**
- `render_items()` iterates the items already loaded in memory — no additional DB queries.
- Polling while animating means new orders are enqueued immediately rather than waiting for the animation to finish. No duplicate delivery: `since` advances to `server_time` on every poll response.
- `processQueue()` is synchronous and O(1) per call — it shifts one item and starts one animation.

**Future compatibility:**
- `alertQueue` is a flat array of fragments — priority alerts can be inserted with `alertQueue.unshift()`.
- `render_items()` is the natural extension point for per-item category routing to different overlay destinations.
- The `queue` key in the poll response is a stable contract; adding new keys to each item (e.g. `priority`, `profile_id`) is additive and non-breaking.

---

### 2.1.0 — True WYSIWYG Overlay Designer

**Goal:** Make the Overlay Designer a real-time canvas editor. All appearance and template settings are now edited through the Properties panel; no form tables exist outside the canvas. The canvas preview matches the OBS output exactly.

**Modified files:**
- `assets/js/layout-editor.js` — full rewrite: background preview on text elements; template substitution with preview data; `hexToRgba()` colour helper; `applyAppearanceProp()` writes to hidden form fields; expanded Properties panel with `buildBackgroundProps()` and `buildElementProps()` (typography + template sections per element); `bindAppearanceInputs()` wires props-panel inputs to hidden fields; `bindFormSync()` watches all appearance fields; clicking empty canvas selects `background` virtual entity; canvas gains `ccmerch-layout-canvas--bg-selected` outline when background is selected
- `includes/admin/class-overlay-admin.php` — `render_layout_editor_section()` rewritten: visible Alert Templates and Appearance form tables replaced by hidden `#ccmerch-overlay-appearance-fields` div; canvas heading renamed to "Overlay Designer"; empty props message updated; class docblock updated
- `assets/css/layout-editor.css` — `min-height:420px` on `.ccmerch-layout-props`; `.ccmerch-layout-canvas--bg-selected` outline; textarea, color, select, and label styles for expanded props panel
- `ccmerch-alert.php` — version bumped to Alpha 2.1.0

**True WYSIWYG architecture:**

All appearance settings (templates, font, color, background, timing) are stored as hidden `<input>`/`<textarea>`/`<select>` fields inside the Settings API `<form>`. The Properties panel generates live inputs that write to these hidden fields via `applyAppearanceProp()`. The canvas re-renders immediately via `renderAll()` on every change. The same settings drive `Overlay_Renderer::render_page()` at runtime — no conversion layer.

**Properties panel by selection:**

| Selection | Properties shown |
|---|---|
| `main_text` | Alert Template textarea, Font Family, Size, Weight, Color, Alignment, Position/Size, Visible |
| `secondary_text` | Secondary Template textarea, same typography controls, Position/Size, Visible |
| `product_image` | Border Radius, Lock Aspect, Image Quality, Position/Size, Visible |
| `background` (click empty canvas) | BG Color, Opacity, Border Radius, Padding, Text Shadow, Fade Duration, Display Duration |

**Canvas preview:**
- Text elements render the actual `rgba()` background colour/opacity on the element container, with scaled border-radius and padding
- Template text is substituted with preview data from the most recent Order Log entry or mock fallback
- Typography changes (font, size, weight, color, alignment) update in real time
- Clicking empty canvas selects the `background` virtual entity and outlines the canvas

**Single Source of Truth remains enforced:**
- All settings stored in `ccmerch_overlay_settings` — no second option key
- Hidden form fields use identical `name="ccmerch_overlay_settings[field]"` attributes; sanitize callback and defaults are unchanged
- Runtime (`Overlay_Renderer`, `overlay.js`, `overlay.css`) is completely unmodified
- Layout positions continue to use hidden layout inputs updated by drag/resize

---

### 2.0.2 — Layout Editor is the single visual editor

**Goal:** Consolidate all visual design controls into one place. Remove duplicate sections from the Overlay Profile page.

**Modified files:**
- `includes/overlay/class-overlay-settings.php` — removed `SECTION_GENERAL` and `SECTION_APPEARANCE` constants; removed all corresponding `add_settings_section()` and `add_settings_field()` calls from `register()`. Field constants, defaults, and sanitize logic are unchanged — the fields still save correctly via the Settings API form POST. Only Audio remains registered via `add_settings_section()`.
- `includes/admin/class-overlay-admin.php` — removed `Overlay_Renderer` from constructor (dead dependency after preview removal); removed Live Preview card and its `$prev_config` / `$prev_s` variables; removed the Live Preview JS IIFE (~230 lines); expanded `render_layout_editor_section()` to render Alert Templates and Appearance tables directly above the canvas, calling the existing `Overlay_Settings` field renderer methods; updated submit button label to "Save Settings"; updated class docblock.
- `includes/class-plugin.php` — removed `$overlay_renderer` argument from `new Overlay_Admin(...)` call.
- `assets/js/layout-editor.js` — updated properties panel hint text: "Appearance section above" → "Appearance fields above the canvas".
- `assets/css/admin.css` — removed entire Live Preview CSS block (`.ccmerch-livepreview`, `.ccmerch-lp-el*`, `.ccmerch-lp-text`, `.ccmerch-livepreview__audio*`, `.ccmerch-livepreview__controls`, `.ccmerch-livepreview__hint`, `.ccmerch-overlay-preview-error`).

**Overlay Profile page structure after this change:**

```
Browser Source URL card
Test Controls card (if overlay ID exists)
Settings form:
    Audio (rendered via do_settings_sections)
    Alert Templates (rendered by render_layout_editor_section)
    Appearance (rendered by render_layout_editor_section)
    Layout Editor canvas (toolbar + drag-and-drop)
    [Save Settings button]
```

**Single source of truth confirmed:**
- One visual editor: the Layout Editor. No other place exists to edit appearance or templates.
- Audio remains a standalone configuration section (configuration, not layout).
- Runtime behaviour is completely unchanged — `Overlay_Renderer`, `Overlay_Controller`, `overlay.js`, and `overlay.css` are untouched.
- All field constants, sanitize logic, and defaults remain in `Overlay_Settings`; only the Settings API registration routing was changed.
- Two-way sync in `layout-editor.js` (`bindFormSync` / `getFormVal`) continues to work because the HTML `name` attributes on the moved fields are identical: `ccmerch_overlay_settings[field]`.

**Backwards compatibility:**
- All settings stored in `ccmerch_overlay_settings` are preserved. Moving fields out of registered sections does not affect stored data or the sanitize callback.
- No migration needed.

---

### 2.0.1 — Architecture validation and single-source-of-truth enforcement

**Problem statement:**
Three drift issues were discovered through a full architecture audit after 2.0.0:

1. `FIELD_IMAGE_POSITION` was a dead setting — stored in `ccmerch_overlay_settings`, shown in the admin form, and consumed by the Live Preview JS, but `Overlay_Renderer::render_page()` never read it. In the independently-positioned layout system, image position = layout x/y coordinates; the left/right/top/bottom concept belongs to the old flex-container era and no longer applies.

2. `--ccmerch-image-size` CSS custom property was injected into the overlay page's `:root` block by `render_page()` but consumed by nothing in `overlay.css`. The image element fills its layout-sized container via `width:100%;height:100%`. The property was a leftover from the old single-container layout.

3. The Live Preview canvas (admin "Trigger Preview Alert" widget) used a completely different DOM structure from the runtime: a single flex container fixed at `bottom:40px;left:40px` in the card. The runtime uses three independently positioned `position:fixed` divs at layout x/y/w/h coordinates. This broke the WYSIWYG guarantee — what the user saw in the preview was NOT what OBS would display.

**Modified files:**
- `includes/overlay/class-overlay-settings.php` — removed `FIELD_IMAGE_POSITION` constant; removed its `add_settings_field()` from `register()`; removed from `defaults()` and `sanitize()`; removed `sanitize_image_position()` private method
- `includes/overlay/class-overlay-renderer.php` — removed `$image_size` variable and `--ccmerch-image-size` CSS custom property from `render_page()` (dead output; `FIELD_IMAGE_SIZE` is retained for `wp_get_attachment_image()` quality in `render_image()`)
- `includes/admin/class-overlay-admin.php` — removed `imagePosition` and `imageSize` from `$prev_config`; replaced single `#ccmerch-livepreview-alert` flex container with three `#ccmerch-lp-image`, `#ccmerch-lp-text`, `#ccmerch-lp-secondary` independent elements; rewrote Live Preview JS IIFE to read element positions from `ccmerchLayout.layout` (already localized), scale coordinates to the canvas size, apply typography CSS vars (font size scaled), and animate all three elements through `ccmerch-lp-el--hidden/visible/hiding` states — identical to `overlay.js showAlert()`; secondary text now shown in Live Preview
- `assets/css/admin.css` — replaced `.ccmerch-livepreview__alert*`, `.ccmerch-livepreview__image*`, `.ccmerch-livepreview__text` with `.ccmerch-lp-el*` and `.ccmerch-lp-text` styles that mirror `overlay.css`
- `ccmerch-alert.php` — version bumped to Alpha 2.0.1

**Validation report (Step 7):**

| Question | Answer |
|---|---|
| Single source of truth | `Overlay_Settings` owns `ccmerch_overlay_settings` exclusively. All settings — template, font, color, animation, audio, layout — stored there. |
| Runtime renderer | `Overlay_Renderer::render_page()` outputs the live OBS page; `render_alert()` produces AJAX fragments. |
| Preview renderer | `Overlay_Controller::handle_preview()` calls `Overlay_Renderer::render_alert()` — the same method used by the live poll. The admin canvas then injects the returned fragments into identically-structured elements at the correct layout positions. |
| Layout editor | `layout-editor.js` embedded in `Overlay_Admin` writes x/y/w/h to hidden `<input>` fields inside the overlay settings form; saved via the WordPress Settings API on form submit. |
| Configuration storage | Every setting in one option key: `ccmerch_overlay_settings`. Layout positions stored in the nested `layout` sub-key via `FIELD_LAYOUT`. No second option key is active. |
| Duplicate settings | None. `FIELD_IMAGE_POSITION` removed. `--ccmerch-image-size` injection removed. |
| Duplicated rendering logic | None. `render_alert()` is the single fragment renderer called by both live poll and admin preview. |
| Mismatched values | None. Live Preview now positions elements identically to the runtime using the same `ccmerchLayout.layout` data. |
| Legacy code paths | None remaining. `ccmerch_layout_settings` fallback in `get_layout()` is a one-time migration read-only path — it never writes to the old option. |

**FIELD_IMAGE_SIZE note:**
`FIELD_IMAGE_SIZE` (default `120`) is retained. It is NOT a display-size setting in the new layout system — it controls the WordPress image resolution fetched via `wp_get_attachment_image( $id, [$size, $size] )`. Actual display dimensions are controlled by layout `product_image.w` and `product_image.h`. The two values serve different purposes and must not be merged.

---

### 2.0.0 — Layout Editor integration into Overlay Profile

**Deleted files:**
- `includes/layout/class-layout-settings.php` — layout data merged into Overlay_Settings
- `includes/layout/class-layout-controller.php` — no longer needed; layout saved via Settings API form
- `includes/admin/class-layout-admin.php` — editor embedded directly in Overlay_Admin page

**New constants in `Overlay_Settings`:**
- `FIELD_SECONDARY_TEMPLATE = 'secondary_template'` — optional second text line below the main alert
- `FIELD_LAYOUT = 'layout'` — nested array of element positions inside `ccmerch_overlay_settings`

**Modified files:**
- `includes/overlay/class-overlay-settings.php` — added `FIELD_SECONDARY_TEMPLATE` and `FIELD_LAYOUT` constants; added `secondary_template` field registration in General section; updated `defaults()` and `sanitize()`; added `get_layout(): array`, `layout_defaults(): array`, `sanitize_layout(mixed): array`
- `includes/overlay/class-overlay-renderer.php` — rewritten: `render_page()` now outputs 3 independently positioned `<div>` elements with `position:fixed` and pixel coordinates from layout data; removed old single flex container; added `render_secondary()` private method; `render_alert()` return gains `secondary_html` key; removed `flex_direction()` helper
- `includes/overlay/class-overlay-controller.php` — added `secondary_html` to poll response (both live-order and transient paths) and preview response
- `includes/admin/class-overlay-admin.php` — enqueues `layout-editor.css` and `layout-editor.js` on overlay page hook; passes `ccmerchLayout` config via `wp_localize_script()`; layout editor section embedded in settings form via `render_layout_editor_section()`; added `render_layout_editor_section()`, `render_layout_hidden_inputs()`, `resolve_preview_data()` private methods; form max-width widened to accommodate canvas
- `includes/class-plugin.php` — removed `use` statements for Layout module; removed `require_once` calls for 3 layout files; removed `Layout_Settings`, `Layout_Controller`, `Layout_Admin` instantiation
- `assets/css/overlay.css` — rewritten: replaced `.ccmerch-overlay` flex container with `.ccmerch-overlay-el` independently positioned elements; animation states now target `.ccmerch-overlay-el--{hidden,visible,hiding}`; added `--ccmerch-img-border-radius` CSS custom property for image element; text background/border-radius/padding applied via inline styles from renderer
- `assets/js/overlay.js` — rewritten: `showAlert()` now accepts `secondaryHtml` 4th argument and animates all 3 elements; filters out `display:none` elements before animating; poll response passes `d.secondary_html`
- `assets/js/layout-editor.js` — rewritten: removed `onSave()` AJAX function and save button; added `updateHiddenInputs(id)` and `updateCanvasHiddenInputs()` to write layout state into form hidden inputs; `moveDrag()`/`moveResize()`/`applyProp()` now call `updateHiddenInputs()`; added `bindFormSync()` — watches overlay form appearance fields (font, color, alignment) and calls `renderAll()` on change; `renderContent()` for text elements now reads from live form fields via `getFormVal()`/`getRadioVal()`; properties panel for text elements shows only X/Y/W/H + Visible (typography is controlled by form fields above); `initHiddenInputs()` seeds all hidden inputs on load; form submit listener resets `dirty = false`

**Single source of truth architecture:**

All overlay configuration (appearance + positions) now lives in one wp_options key: `ccmerch_overlay_settings`.
The `layout` sub-key stores element positions. The old `ccmerch_layout_settings` option is read as a one-time migration fallback in `get_layout()` for users upgrading from 1.9.0.

**Layout data structure (stored under `ccmerch_overlay_settings['layout']`):**

```json
{
  "elements": {
    "product_image":  { "x":40,  "y":880, "w":120, "h":120, "visible":true,  "lock_aspect":true, "border_radius":4 },
    "main_text":      { "x":176, "y":895, "w":700, "h":80,  "visible":true },
    "secondary_text": { "x":176, "y":985, "w":700, "h":50,  "visible":false }
  },
  "canvas": { "snap_grid":false, "grid_size":10 }
}
```

Note: per-element font/color/text_align are removed from layout data. These are global Overlay_Settings fields shared by all text elements.

**Runtime data flow (3 independent positioned elements):**

```
Admin saves Overlay Settings form
    │  Overlay_Settings::sanitize() processes all fields including layout sub-key
    │  Stored as single ccmerch_overlay_settings option
    │
    ▼
OBS Browser Source GET /ccmerch-alert/overlay/{id}/
    │
    ▼
Overlay_Renderer::render_page()
    │  reads Overlay_Settings::get_layout() for element x/y/w/h
    │  reads global settings for font/color/animation
    │  outputs 3 <div> elements with position:fixed + inline pixel coordinates
    │  injects CSS custom properties for typography/animation
    │  exit (standalone HTML page, no WordPress theme)
    │
    └── overlay.js: each poll → showAlert(imageHtml, textHtml, soundUrl, secondaryHtml)
            │  animates all visible elements through --hidden → --visible → --hiding
```

**Two-way sync in the admin editor:**

```
Overlay form field change (font, color, etc.)
    │
    ▼ bindFormSync() listener
layout-editor.js: renderAll() → renderContent() reads live form values
    (canvas preview updates immediately without saving)

Canvas drag/resize
    │
    ▼ moveDrag() / moveResize()
layout-editor.js: updateHiddenInputs(id) writes x/y/w/h to hidden <input> fields
    (included in next form POST via Settings API)
```

**WYSIWYG guarantee:** What you position in the admin canvas is what OBS renders. Coordinates stored in 1920×1080 space; the overlay page uses `position:fixed` with the raw pixel values (OBS renders at 1920×1080, so no scaling is needed at runtime).

**Backwards compatibility:** `Overlay_Settings::get_layout()` reads the new `layout` sub-key first. If absent (existing users), it falls back to the legacy `ccmerch_layout_settings` option. If that is also absent, returns `layout_defaults()`. No data migration step required.

**Secondary text element:** The `secondary_text` element is hidden unless both `visible: true` in layout data AND `FIELD_SECONDARY_TEMPLATE` is non-empty. The renderer's `render_secondary()` returns empty string when the template is blank, and `overlay.js` skips animating elements with no content.

**Extension points:**
- `Overlay_Settings::FIELD_LAYOUT` sub-key can gain new element types by adding a key to `layout_defaults()` and a `renderContent()` branch in `layout-editor.js`.
- `Overlay_Settings::FIELD_SECONDARY_TEMPLATE` uses the same `$Variable` substitution as the primary template — the template engine requires no changes.
- `render_alert()` return array can gain new keys (`secondary_html`, future `tertiary_html`) without breaking existing callers.

**Known limitations (not bugs):**
- The secondary element uses the same background/border-radius/padding as the main text element (both come from global settings). Independent per-element backgrounds are a future option.
- `hasSecondary` in the `ccmerchOverlay` JS config reflects the value at the time the overlay page was rendered. If the secondary template is toggled in settings without regenerating the overlay ID, `overlay.js` will use the old value until the OBS source reloads. This is consistent with how other settings changes take effect.
- The canvas checkerboard uses CSS `background-image` gradients — it does not render in very old browsers (IE11).

---

### 2.5.1 — Overlay_Settings_Contract routing identity fix

**Problem:** `Overlay_Renderer::render_page()` read `overlay_id` from the `all()` array (`$s[Overlay_Settings::FIELD_OVERLAY_ID]`) and hardcoded the poll AJAX action as `'ccmerch_overlay_poll'`. Both assumptions were correct for `Overlay_Settings` but wrong for `Creator_Overlay_Profile`:

1. `Creator_Overlay_Profile::all()` did not contain `overlay_id` — it is a constructor parameter (`$this->overlay_id`), not a settings field. The `defaults()` array intentionally omits it. Reading the missing key produced `PHP Warning: Undefined array key "overlay_id"`.

2. The hardcoded `'ccmerch_overlay_poll'` is the global overlay's AJAX endpoint. Creator overlays use `'ccmerch_creator_overlay_poll'`. The creator's OBS browser source would poll the wrong endpoint, receiving 403 errors silently.

**Root cause:** The `Overlay_Settings_Contract` interface expressed data retrieval (`get`, `all`, `get_layout`) but not routing identity. The renderer conflated two concepts — appearance settings and overlay routing identity — by reaching into the settings array for values that are not settings at all.

**Fix:** Added two methods to `Overlay_Settings_Contract`:
- `get_overlay_id(): string` — returns the 32-char hex access token for this overlay
- `get_poll_action(): string` — returns the AJAX action slug for the live poll endpoint

Both implementors satisfy the new contract methods from their own storage:
- `Overlay_Settings::get_overlay_id()` → `(string) $this->get(FIELD_OVERLAY_ID)`
- `Overlay_Settings::get_poll_action()` → `'ccmerch_overlay_poll'`
- `Creator_Overlay_Profile::get_overlay_id()` → `$this->overlay_id` (constructor property)
- `Creator_Overlay_Profile::get_poll_action()` → `'ccmerch_creator_overlay_poll'`

`Overlay_Renderer::render_page()` now calls `$this->settings->get_overlay_id()` and `$this->settings->get_poll_action()` instead of reading from the settings array.

**Modified files:**
- `includes/overlay/interface-overlay-settings-contract.php` — added `get_overlay_id()` and `get_poll_action()` method declarations; updated docblock to state that `all()` must not include `overlay_id`
- `includes/overlay/class-overlay-settings.php` — added `get_overlay_id()` and `get_poll_action()` implementations
- `includes/creators/class-creator-overlay-profile.php` — added `get_overlay_id()` (returns `$this->overlay_id`) and `get_poll_action()` (returns `'ccmerch_creator_overlay_poll'`) implementations
- `includes/overlay/class-overlay-renderer.php` — line 63: replaced `(string) $s[Overlay_Settings::FIELD_OVERLAY_ID]` with `$this->settings->get_overlay_id()`; JS config: replaced hardcoded `'ccmerch_overlay_poll'` with `$this->settings->get_poll_action()`

**Rule established:** `Overlay_Renderer` must never read `overlay_id` or `poll_action` from the settings array. It must always call the contract methods. The `all()` method returns appearance and timing settings only.

**No database migration required.** The `overlay_id` has always been stored in the DB column, not in the JSON settings blob. The fix is purely in the PHP surface used to retrieve it.

---

### 2.6.0 — Layout Editor single-implementation consolidation

**Goal:** Enforce the ONE-editor rule. `layout-editor.js` (the sole JS implementation) was already
embedded in both the admin Overlay page and the Creator Portal, but the PHP that renders the hidden
`<input>` scaffold the JS reads was duplicated as a private method in two classes. Additionally,
the dirty-flag reset in the JS was hard-coded to find `form[action*="options.php"]`, which silently
failed on the creator portal (form posts to `admin-post.php`), causing a browser "unsaved changes"
warning to appear even after a successful save.

**The bug (dirty-flag guard):**

`layout-editor.js` sets `dirty = true` whenever the user drags an element or edits a property.
A `beforeunload` handler warns the user if `dirty` is true and they navigate away. The flag is
reset inside a form submit listener. Previously that listener was registered against
`form[action*="options.php"]` — correct for the admin overlay page, but returning `null` on the
creator portal. After saving in the portal, `dirty` remained `true` and the browser showed an
"Are you sure you want to leave? Changes may not be saved." dialog — a confusing false alarm.

**The PHP duplication:**

`render_layout_hidden_inputs()` was a private method in both `Overlay_Admin` and
`Creator_Portal_Renderer`, with identical logic. The docblock of the portal copy explicitly noted
"Identical to Overlay_Admin::render_layout_hidden_inputs()". Any future change to the layout
element list (adding a new element, adding a new prop) required two edits to keep them in sync.

**Fixes:**

1. `assets/js/layout-editor.js` — replaced the hardcoded selector with `wrapEl.closest('form')`.
   The canvas wrap element is inside the form in both contexts, so `closest()` always finds the
   correct form. No fallback needed.

2. `includes/overlay/class-overlay-settings.php` — added `public static render_layout_hidden_inputs(array $layout): void`.
   Outputs the hidden inputs for all three canvas elements (product_image, main_text,
   secondary_text) and both canvas options (snap_grid, grid_size). Uses only `self::OPTION_KEY`
   and `self::FIELD_LAYOUT`, which are defined on the class. Static because the method requires
   no instance state.

3. `includes/admin/class-overlay-admin.php` — call site updated to
   `Overlay_Settings::render_layout_hidden_inputs($layout)`. Private duplicate method removed.

4. `includes/creators/class-creator-portal-renderer.php` — call site updated to
   `Overlay_Settings::render_layout_hidden_inputs($layout)`. Private duplicate method removed.

**Architecture rules established by this change:**

- `Overlay_Settings::render_layout_hidden_inputs()` is the **only** place in the codebase that
  outputs the layout hidden-input scaffold. No other class may implement this method.
- `layout-editor.js` is the **only** Layout Editor implementation. The same JS file serves both
  the admin overlay page and the creator portal.
- The Creator Portal is the primary editing interface for creators. Creators do not need to visit
  the admin overlay page to configure their overlay.
- The admin overlay page remains active for the site-wide global overlay (managed by the site
  admin). It uses the same Layout Editor JS file via `wp_enqueue_script()`.

**Validation checklist (Step 10):**

| Check | Result |
|---|---|
| Layout Editor exists in ONE JS implementation | `assets/js/layout-editor.js` — one file ✓ |
| Layout hidden-input scaffold in ONE PHP implementation | `Overlay_Settings::render_layout_hidden_inputs()` ✓ |
| Creator Portal uses that same editor | Calls `layout-editor.js` + `Overlay_Settings::render_layout_hidden_inputs()` ✓ |
| Changes affect only the correct profile | Creator portal form posts to `admin-post.php → handle_save()` which saves to `ccmerch_creator_overlay_settings` table ✓ |
| Browser Source reflects updates | `Overlay_Renderer::render_page()` reads from `Creator_Overlay_Profile` (implements `Overlay_Settings_Contract`) ✓ |
| Admin global overlay is not a duplicate system | Separate instance (different storage, different URL, different poll action) ✓ |
| Dirty-flag warning after creator portal save | Fixed — `wrapEl.closest('form')` resolves to the portal form ✓ |

**No database migration required.**

---

### 2.6.1 — Creator Portal canvas visibility fix

**Problem:** The 1920×1080 Overlay Designer canvas was not visible on the Creator Portal page on initial load.

**Root cause (CSS):**

`.ccmerch-layout-editor` uses `display:flex; align-items:flex-start`. Before `layout-editor.js` runs,
the `.ccmerch-layout-canvas-col` child has no content, so its natural height is zero. The canvas
column's `background: #1a1a2e` never renders because a zero-height element is invisible regardless
of background color. The props column has `min-height: 420px` which keeps the editor container
alive, but the dark canvas area on the left never appeared until JavaScript set the canvas element's
explicit height via `computeScale()`.

**Root cause (form order):**

The Audio card was the **first visible section** inside the configuration form, pushing the Overlay
Designer canvas below the fold. Creators saw "Alert Sound" and "Default Sound" fields immediately
and may not have scrolled further, giving the impression the canvas was absent.

**Modified files:**

- `assets/css/creator-portal.css` — added `.ccmerch-portal .ccmerch-layout-canvas-col { min-height: 506px; }`.
  Scoped to the portal page only (admin overlay page layout is unaffected). The value 506 px
  matches the fallback canvas height: 900 px × (1080 / 1920). JavaScript's `computeScale()`
  overrides this with the exact computed height once it runs.

- `includes/creators/class-creator-portal-renderer.php` — moved the Audio card from the top of
  the form to below the canvas section (after `render_layout_hidden_inputs()`). The Overlay
  Designer is now the first thing creators see when they reach the configuration form, matching
  its role as the primary configuration interface. Audio remains inside the form so its fields
  are included in the same POST.

**Form order after this change:**

```
[hidden: action, creator_token, nonce]
[hidden: appearance fields — template, font, color, etc.]
Overlay Designer heading + description
Toolbar (snap to grid)
1920×1080 canvas + Properties panel
[hidden: layout position inputs]
Audio card
Save Settings button
```

**Architecture rules confirmed (unchanged from 2.6.0):**

- `layout-editor.js` is the **only** Layout Editor implementation.
- `Overlay_Settings::render_layout_hidden_inputs()` is the **only** PHP method that outputs the
  layout hidden-input scaffold.
- The Creator Portal embeds the exact same editor — same JS file, same CSS, same hidden-field
  contract. No simplified substitute exists.
- Saving from the Creator Portal updates `ccmerch_creator_overlay_settings` → `Creator_Overlay_Profile`
  → Browser Source, without admin intervention.

**No database migration required.**

---

### 2.7.0 — Shared Overlay Configuration renderer

**Goal:** Eliminate all duplicate overlay configuration HTML between the admin Overlay page and the Creator Portal. A new `Overlay_Config_Renderer` static class is the single implementation for the form body shared by both pages.

**New files:**
- `includes/overlay/class-overlay-config-renderer.php` — `CCMerchAlert\Overlay\Overlay_Config_Renderer`

**Modified files:**
- `includes/admin/class-overlay-admin.php` — removed private `render_layout_editor_section()` and `resolve_preview_data()` methods; uses `Overlay_Config_Renderer` for all three form body sections; added `use Overlay_Config_Renderer`
- `includes/creators/class-creator-portal-renderer.php` — removed manual HTML for hidden fields, canvas, and audio; uses `Overlay_Config_Renderer` for all three sections; removed private `resolve_preview_data()` method; added `use Overlay_Config_Renderer`
- `includes/class-plugin.php` — added `require_once` for `class-overlay-config-renderer.php` after `class-overlay-settings.php`

**`Overlay_Config_Renderer` public API:**

| Method | Purpose |
|---|---|
| `render_appearance_fields(array $values, string $option_key): void` | Hidden div with all appearance inputs (template, font, color, bg, timing, image_size). layout-editor.js reads/writes these. |
| `render_designer(array $layout): void` | Overlay Designer heading, toolbar, 1920×1080 canvas, properties panel, layout hidden inputs. |
| `render_audio_section(array $values, string $option_key, bool $with_media_picker = true, ?array $allowed_term_ids = null): void` | Full Audio card: Enable checkbox, Default Sound, Category Sounds mapping table. `null` = all categories (admin); `int[]` = only creator's assigned categories (portal). |
| `resolve_preview_data(Order_Repository $repository): array` | Most recent order from Order Log or mock fallback. |

**`render_audio_section()` context switching:**

`$with_media_picker = true` (admin): Default Sound and each category row show a hidden attachment-ID input + "Select Sound File" button + "Remove" button, wired to `wp.media()` by the inline JS in `Overlay_Admin::render_page()`.

`$with_media_picker = false` (Creator Portal): Default Sound and category rows show a visible number input for the attachment ID. Audio file selection via media picker is not available because creators may not be WordPress users with `upload_files` capability. The Category Sounds mapping table is present and fully functional — only the picker mechanism differs.

`$allowed_term_ids = null` (admin): the Category Sounds table lists all WooCommerce product categories.
`$allowed_term_ids = int[]` (Creator Portal): the Category Sounds table lists ONLY the categories assigned to this creator's profile. Other categories are never shown. See 3.1.0 for the audio isolation implementation.

**What the admin Overlay page form now calls (in order):**
1. `settings_fields( Overlay_Settings::PAGE )` — nonce + option group fields
2. `Overlay_Config_Renderer::render_appearance_fields( $this->settings->all(), Overlay_Settings::OPTION_KEY )`
3. `Overlay_Config_Renderer::render_designer( $layout )`
4. `Overlay_Config_Renderer::render_audio_section( $this->settings->all(), Overlay_Settings::OPTION_KEY, true )`
5. `submit_button()`

**What the Creator Portal form now calls (in order):**
1. Hidden action / creator_token / _nonce fields
2. `Overlay_Config_Renderer::render_appearance_fields( $all_settings, $option_key )`
3. `Overlay_Config_Renderer::render_designer( $layout )`
4. `Overlay_Config_Renderer::render_audio_section( $all_settings, $option_key, false, $allowed_term_ids )`
5. Save Settings button

**`do_settings_sections()` removed from admin:** The admin Overlay page no longer calls `do_settings_sections( Overlay_Settings::PAGE )`. The Audio section was the only section it rendered; it is now rendered by `Overlay_Config_Renderer::render_audio_section()`. The `add_settings_section()` and `add_settings_field()` registrations in `Overlay_Settings::register()` remain (unused for rendering but harmless). The form still saves correctly because `register_setting()` registers the sanitize callback independently.

**Architecture rules confirmed by this version:**

- `Overlay_Config_Renderer` is the **only** place that renders the overlay configuration form body. No other class may duplicate these HTML sections.
- `Overlay_Settings::render_layout_hidden_inputs()` remains the **only** place that renders layout position hidden inputs (unchanged).
- `layout-editor.js` remains the **only** Layout Editor JS implementation (unchanged).
- The Creator Portal renders the **complete** Audio section including the Category Sounds mapping table. The only difference from the admin is that audio file selection uses number inputs rather than `wp.media()` picker buttons.
- The Folder Structure section and Class descriptions in this file must be updated when new files are added. `Overlay_Config_Renderer` lives at `includes/overlay/class-overlay-config-renderer.php`, namespace `CCMerchAlert\Overlay`.

**No database migration required.**

---

### 2.8.0 — Clipboard helper consolidation and sanitize/defaults deduplication

**Goal:** Eliminate the last three categories of duplication in the Overlay Configuration system:
1. `ccmerchCopyToClipboard` JS function defined inline in 4 separate locations.
2. `sanitize_fields`, `layout_defaults`, `sanitize_layout`, `sanitize_font_weight`, `sanitize_text_align` private methods duplicated identically in `Overlay_Settings` and `Creator_Overlay_Profile`.
3. Browser Source URL card HTML duplicated in `Overlay_Admin` and `Creator_Portal_Renderer`.

**New files:**
- `assets/js/ccmerch-utils.js` — canonical `ccmerchCopyToClipboard` implementation (HTTP-safe: Clipboard API → `execCommand` fallback → `window.prompt` last resort); exports `window.ccmerchCopyToClipboard`, `window.ccmerchCopyPortalUrl`, and `window.ccmerchCopyLink` so existing `onclick` attributes and named wrappers continue to work

**Modified files:**
- `includes/overlay/class-overlay-settings.php` — promoted five private helpers to public static; added two new public static methods; `sanitize()` simplified to call the shared statics:
  - `public static field_defaults(): array` — all appearance/audio/layout defaults WITHOUT `overlay_id`; called by `Creator_Overlay_Profile` instead of maintaining a duplicate array
  - `public static sanitize_fields(array $input, string $cat_map_json = '{}'): array` — sanitizes all appearance and audio fields; accepts pre-sanitized `$cat_map_json` so callers handle context-specific category map logic
  - `public static layout_defaults(): array` — was `private function`; now public static
  - `public static sanitize_layout_data(mixed $input): array` — was `private function sanitize_layout()`; renamed and made public static
  - `public static sanitize_font_weight(mixed $value): string` — was `private function`; now public static
  - `public static sanitize_text_align(mixed $value): string` — was `private function`; now public static
  - `private function defaults(): array` — now a thin wrapper calling `array_merge(['overlay_id' => ''], self::field_defaults())`; unchanged external behavior
- `includes/creators/class-creator-overlay-profile.php` — removed five private helper methods (~145 lines of duplicated logic); `__construct` uses `Overlay_Settings::field_defaults()`; `get()` falls back to `Overlay_Settings::field_defaults()`; `get_layout()` uses `Overlay_Settings::layout_defaults()`; `sanitize()` calls `Overlay_Settings::sanitize_fields($input, '{}')` (portal v1 always passes `'{}'` for category map)
- `includes/overlay/class-overlay-config-renderer.php` — added `render_browser_source_card()` public static method; the Browser Source URL card (URL input, Copy button, Open link, Generate ID form) is now rendered from one shared implementation
- `includes/admin/class-overlay-admin.php` — added `wp_enqueue_script('ccmerch-utils', ...)` in `enqueue_assets()`; replaced inline Browser Source card HTML with `Overlay_Config_Renderer::render_browser_source_card()` call; removed old copy-button event listener (was a simplified pattern that did not follow CLAUDE.md — now uses the shared CLAUDE.md-compliant `onclick` attribute)
- `includes/creators/class-creator-portal-renderer.php` — replaced inline Browser Source card HTML with `Overlay_Config_Renderer::render_browser_source_card()` call; removed inline `ccmerchCopyToClipboard` function definition (~35 lines); added `<script src="ccmerch-utils.js">` tag before `layout-editor.js`
- `includes/admin/class-creator-admin.php` — added `wp_enqueue_script('ccmerch-utils', ...)` in `enqueue_assets()`; removed inline `ccmerchCopyToClipboard` + `ccmerchCopyPortalUrl` definition from `render_list_page()` (~43 lines); removed inline `ccmerchCopyToClipboard` + `ccmerchCopyLink` definition from `render_edit_page()` (~43 lines)

**`render_browser_source_card()` signature:**

```php
public static function render_browser_source_card(
    string   $overlay_url,
    string   $icon_html,
    callable $generate_form_fields,
    string   $card_style = 'margin-top:20px;'
): void
```

`$generate_form_fields` is a callable invoked inside the `<form>` element before the submit button. It outputs the auth hidden fields specific to each context:
- Admin: `<input type="hidden" name="action" value="...">` + `wp_nonce_field()`
- Portal: `<input type="hidden" name="action" value="...">` + `creator_token` + `_nonce`

The Copy URL button inside the shared card always uses the CLAUDE.md-compliant `onclick="ccmerchCopyToClipboard(this, url)"` pattern. `ccmerch-utils.js` must be loaded by the caller before the card is rendered.

**Shared sanitize pipeline:**

```
Overlay_Settings::sanitize()                    Creator_Overlay_Profile::sanitize()
    │  sanitize_category_map() → $cat_map           │  layout preserved from get_layout()
    │  inject stored layout into $input             │
    ▼                                               ▼
Overlay_Settings::sanitize_fields( $input, $cat_map )
    │  delegates to:
    │   · sanitize_font_weight()    (public static)
    │   · sanitize_text_align()     (public static)
    │   · sanitize_layout_data()    (public static)
    ▼
array<string, mixed>  (all appearance + audio + layout fields sanitized)
```

**ccmerch-utils.js loading strategy:**

| Page | How loaded |
|---|---|
| Admin Overlay page | `wp_enqueue_script('ccmerch-utils', ...)` in `Overlay_Admin::enqueue_assets()` |
| Admin Creator Profiles page | `wp_enqueue_script('ccmerch-utils', ...)` in `Creator_Admin::enqueue_assets()` |
| Creator Portal (public page) | `<script src="ccmerch-utils.js?ver=...">` tag in `Creator_Portal_Renderer::render_page()` |

WordPress de-duplicates enqueued scripts by handle — loading `ccmerch-utils` on a page that already loads it is a safe no-op.

**Architecture rules established by this version:**

- `assets/js/ccmerch-utils.js` is the **only** place that defines `ccmerchCopyToClipboard`. No other file may contain a definition of this function. Current uses: Overlay_Admin, Creator_Portal_Renderer, Creator_Admin list and edit pages.
- `Overlay_Settings` is the **only** place that defines overlay field defaults and sanitization rules. `Creator_Overlay_Profile` has no independent logic — it delegates entirely to `Overlay_Settings` static methods.
- `Overlay_Config_Renderer::render_browser_source_card()` is the **only** place that renders the Browser Source URL card HTML. No other class may duplicate this section.
- The `onclick="ccmerchCopyToClipboard(this, url)"` pattern (CLAUDE.md) is the only permitted form of clipboard interaction across the entire plugin. Event-listener-based clipboard is not permitted.

**No database migration required.**

---

### 2.9.0 — Component-Based UI Framework

**Goal:** Eliminate the remaining duplication between the admin Overlay page and the Creator Portal:
the Test Controls card (last UI element still rendered by hand in both places) and the scattered asset
URL strings. Establish a formal UI extension point via `Component_Registry` and `Asset_Loader`.

**New files:**
- `includes/ui/class-component-registry.php` — static registry mapping string IDs to render callables
- `includes/ui/class-asset-loader.php` — single source of truth for all plugin asset URLs; admin enqueue presets; versioned URL helper for standalone pages
- `includes/ui/components/class-test-controls-component.php` — shared Test Controls card; data-attribute AJAX pattern; no per-page JS required

**Modified files:**
- `assets/js/ccmerch-utils.js` — added `ccmerchSendTestRequest(btn)` and a delegated `click` listener on `document`; reads all AJAX parameters from `data-ccmerch-*` attributes; handles success/failure/network-error feedback via `.ccmerch-test-feedback`
- `includes/class-plugin.php` — added `use` statements and `require_once` for the three new UI files; registered five components (`browser-source-card`, `appearance-fields`, `overlay-designer`, `audio-section`, `test-controls`) in `load_components()` via `Component_Registry::register()`
- `includes/admin/class-overlay-admin.php` — replaced 4 explicit `wp_enqueue_*` calls in `enqueue_assets()` with `Asset_Loader::enqueue_overlay_config()`; replaced Test Controls card HTML with `Test_Controls_Component::render()`; removed the test-controls JS IIFE from the inline `<script>` block (the audio picker IIFE is kept)
- `includes/creators/class-creator-portal-renderer.php` — replaced 4 hardcoded `CCMERCH_ALERT_URL . 'assets/...'` URL variables with `Asset_Loader::versioned(Asset_Loader::*())`; replaced Test Controls card HTML with `Test_Controls_Component::render()`; removed the per-page test-alert JS IIFE entirely; ccmerch-utils.js `<script>` tag positioned before layout-editor.js
- `includes/admin/class-creator-admin.php` — replaced 2 explicit `wp_enqueue_*` calls in `enqueue_assets()` with `Asset_Loader::enqueue_creator_admin()`

**Architecture rules established by this version:**

- `Component_Registry` is the discoverable index of all reusable UI components. Register new
  shared components in `Plugin::load_components()` via `Component_Registry::register()`.
- `Asset_Loader` is the **only** class that may build plugin asset URLs from `CCMERCH_ALERT_URL`.
  No other class may construct an asset URL directly.
- `Test_Controls_Component::render()` is the **only** place that renders the Test Controls card.
  No other class may duplicate this card.
- `ccmerchSendTestRequest(btn)` in `ccmerch-utils.js` is the **only** JS AJAX handler for test
  controls buttons. No per-page IIFE may re-implement this logic.
- Test controls buttons must use `data-ccmerch-test-action` + data attributes — not inline JS,
  not `addEventListener`, not per-page functions. The delegated listener in ccmerch-utils.js handles
  the click automatically.

**ccmerch-utils.js loading strategy (updated from 2.8.0):**

| Page | How loaded |
|---|---|
| Admin Overlay page | `Asset_Loader::enqueue_overlay_config()` in `Overlay_Admin::enqueue_assets()` |
| Admin Creator Profiles page | `Asset_Loader::enqueue_creator_admin()` in `Creator_Admin::enqueue_assets()` |
| Creator Portal (public page) | `<script src="<?= Asset_Loader::versioned( Asset_Loader::utils_js() ) ?>">` in `Creator_Portal_Renderer::render_page()` |

**Test Controls data-attribute contract:**

| Attribute | Required | Description |
|---|---|---|
| `data-ccmerch-test-action` | Yes | WordPress AJAX action name |
| `data-ccmerch-auth-field` | Yes | POST field name for auth credential |
| `data-ccmerch-auth-value` | Yes | Auth value (WP nonce or creator token) |
| `data-ccmerch-ajax-url` | Yes | admin-ajax.php URL |
| `data-ccmerch-sending` | No | Button label while request is in flight |
| `data-ccmerch-success` | No | Feedback text on server success |
| `data-ccmerch-error` | No | Feedback text on server error |
| `data-ccmerch-net-error` | No | Feedback text on network/parse failure |

**Known architectural limitation (documented, not a bug):**

`OverlaySaveService` was considered but not implemented. The two save paths — WordPress Settings API
(`options.php`) for the admin and `admin-post.php` with creator token auth for the portal — have
fundamentally different security models. The sanitization logic IS already shared via
`Overlay_Settings::sanitize_fields()`. Wrapping the save itself in a shared service would require
bypassing the WP Settings API and would not reduce code. This is the correct split of concerns.

**No database migration required.**

---

### 3.0.0 — Order Item Routing Engine (Alert Event Queue)

**Goal:** Fix the fundamental cross-creator contamination bug in creator overlay alerts and establish
a correct, isolated, per-creator alert event queue. Every WooCommerce order item now resolves
fully at write time and is stored in its own creator-scoped queue. The overlay poll reads only events
that belong to its specific overlay_id — cross-creator contamination is architecturally impossible.

**Core design principle: "Resolve once. Store once. Render many."**

Routing decisions (category → creator → overlay_id) happen ONCE, in `Alert_Router`, immediately
after `Order_Logger::save()` succeeds. The overlay poll endpoint never performs routing, category
lookups, or WooCommerce API calls. It reads pre-resolved `Alert_Event` rows and renders them.

**The bug that was fixed:**

`Creator_Overlay_Controller::handle_poll()` called `Order_Repository::get_since(FILTER_ALL)` with
no creator filtering. This returned ALL orders from ALL creators on EVERY creator overlay poll.
The fix replaces that call with `Alert_Event_Repository::get_pending_for_overlay(overlay_id, since)`
which is intrinsically scoped to the exact overlay_id — events from other creators cannot appear.

**New files:**
- `includes/engine/class-alert-event.php` — immutable value object; one per order item; stores
  creator_id, creator_uuid, overlay_id, order data, and full product data (resolved at write time)
- `includes/engine/class-alert-event-installer.php` — `maybe_install()` / `activate()` pattern;
  creates `{prefix}ccmerch_alert_events`; bumped via `DB_VERSION_KEY / DB_VERSION`
- `includes/engine/class-alert-event-repository.php` — `enqueue(data): bool`;
  `get_pending_for_overlay(overlay_id, since): Alert_Event[]` (ASC order, no reversal needed)
- `includes/engine/class-alert-router.php` — `process_order(order_id, order_data, items_data): void`;
  per-item: `match_creator(term_ids)` → fetch overlay_id → `enqueue()`

**Modified files:**
- `includes/overlay/class-overlay-renderer.php` — added `render_event(Alert_Event): array`; wraps
  a single `Alert_Event` in an `Order_Log_Entry` + `Order_Log_Item` and delegates to `render_alert()`
- `includes/order-log/class-order-logger.php` — added `item_id` (WC order item ID) to every item
  in `$items_data`; added nullable `?Alert_Router` constructor param; after successful `save()`,
  calls `$this->alert_router->process_order()` when router is injected
- `includes/creators/class-creator-overlay-controller.php` — added `Alert_Event_Repository` to
  constructor; `handle_poll()` now calls `get_pending_for_overlay()` instead of `get_since(FILTER_ALL)`;
  removed `array_reverse()` (events come back ASC from DB already); each event rendered via
  `$renderer->render_event($event)` (one fragment per event, no inner loop over items)
- `includes/class-plugin.php` — added `use`/`require_once` for 4 new engine files; creator repos
  moved to instantiate before `Order_Logger` so `Alert_Router` can be constructed first; `Alert_Router`
  injected into `Order_Logger`; `Alert_Event_Repository` injected into `Creator_Overlay_Controller`
- `ccmerch-alert.php` — version bumped to Alpha 3.0.0; added `require_once` for installer; added
  `register_activation_hook` for `Alert_Event_Installer::activate()`; added
  `Alert_Event_Installer::maybe_install()` call in `plugins_loaded`
- `uninstall.php` — added `DROP TABLE IF EXISTS {prefix}ccmerch_alert_events` + `delete_option`

**New database table (`{prefix}ccmerch_alert_events`):**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| creator_id | BIGINT UNSIGNED | FK to creator_profiles.id |
| creator_uuid | CHAR(36) | Creator UUID v4 |
| overlay_id | VARCHAR(32) | 32-char hex browser-source token; KEY (overlay_created composite) |
| order_id | BIGINT UNSIGNED | WooCommerce order ID |
| order_number | VARCHAR(64) | WooCommerce order number |
| order_item_id | BIGINT UNSIGNED | WC order item ID (`WC_Order_Item::get_id()`) |
| customer_first_name | VARCHAR(255) | Billing first name |
| quantity | INT | Units purchased |
| product_id | BIGINT UNSIGNED | Parent product ID (not variation) |
| variation_id | BIGINT UNSIGNED DEFAULT 0 | 0 = simple product |
| product_name | VARCHAR(255) | Name at purchase time |
| featured_image_attachment_id | BIGINT UNSIGNED DEFAULT 0 | 0 = no image |
| category_term_ids | TEXT | JSON-encoded int[] of WC product_cat term IDs |
| template_id | BIGINT UNSIGNED NULL | Reserved for future per-creator template assignment |
| audio_profile_id | BIGINT UNSIGNED NULL | Reserved for future per-creator audio profile |
| animation_profile_id | BIGINT UNSIGNED NULL | Reserved for future per-creator animation profile |
| created_at | DATETIME | UTC; indexed via `overlay_created (overlay_id, created_at)` |

**Correct routing data flow:**

```
WooCommerce order reaches "processing" or "completed" status
    │
    ▼
Order_Logger::log_order(order_id)
    │  1. Checks Order_Repository::exists() — skips if duplicate
    │  2. Resolves order data + item data (SKU, image_id, category_ids, item_id)
    │  3. Order_Repository::save(order_data, items_data) → bool
    │
    ▼ (only when save() returned true)
Alert_Router::process_order(order_id, order_data, items_data)
    │  For each item:
    │    match_creator(item.category_ids)
    │      → Creator_Repository::find_by_category_term_id(term_id) [first match wins]
    │    Creator_Overlay_Repository::find_by_creator_id(creator.id)
    │      → overlay_id (skip if empty)
    │    Alert_Event_Repository::enqueue(fully-resolved snapshot)
    │
    ▼  (on creator overlay poll)
Alert_Event_Repository::get_pending_for_overlay(overlay_id, since)
    │  SELECT WHERE overlay_id = %s AND created_at > %s ORDER BY created_at ASC LIMIT 50
    │  Returns Alert_Event[] for THIS overlay ONLY — no other creator's events can appear
    │
    ▼
Overlay_Renderer::render_event(Alert_Event)
    │  Wraps event in Order_Log_Entry + Order_Log_Item → delegates to render_alert()
    │  Returns { image_html, text_html, secondary_html, sound_url }
    │
    ▼
Creator overlay JS queue: plays each alert in purchase sequence
```

**Design decisions:**

- **No `to_log_entry()` on Alert_Event**: keeps the value object pure; no engine→order-log dependency.
  `render_event()` on `Overlay_Renderer` holds the projection logic instead.
- **item_id in items_data**: `WC_Order_Item_Product::get_id()` added to every item. `Order_Repository::save()`
  ignores unknown keys — fully backwards-compatible. Alert_Router uses it for traceability.
- **Creator repos instantiated early**: moved before `Order_Logger` in `Plugin::load_components()` so
  `Alert_Router` can be constructed and injected. No other change to instantiation order.
- **ASC ordering in get_pending_for_overlay**: events play in chronological purchase order without
  client-side `array_reverse()`. The old code used `array_reverse()` because `get_since()` returned
  newest-first; that is no longer needed.
- **Global overlay unchanged**: `Overlay_Controller::handle_poll()` still reads from
  `Order_Repository::get_since()`. Only creator overlays use the new queue system.
- **Test/Replay still use Order_Repository**: `handle_test()`, `handle_replay()`, `handle_portal_test()`
  all call `$this->repository->get_recent(1)`. These are admin debug paths that render a mock alert
  regardless of creator routing. Intentional.
- **Nullable Alert_Router**: `Order_Logger` accepts `?Alert_Router = null` for backwards compatibility.
  In production, the router is always injected via `Plugin::load_components()`.

**Known limitations (not bugs):**
- The `ccmerch_alert_events` table accumulates rows indefinitely. A future purge job (e.g. deleting
  events older than 24 hours) should be added when the table size becomes a concern.
- Events are written to the queue atomically per item, but the poll reads all events since the last
  `server_time`. If the OBS source is offline for hours, many events may accumulate and all will
  play sequentially when it reconnects. This is the correct behaviour.
- The three nullable profile ID columns (`template_id`, `audio_profile_id`, `animation_profile_id`)
  are stored but not yet read. They are reserved for future per-creator profile systems and require
  no migration when implemented.

---

### 3.0.1 — Alert Event queue delivery stabilisation

**Goal:** Fix a timing-based bug where Alert Events could be permanently invisible to the overlay
poll, and eliminate all silent event discard paths in the routing engine.

**Root cause of the delivery bug:**

`Alert_Event_Repository::get_pending_for_overlay()` used a strict `>` comparison on `created_at`.
`Alert_Router` stores `created_at = \gmdate('Y-m-d H:i:s')` at insertion time (second precision).
`Creator_Overlay_Controller::handle_poll()` returns `server_time = \time()` on every response.

If an order is placed at the exact same Unix second that a previous poll fires and records
`server_time`, the next poll sends `since = server_time`. The `since_dt` then equals the
events' `created_at` string exactly, making `created_at > since_dt` FALSE — the events are
permanently missed by all subsequent polls.

**Concrete failure scenario:**

```
Poll A fires at T=1000 → server_time = 1000 → JS since = 1000
Order placed at T=1000 → events created_at = '...1000...'
Poll B fires at T=1005 with since=1000:
    since_dt = '...1000...'
    WHERE created_at > '...1000...'   ← '1000' > '1000' = FALSE
    → Both events permanently invisible
```

**Modified files:**
- `includes/engine/class-alert-event-repository.php` — changed `>` to `>=` in `get_pending_for_overlay()`
  query; added `id ASC` as secondary sort key for deterministic ordering when multiple events share
  the same `created_at` second
- `includes/creators/class-creator-overlay-controller.php` — in `handle_poll()`, after reading events,
  advances `$now` to `max($now, last_event->created_at + 1)` before sending `server_time`; this
  prevents the `>=` query from re-delivering already-played events on the next poll
- `includes/engine/class-alert-router.php` — added `WP_DEBUG_LOG` logging at every decision point
  in `process_item()`: no-creator-match skip, no-overlay-row skip, empty-overlay-id skip, and
  successful enqueue; refactored item_id and category_ids into local variables to reduce repetition

**How the fix works:**

With `>=`, events created at the exact `since_dt` second are now included. Re-delivery is prevented
by the server-side `$now` advancement: when events are returned, `server_time` is set to at least
`last_event.created_at + 1`. The next poll then uses `since >= last_event.created_at + 1`, which
correctly excludes all events from the previous batch.

```
Poll A fires at T=1000, no events → server_time=1000 → JS since=1000
Order placed at T=1000 → events created_at='...1000...'
Poll B fires at T=1005 with since=1000:
    since_dt = '...1000...'
    WHERE created_at >= '...1000...'  ← '1000' >= '1000' = TRUE ✓
    Events returned; $now advanced to max(1005, 1000+1) = 1005
    server_time = 1005 → JS since = 1005
Poll C fires with since=1005:
    since_dt = '...1005...'
    WHERE created_at >= '...1005...'  ← '1000' >= '1005' = FALSE ✓ (no re-delivery)
```

**Logging contract (WP_DEBUG_LOG only):**

| Log message | When it fires |
|---|---|
| `[Alert_Router] order N item N skipped — no creator assigned to categories [...]` | Product's WC categories have no creator assignment in the admin |
| `[Alert_Router] order N item N skipped — creator N has no overlay profile row` | Creator exists but the overlay settings row is missing (should not happen post-2.5.0) |
| `[Alert_Router] order N item N skipped — creator N overlay_id is empty (OBS URL not generated yet)` | Creator exists with an overlay row but the admin hasn't generated the OBS URL yet |
| `[Alert_Router] order N item N → creator N overlay X — event enqueued` | Event successfully written to the alert queue |
| `[Alert_Event_Repository::enqueue] insert failed — <DB error>` | Pre-existing — MySQL insert failure |

**Rule established:** No Alert Event may be dropped silently. Every discard path in `Alert_Router`
must emit a `WP_DEBUG_LOG` line explaining why the item was not routed.

**No database migration required.** The query change (`>` → `>=`) is a runtime behaviour fix;
no schema changes are involved. The `id ASC` tiebreaker uses the existing primary key index.

---

### 3.0.2 — Alert Event idempotency

**Problem:** A single WooCommerce Order Item was generating two identical alerts in the Browser Source.

**Root cause — two mechanisms, one primary:**

**A) JS-level duplicate (primary — introduced by 3.0.1's `>=` change):**

`overlay.js` uses `setInterval` which fires `poll()` regardless of whether a previous poll request
has completed. Before 3.0.1, events created at `T == since` were never returned (`>` operator
excluded them), so overlapping polls with the same `since` both got empty responses — harmless.
With `>=`, both overlapping polls now correctly return the pending events. Both push the same
fragments to `alertQueue` independently. One order item → two alerts in the browser source.

```
T=0:      setInterval fires → poll() starts with since=S
T=5000ms: setInterval fires → poll() starts with since=S (unchanged — Poll 1 hasn't responded)
T=5100ms: Poll 1 response → since=S2, alertQueue.push([E1]) → 1 alert
T=5200ms: Poll 2 response → since=S3, alertQueue.push([E1]) → duplicate alert
```

**B) DB-level gap (architectural — pre-existing):**

`ccmerch_alert_events` had no UNIQUE KEY on `(order_item_id, overlay_id)`. If `process_item()`
ever ran twice for the same item (race condition between concurrent HTTP requests, a WooCommerce
plugin firing the order hook twice, etc.), two identical rows were inserted silently.

**Modified files:**
- `assets/js/overlay.js` — added `var inFlight = false` state variable; `poll()` returns
  immediately when `inFlight === true`; sets `inFlight = true` before the fetch and `false`
  in both `.then()` and `.catch()` so the flag is always released
- `includes/engine/class-alert-event-installer.php` — added
  `UNIQUE KEY item_overlay (order_item_id, overlay_id)` to the CREATE TABLE statement;
  `DB_VERSION` bumped from `'1.0'` to `'1.1'` so `maybe_install()` applies the new key to
  existing installations on next request
- `includes/engine/class-alert-event-repository.php` — added `event_exists(int $order_item_id,
  string $overlay_id): bool`; returns false early when `order_item_id <= 0` or `overlay_id`
  is empty so the UNIQUE KEY remains the final safety net for degenerate inputs
- `includes/engine/class-alert-router.php` — `process_item()` now calls `event_exists()` before
  `enqueue()`; if true, logs the skip (WP_DEBUG_LOG) and returns — the UNIQUE KEY constraint is a
  secondary guard only
- `ccmerch-alert.php` — version bumped to Alpha 3.0.2

**How the combined fix works:**

```
setInterval fires two overlapping polls:
    Poll A (since=S) → inFlight=true
    Poll B (since=S) → inFlight=true → RETURNS IMMEDIATELY (no request sent)
    Poll A response  → inFlight=false → since=S2, alertQueue.push([E1]) → 1 alert ✓
    Next tick        → inFlight=false → poll() fires normally

Two concurrent HTTP requests for the same order (race condition):
    Request A: process_item() → event_exists(item=123, overlay=X) → false → enqueue() → INSERT ✓
    Request B: process_item() → event_exists(item=123, overlay=X) → TRUE  → skip + log ✓
    (If both pass event_exists simultaneously: UNIQUE KEY rejects the second INSERT)
```

**Idempotency rule (now established):**

Every WooCommerce Order Item + Creator Overlay mapping must produce **exactly one** Alert Event.
Duplicate prevention is enforced at three independent layers:

1. **Application layer** — `Alert_Router::process_item()` calls `event_exists()` before `enqueue()`.
2. **Database layer** — `UNIQUE KEY item_overlay (order_item_id, overlay_id)` rejects duplicate INSERTs.
3. **Delivery layer** — `overlay.js` `inFlight` flag prevents concurrent polls from each receiving
   the same events.

No secondary safeguard may rely on runtime filtering alone. All three layers are required.

**Routing principle (confirmed):**

```
Single Order Item → Single Routing Execution → Single Alert Event → Single Queue Entry → Single Alert
```

**Logging contract (updated — new entry added):**

| Log message | When it fires |
|---|---|
| `[Alert_Router] order N item N skipped — no creator assigned to categories [...]` | No creator assignment |
| `[Alert_Router] order N item N skipped — creator N has no overlay profile row` | Missing overlay row |
| `[Alert_Router] order N item N skipped — creator N overlay_id is empty (OBS URL not generated yet)` | Overlay ID not generated |
| `[Alert_Router] order N item N skipped — duplicate event already exists for overlay X` | Idempotency guard fired |
| `[Alert_Router] order N item N → creator N overlay X — event enqueued` | Successful enqueue |
| `[Alert_Event_Repository::enqueue] insert failed — <DB error>` | MySQL insert failure |

**Schema migration:** `DB_VERSION` bumped from `'1.0'` to `'1.1'`. `maybe_install()` triggers
`dbDelta()` on the next request. `dbDelta()` adds the new UNIQUE KEY to the existing table
non-destructively. If duplicate rows already exist in the table (from before this fix), `dbDelta()`
will silently fail to add the key — the application-layer `event_exists()` check then serves as the
sole guard. Clean the duplicate rows manually if needed.

**Known limitation:** If a poll request hangs indefinitely (TCP established, server never responds),
`inFlight` stays `true` until the browser's native fetch timeout fires the `.catch()` handler. In
practice, OBS Browser Source (Chromium) has a built-in request timeout; no explicit `AbortSignal`
is needed for this use case.

---

### 3.1.0 — Audio Isolation: Creator-scoped category sounds

**Goal:** Enforce strict creator-scoped isolation in the Audio Module. A creator can only view, edit,
and save audio settings for WooCommerce product categories assigned to their own Creator Profile.
Other creators' categories are never shown in the UI and are silently rejected at the save boundary.

**The isolation violation that was fixed:**

`Overlay_Config_Renderer::render_audio_category_map()` called `get_terms(['taxonomy' => 'product_cat'])`
with no creator filter, exposing ALL WooCommerce product categories to every creator on their portal
page. A creator assigned only to "Gaming" would also see "Merch", "Art", and every other category
configured in the store — a both a UI confusion issue and a data scope violation.

Additionally, `Creator_Overlay_Profile::sanitize()` always passed `'{}'` as the audio category map
JSON regardless of what the creator submitted in the form. Per-category audio was silently discarded
on every portal save — making the Category Sounds table on the portal non-functional.

**Modified files:**

- `includes/overlay/class-overlay-settings.php` — promoted `sanitize_category_map()` from `private function`
  to `public static function`; added `array $allowed_term_ids = []` parameter; when non-empty, filters the
  decoded map to only keep entries whose term ID is in `$allowed_term_ids`; updated call site in
  `sanitize()` from `$this->sanitize_category_map()` to `self::sanitize_category_map()`; updated
  class docblock to list `sanitize_category_map()` among the shared static helpers

- `includes/overlay/class-overlay-config-renderer.php` — added `?array $allowed_term_ids = null` as
  4th parameter to `render_audio_section()`; passes it through to the private `render_audio_category_map()`
  helper; in that helper: when `$allowed_term_ids` is a non-null array, filters the `get_terms()` result
  to only the allowed term IDs; shows a tailored "No categories are assigned to your creator profile"
  notice when the filtered list is empty

- `includes/creators/class-creator-overlay-profile.php` — updated `sanitize(mixed $input)` to accept
  a second parameter `array $allowed_term_ids = []`; replaced the hardcoded `'{}'` category map with
  `Overlay_Settings::sanitize_category_map($input[FIELD_AUDIO_CAT_MAP] ?? [], $allowed_term_ids)`;
  updated docblock to document the purpose and the caller's responsibility to provide the IDs

- `includes/creators/class-creator-overlay-controller.php` — `handle_save()` now calls
  `$this->service->get_category_ids($creator->id)` before `$profile->sanitize()` and passes the
  result as the second argument; `Creator_Service::get_category_ids()` was already present in 2.3.0

- `includes/creators/class-creator-portal-renderer.php` — added
  `$allowed_term_ids = $this->service->get_category_ids($creator->id)` after the existing settings
  assignment block; passes it to `Overlay_Config_Renderer::render_audio_section()` as the 4th argument

- `ccmerch-alert.php` — version bumped to Alpha 3.1.0

**Audio Isolation Model:**

```
Creator Profile (ccmerch_creator_profiles)
    │  creator_id
    ▼
Creator Category Assignments (ccmerch_creator_category_assignments)
    │  wc_category_term_id[]
    ▼
Creator_Service::get_category_ids(creator_id) → int[]
    │
    ├── UI path: render_audio_section($values, $key, false, $allowed_term_ids)
    │       → Category Sounds table shows ONLY creator's categories
    │
    └── Save path: $profile->sanitize($input, $allowed_term_ids)
            → sanitize_category_map($raw, $allowed_term_ids)
            → entries for non-creator categories are silently dropped
            → sanitize_fields($input, $filtered_cat_map_json)
```

**What is isolated:**
- Category Sounds table: shows only the creator's assigned WC product categories
- Saved audio map: only entries for creator-owned categories survive sanitization
- Runtime audio: unchanged — `resolve_sound_url()` reads from the stored JSON which is now
  correctly scoped; no runtime filtering needed

**What is NOT changed:**
- The admin Overlay page still calls `render_audio_section($values, $key, true, null)` — all
  WooCommerce categories shown, all categories saveable. Admin is unaffected.
- `Overlay_Settings::sanitize()` (admin path) calls `self::sanitize_category_map(input)` with
  no `$allowed_term_ids` — empty array = no restriction, correct behavior unchanged.
- Audio runtime delivery (`resolve_sound_url()` in `Overlay_Renderer`) is unchanged.
- The per-category audio row in the portal shows a number input (not a media picker) — unchanged
  since 2.7.0. Creators enter the WordPress attachment ID manually.

**Fix: Per-category audio now actually saves from the portal:**

Before 3.1.0: `Creator_Overlay_Profile::sanitize()` always returned `'{}'` for the audio category
map. Creators could type attachment IDs into the Category Sounds table, hit Save, and nothing would
be stored. This was documented as a "v1 limitation" but there was no technical reason for it once
the number-input rows were in place (added in 2.7.0).

After 3.1.0: the category map from the POST body is sanitized via `Overlay_Settings::sanitize_category_map()`
and filtered to the creator's own categories. Valid entries (category in `$allowed_term_ids`, att_id > 0)
are stored in the creator's overlay settings JSON blob.

**Security model:**

| Threat | Mitigation |
|---|---|
| Creator POSTs audio for a foreign category | `sanitize_category_map($raw, $allowed_term_ids)` filters to creator-owned term IDs only |
| Creator sees foreign categories in the table | `render_audio_category_map()` filters the `get_terms()` list to `$allowed_term_ids` |
| Creator POSTs audio for a category they no longer own | `get_category_ids()` fetched fresh on every save; stale category entries are dropped |
| Admin POSTs to a creator overlay endpoint | `handle_save()` authenticates via `creator_access_token`; admin credentials are not accepted |

**No database migration required.** No schema changes. No new tables. The audio category map is
stored in the existing `ccmerch_creator_overlay_settings.settings` JSON blob — only the values
written to it change.

---

### 3.2.0 — Creator Audio File Uploads

**Goal:** Replace the number-input attachment-ID workaround with a first-class audio upload UI. Creators can upload, preview, replace, and delete their own alert sound files directly from the Creator Portal without WordPress admin access. The admin Overlay page gains the same component. A new Media Settings section in the plugin Settings page controls the maximum file size and maximum audio duration enforced on every upload.

**New files:**
- `includes/audio/class-audio-upload-controller.php` — `CCMerchAlert\Audio\Audio_Upload_Controller`; handles all audio upload/delete AJAX for both creator portal (nopriv, creator-token auth) and admin (manage_options + nonce); shared `process_upload()` pipeline; file ownership stored in post_meta; atomic replace (save-new-first, delete-old-after)
- `includes/ui/components/class-audio-upload-component.php` — `CCMerchAlert\UI\Components\Audio_Upload_Component`; shared static renderer for the upload UI card (filename, size, duration, HTML5 player, Upload/Replace button, Delete button, status feedback); all AJAX params encoded as data attributes on the container; registered in `Component_Registry` as `'audio-upload'`

**Modified files:**
- `includes/class-settings.php` — added `SECTION_MEDIA`, `FIELD_MAX_AUDIO_SIZE` (bytes, default 10 485 760 = 10 MB, clamped to [102 400, 524 288 000]), `FIELD_MAX_AUDIO_DURATION` (seconds, default 30, clamped to [1, 3 600]); both rendered as value + unit dropdown pairs (same pattern as `FIELD_REFRESH_INTERVAL`); registered in new "Media Settings" section on the plugin settings page
- `includes/overlay/class-overlay-config-renderer.php` — added 5th parameter `array $audio_upload_args = []` to `render_audio_section()`; when non-empty, the Default Sound row renders via `Audio_Upload_Component::render()` instead of the legacy media picker / number input; `$with_media_picker` still controls the category map table independently; added `use Audio_Upload_Component`
- `includes/admin/class-overlay-admin.php` — passes admin-context `$audio_upload_args` (action: `AJAX_ADMIN_UPLOAD/DELETE`, auth: `_ajax_nonce` + `NONCE_ADMIN`) to `render_audio_section()`; added `use Audio_Upload_Controller`
- `includes/creators/class-creator-portal-renderer.php` — passes portal-context `$audio_upload_args` (action: `AJAX_CREATOR_UPLOAD/DELETE`, auth: `creator_token` + `_nonce`) to `render_audio_section()`; added `use Audio_Upload_Controller`
- `includes/class-plugin.php` — added `use`/`require_once` for `Audio_Upload_Controller` and `Audio_Upload_Component`; instantiates `new Audio_Upload_Controller($settings, $creator_service)` always (not admin-only — creator nopriv endpoints active on front-end requests); registers `'audio-upload'` component in `load_components()`
- `assets/js/ccmerch-utils.js` — added `audioContainer()`, `audioStatus()`, `ccmerchAudioUpload()`, `ccmerchAudioDelete()` functions; added delegated `click` listener (`[data-ccmerch-audio-upload]` → file input click; `[data-ccmerch-audio-delete]` → delete); added delegated `change` listener (`[data-ccmerch-audio-file-input]` → upload); no per-page JS required for audio upload/delete interactions
- `assets/css/admin.css` — added `.ccmerch-audio-upload` block and sub-element styles (`.ccmerch-audio-upload__empty`, `__info`, `__filename`, `__meta`, `__player`, `__actions`, `__status`)
- `ccmerch-alert.php` — version bumped to Alpha 3.2.0

**Admin Media Settings (new section on plugin Settings page):**

| Field | Constant | Default | Clamp | Unit options |
|---|---|---|---|---|
| Maximum Audio File Size | `FIELD_MAX_AUDIO_SIZE` | 10 MB (10 485 760 bytes) | 100 KB – 500 MB | KB / MB |
| Maximum Audio Length | `FIELD_MAX_AUDIO_DURATION` | 30 s | 1 s – 1 h | Seconds / Minutes |

**Upload validation pipeline (`Audio_Upload_Controller::process_upload()`):**

1. Check `$_FILES['ccmerch_audio_file']` is present with `UPLOAD_ERR_OK`.
2. `wp_check_filetype_and_ext()` against allowed MIME types: `audio/mpeg` (mp3, m4a, m4b), `audio/wav` (wav), `audio/ogg` (ogg, oga).
3. File size check against `Settings::FIELD_MAX_AUDIO_SIZE` — returns WP_Error before moving the file.
4. `wp_handle_upload()` moves file to the WordPress uploads directory.
5. `wp_read_audio_metadata()` (getid3) checks `length` against `Settings::FIELD_MAX_AUDIO_DURATION` — on failure: `unlink($moved_file)` then return WP_Error (no orphaned files or DB records).
6. `wp_insert_attachment()` + `wp_generate_attachment_metadata()` + `wp_update_attachment_metadata()` — creates a proper WordPress media library entry.
7. `update_post_meta()` stores `_ccmerch_audio_creator_id`, `_ccmerch_audio_overlay_id`, `_ccmerch_audio_upload_timestamp` for ownership verification.

**Atomic replace pattern:**

When an existing attachment ID is sent (`old_attachment_id`), the new file is fully validated, inserted into the media library, and its ID is returned to the client BEFORE the old attachment is deleted. If the new upload fails at any step, the existing audio is never touched. After a successful new insert: `wp_delete_attachment($old_id, true)` removes both the DB record and the physical file.

**File ownership model:**

| Meta key | Value | Purpose |
|---|---|---|
| `_ccmerch_audio_creator_id` | `int` — creator profile ID | Ownership gate: only the owning creator or an admin may delete |
| `_ccmerch_audio_overlay_id` | `string` — overlay_id | Context tracking |
| `_ccmerch_audio_upload_timestamp` | `int` — Unix timestamp | Audit trail |

`delete_if_owner()` reads `_ccmerch_audio_creator_id` and compares to the authenticated creator. On mismatch: logs via `error_log()` when `WP_DEBUG_LOG` is true and returns false without deleting. Prevents a creator from deleting audio they don't own by forging an attachment ID.

**Audio Upload Component data-attribute contract:**

Attributes on the container `[data-ccmerch-audio-container]`:

| Attribute | Description |
|---|---|
| `data-ccmerch-upload-action` | WordPress AJAX action for upload |
| `data-ccmerch-delete-action` | WordPress AJAX action for delete |
| `data-ccmerch-audio-auth-field` | POST field name for the auth credential |
| `data-ccmerch-audio-auth-value` | Auth credential value (nonce or creator token) |
| `data-ccmerch-audio-nonce-field` | Secondary nonce POST field (empty for admin) |
| `data-ccmerch-audio-nonce-value` | Secondary nonce value (empty for admin) |
| `data-ccmerch-audio-ajax-url` | admin-ajax.php URL |

**Security model:**

| Concern | Implementation |
|---|---|
| Creator upload auth | `creator_access_token` (POST) + `wp_verify_nonce('ccmerch_portal_action_' . $token)` |
| Creator delete auth | same token + nonce; `delete_if_owner()` verifies `_ccmerch_audio_creator_id` |
| Admin upload/delete auth | `check_ajax_referer(NONCE_ADMIN)` + `current_user_can('manage_options')` |
| MIME type enforcement | `wp_check_filetype_and_ext()` against hardcoded `ALLOWED_MIMES` — user-supplied file extension is not trusted |
| Size enforcement | checked BEFORE `wp_handle_upload()` — server-side only; browser `accept` attribute is advisory |
| Duration enforcement | checked AFTER `wp_handle_upload()` but BEFORE `wp_insert_attachment()` — physical file deleted on failure |
| Cross-creator delete | `_ccmerch_audio_creator_id` meta prevents deletion of another creator's file even with a valid but foreign attachment ID |

**AJAX endpoints:**

| Constant | Action | Auth |
|---|---|---|
| `AJAX_CREATOR_UPLOAD` | `ccmerch_creator_audio_upload` | nopriv + creator_token + portal nonce |
| `AJAX_CREATOR_DELETE` | `ccmerch_creator_audio_delete` | nopriv + creator_token + portal nonce |
| `AJAX_ADMIN_UPLOAD` | `ccmerch_admin_audio_upload` | wp_ajax_ + manage_options + NONCE_ADMIN |
| `AJAX_ADMIN_DELETE` | `ccmerch_admin_audio_delete` | wp_ajax_ + manage_options + NONCE_ADMIN |

**Save behaviour:**

Upload and delete operations update the hidden form field (`[data-ccmerch-audio-att-id]`) via JavaScript immediately. The overlay profile DB is NOT updated until the user clicks "Save Settings". This is consistent with how drag-resize and appearance changes work in the Layout Editor.

**Architecture rules established by this version:**

- `Audio_Upload_Controller` is the **only** place that handles audio file upload and delete. No other class may call `wp_handle_upload()` or `wp_delete_attachment()` for audio files.
- `Audio_Upload_Component::render()` is the **only** place that renders the audio upload UI widget. No other class may duplicate this card.
- `ccmerchAudioUpload()` and `ccmerchAudioDelete()` in `ccmerch-utils.js` are the **only** JS handlers for audio upload/delete interactions. No per-page IIFE may re-implement this logic.
- Audio upload buttons must use `data-ccmerch-upload-action` + container data attributes — not inline JS. The delegated listeners in `ccmerch-utils.js` handle all interactions automatically.
- All audio files written by this plugin carry `_ccmerch_audio_creator_id` post_meta. Any future deletion path must verify ownership via `delete_if_owner()` before calling `wp_delete_attachment()`.

**Known limitations (not bugs):**
- `wp_read_audio_metadata()` requires getid3, which is bundled with WordPress. On some hosts it may fail to read duration for certain encoding variations. When it returns no `length` key, the upload proceeds without duration checking (fail-open is safer than blocking legitimate files).
- The category sound map table in the audio section still uses number inputs for attachment IDs (audio per category requires entering IDs manually). The `Audio_Upload_Component` is used for the Default Sound field only in this version; per-category upload UI is a future enhancement.
- Creators uploading audio via the portal bypass the WordPress media library UI. Files are still registered as proper WordPress attachments and appear in the Media Library, tagged with creator meta.

**No database migration required.** Audio attachments are stored in the standard WordPress posts/postmeta tables via `wp_insert_attachment()`. No new custom tables were added.

---

### 3.3.0 — Auth_Context: dual-context AJAX authentication

**Goal:** Fix a critical production bug where audio uploads from the Creator Portal fail with an
unhandled AJAX action when the requesting user is logged into WordPress. Introduce `Auth_Context`
as the standard authentication resolution layer for all dual-context AJAX endpoints.

**Root cause of the bug:**

`Audio_Upload_Controller` registered `wp_ajax_nopriv_ccmerch_creator_audio_upload` but NOT
`wp_ajax_ccmerch_creator_audio_upload`. WordPress only fires `wp_ajax_nopriv_*` for unauthenticated
requests. When a logged-in WordPress user (e.g. the site admin testing the creator portal, or any
creator who happens to also have a WP account) sends an upload request, WordPress routes it to
`wp_ajax_ccmerch_creator_audio_upload` — which had no handler. WordPress fell through to the default
response and returned `-1` (a string, not valid JSON). The JS `r.json()` call threw a parse error,
and the `.catch()` handler showed "Request failed." The symptom appeared as an HTTP 400/unhandled
response to the consumer.

**Secondary issue:**

The previous `auth_creator()` private method verified a WordPress nonce (`wp_verify_nonce()`). On
cached portal pages, the nonce embedded in HTML could be more than 24 hours old (expired beyond 2
nonce ticks), causing all upload attempts on cached pages to fail with "Security check failed."
The creator access token (256-bit entropy) is already a stronger authentication guarantee than a
12-hour WP nonce — the nonce was adding fragility without meaningful additional security.

**New files:**
- `includes/auth/class-auth-context.php` — `CCMerchAlert\Auth\Auth_Context`; static factory class;
  `resolve_creator()` authenticates via creator_access_token (no nonce); `resolve_admin()` uses
  `check_ajax_referer()` + `current_user_can('manage_options')`; all failures terminate with structured
  `wp_send_json_error(['message', 'reason', 'step'])`

**Modified files:**
- `includes/audio/class-audio-upload-controller.php` — added `wp_ajax_ccmerch_creator_audio_upload`
  and `wp_ajax_ccmerch_creator_audio_delete` hooks (alongside existing nopriv variants); replaced
  inline `auth_creator()` private method with `Auth_Context::resolve_creator()`; replaced inline
  admin auth with `Auth_Context::resolve_admin()`; removed `auth_creator()` method; added
  `use CCMerchAlert\Auth\Auth_Context`
- `includes/class-plugin.php` — added `require_once $dir . '/auth/class-auth-context.php'` before
  the audio controller require (load order: auth → audio)
- `ccmerch-alert.php` — version bumped to Alpha 3.3.0
- `CLAUDE.md` — added `CCMerchAlert\Auth` namespace, `includes/auth/` folder entry, `Auth_Context`
  class description, AJAX Architecture section, this changelog

**Architecture rules established by this version:**

- `Auth_Context` is the **only** place that resolves AJAX authentication context. No handler method
  may contain inline nonce checks, `current_user_can()` calls, or token lookups outside of
  `Auth_Context`.
- Creator portal AJAX endpoints must register BOTH `wp_ajax_nopriv_*` AND `wp_ajax_*` hooks. The
  same handler method handles both — `Auth_Context::resolve_creator()` provides token-based auth
  regardless of WP session state.
- `wp_send_json_error(['message' => '...', 'reason' => '...', 'step' => '...'])` is the required
  error format for all AJAX endpoints. `wp_die(-1)` or any non-JSON response is not permitted.

**No database migration required.**

---

### 3.3.1 — Audio Silence Fix: stale `audioEnabled` JS gate removed

**Root cause:** `ccmerchOverlay.audioEnabled` in `overlay.js` was evaluated at page load time and
never updated. If the OBS browser source was loaded when audio was disabled (the default is `'0'`),
`audioEnabled = false` was baked into the page's JS config. Even after the creator enabled audio in
settings and saved, subsequent polls returned valid non-empty `sound_url` values — but `AudioManager.play()`
short-circuited on the stale flag and returned silently. No audio. No error. No diagnostic output.

**Why the dual gate was wrong:**

`resolve_sound_url()` already returns `''` when `audio_enabled !== '1'`. The `sound_url` field in
every poll response IS the current audio-enabled state. The frontend `audioEnabled` flag was redundant
and actively harmful — it could only go stale, never update, and its staleness was invisible.

**Modified files:**
- `assets/js/overlay.js` — removed `! ccmerchOverlay.audioEnabled ||` from `AudioManager.play()`;
  `! url` is now the sole JS gate; added doc comment explaining why `audioEnabled` is intentionally
  absent; added `console.log('[CCMerch Queue]')` in `processQueue()` showing `sound_url` and queue
  depth; added `console.log('[CCMerch Audio] play()')` on URL, `.then()` on success, `.catch()` +
  `console.warn()` on failure
- `includes/overlay/class-overlay-renderer.php` — added `WP_DEBUG_LOG`-gated `error_log()` calls
  in `resolve_sound_url()` at every decision point: enabled check, category map lookup (term IDs
  and map keys), per-category URL resolution, default sound URL resolution, and the empty fallback

**Architecture rule confirmed:**

`AudioManager.play()` uses `! url` as its sole gate. The backend (`resolve_sound_url()`) is the
single source of truth for whether audio fires. Do not re-add a frontend `audioEnabled` check.

**Audio `attachment_id` on `Alert_Event` (clarification):**

`Alert_Event` intentionally has no `audio_attachment_id` field. Audio is resolved at render time
from the creator's overlay settings (`FIELD_AUDIO_CAT_MAP` / `FIELD_AUDIO_DEFAULT`) using the
event's `category_term_ids`. This means a creator can change their sound file at any time and the
next poll immediately plays the new file — no re-routing or event recreation required.

**Checklist when audio does not play:**
1. Is `audio_enabled = '1'` saved in overlay settings? (default is `'0'`)
2. Is a default attachment ID > 0 saved, OR does the product's WC category have a sound mapped?
3. Did the creator click "Save Settings" after uploading? Uploading alone does not persist the ID.
4. Open OBS Browser Source devtools (right-click → Inspect) and check console for `[CCMerch Audio]` lines.
5. Enable `WP_DEBUG_LOG` and check `wp-content/debug.log` for `[CCMerch Audio] resolve_sound_url:` lines.

**No database migration required.**

---

### Beta 1.0.1 — Release System v2: dynamic changelog infrastructure

**Version:** Beta 1.0.0 → Beta 1.0.1.

**Goal:** Treat plugin releases as data, not code. Replace the static PHP `$entries` array in
`Changelog_Modal_Component` with a database-backed release system, a REST API endpoint, an admin
Changelog Manager page, and a feature-tag vocabulary. The Creator Portal changelog modal now fetches
live data from the REST API on first open.

**New files:**
- `includes/releases/class-release-installer.php` — `CCMerchAlert\Releases\Release_Installer`;
  `activate()` + `maybe_install()` pattern; creates `{prefix}ccmerch_releases` table; `seed_if_empty()`
  inserts Beta 1.0.0 and Beta 1.0.1 entries on fresh install
- `includes/releases/class-release.php` — `CCMerchAlert\Releases\Release`; immutable value object;
  `stage_label()`, `to_api_array()` (includes `feature_labels` resolved from registry)
- `includes/releases/class-feature-tag-registry.php` — `CCMerchAlert\Releases\Feature_Tag_Registry`;
  closed vocabulary of 11 predefined tag slugs; `is_valid()`, `label()`, `filter_valid()`
- `includes/releases/class-release-repository.php` — `CCMerchAlert\Releases\Release_Repository`;
  `get_all(?stage, ?feature_tag)`, `get_by_id()`, `get_latest()`, `create()`, `update()`, `delete()`
- `includes/api/class-releases-controller.php` — `CCMerchAlert\Api\Releases_Controller`; registers
  `GET /wp-json/ccmerch/v1/releases` with `permission_callback => '__return_true'`; supports
  `?stage=` and `?feature=` query params
- `includes/admin/class-changelog-admin.php` — `CCMerchAlert\Admin\Changelog_Admin`; admin submenu
  page "CCMerch Alert → Changelog"; list + create/edit/delete form; PRG pattern; feature tag
  checkboxes from `Feature_Tag_Registry::TAGS`

**Modified files:**
- `ccmerch-alert.php` — `CCMERCH_ALERT_VERSION` → `'Beta 1.0.1'`; added `require_once` for
  `Release_Installer`; added `register_activation_hook` for `Release_Installer::activate()`;
  added `Release_Installer::maybe_install()` in `plugins_loaded` callback
- `includes/class-plugin.php` — added `use`/`require_once` for all 6 new classes; instantiates
  `Release_Repository` and `Releases_Controller` unconditionally; instantiates `Changelog_Admin`
  inside `is_admin()` block
- `includes/ui/components/class-changelog-modal-component.php` — removed static `$entries` array;
  modal body replaced with loading placeholder (`id="ccmerch-changelog-body"`); body populated
  dynamically by `ccmerchFetchChangelog()` in ccmerch-utils.js
- `includes/creators/class-creator-portal-renderer.php` — added
  `<script>var ccmerchApiBase = '...rest_url...';</script>` inline script so ccmerch-utils.js knows
  the REST API base URL
- `assets/js/ccmerch-utils.js` — replaced static changelog open/close with API-fetching version;
  added `_changelogFetched` one-time guard; added `ccmerchFetchChangelog()` and
  `ccmerchRenderChangelog()` helpers; all DOM building uses `textContent` (no innerHTML XSS risk)
- `assets/css/creator-portal.css` — added `.ccmerch-changelog-loading`, `.ccmerch-changelog-error`,
  `.ccmerch-modal__release-title`, `.ccmerch-modal__release-desc` styles
- `uninstall.php` — added `DROP TABLE IF EXISTS {prefix}ccmerch_releases` + `delete_option`
- `CLAUDE.md` — added `CCMerchAlert\Releases` and `CCMerchAlert\Api` namespace rules; updated
  folder structure; added 6 class descriptions; updated WordPress Constants version; partially
  implemented REST API future module entry; this changelog entry

**New database table (`{prefix}ccmerch_releases`):**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| version | VARCHAR(50) | Semver string, e.g. `1.0.1` |
| stage | VARCHAR(20) DEFAULT 'beta' | alpha / beta / production |
| title | VARCHAR(255) | Short release title |
| description | TEXT | Full release notes text |
| feature_tags | TEXT DEFAULT '[]' | JSON array of Feature_Tag_Registry slugs |
| created_at | DATETIME | UTC; KEY for sort |
| updated_at | DATETIME | UTC |

**Feature Tag Registry (predefined slugs):**

`alert_templates`, `audio_isolation`, `audio_upload`, `browser_source`, `changelog_system`,
`creator_portal`, `dashboard`, `order_log`, `overlay_designer`, `release_manager`, `routing_engine`

**REST API endpoint:**

```
GET /wp-json/ccmerch/v1/releases
GET /wp-json/ccmerch/v1/releases?stage=beta
GET /wp-json/ccmerch/v1/releases?feature=creator_portal

Response: [
  {
    "id": 2,
    "version": "1.0.1",
    "stage": "beta",
    "stage_label": "Beta",
    "title": "Release System v2",
    "description": "...",
    "feature_tags": ["changelog_system", "release_manager"],
    "feature_labels": ["Changelog System", "Release Manager"],
    "created_at": "2026-06-30T00:00:00+00:00",
    "updated_at": "2026-06-30T00:00:00+00:00"
  }
]
```

**Changelog modal data flow:**

```
Creator Portal page loads
    │  <script>var ccmerchApiBase = rest_url('ccmerch/v1');</script>
    │
    ▼ user clicks "Changelog" button
ccmerch-utils.js: ccmerchOpenChangelog()
    │  modal opens with "Loading…" placeholder
    │  _changelogFetched guard prevents duplicate fetches
    │
    ▼ ccmerchFetchChangelog()
    │  fetch(ccmerchApiBase + '/releases')
    │  → GET /wp-json/ccmerch/v1/releases
    │
    ▼ Releases_Controller::get_releases()
    │  Release_Repository::get_all()
    │  → Release[] mapped via to_api_array()
    │
    ▼ ccmerchRenderChangelog(body, releases)
    │  DOM built via createElement + textContent (XSS-safe)
    │  version, stage_label, title, description, feature_labels rendered
    │
    ▼ modal body displays release list, newest first
```

**Architecture decisions:**

- `CCMERCH_ALERT_VERSION` constant is retained. WordPress plugin infrastructure requires a static
  version string in the plugin header; `@ver` in enqueued asset URLs must be a constant.
  Changelog/release DATA is now fully DB-driven and editable from the admin. These are separate
  concerns — the constant is not the changelog.
- `@feature` annotations added only to new files. Retrofitting annotations to all 60+ existing
  files would violate CLAUDE.md's "Never rewrite working code" rule. Future new files should
  include `@feature` annotations.
- Feature tag filtering (`filter_valid()`) strips unknown slugs before persistence. The vocabulary
  is intentionally closed — free-text tags would make filtering unreliable.
- The `ccmerchApiBase` variable is injected only in `Creator_Portal_Renderer` (standalone HTML,
  no `wp_head`). Admin pages can use `wp_localize_script()` to inject the same value when needed.

**No database migration required for existing installations.** `Release_Installer::maybe_install()`
runs on every request (cheap `get_option()` guard) and creates the table if absent.

---

### Beta 1.0.0 — Version bump and Creator Portal Changelog Modal

**Version:** Alpha 3.3.1 → Beta 1.0.0.

This marks the first Beta release of CCMerch Alert. The codebase is feature-complete for the
initial creator-overlay scope and stable enough for real-environment testing.

**New files:**
- `includes/ui/components/class-changelog-modal-component.php` — `CCMerchAlert\UI\Components\Changelog_Modal_Component`; static `render_button()` and `render_modal()` methods; changelog entries stored as a static array; designed for future dynamic feed replacement without API changes.

**Modified files:**
- `ccmerch-alert.php` — Plugin header and `CCMERCH_ALERT_VERSION` constant updated from `'Alpha 3.3.1'` to `'Beta 1.0.0'`.
- `includes/class-plugin.php` — added `use` + `require_once` for `Changelog_Modal_Component`; registered `'changelog-button'` and `'changelog-modal'` in `Component_Registry`.
- `includes/creators/class-creator-portal-renderer.php` — added `use Changelog_Modal_Component`; wrapped header brand bar in `.ccmerch-portal__header-top` flex container with `Changelog_Modal_Component::render_button()` on the right; added `Changelog_Modal_Component::render_modal()` before `</body>`.
- `assets/js/ccmerch-utils.js` — added `ccmerchOpenChangelog()` / `ccmerchCloseChangelog()` helpers; added delegated `click` listener for `[data-ccmerch-changelog="1"]` (open) and `[data-ccmerch-changelog-close]` (close); added `keydown` listener for Escape key close.
- `assets/css/creator-portal.css` — added `.ccmerch-portal__header-top` flex row styles; added full `.ccmerch-modal` block (backdrop, dialog, header, close button, body, entry, version, notes).
- `CLAUDE.md` — added `### Versioning` and `### Changelog System` subsections in Architecture Principles; updated WordPress Constants table version; added this changelog entry.

**Changelog modal architecture:**

```
Creator Portal header
    │  .ccmerch-portal__header-top (flex row)
    │    ├── .ccmerch-portal__brand
    │    └── <button data-ccmerch-changelog="1">Changelog</button>
    │
    ▼ click → ccmerch-utils.js ccmerchOpenChangelog()
    │  modal.classList.add('ccmerch-modal--open')
    │  modal.setAttribute('aria-hidden', 'false')
    │
    ▼ #ccmerch-changelog-modal.ccmerch-modal--open (display:flex)
    │    .ccmerch-modal__backdrop [data-ccmerch-changelog-close]
    │    .ccmerch-modal__dialog
    │      .ccmerch-modal__header
    │        <h2>Changelog</h2>
    │        <button data-ccmerch-changelog-close>✕</button>
    │      .ccmerch-modal__body
    │        .ccmerch-modal__entry × N (newest first)
    │
    ▼ close triggers (any of):
       · X button click [data-ccmerch-changelog-close]
       · backdrop click [data-ccmerch-changelog-close]
       · Escape key (keydown listener)
    → ccmerchCloseChangelog() → removes class, sets aria-hidden=true
```

**To add a future changelog entry:** prepend a new `['version' => '...', 'notes' => [...]]` array
item to `Changelog_Modal_Component::$entries`. No other file requires modification.

**Architecture rules confirmed by this version:**

- `Changelog_Modal_Component` is the **only** place that renders the changelog button and modal.
  No other class may duplicate this UI.
- `ccmerchOpenChangelog()` and `ccmerchCloseChangelog()` in `ccmerch-utils.js` are the **only**
  JS functions that open and close the changelog modal. No per-page IIFE may re-implement this.
- Changelog trigger buttons must carry `data-ccmerch-changelog="1"`. Close triggers must carry
  `data-ccmerch-changelog-close`. The delegated listeners in `ccmerch-utils.js` handle all
  interactions automatically.

**No database migration required.**

---

### Beta 1.0.1 patch — Creator Save Architecture fix

**Version:** Beta 1.0.1 (no version bump — patch within existing release).

**Root cause fixed:** `Creator_Overlay_Controller` registered `admin_post_ccmerch_creator_overlay_save`
and `admin_post_ccmerch_creator_generate_overlay_id` without `admin_post_nopriv_` variants. WordPress
returns HTTP 400 for unauthenticated requests to `admin_post_{action}` when no `_nopriv` variant exists.
Creators are not WordPress users, so every save attempt produced a 400 with no explanation.

**New files:**
- `includes/creators/class-creator-save-service.php` — `CCMerchAlert\Creators\Creator_Save_Service`;
  `AJAX_SAVE` and `AJAX_GENERATE_ID` constants; `register()` wires both `wp_ajax_nopriv_` AND
  `wp_ajax_` hooks; `handle_save()` and `handle_generate_id()` authenticated via
  `Auth_Context::resolve_creator()`; all responses are JSON; no page reload.

**Modified files:**
- `includes/creators/class-creator-overlay-controller.php` — removed `ACTION_GENERATE_ID`,
  `ACTION_SAVE` constants; removed `handle_generate_id()` and `handle_save()` methods; removed
  `admin_post_` hook registrations; updated docblock to reference `Creator_Save_Service`.
- `includes/overlay/class-overlay-config-renderer.php` — `render_browser_source_card()` gains
  optional 5th parameter `bool $use_ajax_generate = false`; when true, form targets
  `admin-ajax.php` and a `data-ccmerch-generate-id="1"` attribute enables JS interception;
  URL section wrapped in `id="ccmerch-overlay-url-section"` for DOM rebuild on first generation.
- `includes/creators/class-creator-portal-renderer.php` — form action changed from
  `admin-post.php` to `admin-ajax.php`; `action` hidden field uses `Creator_Save_Service::AJAX_SAVE`
  and `Creator_Save_Service::AJAX_GENERATE_ID`; added `data-ccmerch-portal-save="1"` on form;
  added `id="ccmerch-portal-save-feedback"` feedback div after submit button.
- `assets/js/ccmerch-utils.js` — added `ccmerchPortalSave(form)`, `ccmerchHandleGenerateOverlayId(form)`,
  `ccmerchBuildOverlayUrlHtml(url)` helpers; added delegated `submit` listeners for
  `[data-ccmerch-portal-save]` and `[data-ccmerch-generate-id]` forms; after successful save,
  dispatches non-cancelable synthetic `submit` event so `layout-editor.js` resets `dirty = false`.
- `includes/class-plugin.php` — added `use Creator_Save_Service`; added `require_once` for
  `class-creator-save-service.php` (before `class-creator-portal-renderer.php` — load-order
  dependency on `AJAX_SAVE` / `AJAX_GENERATE_ID` constants); instantiates
  `( new Creator_Save_Service( $this->creator_service ) )->register()` unconditionally.
- `CLAUDE.md` — added Creator Save Architecture section; added `Creator_Save_Service` class
  description; updated folder structure; updated AJAX Architecture section with `admin-post.php`
  prohibition; this changelog entry.

**Architecture rules established by this patch:**

- `Creator_Save_Service` is the **only** class that registers AJAX save/generate-ID endpoints for
  the Creator Portal. `Creator_Overlay_Controller` must not re-add these handlers.
- No creator portal write operation may use `admin-post.php`. All writes go through `admin-ajax.php`.
- `ccmerchPortalSave()` in `ccmerch-utils.js` is the **only** JS save handler for the portal form.
  No per-page IIFE or event listener may re-implement portal form submission.
- The non-cancelable synthetic `submit` event pattern (`{ cancelable: false }`) is the correct way
  to signal a completed AJAX save to `layout-editor.js`. The dirty-flag listener in `layout-editor.js`
  must not change this behaviour.

**No database migration required.**

---

### Beta 1.0.1 patch — SSE Browser Source Runtime

**Version:** Beta 1.0.1 (no version bump — patch within existing release).

**Goal:** Replace the AJAX polling loop in the Browser Source with a Server-Sent Events (SSE) persistent
connection so all alert types (test, replay, live orders) appear immediately without requiring a
Browser Source page refresh or cache clear.

**Root cause of the original latency/refresh problem:**

The overlay page used `setInterval` to poll every 5 seconds. OBS Browser Source throttles JavaScript
execution in suspended tabs (when OBS is minimised or the scene is not active), causing the `setInterval`
to stall entirely. When OBS becomes active again, queued polls fired rapidly but the user perceived alerts
as "not working until refresh". Additionally, test alerts had up to 5 seconds of inherent latency.

**What changed:**

- `overlay.js` — complete rewrite. Primary transport is now `EventSource` (SSE). AJAX polling is
  retained as a fallback for non-SSE environments. All alert types are received via `handleAlertBatch()`,
  a single shared function that is transport-agnostic. Auto-reconnects on disconnect (planned or
  unplanned). No page refresh required under any circumstance.
- `includes/overlay/interface-overlay-settings-contract.php` — added `get_stream_action(): string`.
- `includes/overlay/class-overlay-settings.php` — implemented `get_stream_action()` → `'ccmerch_overlay_stream'`.
- `includes/creators/class-creator-overlay-profile.php` — implemented `get_stream_action()` → `'ccmerch_creator_overlay_stream'`.
- `includes/overlay/class-overlay-renderer.php` — added `streamAction` to `ccmerchOverlay` JS config.
- `includes/overlay/class-overlay-controller.php` — added `AJAX_STREAM` constant; registered `wp_ajax_nopriv_` and `wp_ajax_` hooks; added `handle_stream()` SSE loop and `sse_emit()` helper.
- `includes/creators/class-creator-overlay-controller.php` — same additions for creator overlays; loop reads from `Alert_Event_Repository::get_pending_for_overlay()`.
- `CLAUDE.md` — added "Browser Source Runtime" section to Architecture Principles.

**SSE connection lifecycle:**

```
overlay.js: connectSSE()
    │  EventSource GET admin-ajax.php?action=...stream&overlay_id=X&since=T
    │
    ▼ PHP: handle_stream()
    │  Validates overlay_id (hash_equals)
    │  Sends SSE headers (Content-Type: text/event-stream, X-Accel-Buffering: no)
    │  Emits: event: connected
    │  Loops with sleep(1):
    │    Checks transient (test/replay alerts)
    │    Checks DB for new events since cursor
    │    Emits: event: alert  (when events found)
    │    Emits: event: heartbeat  (every 15 s)
    │  After 55 s: emits event: reconnect, exits
    │
    ▼ overlay.js: receives reconnect event
    │  Closes EventSource, calls connectSSE() immediately
    │  New URL includes current since value
    │  Server reads Last-Event-ID header on reconnect as the since cursor
    │
    ▼ Loop resumes — no events missed
```

**Architecture rules established by this patch:**

- `handleAlertBatch(queue, serverTime)` in `overlay.js` is the **only** entry point for incoming alert
  events. Both SSE and AJAX polling call this function. No separate code paths for test vs. real alerts.
- `Overlay_Settings_Contract::get_stream_action()` is required on all implementations. Returning the
  correct action slug for the concrete overlay context is the responsibility of each implementor.
- `Overlay_Renderer::render_page()` must inject `streamAction` into the `ccmerchOverlay` config.
  Never hardcode the stream action in `overlay.js`.
- The SSE endpoint must register BOTH `wp_ajax_nopriv_` AND `wp_ajax_` hooks. A logged-in WordPress
  admin testing the overlay page must reach the same handler as an anonymous OBS Browser Source.
- The `X-Accel-Buffering: no` header is required on all SSE responses. Without it, nginx and some
  reverse proxies buffer the entire response body before forwarding it, breaking the streaming behaviour.
- Future transports (WebSocket): add a WebSocket client that calls `handleAlertBatch()` on message.
  The queue, animation, and audio code require no changes.

**No database migration required.**

---

### Beta 1.1.0 — Creator-Isolated Test Alert System

**Goal:** Enforce strict Creator Isolation in the Test Alert system. Before this change, both
`handle_portal_test()` and `handle_test()` used `Order_Repository::get_recent(1)`, which reads
the most recently logged order from the global Order Log with no creator scoping. A creator's test
alert could display another creator's product, customer name, or audio — a direct violation of
the Creator Isolation Model established in 3.0.0.

**The isolation violation that was fixed:**

`Order_Repository::get_recent(1)` returns the most recent order site-wide. If Creator A's last
test occurs while Creator B's product was the most recently logged item, Creator A's overlay
displayed Creator B's product. This was silently broken for any multi-creator installation.

**Modified files:**
- `includes/creators/class-creator-service.php` — added `get_random_product_for_creator(int): ?array`
- `includes/creators/class-creator-overlay-controller.php` — added `TEST_FIRST_NAMES` constant;
  rewrote `handle_test()` and `handle_portal_test()` to use creator-scoped product selection

**Two alert engine changes (as requested):**

**Change 1 — `handle_portal_test()` (creator portal, nopriv path):**
- Previously: called `$this->repository->get_recent(1)` → used most recent global order.
- Now: calls `$this->service->get_random_product_for_creator($creator->id)` → scoped to creator's
  assigned WC categories. Falls back to a friendly JSON error when null (no products in category).
  First name randomly selected from `TEST_FIRST_NAMES`. No Order_Repository call at all.

**Change 2 — `handle_test()` (admin path):**
- Previously: called `$this->repository->get_recent(1)` → same unscoped read.
- Now: calls `$this->service->get_random_product_for_creator($creator_id)` → same scoped selection.
  Falls back to a JSON error if no products. First name randomly selected from `TEST_FIRST_NAMES`.
  No Order_Repository call at all.

**New method: `Creator_Service::get_random_product_for_creator(int $creator_id): ?array`**

Returns null when: WooCommerce is inactive; creator has no category assignments; no published
products exist in the assigned categories. On success returns:
`['product_id', 'product_name', 'featured_image_attachment_id', 'category_term_ids']`

Selection algorithm:
1. Gate on `WooCommerce::is_active()`.
2. `get_category_ids($creator_id)` — returns `int[]` of assigned `product_cat` term IDs.
3. `get_posts(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 50,
   'orderby' => 'rand', 'fields' => 'ids', 'tax_query' => ...])`.
4. `array_rand()` picks one product ID from the returned list.
5. `get_post_thumbnail_id()` → `featured_image_attachment_id` (null when 0).
6. `get_the_terms()` → category term IDs filtered to only the creator's assigned term IDs.

**Predefined first names (`TEST_FIRST_NAMES`):**
`Alex`, `Emma`, `Oliver`, `Sofia`, `Lucas`, `Isabella`, `Noah`, `Freja`, `William`, `Ella`
Randomly selected via `array_rand()` on each test; never derived from WooCommerce customer data.

**Simulated alert pipeline (unchanged):**

```
Creator clicks "Test Alert" → handle_portal_test() / handle_test()
    │  get_random_product_for_creator(creator_id)
    │  → WC product scoped to creator's product_cat term IDs
    │  → random first name from TEST_FIRST_NAMES
    │
    ▼
Order_Log_Item( product_id, product_name, image_att_id, category_term_ids )
Order_Log_Entry( first_name, [item] )
    │
    ▼
Overlay_Renderer::render_items( $mock_entry )
    │  resolve_sound_url() uses category_term_ids → creator's audio map
    │  render_alert() renders product image, template, secondary text
    │
    ▼
set_transient( 'ccmerch_creator_overlay_pending_' . $overlay_id, ['queue' => $queue], 300 )
    │
    ▼
SSE loop detects transient → emits 'alert' event → overlay.js handleAlertBatch()
    │
    ▼
showAlert() → animation + audio (creator's configured sound file)
```

**Isolation guarantees (confirmed):**
- Product: scoped to creator's assigned `product_cat` term IDs via `tax_query`.
- Image: the scoped product's own featured image.
- Audio: `resolve_sound_url()` uses `category_term_ids` from the scoped product — resolves to the
  creator's configured audio for that category, not any other creator's audio.
- Template: applied via `Overlay_Template_Engine` from the creator's own `Creator_Overlay_Profile`.
- Overlay: `set_transient` uses `$profile->overlay_id` — only this creator's SSE loop reads it.
- Customer data: no real customer names, no order tables, no WooCommerce customer queries.

**Error states:**
| Condition | Response |
|---|---|
| No categories assigned | `wp_send_json_error(['message' => 'No products are currently assigned to your category.'])` |
| No products in categories | same |
| WooCommerce inactive | same |
| Overlay profile not found | `wp_send_json_error(['message' => 'Overlay profile not found.'])` |

**No database migration required.**

---

### Beta 1.1.1 — Creator-Isolated Overlay Designer Preview

**Goal:** Enforce strict Creator Isolation in the Overlay Designer canvas preview and establish a
shared Preview Data Provider so the preview and the Test Alert system never duplicate logic.

**The isolation violation that was fixed:**

`Creator_Portal_Renderer` called `Overlay_Config_Renderer::resolve_preview_data($this->repository)`,
which called `Order_Repository::get_recent(1)` — a global, unscoped query returning the most recently
logged order from any creator. A creator's canvas preview could display another creator's product
name, image, and customer name. Additionally, `Creator_Portal_Renderer` held an `Order_Repository`
constructor dependency it no longer needed after this fix.

**New shared component: `Creator_Service::build_preview_data(int $creator_id): array`**

This is the single Preview Data Provider used by both the Overlay Designer canvas preview and the
Test Alert system. It calls `get_random_product_for_creator($creator_id)` for creator-scoped product
selection and `self::TEST_FIRST_NAMES` (moved from `Creator_Overlay_Controller`) for name generation.
The return shape `{first_name, product, quantity, image_url, mock}` is identical to the `preview`
key in the `ccmerchLayout` JS object consumed by `layout-editor.js`.

**`TEST_FIRST_NAMES` moved to `Creator_Service`:**

The predefined first names list was a `private const` on `Creator_Overlay_Controller`. It is now
`public const TEST_FIRST_NAMES` on `Creator_Service` — the single source of truth accessible to
all preview and test alert paths without duplication.

**New AJAX endpoint: `Creator_Overlay_Controller::AJAX_PREVIEW_DATA`**

Action: `'ccmerch_creator_preview_data'`. Auth: creator portal access token (same model as test
alert). Response: `wp_send_json_success(build_preview_data($creator->id))`.

**Refresh Preview button:**

`Overlay_Config_Renderer::render_designer()` gained an optional `array $refresh_btn = []` second
parameter. When non-empty (Creator Portal only), a "Refresh Preview" button is rendered in the
toolbar with all AJAX parameters encoded as `data-ccmerch-refresh-*` attributes. The admin Overlay
page passes nothing — no button is rendered there.

`ccmerchRefreshPreview(btn)` in `ccmerch-utils.js` handles the click: posts to the AJAX endpoint
and on success dispatches a `ccmerch:previewData` CustomEvent on `document`. `layout-editor.js`
listens for this event inside the IIFE, updates its internal `preview` variable, and calls
`renderAll()` to re-render the canvas immediately (no page reload).

**Modified files:**
- `includes/creators/class-creator-service.php` — added `public const TEST_FIRST_NAMES`; added
  `build_preview_data(int): array`
- `includes/creators/class-creator-overlay-controller.php` — removed `private const TEST_FIRST_NAMES`;
  all `self::TEST_FIRST_NAMES` references updated to `Creator_Service::TEST_FIRST_NAMES`; added
  `AJAX_PREVIEW_DATA` constant; registered nopriv + priv hooks; added `handle_preview_data()` method
- `includes/overlay/class-overlay-config-renderer.php` — added `array $refresh_btn = []` optional
  parameter to `render_designer()`; renders button in toolbar when non-empty
- `includes/creators/class-creator-portal-renderer.php` — removed `Order_Repository $repository`
  constructor parameter; removed `use CCMerchAlert\OrderLog\Order_Repository`; changed preview data
  call to `$this->service->build_preview_data($creator->id)`; passes refresh button args to
  `render_designer()`
- `includes/class-plugin.php` — removed `$this->order_repository` argument from
  `new Creator_Portal_Renderer(...)` call
- `assets/js/layout-editor.js` — added `document.addEventListener('ccmerch:previewData', ...)` inside
  the IIFE; updates `preview` variable and calls `renderAll()` on the event
- `assets/js/ccmerch-utils.js` — added `ccmerchRefreshPreview(btn)` function; added delegated `click`
  listener for `[data-ccmerch-refresh-preview]`
- `ccmerch-alert.php` — version bumped to `'Beta 1.1.1'`
- `CLAUDE.md` — added `### Preview Data Provider` section before `### Test Alert System`; updated
  `TEST_FIRST_NAMES` reference in Test Alert System section; updated version constant table

**Architecture rules established by this version:**

- `Creator_Service::TEST_FIRST_NAMES` is the **only** predefined first names list. No other class
  may define or duplicate this array.
- `Creator_Service::build_preview_data()` is the **only** Preview Data Provider implementation.
  No other class may replicate product selection or name generation for canvas previews.
- `Creator_Portal_Renderer` must never hold an `Order_Repository` dependency. All preview data
  is sourced from `Creator_Service::build_preview_data()`.
- `ccmerchRefreshPreview(btn)` in `ccmerch-utils.js` is the **only** JS function that refreshes
  canvas preview data from the server. No per-page IIFE may re-implement this logic.
- The `ccmerch:previewData` custom DOM event is the bridge between ccmerch-utils.js and the
  layout-editor.js IIFE. The event detail must be the full preview object
  `{first_name, product, quantity, image_url, mock}`.
- The Refresh Preview button is rendered only by `Overlay_Config_Renderer::render_designer()`
  when `$refresh_btn` is non-empty. The admin Overlay page never passes `$refresh_btn`.
- `Overlay_Config_Renderer::resolve_preview_data(Order_Repository)` remains in place for the
  admin Overlay page (global, unscoped — acceptable there). It must not be called from any
  creator-specific context.

**No database migration required.**

---

### Beta 1.2.0 — Test Alert instant delivery

**Goal:** Reduce Test Alert latency from ~600 ms average / ~1100 ms worst-case to under 200 ms
on a local installation. Creators can now iterate on their Overlay design with near-instant
feedback.

**Root cause profiled:**

| Step | Before | After |
|---|---|---|
| AJAX request (button → PHP → browser) | ~80 ms | ~50 ms |
| SSE loop detects transient | 0–1000 ms (avg 500 ms) | 0–100 ms (avg 50 ms) |
| Browser Source renders | ~0 ms | ~0 ms |
| **Total worst case** | **~1100 ms** | **~200 ms** |

The dominant bottleneck was `sleep(1)` inside both SSE loop handlers. Every iteration slept for a
full second before checking the transient; a test alert queued just after the sleep began would
wait up to 999 ms before delivery.

**Modified files:**

- `includes/overlay/class-overlay-controller.php` — `handle_stream()`: replaced `sleep(1)` with
  `usleep(100_000)` (100 ms); added `microtime(true)` float timing variables
  (`$last_event_check`, `$last_config_check`, `$last_heartbeat_f`) so live-event DB queries and
  config-version checks remain at 1 s intervals — DB load is unchanged. Transient check (Priority 1)
  runs every 100 ms. Added `'test' => (bool)($pending['test'] ?? false)` forwarded in emitted
  alert payload. `handle_test_alert()` transient now carries `['queue' => $queue, 'test' => true]`.
  `handle_replay_latest()` transient carries `['queue' => $queue, 'test' => false]`.
  Updated success message to "Watch your overlay." (removes the misleading "within a few seconds").

- `includes/creators/class-creator-overlay-controller.php` — identical SSE loop changes;
  `handle_test()` and `handle_portal_test()` transients now carry `'test' => true`.

- `includes/creators/class-creator-service.php` — `get_random_product_for_creator()`:
  `posts_per_page=50, fields=ids, orderby=rand` → `posts_per_page=1, orderby=rand, no_found_rows=true`.
  Eliminated the separate `get_post($product_id)` call (WP_Post is now in memory from `get_posts()`).
  Also eliminated `array_rand()` on 50 IDs (only 1 result now). Saves one DB round-trip and the
  MySQL overhead of `ORDER BY RAND() LIMIT 50`.

- `CLAUDE.md` — added `### Test Alert Performance` section in Architecture Principles; this changelog.

**SSE loop DB query budget (unchanged):**

| Query | Old frequency | New frequency |
|---|---|---|
| `get_transient()` (transient check) | 1×/s | 10×/s |
| `get_pending_for_overlay()` or `get_since()` (live events) | 1×/s | 1×/s |
| `get_overlay_config_version()` (config sync) | 1×/s | 1×/s |

The 9 extra `get_transient()` calls per second are single `SELECT` queries against `wp_options`
by primary key — the cheapest possible DB read. For a typical deployment with a small number of
concurrent SSE connections this is negligible.

**`test=true` marker:**

Stored in transients by all four test-alert handlers. Forwarded as a field in the SSE alert event
payload. The JS (`overlay.js`) ignores unknown keys — no JS changes required. The marker is a
forward-compatibility signal for future server-side processing that needs to distinguish test
alerts from live order alerts.

**No database migration required.**

---

### Beta 1.3.1 — Event-driven Browser Source runtime refactor

**Goal:** Eliminate architectural causes of Browser Source connection instability. Replace scattered
state variables and direct inter-module calls in `overlay.js` with an event-driven, module-based
architecture. Fix PHP SSE handler buffering that caused all events to arrive simultaneously when
running behind Cloudflare or other CDNs.

**PHP fixes (both `Overlay_Controller::handle_stream()` and `Creator_Overlay_Controller::handle_stream()`):**
- Added `ini_set('zlib.output_compression', '0')` before `ob_end_clean()` loop. PHP's built-in gzip
  compression buffers all output until the process exits; disabling it ensures each SSE frame is flushed
  immediately.
- Added `header('Content-Encoding: identity')`. Without this, CDNs (Cloudflare, etc.) may attempt to
  re-compress the response, which necessarily buffers the entire stream. `identity` signals the body is
  raw, allowing frames to be forwarded as they arrive.
- Reduced `$max_run` from 55 → **25 seconds**. Common PHP-FPM `request_terminate_timeout` values are
  30 s; running to 55 s caused PHP to be killed mid-stream (unplanned disconnect) instead of sending
  the graceful `reconnect` event. 25 s provides a 5 s safety margin.
- Added `header('Connection: keep-alive')` for hosts that do not default to persistent connections.

**`overlay.js` refactor — event-driven, module-based IIFE:**

Replaced 6 scattered `var` state declarations and direct inter-function calls with named modules that
communicate through a central `EventBus`. All mutable state moved to `RuntimeState`. No behavioral
change — every animation, reconnect rule, backoff value, and timing constant is preserved exactly.

New module structure:

| Module | What changed |
|---|---|
| `EventBus` | New. Lightweight pub/sub. All inter-module signals. |
| `RuntimeState` | New. Replaces `var since`, `animating`, `alertQueue`, `connectionState`, `reconnectAttempts` + live config values. |
| `ConnectionManager` | New module boundary. Contains all SSE lifecycle code (was: global functions `connectSSE`, `closeSSE`, `scheduleReconnect`, `getBackoffDelay`, timers). |
| `QueueManager` | New module boundary. Contains alert pipeline (was: global functions `handleAlertBatch`, `processQueue`, `showAlert`). |
| `OverlayStateService` | Unchanged behavior; now emits `EVENTS.OVERLAY_UPDATED` after applying config. |
| `DebugPanel` | Subscribes to `EventBus` events for automatic updates (was: required every module to call `DebugPanel.set()` explicitly). |
| `AudioManager` | Unchanged. |
| `PollFallback` | Unchanged behavior; now calls `QueueManager.receive()` instead of `handleAlertBatch()`. |

Named `EVENTS` constants replace string magic: `CONNECTION_OPENING`, `CONNECTION_OPENED`,
`CONNECTION_CLOSING`, `CONNECTION_LOST`, `CONNECTION_RECOVERED`, `ALERT_RECEIVED`, `ALERT_QUEUED`,
`ALERT_DISPLAYED`, `ALERT_COMPLETE`, `OVERLAY_UPDATED`, `HEARTBEAT_RECEIVED`, `HEARTBEAT_TIMEOUT`.

**Modified files:**
- `assets/js/overlay.js` — full internal refactor (behavior unchanged, architecture modernized)
- `includes/overlay/class-overlay-controller.php` — SSE header + `$max_run` fixes
- `includes/creators/class-creator-overlay-controller.php` — same SSE fixes
- `ccmerch-alert.php` — version bumped to Beta 1.3.1
- `CLAUDE.md` — updated Browser Source Runtime and Alert Stream Runtime Stability sections

**No database migration required.**

---

### Beta 1.3.2 — Alert History customer name privacy

**Goal:** Allow creators to safely display their Alert History on stream without exposing
customer names. A single toggle in the Alert History tab replaces every character in each
customer first name with `*` (length-preserving) for the duration of the session, or until
the creator disables it. The preference persists across page reloads via `localStorage`.

**Scope:**

This feature is entirely client-side and affects only the Creator Portal Alert History UI.
No stored data, WooCommerce orders, replay behaviour, live alerts, or overlay rendering are
modified in any way.

**New shared component: `CustomerNameFormatter`**

Defined at the top of `assets/js/ccmerch-utils.js` as a module-pattern object. Single
source of truth for all customer name display formatting. API:

| Method | Description |
|---|---|
| `isEnabled(): bool` | Returns current privacy mode state |
| `setEnabled(val)` | Toggles privacy mode and persists to `localStorage` |
| `format(name): string` | Returns the name as-is (mode off) or fully anonymized (mode on) |

Anonymization algorithm: `new Array( name.length + 1 ).join( '*' )` — ES5, no polyfills
required. Every character becomes `*`; the length is preserved exactly.

**DOM contract for name elements:**

Any element that displays a customer name must carry `data-ccmerch-customer-name="<raw value>"`.
The element's `textContent` is the display-safe output from `CustomerNameFormatter.format()`.
`ccmerchApplyAnonymization()` walks all such elements and re-renders them when the toggle fires.

**Toggle lifecycle:**

1. PHP renders the toggle checkbox in `class-creator-portal-renderer.php` as a permanent
   sibling above `#ccmerch-history-container` inside `#ccmerch-portal-section-history`.
   Being outside the container means it is never wiped when JS re-renders history on pagination.
2. `ccmerch-utils.js` initializes `checkbox.checked` from `CustomerNameFormatter.isEnabled()`
   (reads `localStorage`) at script-load time.
3. A delegated `change` listener fires on `[data-ccmerch-anonymize-toggle]`: calls
   `CustomerNameFormatter.setEnabled(cb.checked)` then `ccmerchApplyAnonymization()`.
4. All subsequent history page renders (pagination) call `ccmerchBuildHistoryGroup()` which
   already calls `CustomerNameFormatter.format()` at build time — newly loaded pages are
   always rendered with the current privacy mode applied.

**Modified files:**

- `assets/js/ccmerch-utils.js` — added `CustomerNameFormatter` module; added
  `ccmerchApplyAnonymization()`; added delegated `change` listener for
  `[data-ccmerch-anonymize-toggle]`; updated `ccmerchBuildHistoryGroup()` to store raw name
  in `data-ccmerch-customer-name` and call `CustomerNameFormatter.format()`; added checkbox
  initialization from `localStorage` at script-load time
- `includes/creators/class-creator-portal-renderer.php` — added `.ccmerch-history__toolbar`
  div with `#ccmerch-anonymize-toggle` checkbox above `#ccmerch-history-container`
- `assets/css/creator-portal.css` — added `.ccmerch-history__toolbar` and
  `.ccmerch-history__privacy-toggle` styles; adjusted `.ccmerch-history` top margin
- `ccmerch-alert.php` — version bumped to Beta 1.3.2
- `CLAUDE.md` — added `### Customer Name Privacy` section in Architecture Principles;
  this changelog entry

**Validation checklist:**

| Check | Result |
|---|---|
| Toggle only affects the Creator Portal UI | ✓ Purely client-side; no PHP data modified |
| Original alert data unchanged | ✓ Raw name stored in data attribute; DB never touched |
| Replay uses original stored data | ✓ Replay buttons read from existing AJAX responses; formatter is not applied to replay payload |
| Every character replaced with `*` | ✓ `new Array( name.length + 1 ).join( '*' )` |
| Name length preserved | ✓ Result has same `.length` as input |
| View updates instantly on toggle | ✓ `ccmerchApplyAnonymization()` walks DOM immediately |
| CustomerNameFormatter is reusable | ✓ Defined once in `ccmerch-utils.js`; no other module may duplicate it |
| Preference persists between visits | ✓ `localStorage.setItem( 'ccmerch_anonymize_names', … )` |

**Architecture rule established:**

`CustomerNameFormatter` in `ccmerch-utils.js` is the **only** implementation of customer name
anonymization. Any future module that displays a customer name must call
`CustomerNameFormatter.format()` and store the raw value in `data-ccmerch-customer-name`.

**No database migration required.**

---

### Beta 1.3.3 — Anonymize toggle modernized to CSS flip switch

**Version:** Beta 1.3.2 → Beta 1.3.3.

**Goal:** Replace the plain `<input type="checkbox">` anonymize toggle in the Creator Portal Alert
History toolbar with a Uiverse.io-style CSS flip switch (pill + knob), matching the visual quality
of the rest of the portal UI.

**What changed:**

- `includes/creators/class-creator-portal-renderer.php` — wrapped the `#ccmerch-anonymize-toggle`
  checkbox inside a `<label class="switch">` element followed by `<span class="slider"></span>`;
  moved the "Anonymize customer names" text to a separate sibling `<span class="ccmerch-history__toggle-label">` outside the label.

- `assets/css/creator-portal.css` — replaced the old `.ccmerch-history__privacy-toggle` ruleset
  with the Uiverse.io CSS flip switch implementation scoped under `.ccmerch-history__toolbar`:
  CSS custom properties (`--button-width`, `--button-height`, `--toggle-diameter`,
  `--button-toggle-offset`, `--toggle-shadow-offset`, `--toggle-wider`, `--color-grey`,
  `--color-green`) on `.ccmerch-history__toolbar .switch`; pill via `.slider`; knob via
  `.slider::after`; checked states via adjacent sibling selector
  `.switch input[type="checkbox"]:checked + .slider`; active stretch via `--toggle-wider`.
  All selectors scoped under `.ccmerch-history__toolbar` to prevent conflicts with theme or
  third-party `.switch`/`.slider` rules.

- `ccmerch-alert.php` — version bumped to Beta 1.3.3.

**JS unchanged:** `id="ccmerch-anonymize-toggle"` and `data-ccmerch-anonymize-toggle="1"` are
preserved on the checkbox input. `CustomerNameFormatter`, `ccmerchApplyAnonymization()`, the
delegated `change` listener, and `localStorage` persistence are unaffected.

**Adjacent sibling selector chain (valid after scoping):**

```
.ccmerch-history__toolbar .switch input[type="checkbox"]:checked + .slider
```

`.slider` is the immediate next sibling of `input` inside `.switch`, which is inside
`.ccmerch-history__toolbar` — the descendant chain is satisfied.

**No database migration required.**

---

### Beta 1.3.0 — Creator Portal Alert History and Replay System

**Goal:** Allow each creator to view their own alert history and replay past alerts directly from
the Creator Portal. Full creator isolation is enforced at the SQL layer on every query and
every replay operation. The architecture is designed for future search, filtering, date-range
selection, analytics, and export without requiring any structural changes.

**New files:**
- `includes/engine/class-alert-history-service.php` — `CCMerchAlert\Engine\Alert_History_Service`;
  business-logic layer for reading and paginating a creator's alert history; delegates all DB
  access to `Alert_Event_Repository`; pagination operates at the ORDER level (`PER_PAGE = 10`)
  so one page shows 10 distinct WooCommerce orders regardless of per-order alert count
- `includes/engine/class-replay-service.php` — `CCMerchAlert\Engine\Replay_Service`;
  ownership-validated replay pipeline; `replay_event(event_id, creator_id)` and
  `replay_order(order_id, creator_id)` enforce creator ownership via DB-layer scoped queries;
  `log_denied()` writes a machine-readable audit log line when `WP_DEBUG_LOG` is enabled

**Modified files:**
- `includes/engine/class-alert-event-repository.php` — added 4 new read methods:
  `get_order_groups_for_creator()`, `count_order_groups_for_creator()`,
  `get_events_for_creator_order()`, `get_event_by_id_for_creator()`. All use
  `WHERE creator_id = %d` at the SQL layer — not application-layer post-filtering.
- `includes/creators/class-creator-overlay-controller.php` — added 2 use statements, 4 AJAX
  constants, 2 constructor parameters, 8 hook registrations, and 4 handler methods:
  `handle_history()`, `handle_history_order()`, `handle_replay_event()`, `handle_replay_order()`.
  All endpoints use creator access token auth (same model as all other portal endpoints); both
  `wp_ajax_nopriv_*` and `wp_ajax_*` hooks registered for each.
- `includes/creators/class-creator-portal-renderer.php` — added tab navigation bar with
  "Overlay Configuration" and "Alert History" tabs; wrapped existing config content in
  `#ccmerch-portal-section-config` div; added `#ccmerch-portal-section-history` div with
  `#ccmerch-history-container` data-attribute container; history section starts hidden and
  is lazy-loaded on first tab activation
- `includes/class-plugin.php` — added 2 use statements, 2 require_once calls,
  instantiates `Alert_History_Service` and `Replay_Service` before
  `Creator_Overlay_Controller`; updated `Creator_Overlay_Controller` constructor to accept
  the two new services
- `assets/js/ccmerch-utils.js` — added `ccmerchSwitchPortalTab()` and delegated click
  listener for `[data-ccmerch-portal-tab]`; added `ccmerchLoadHistory()`,
  `ccmerchRenderHistoryPage()`, `ccmerchBuildHistoryGroup()`, `ccmerchToggleHistoryGroup()`,
  `ccmerchRenderHistoryEvents()`, `ccmerchBuildHistoryPagination()`,
  `ccmerchSendReplayRequest()`, and delegated click listener for `[data-ccmerch-replay-action]`
- `assets/css/creator-portal.css` — added `.ccmerch-portal__tabs`, `.ccmerch-portal__tab`,
  `.ccmerch-portal__section`, `.ccmerch-portal__section--hidden`,
  `.ccmerch-history` family of styles; responsive override for narrow viewports
- `ccmerch-alert.php` — version bumped to `'Beta 1.3.0'`

**Creator Isolation Model (Alert History):**

Creator isolation is enforced at the SQL layer on every history and replay operation. There is
no application-layer post-filtering — the database itself enforces the boundary.

| Query | Isolation mechanism |
|---|---|
| `get_order_groups_for_creator()` | `WHERE creator_id = %d` |
| `count_order_groups_for_creator()` | `COUNT(DISTINCT order_id) WHERE creator_id = %d` |
| `get_events_for_creator_order()` | `WHERE creator_id = %d AND order_id = %d` |
| `get_event_by_id_for_creator()` | `WHERE id = %d AND creator_id = %d LIMIT 1` |

**Cross-creator WooCommerce order isolation:**

When a single WooCommerce order contains products from Creator A and Creator B, each creator's
history shows only their own items. Replaying the order as Creator A queues only Creator A's
`Alert_Event` rows; Creator B's rows are unreachable at the query level, not filtered
in application code. Creator B's items are never visible in Creator A's history under any
circumstances.

**Replay pipeline (identical to test/replay from previous versions):**

```
Creator clicks "Replay Event" / "Replay Order" button
    │  ccmerch-utils.js: ccmerchSendReplayRequest(btn)
    │  POST admin-ajax.php → handle_replay_event() or handle_replay_order()
    │  Auth: creator_access_token + hash_equals()
    │
    ▼ Replay_Service::replay_event(event_id, creator_id)
    │   OR Replay_Service::replay_order(order_id, creator_id)
    │  get_event_by_id_for_creator(event_id, creator_id) → null? log + return false
    │  get_events_for_creator_order(creator_id, order_id) → empty? log + return false
    │  Overlay_Renderer::render_event(event) for each event
    │
    ▼ set_transient('ccmerch_creator_overlay_pending_' + overlay_id, queue, 300)
    │
    ▼ SSE loop detects transient (within 100 ms — Beta 1.2.0 check interval)
    │
    ▼ overlay.js handleAlertBatch() → alertQueue.push() → processQueue()
    │
    ▼ showAlert() → animation + audio (creator's configured sound file)
```

**Security model:**

| Threat | Mitigation |
|---|---|
| Creator reads another creator's history | `WHERE creator_id = %d` in all history queries |
| Creator replays another creator's event | `WHERE id = %d AND creator_id = %d` returns null; `log_denied()` fires |
| Creator replays another creator's order items | `WHERE creator_id = %d AND order_id = %d` returns only their own events |
| Unauthenticated access | creator_access_token validated + `hash_equals()` before any query |
| WP-logged-in admin visiting portal | `wp_ajax_*` hook registered alongside `wp_ajax_nopriv_*`; both use token auth |
| Unauthorized replay attempt audit | `Replay_Service::log_denied()` writes to `error_log()` when `WP_DEBUG_LOG` is enabled |

**Security audit log format (WP_DEBUG_LOG only):**

```
[CCMerch Alert] Replay denied — creator_id=N type=event|order target_id=N reason=SLUG timestamp=N
```

Reason slugs: `event_not_found_or_not_owned`, `no_events_found_for_creator_order`.
No PII, no raw SQL, no WooCommerce data in the log line.

**Alert History UI data flow:**

```
Creator clicks "Alert History" tab
    │  ccmerch-utils.js: ccmerchSwitchPortalTab('history')
    │  First activation → ccmerchLoadHistory(1)
    │
    ▼ POST admin-ajax.php → handle_history()
    │  Auth: creator_access_token
    │  Alert_History_Service::get_order_groups(creator_id, page)
    │  Returns: { groups[], total_orders, page, per_page, total_pages }
    │  Each group: { order_id, order_number, customer_first_name, alert_count,
    │                created_at, latest_created_at, created_at_formatted }
    │
    ▼ ccmerchRenderHistoryPage(container, data)
    │  Builds table with one row per order group
    │  Each row: order #, customer, alert count, date, "Replay Order" + "Details" buttons
    │
    ▼ Creator clicks "Details" on a row
    │  ccmerchToggleHistoryGroup(container, groupEl)
    │  First expand → POST admin-ajax.php → handle_history_order()
    │     Auth: creator_access_token
    │     Alert_History_Service::get_events_for_order(creator_id, order_id)
    │     Returns: { events: [{id, product_name, variation_id, quantity,
    │                          category_names, image_html, created_at_formatted}] }
    │  ccmerchRenderHistoryEvents() → per-alert items with "Replay" button
    │  Result cached in DOM (data-loaded="1" prevents re-fetch on collapse/expand)
```

**AJAX endpoints (all nopriv + priv, creator-token authenticated):**

| Constant | Action | Description |
|---|---|---|
| `AJAX_HISTORY` | `ccmerch_creator_alert_history` | Paginated order groups |
| `AJAX_HISTORY_ORDER` | `ccmerch_creator_history_order` | Per-order event detail (lazy) |
| `AJAX_REPLAY_EVENT` | `ccmerch_creator_replay_event` | Replay single alert event |
| `AJAX_REPLAY_ORDER` | `ccmerch_creator_replay_order` | Replay all events for an order |

**Tab architecture:**

Two tabs are registered in the portal header: "Overlay Configuration" (default, always visible)
and "Alert History" (hidden on load). JS toggles `ccmerch-portal__tab--active` on tabs and
`ccmerch-portal__section--hidden` on sections. History data is lazy-loaded on the first tab
activation via `data-history-loaded` attribute guard — the history AJAX call never fires
unless the creator explicitly opens the history tab.

**Architecture rules established by this version:**

- `Alert_History_Service` is the **only** class that aggregates alert history for pagination.
  No other class may query `ccmerch_alert_events` grouped by `order_id`.
- `Replay_Service` is the **only** class that performs alert replay. No other class may write
  a replay queue to the creator overlay transient directly — all replay must go through
  `Replay_Service::replay_event()` or `Replay_Service::replay_order()`.
- All history and replay queries **must** include `WHERE creator_id = %d`. Application-layer
  post-filtering as a substitute is not permitted.
- `ccmerchSendReplayRequest(btn)` in `ccmerch-utils.js` is the **only** JS function that
  sends replay requests. No per-page IIFE may re-implement this logic.
- `Replay_Service::log_denied()` is the **only** security audit trail for denied replay
  attempts. It fires on every access denial; no silent failures are permitted.

**Future compatibility (architecture prepared, not implemented):**

- Date-range filtering: `get_order_groups_for_creator()` accepts `LIMIT/OFFSET` — a `WHERE
  created_at BETWEEN %s AND %s` clause can be added without changing the calling API.
- Full-text search: `WHERE product_name LIKE %s` in `get_events_for_creator_order()`.
- Analytics: `COUNT(*) GROUP BY DATE(created_at)` queries on the same table.
- CSV export: a new `export_events_for_creator()` method on the repository with no LIMIT.
- None of these require structural changes to existing methods or classes.

**No database migration required.** All history and replay operations read from the existing
`{prefix}ccmerch_alert_events` table written by `Alert_Router` since Beta 3.0.0. No new tables,
no schema changes, no data migration.

---

### Beta 1.3.4 — Customer Username Checkout Field

**Goal:** Allow customers to enter their streaming or community username during WooCommerce checkout.
The value is saved as WooCommerce order meta and exposed as the `$UserName` template variable in all
overlay types (global, creator, replay). The feature is disabled by default and opt-in via admin settings.

**New files:**
- `includes/checkout/class-customer-username-field.php` — `CCMerchAlert\Checkout\Customer_Username_Field`;
  registers the optional WooCommerce checkout field when `Settings::FIELD_USERNAME_ENABLED` is truthy;
  saves the submitted value (or `'Anonymous'` when blank) as `_ccmerch_username` order meta
- `includes/checkout/class-customer-username-resolver.php` — `CCMerchAlert\Checkout\Customer_Username_Resolver`;
  static utility; `register_on_engine(Overlay_Template_Engine)` registers `$UserName` on any engine instance;
  resolver reads `_ccmerch_username` from order meta and returns `'Anonymous'` when absent

**Modified files:**
- `includes/class-settings.php` — added `SECTION_CHECKOUT`, `FIELD_USERNAME_ENABLED`, `FIELD_USERNAME_LABEL`
  constants; new "Checkout" section with "Enable Customer Username Field" checkbox and "Checkout Field Label"
  text input; added to `register()`, `defaults()`, and `sanitize()`
- `includes/class-plugin.php` — added `use`/`require_once` for both checkout classes; extracted three inline
  `new Overlay_Template_Engine()` calls to named variables (`$overlay_engine`, `$replay_engine`,
  `$creator_overlay_engine`) so `Customer_Username_Resolver::register_on_engine()` can be called on each;
  instantiates `new Customer_Username_Field($this->settings)` unconditionally (constructor is a no-op when disabled)
- `assets/js/layout-editor.js` — added `$UserName` to `substituteTemplate()` canvas preview substitution
  (uses `p.first_name || 'StreamUser'` as placeholder value); added `$UserName` to the variable hint
  `<p>` tag in the Properties panel template fields
- `ccmerch-alert.php` — version bumped to `'Beta 1.3.4'`
- `CLAUDE.md` — added `CCMerchAlert\Checkout` namespace rule; added `includes/checkout/` folder entry;
  added `Customer_Username_Field` and `Customer_Username_Resolver` class descriptions; added
  "Customer Username Checkout Field" Architecture Principles section; updated version constant table

**Admin settings (new "Checkout" section on plugin Settings page):**

| Field | Constant | Type | Default | Notes |
|---|---|---|---|---|
| Enable Customer Username Field | `FIELD_USERNAME_ENABLED` | checkbox | `0` (off) | Master switch |
| Checkout Field Label | `FIELD_USERNAME_LABEL` | text | `'Stream Username'` | Label shown to customer at checkout |

**WooCommerce checkout behaviour:**

When enabled, an optional text field appears in the "Order notes" section (priority 110, `form-row-wide`).
The label is the configured `FIELD_USERNAME_LABEL`. Placeholder: "Your streaming username (optional)".
On order submission, `save_field()` reads, trims, and sanitizes the value:
- Non-empty → stored as-is.
- Empty → `'Anonymous'` stored, ensuring `$UserName` always resolves to a displayable string.

Meta key: `_ccmerch_username` (underscore-prefixed = protected, not shown in WC order UI by default).

**`$UserName` template variable:**

Available in all overlay types immediately. Independent of `$FirstName` — both can appear in the same
template. Resolution order:
1. `get_post_meta($entry->order_id, '_ccmerch_username', true)` — returns stored value.
2. Fallback `'Anonymous'` when meta is absent (orders before the feature was enabled, or feature disabled).

**Three engine registrations (in `Plugin::load_components()`):**

| Engine | Used by |
|---|---|
| `$overlay_engine` | Global overlay (`Overlay_Controller`) |
| `$replay_engine` | `Replay_Service` (creator alert history replay) |
| `$creator_overlay_engine` | `Creator_Overlay_Controller` (creator overlays + test alerts) |

**Backwards compatibility:**

- When the feature is disabled, `Customer_Username_Field` registers no hooks — WooCommerce checkout
  is completely unaffected.
- `$UserName` is registered on all engine instances regardless of the enabled state. Orders without
  the meta → resolver returns `'Anonymous'`. Templates using `$UserName` on pre-feature orders work
  correctly without any data migration.
- The meta key `_ccmerch_username` is new; no existing data uses it.

**No database migration required.** The username is stored as standard WordPress post meta via
`update_post_meta()`. No new tables were added.

---

### Beta 1.5.0 — Admin Translation Management System

**Goal:** Provide a single admin page where all hardcoded UI strings can be viewed, edited per
language, and reset to their defaults. Replace hardcoded strings in checkout fields, Creator Portal
tabs, history UI, and Save button with calls to a shared translation service. Multi-language-ready
from day one; only overrides are stored.

**New files:**
- `includes/translations/class-string-registry.php` — `CCMerchAlert\Translations\String_Registry`;
  static boot-time registry; stores `{key, default_text, context}` per key; no DB reads
- `includes/translations/class-translation-store.php` — `CCMerchAlert\Translations\Translation_Store`;
  lazy-loading persistent store; `OPTION_KEY = 'ccmerch_translations'`; in-process request cache;
  `get()`, `set()`, `delete()`, `load()`, `invalidate()`
- `includes/translations/class-translation-service.php` — `CCMerchAlert\Translations\Translation_Service`;
  resolution façade; `t(key)`, `save()`, `reset()`, `all_for_admin()`, `to_js_array()`;
  save normalises storage: if value equals default or is empty, deletes the override
- `includes/translations/class-string-definitions.php` — `CCMerchAlert\Translations\String_Definitions`;
  single source of all 21 registered string keys; `register_all()` called at top of
  `Plugin::load_components()`
- `includes/admin/class-translations-admin.php` — `CCMerchAlert\Admin\Translations_Admin`;
  CCMerch Alert → Translations admin submenu page; table with search + context filter;
  inline AJAX save/reset via data-attribute pattern; `enqueue_assets()` calls
  `Asset_Loader::enqueue_translations_admin()` + `wp_localize_script('ccmerch-utils', 'ccmerchTranslations', [...])`

**Modified files:**
- `ccmerch-alert.php` — version bumped to `'Beta 1.5.0'`; added `require_once` for the three
  translation core classes before `plugins_loaded`; defined global `ccmerch_translate()` helper
- `includes/ui/class-asset-loader.php` — added `enqueue_translations_admin()` preset
- `includes/class-plugin.php` — added `use`/`require_once` for `Translations_Admin` and
  `String_Definitions`; `String_Definitions::register_all()` at top of `load_components()`;
  `new Translations_Admin()` inside `is_admin()` block
- `includes/checkout/class-customer-username-field.php` — replaced `__()` calls with
  `ccmerch_translate('checkout.username.label')` and `ccmerch_translate('checkout.username.placeholder')`
- `includes/creators/class-creator-portal-renderer.php` — added `use Translation_Service`;
  added `<script>var ccmerchStrings = ...;</script>` inline block before `</head>`;
  replaced tab button / save button text with `ccmerch_translate()` calls
- `assets/js/ccmerch-utils.js` — replaced hardcoded history strings (column headers, alert
  count singular/plural, Details/Hide/Replay) with `window.ccmerchStrings` lookups with
  hard-coded fallbacks; added `ccmerchSaveTranslation()`, `ccmerchResetTranslation()`,
  `ccmerchFilterTranslations()` functions and their delegated click/input/change listeners
- `assets/css/admin.css` — added Translation Manager block (`.ccmerch-trans-toolbar`,
  `.ccmerch-trans-table`, `.ccmerch-trans-col-*`, `.ccmerch-trans-context-badge`,
  `.ccmerch-trans-row--overridden`, `.ccmerch-trans-input`, `.ccmerch-trans-feedback`)
- `uninstall.php` — added `delete_option('ccmerch_translations')`

**Registered string keys (21 total):**

| Key | Default | Context |
|---|---|---|
| `checkout.username.label` | `Stream Username` | checkout |
| `checkout.username.placeholder` | `Your streaming username (optional)` | checkout |
| `portal.tab.config` | `Overlay Configuration` | portal |
| `portal.tab.history` | `Alert History` | portal |
| `portal.save.button` | `Save Settings` | portal |
| `portal.changelog.button` | `Changelog` | portal |
| `portal.source.heading` | `Browser Source URL` | portal |
| `portal.source.generate.button` | `Generate New Overlay ID` | portal |
| `history.col.order` | `Order` | history |
| `history.col.customer` | `Customer` | history |
| `history.col.alerts` | `Alerts` | history |
| `history.col.date` | `Date` | history |
| `history.col.actions` | `Actions` | history |
| `history.row.details` | `Details` | history |
| `history.row.hide` | `Hide` | history |
| `history.row.replay_order` | `Replay Order` | history |
| `history.row.alert_singular` | `alert` | history |
| `history.row.alert_plural` | `alerts` | history |
| `history.event.replay` | `Replay` | history |
| `history.empty` | `No alerts yet.` | history |
| `overlay.anonymous` | `Anonymous` | overlay |

**Architecture:**

```
ccmerch-alert.php (plugins_loaded, before init):
    require_once String_Registry, Translation_Store, Translation_Service
    define ccmerch_translate( key ) → Translation_Service::t( key )

Plugin::load_components() (plugins_loaded):
    String_Definitions::register_all() ← registers 21 keys into String_Registry

Request rendering (checkout, portal, admin):
    ccmerch_translate('key')
        → Translation_Service::t('key')
            → Translation_Store::get('key', 'en')  [lazy-loads ccmerch_translations option]
            → overridden? return override
            → String_Registry::default_for('key')  [no DB]
            → key itself (fallback)

Admin save (AJAX):
    Translation_Service::save(key, value, lang)
        → value == default or empty? Translation_Store::delete(key, lang) [clean storage]
        → else Translation_Store::set(key, value, lang)
        → Translation_Store::invalidate() [clears in-process cache]

Portal JS:
    ccmerchStrings = Translation_Service::to_js_array([...keys]) [PHP → JS inline script]
    JS: (window.ccmerchStrings && window.ccmerchStrings['key']) || 'Default'
```

**Storage model:**

Only overrides are stored. The `ccmerch_translations` wp_options value is a nested array
`{ en: { 'key' => 'override_value' } }`. When a value is reset or saved as equal to its
default, the key is deleted from the stored array — keeping storage compact and making
"is this string overridden?" a simple key-existence check.

**JS strings (Creator Portal):**

The Creator Portal is a standalone HTML document with no `wp_head`. JS strings for the history
UI are injected via an inline `<script>var ccmerchStrings = ...;</script>` block before `</head>`,
populated by `Translation_Service::to_js_array()`. Admin pages receive strings via
`wp_localize_script('ccmerch-utils', 'ccmerchTranslations', [...])` — this is the AJAX config
object for the Translation Manager page, not the same as `ccmerchStrings`.

**Architecture rules established by this version:**

- `ccmerch_translate('key')` is the **only** way to output any translatable UI string in PHP.
  Hardcoded `__()` or `esc_html_e()` calls for strings that appear in the Translation Manager
  are not permitted.
- `String_Definitions::register_all()` is the **single source of truth** for all registered
  string keys. No other file may call `String_Registry::register()`.
- `Translation_Store` is the **only** place that reads from or writes to `ccmerch_translations`
  in wp_options. No other class may call `get_option('ccmerch_translations')` directly.
- `Translation_Service::to_js_array()` is the **only** way to convert translation overrides
  to the JS `ccmerchStrings` object format. No inline PHP JSON-encoding of raw DB values
  is permitted.
- `ccmerchSaveTranslation()` and `ccmerchResetTranslation()` in `ccmerch-utils.js` are the
  **only** JS handlers for translation admin interactions. No per-page IIFE may re-implement
  this logic.
- Translation admin buttons must carry `data-ccmerch-translation-save="1"` or
  `data-ccmerch-translation-reset="1"` with `data-trans-key`. The delegated click listeners
  in `ccmerch-utils.js` handle all interactions automatically.

**Future language support:**

To add a second language (e.g. German):
1. `Translation_Service::$lang` would be resolved dynamically (from `get_user_locale()` or a
   portal preference setting) instead of the hardcoded `'en'`.
2. The admin Translation Manager page would show a language selector.
3. The portal `ccmerchStrings` injection would use the detected language.
4. No schema migration required — storage is already `{ lang: { key: value } }`.

**No database migration required.** Translations are stored in a single `ccmerch_translations`
wp_options key created on first save. The key does not exist until an override is saved.

---

### Beta 1.6.0 — Google Fonts Management System

**Goal:** Allow creators and admins to use Google Fonts in their overlay designs. Admins sync and
curate a font catalog from the Google Fonts API. Creators see only approved fonts in a drop-down
selector. The Browser Source loads only the selected font (not the full library). All font data
flows through the local database — the Creator Portal never communicates with Google directly.

**New files:**
- `includes/fonts/class-google-fonts-installer.php` — `CCMerchAlert\Fonts\Google_Fonts_Installer`;
  `activate()` + `maybe_install()` pattern; creates `{prefix}ccmerch_google_fonts` table
- `includes/fonts/class-google-fonts-provider.php` — `CCMerchAlert\Fonts\Google_Fonts_Provider`;
  `sync(api_key)` calls Google Fonts API v1 and upserts rows preserving `approved` flag;
  `search()`, `get_all()`, `get_count()`, `get_last_synced()`, `set_approved()`
- `includes/fonts/class-font-library.php` — `CCMerchAlert\Fonts\Font_Library`;
  constructor `(Google_Fonts_Provider)`; `get_approved(): string[]` (internal PHP use),
  `get_approved_with_meta(): array` (returns `[{family, category}]` for JS injection),
  `is_approved(family): bool` (returns true for `'inherit'` and `''`), `get_count()`, `set_approved()`
- `includes/ui/components/class-font-loader-component.php` — `CCMerchAlert\UI\Components\Font_Loader_Component`;
  static-only class; `WEIGHTS`, `BASE_URL`; `build_url(family): string` — CSS2 URL or `''`;
  `build_link_tag(family): string` — preconnect + link tag HTML, or `''`
- `includes/admin/class-fonts-admin.php` — `CCMerchAlert\Admin\Fonts_Admin`;
  `PAGE = 'ccmerch-alert-fonts'`; `AJAX_SYNC`, `AJAX_TOGGLE`, `NONCE_ACTION`;
  constructor `(Google_Fonts_Provider, Font_Library, Settings)`;
  admin submenu page with stats bar, sync button, search filter, approve toggles

**Modified files:**
- `includes/class-settings.php` — added `SECTION_FONTS`, `FIELD_GOOGLE_FONTS_API_KEY`; registered
  new "Google Fonts" section and API key field on the plugin Settings page
- `includes/ui/class-asset-loader.php` — added `enqueue_fonts_admin()` static preset
- `includes/overlay/class-overlay-renderer.php` — added `Font_Loader_Component::build_link_tag()`
  injection before overlay CSS in `render_page()`; added `fontFamily` and `fontUrl` to
  `ccmerchOverlay` JS config; added `'font_url'` key in `get_config_payload()` for live SSE config sync
- `includes/overlay/interface-overlay-settings-contract.php` — (unchanged — `FIELD_FONT_FAMILY`
  was already present in settings defaults)
- `includes/admin/class-overlay-admin.php` — added `Font_Library` constructor param; added
  `wp_localize_script('ccmerch-layout-editor', 'ccmerchApprovedFonts', ...)` in `enqueue_assets()`
- `includes/creators/class-creator-portal-renderer.php` — added `Font_Library` constructor param;
  added `<script>var ccmerchApprovedFonts = ...;</script>` in `<head>`; added disabled-font notice
  when the saved font is no longer in the approved list
- `includes/class-plugin.php` — added `use`/`require_once`/properties/instantiation for
  `Google_Fonts_Provider`, `Font_Library`, `Font_Loader_Component`, `Fonts_Admin`; passed
  `Font_Library` to `Creator_Portal_Renderer` and `Overlay_Admin` constructors
- `ccmerch-alert.php` — version bumped to `'Beta 1.6.0'`; added installer require + activation hook +
  `maybe_install()` call
- `uninstall.php` — added `DROP TABLE IF EXISTS {prefix}ccmerch_google_fonts` + `delete_option`
- `assets/js/overlay.js` — added `FontLoader` IIFE module (prevents duplicate `<link>` injection via
  `_loaded` set); `OverlayStateService.applyConfig()` calls `FontLoader.loadUrl(data.font_url)`;
  bootstrap init calls `FontLoader.loadUrl(config.fontUrl)`
- `assets/js/layout-editor.js` — added `fontSelectInput(field, currentVal)` helper that reads
  `window.ccmerchApprovedFonts`; replaced `textInput('font_family', ...)` in `buildElementProps()`
  with `fontSelectInput('font_family', ...)`
- `assets/css/admin.css` — added Font Library admin page styles (`.ccmerch-fonts-*`, `.ccmerch-font-*`,
  `.ccmerch-toggle-track`, `.ccmerch-col-*`)

**New database table (`{prefix}ccmerch_google_fonts`):**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| family | VARCHAR(255) | UNIQUE KEY — Google Fonts family name |
| category | VARCHAR(64) | serif / sans-serif / display / handwriting / monospace |
| variants | TEXT | JSON array of variant strings, e.g. `["regular","700","italic"]` |
| subsets | TEXT | JSON array of subset strings, e.g. `["latin","latin-ext"]` |
| approved | TINYINT(1) DEFAULT 0 | Admin-controlled; 0 = not shown to creators |
| last_synced_at | DATETIME | UTC — set by every `sync()` call |

**Architecture rules established by this version:**

- `Font_Loader_Component` is the **only** place that builds Google Fonts CSS2 URLs. No other
  class may construct a `fonts.googleapis.com/css2` URL.
- `Font_Library::get_approved()` and `Font_Library::get_approved_with_meta()` are the **only**
  sources for the creator-visible font list. `get_approved()` returns `string[]` for internal
  PHP use. `get_approved_with_meta()` returns `{family, category}[]` for JS injection
  (`ccmerchApprovedFonts`). No other class may query `approved = 1` fonts from the DB directly.
- `Font_Library::is_approved()` returns `true` for `'inherit'` and `''` unconditionally —
  "browser default" is always a valid selection regardless of the approved list.
- The Creator Portal must never call the Google Fonts API. All font data comes from
  `Font_Library`, which reads from the locally-cached DB table.
- `fontSelectInput()` in `layout-editor.js` is the **only** font selector in the Properties
  panel. No per-page JS may duplicate this select-building logic.
- `ccmerchApprovedFonts` is the JS global for the approved font array. It is injected:
  - Admin overlay page: via `wp_localize_script('ccmerch-layout-editor', 'ccmerchApprovedFonts', ...)`
  - Creator Portal: via inline `<script>var ccmerchApprovedFonts = ...;</script>` in `<head>`
- `FontLoader` in `overlay.js` is the **only** mechanism for injecting Google Fonts links into
  the Browser Source. No other code may call `document.head.appendChild` for font links.
- `OverlayStateService.applyConfig()` calls `FontLoader.loadUrl(data.font_url)` when a live
  config event arrives — font changes in settings are reflected in OBS without a page reload.
- The `_loaded` set in `FontLoader` prevents duplicate `<link>` injection when the same font
  URL arrives in multiple consecutive config events.
- `ON DUPLICATE KEY UPDATE` in `Google_Fonts_Provider::sync()` preserves the `approved` flag
  for fonts already in the catalog — re-syncing never resets admin approvals.
- `CCMerchFonts.FontPreview` in `ccmerch-fonts.js` is the **only** implementation of lazy
  font-preview loading for admin UI. No other JS may inject Google Fonts `<link>` tags in admin
  pages — call `FontPreview.loadFamily()` or `FontPreview.observe()` instead.
- `CCMerchFonts.FontSelector` in `ccmerch-fonts.js` is the **only** implementation of the
  font-picker dropdown widget. No admin page or portal page may implement a custom font
  picker — call `FontSelector.initAll(container)` after building the Properties panel HTML.
- `ccmerchApprovedFonts` is always `{family: string, category: string}[]` when injected from
  PHP via `get_approved_with_meta()`. Both `FontSelector.getFonts()` and the `fontSelectInput()`
  fallback in `layout-editor.js` normalise both formats for backward compatibility.
- `data-font-preview="FAMILY"` on any DOM element causes `FontPreview.observeAll()` to register
  an IntersectionObserver on that element automatically at DOMContentLoaded. No per-page JS
  is required to enable lazy preview loading.

**Security model:**

| Concern | Implementation |
|---|---|
| API key storage | Stored in `ccmerch_alert_settings` under `FIELD_GOOGLE_FONTS_API_KEY`; never exposed to the browser |
| Sync endpoint | `wp_ajax_*` only (admin-only); nonce + `manage_options` cap |
| Toggle endpoint | `wp_ajax_*` only; nonce + `manage_options` cap |
| Creator font list | Read-only from DB; creators cannot write to the approved list |
| Font URLs in overlay | Only the selected family name is stored; URL built at render time by `Font_Loader_Component` |

**No database migration required for existing installations.** `Google_Fonts_Installer::maybe_install()`
runs on every request (cheap `get_option()` guard) and creates the table if absent. The table starts
empty; an admin must sync the catalog before fonts are available.

---

### Beta 2.0.0 — Twitch Chatbot Notification System

**Goal:** Deliver real-time WooCommerce order notifications to each creator's Twitch channel via a
centralized bot account. A new admin "Chatbot" page manages the bot connection and maps creators to
channels. Creators configure their own message template and enable/disable the feature from a new
"Chatbot" tab in the Creator Portal.

**New files:**
- `includes/chatbot/class-chatbot-installer.php` — `CCMerchAlert\Chatbot\Chatbot_Installer`; creates
  `{prefix}ccmerch_creator_chatbot` table; `activate()` + `maybe_install()` pattern
- `includes/chatbot/class-chatbot-creator-settings.php` — `CCMerchAlert\Chatbot\Chatbot_Creator_Settings`;
  immutable value object: `id`, `creator_id`, `twitch_channel`, `enabled`, `template`
- `includes/chatbot/class-chatbot-repository.php` — `CCMerchAlert\Chatbot\Chatbot_Repository`; all SQL
  for `ccmerch_creator_chatbot`; `find_by_creator_id()`, `save()` (upsert), `set_channel()` (upsert),
  `get_all()`
- `includes/chatbot/class-twitch-auth-service.php` — `CCMerchAlert\Chatbot\Twitch_Auth_Service`;
  Twitch OAuth 2.0 authorization code flow; AES-256-CBC encrypted token storage in `wp_options`;
  `get_tokens()` returns decrypted; `store_tokens()` stores encrypted; OAuth state in transients
- `includes/chatbot/class-twitch-chat-service.php` — `CCMerchAlert\Chatbot\Twitch_Chat_Service`;
  sends messages via Helix Chat Messages API; broadcaster ID cached in transients (TTL 3600 s);
  retry-once on 401 via `$retry = false` parameter after successful token refresh
- `includes/chatbot/class-chatbot-settings-service.php` — `CCMerchAlert\Chatbot\Chatbot_Settings_Service`;
  business-logic façade; `DEFAULT_TEMPLATE = '🔔 $Name just bought $Product!'`; `is_connected()`,
  `get_settings()` (backfills default template), `save_creator_settings()`, `set_creator_channel()`,
  `get_channel()`, `is_enabled()`, `get_all_settings()`, `get_template()`
- `includes/chatbot/class-chatbot-message-service.php` — `CCMerchAlert\Chatbot\Chatbot_Message_Service`;
  hooks WC `woocommerce_order_status_processing` + `woocommerce_order_status_completed` at priority 20;
  resolves creator IDs via `Alert_Event_Repository::get_creator_ids_for_order()`; fully non-blocking
  (`try/catch \Throwable`); variables: `$Name`, `$UserName`, `$Product`, `$Amount`
- `includes/chatbot/class-chatbot-portal-controller.php` — `CCMerchAlert\Chatbot\Chatbot_Portal_Controller`;
  `AJAX_SAVE = 'ccmerch_creator_chatbot_save'`, `AJAX_PREVIEW = 'ccmerch_creator_chatbot_preview'`;
  registers `wp_ajax_nopriv_*` AND `wp_ajax_*` for both; auth via `Auth_Context::resolve_creator()`
- `includes/admin/class-chatbot-admin.php` — `CCMerchAlert\Admin\Chatbot_Admin`; `PAGE = 'ccmerch-alert-chatbot'`;
  three sections: Bot Connection (OAuth flow + status badge), Creator Channel Mapping (per-creator channel
  input table), Queue Monitoring (coming-soon placeholder); all mutations via `admin_post_` + `check_admin_referer()`

**Modified files:**
- `includes/engine/class-alert-event-repository.php` — added `get_creator_ids_for_order(int): array`
  (DISTINCT creator_id WHERE order_id = %d); used by Chatbot_Message_Service to route notifications
  without any WooCommerce or Order_Repository dependency
- `includes/ui/class-asset-loader.php` — added `enqueue_chatbot_admin()` static preset
  (admin CSS + ccmerch-utils)
- `includes/class-plugin.php` — added 7 use statements, 1 class property (`$twitch_auth_service`),
  9 require_once calls; chatbot instantiation block in `load_components()` before Creator_Portal_Renderer;
  updated Creator_Portal_Renderer constructor to accept `$chatbot_settings_service`; added `Chatbot_Admin`
  instantiation inside `is_admin()` block
- `includes/creators/class-creator-portal-renderer.php` — added `Chatbot_Settings_Service` as 3rd
  constructor param; added "Chatbot" tab button; added full chatbot section with enable toggle, template
  textarea, Save button, Preview button, feedback/preview output divs
- `assets/js/ccmerch-utils.js` — added `ccmerchChatbotSave()`, `ccmerchChatbotPreview()`, delegated
  submit listener for `[data-ccmerch-chatbot-save]`, delegated click listener for
  `[data-ccmerch-chatbot-preview]`
- `assets/css/creator-portal.css` — added chatbot preview output styles (light + dark theme)
- `assets/css/admin.css` — added chatbot admin page styles: `.ccmerch-chatbot-status` connection badge,
  `.ccmerch-chatbot-channel-table` mapping table, `.ccmerch-chatbot-queue-placeholder` coming-soon box
- `ccmerch-alert.php` — version bumped to `'Beta 2.0.0'`; added `require_once` for
  `Chatbot_Installer`; added `register_activation_hook` for `Chatbot_Installer::activate()`;
  added `Chatbot_Installer::maybe_install()` in `plugins_loaded` callback
- `uninstall.php` — added `DROP TABLE IF EXISTS {prefix}ccmerch_creator_chatbot` + three
  `delete_option` calls for `ccmerch_chatbot_db_version`, `ccmerch_chatbot_twitch_tokens`,
  `ccmerch_chatbot_twitch_app`

**New database table (`{prefix}ccmerch_creator_chatbot`):**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| creator_id | BIGINT UNSIGNED | UNIQUE KEY — one chatbot row per creator |
| twitch_channel | VARCHAR(100) DEFAULT '' | Twitch login name (lowercase) |
| enabled | TINYINT(1) DEFAULT 0 | Creator-controlled enable switch |
| template | TEXT | Message template; empty = `DEFAULT_TEMPLATE` |
| created_at | DATETIME | UTC |
| updated_at | DATETIME | UTC |

**Security model:**

| Concern | Implementation |
|---|---|
| Twitch Client ID / Secret | Stored in `ccmerch_chatbot_twitch_app` wp_option; never sent to browser |
| Access / refresh tokens | AES-256-CBC encrypted; key = `\substr(\hash('sha256', wp_salt('auth'), true), 0, 32)`; stored as `base64_encode(IV \|\| ciphertext)` |
| OAuth state parameter | Stored as WordPress transient (600 s TTL); verified via `\hash_equals()` on callback |
| Admin save endpoints | `check_admin_referer()` + `current_user_can('manage_options')` |
| Creator save endpoint | `Auth_Context::resolve_creator()` (creator access token, 256-bit) |
| Non-blocking delivery | `try { ... } catch ( \Throwable $e ) { \error_log(...) }` — chat failures never affect checkout |

**Message variable resolution:**

| Variable | Source |
|---|---|
| `$Name` | `WC_Order::get_billing_first_name()` |
| `$UserName` | `get_post_meta($order_id, '_ccmerch_username', true)` → `'Anonymous'` if empty |
| `$Product` | First WC line item product name |
| `$Amount` | `WC_Order::get_formatted_order_total()` stripped of HTML tags |

**Routing data flow:**

```
WooCommerce order reaches "processing" or "completed" status (priority 10)
    │
    ▼ Order_Logger hooks fire → Alert_Events written with creator_id per item
    │
    ▼ Chatbot_Message_Service (priority 20)
    │  get_creator_ids_for_order(order_id)
    │    SELECT DISTINCT creator_id FROM ccmerch_alert_events WHERE order_id = N
    │
    ▼ For each creator_id:
    │  chatbot_settings_service->is_enabled(creator_id) — skip if disabled
    │  chatbot_settings_service->get_channel(creator_id) — skip if empty
    │  render_message(order, creator_id) — $Name/$UserName/$Product/$Amount substitution
    │
    ▼ Twitch_Chat_Service::send_message(channel, message)
    │  Resolve broadcaster ID (transient cache, TTL 3600 s)
    │  POST /helix/chat/messages { broadcaster_id, sender_id, message }
    │  On 401: refresh_tokens() → retry once ($retry=false)
    │
    ▼ Message delivered (or silently logged on failure)
```

**Admin Bot Connection flow:**

```
Admin enters Client ID + Client Secret → "Save & Connect with Twitch"
    │  admin_post_ handler → stores app credentials → redirects to Twitch OAuth URL
    │  scope: chat:read chat:edit
    │
    ▼ Twitch redirects to admin-ajax.php?action=ccmerch_twitch_oauth_callback
    │  Validates state transient (hash_equals)
    │  Exchanges code for access_token + refresh_token
    │  Encrypts and stores tokens
    │
    ▼ Redirects to admin.php?page=ccmerch-alert-chatbot&ccmerch_notice=chatbot_connected
    │  Status badge shows "Connected"
    │
    ▼ Disconnect button → admin_post_ handler → deletes encrypted tokens option
       Status badge shows "Disconnected"
```

**Architecture rules established by this version:**

- `Twitch_Auth_Service` is the **only** place that stores, retrieves, or refreshes Twitch OAuth tokens.
  No other class may call `get_option('ccmerch_chatbot_twitch_tokens')` directly.
- `Twitch_Chat_Service::send_message()` is the **only** place that calls the Twitch Helix chat API.
  No other class may construct a request to `api.twitch.tv`.
- `Chatbot_Message_Service` is the **only** class that hooks WooCommerce order status events for
  chatbot notifications. No other class may register these hooks for this purpose.
- All chatbot delivery code is wrapped in `try { ... } catch ( \Throwable $e )`. The chatbot must
  never throw an exception that could propagate to WooCommerce checkout.
- `Chatbot_Portal_Controller` registers BOTH `wp_ajax_nopriv_*` AND `wp_ajax_*` for all portal
  endpoints — the standard dual-hook pattern for all creator portal operations.
- Creator chatbot save operations use `admin-ajax.php` via `Chatbot_Portal_Controller`. The
  `admin-post.php` pathway is prohibited for creator portal operations.
- Twitch credentials (Client ID / Secret) must never appear in any HTTP response body or JS config.
- The `ccmerchChatbotSave()` and `ccmerchChatbotPreview()` functions in `ccmerch-utils.js` are the
  **only** JS handlers for chatbot portal interactions. No per-page IIFE may re-implement this logic.

**Known limitations (not bugs):**
- The Queue Monitoring section is a placeholder. Future implementation will display a real-time
  feed of pending and recently delivered chatbot messages.
- Twitch broadcaster ID lookup uses a transient cache keyed by channel name. If a creator changes
  their Twitch login name, the stale cache entry (TTL 3600 s) causes a period where message delivery
  fails silently until the transient expires and the correct ID is re-fetched.
- The Creator Portal Chatbot tab shows a "contact your admin" notice when the bot is not connected.
  Creators cannot initiate the bot connection themselves — it is an admin-only operation.

**No database migration required for existing installations.** `Chatbot_Installer::maybe_install()`
runs on every request and creates the table if absent.

---

### Beta 2.0.1 — Persistent Chatbot Runtime, Creator Channel Authorization, and Admin Diagnostics

**Version:** Beta 2.0.0 → Beta 2.0.1.

**Goal:** Elevate the Twitch Chatbot from a fire-and-forget delivery mechanism into a persistent,
production-ready platform. Add background token maintenance via WP-Cron, a dedicated OAuth flow for
creators to authorize the bot in their own Twitch channel, a health monitoring subsystem, and expanded
admin diagnostics. The delivery gate ensures messages are never sent to channels where the broadcaster
has not explicitly authorized the bot.

**New files:**
- `includes/chatbot/class-chatbot-health-monitor.php` — `CCMerchAlert\Chatbot\Chatbot_Health_Monitor`;
  `OPTION_KEY = 'ccmerch_chatbot_health'`; in-process cache; read API: `get_bot_status()`,
  `get_last_refresh_at()`, `last_refresh_ok()`, `get_total_sent()`, `get_total_failed()`,
  `get_channel_stats()`, `get_channel_stat()`; write API: `set_bot_status()`, `record_token_refresh()`,
  `record_message_sent()`, `record_message_failed()`, `invalidate()`
- `includes/chatbot/class-chatbot-token-manager.php` — `CCMerchAlert\Chatbot\Chatbot_Token_Manager`;
  `TRANSIENT_STATUS = 'ccmerch_chatbot_token_status'` (TTL 3000 s); `is_token_valid(): bool` (transient
  cache-first); `run_maintenance(): bool` — invalidates transient, validates via Twitch, proactively
  refreshes if `expires_in < 3600`, updates health monitor
- `includes/chatbot/class-chatbot-runtime.php` — `CCMerchAlert\Chatbot\Chatbot_Runtime`;
  `HOOK_TOKEN_CHECK = 'ccmerch_chatbot_runtime_token_check'`; `init()` registers cron action and calls
  `maybe_schedule()` (hourly if not already scheduled); `static deactivate()` registered as plugin
  deactivation hook to clear the cron event; `run_token_check()` cron callback wrapped in
  `try/catch \Throwable`
- `includes/chatbot/class-creator-channel-auth.php` — `CCMerchAlert\Chatbot\Creator_Channel_Auth`;
  immutable value object: `id`, `creator_id`, `channel_login`, `scope`, `authorized_at`,
  `updated_at`; `is_authorized(): bool` (true when `channel_login` non-empty)
- `includes/chatbot/class-creator-channel-auth-repository.php` — `CCMerchAlert\Chatbot\Creator_Channel_Auth_Repository`;
  all SQL for `{prefix}ccmerch_creator_channel_auth`; AES-256-CBC token encryption (key from
  `wp_salt('auth')`, IV prepended); `save()` upsert, `update_tokens()`, `delete_by_creator_id()`,
  `find_by_creator_id()`, `get_tokens()`, `get_all()`
- `includes/chatbot/class-creator-channel-auth-service.php` — `CCMerchAlert\Chatbot\Creator_Channel_Auth_Service`;
  manages creator-side Twitch OAuth flow for `channel:bot` scope; `AJAX_GET_AUTH_URL`,
  `AJAX_CALLBACK`, `AJAX_REVOKE` constants; registers nopriv+priv hooks for all three;
  `handle_get_auth_url()` generates state transient + returns OAuth URL;
  `handle_callback()` exchanges code, fetches broadcaster identity, stores tokens, redirects back
  to portal with `?ccmerch_notice=channel_auth_success` or `channel_auth_failed`;
  `handle_revoke()` deletes the creator's auth record

**Modified files:**
- `includes/chatbot/class-chatbot-installer.php` — `DB_VERSION` bumped to `'2.0'`; `create_tables()`
  now creates `ccmerch_creator_chatbot` AND `ccmerch_creator_channel_auth` in one call; updated
  `activate()` and `maybe_install()` to use the new version key
- `includes/chatbot/class-twitch-chat-service.php` — added `?Chatbot_Health_Monitor $health_monitor`
  as second constructor param (nullable, default null); `send_message()` calls
  `$health_monitor->record_message_sent(channel)` on success and
  `$health_monitor->record_message_failed(channel)` on any failure path;
  added `public resolve_broadcaster_id(string $channel): ?string` for diagnostic use;
  added `DIAGNOSTICS_OPTION = 'ccmerch_chatbot_diagnostics'` constant for per-channel diagnostic storage
- `includes/chatbot/class-chatbot-message-service.php` — added `?Creator_Channel_Auth_Service $channel_auth`
  as fourth constructor param (nullable, default null); in `send_for_creator()`: if `$this->channel_auth`
  is set and `$this->channel_auth->is_authorized($creator_id)` returns false, logs the skip
  (WP_DEBUG_LOG) and returns without sending — the delivery gate
- `includes/admin/class-chatbot-admin.php` — added three nullable constructor params:
  `?Chatbot_Health_Monitor`, `?Chatbot_Token_Manager`, `?Creator_Channel_Auth_Service`; added
  `render_runtime_status_card()` private method: reads health monitor data, token validity
  (cached), and WP-Cron next-run timestamp; added `render_channel_auth_card(array $creators)`
  private method: reads all auth records via `$channel_auth_service->get_all()`, renders a
  table per creator showing Auth Status badge, authorized Twitch channel, and authorization timestamp
- `includes/creators/class-creator-portal-renderer.php` — added `Creator_Channel_Auth_Service` as
  4th constructor parameter; `render_page()` fetches `$channel_auth` via
  `$this->channel_auth_service->get_authorization($creator->id)`; renders Channel Authorization
  section inside the Chatbot tab: shows `is_authorized()` status badge, authorized channel name (when
  present), "Authorize Bot" button (data-attribute AJAX → `handle_get_auth_url()`), and "Revoke"
  button (data-attribute AJAX → `handle_revoke()`); added notices for `channel_auth_success` and
  `channel_auth_failed` query params in `render_notices()`
- `assets/js/ccmerch-utils.js` — added `ccmerchGetChannelAuthUrl(btn)`: POSTs to
  `AJAX_GET_AUTH_URL`, receives `{url}`, redirects browser to Twitch OAuth consent page;
  added `ccmerchRevokeChannelAuth(btn)`: POSTs to `AJAX_REVOKE`, on success updates the
  authorization section DOM to show unauthorized state; added two delegated `click` listeners
  for `[data-ccmerch-channel-auth-url="1"]` and `[data-ccmerch-channel-auth-revoke="1"]`
- `includes/class-plugin.php` — added 5 use statements, 6 require_once calls, and the following
  instantiation additions in `load_components()`:
  `$health_monitor = new Chatbot_Health_Monitor()`;
  `$twitch_chat_service = new Twitch_Chat_Service($twitch_auth_service, $health_monitor)`;
  `$token_manager = new Chatbot_Token_Manager($twitch_auth_service, $health_monitor)`;
  `$chatbot_runtime = new Chatbot_Runtime($token_manager); $chatbot_runtime->init()`;
  `$channel_auth_repository = new Creator_Channel_Auth_Repository()`;
  `$channel_auth_service = new Creator_Channel_Auth_Service($channel_auth_repository, $twitch_auth_service, $creator_service); $channel_auth_service->register()`;
  `$channel_auth_service` injected into `Creator_Portal_Renderer` constructor;
  `$health_monitor`, `$token_manager`, `$channel_auth_service` injected into `Chatbot_Admin` constructor
- `ccmerch-alert.php` — `CCMERCH_ALERT_VERSION` bumped to `'Beta 2.0.1'`; added early
  `require_once` for `class-chatbot-runtime.php` (before `plugins_loaded`) so the static
  `deactivate()` method is available for `register_deactivation_hook()`; added
  `register_deactivation_hook( __FILE__, [ Chatbot_Runtime::class, 'deactivate' ] )`
- `uninstall.php` — added `DROP TABLE IF EXISTS {prefix}ccmerch_creator_channel_auth`,
  `delete_option('ccmerch_chatbot_health')`, `delete_option('ccmerch_chatbot_scopes')`,
  `wp_clear_scheduled_hook('ccmerch_chatbot_runtime_token_check')`

**New database table (`{prefix}ccmerch_creator_channel_auth`):**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| creator_id | BIGINT UNSIGNED | UNIQUE KEY — one auth record per creator |
| channel_login | VARCHAR(100) NOT NULL DEFAULT '' | Twitch broadcaster login name (lowercase) |
| access_token | TEXT | AES-256-CBC encrypted |
| refresh_token | TEXT | AES-256-CBC encrypted |
| created_at | DATETIME | UTC |
| updated_at | DATETIME | UTC |

**Two-OAuth-flow architecture:**

| Flow | Who completes it | Class | What it grants |
|---|---|---|---|
| Bot account OAuth | Site admin | `Twitch_Auth_Service` | Bot can authenticate; grants `user:write:chat` + `user:bot` |
| Creator channel OAuth | Each creator individually | `Creator_Channel_Auth_Service` | Bot can join the creator's channel; grants `channel:bot` to the broadcaster |

The bot account OAuth (`user:write:chat` + `user:bot`) must be completed before any messages are sent.
The creator channel OAuth (`channel:bot`) is available for creators who wish to authorize the bot
in their channel but is **not** required for delivery — the admin's channel assignment is the consent
mechanism. See Beta 2.0.1 patch 2 for the gate removal rationale.

**Delivery gate data flow:**

```
WooCommerce order reaches "processing" or "completed" status (priority 20)
    │
    ▼ Chatbot_Message_Service::send_for_creator(order, creator_id)
    │  1. chatbot_settings_service->is_enabled(creator_id) — skip if disabled
    │  2. chatbot_settings_service->is_connected() — skip if bot not connected
    │  3. render_message(order, creator_id) — $Name/$UserName/$Product/$Amount
    │
    ▼ Twitch_Chat_Service::send_message(channel, message)
    │  On success: health_monitor->record_message_sent(channel)
    │  On failure: health_monitor->record_message_failed(channel)
```

**Background token maintenance (WP-Cron):**

```
Plugin activates → Chatbot_Runtime::init() → wp_schedule_event('hourly', HOOK_TOKEN_CHECK)
    │
    ▼ Every hour: run_token_check() callback fires
    │  Chatbot_Token_Manager::run_maintenance()
    │    1. delete_transient(TRANSIENT_STATUS) — force fresh check
    │    2. POST /helix/validate → check expiry
    │    3. If expires_in < 3600: refresh_tokens() proactively
    │    4. Set transient: 'valid' or 'invalid' (TTL 3000 s)
    │    5. health_monitor->record_token_refresh(success)
    │    6. health_monitor->set_bot_status(STATUS_CONNECTED or STATUS_DISCONNECTED)
    │
    ▼ Hot-path check in is_token_valid():
    │  get_transient(TRANSIENT_STATUS) → 'valid'? return true immediately (no Twitch call)
    │  transient absent? → validate_and_cache() (one live check, then cached)

Plugin deactivates → Chatbot_Runtime::deactivate() → wp_clear_scheduled_hook(HOOK_TOKEN_CHECK)
```

**Creator channel authorization flow:**

```
Creator clicks "Authorize Bot" button in Creator Portal → Chatbot tab
    │  ccmerch-utils.js: ccmerchGetChannelAuthUrl(btn)
    │  POST admin-ajax.php → Creator_Channel_Auth_Service::handle_get_auth_url()
    │    Auth: creator_access_token (standard portal auth)
    │    Generates random state: bin2hex(random_bytes(16))
    │    Stores transient: ccmerch_cca_state_{state} = {creator_id, portal_url} (TTL 600 s)
    │    Returns JSON: { url: 'https://id.twitch.tv/oauth2/authorize?...' }
    │  JS redirects browser to returned URL
    │
    ▼ Twitch redirects to:
    │  admin-ajax.php?action=ccmerch_twitch_channel_auth_callback&code=...&state=...
    │  Creator_Channel_Auth_Service::handle_callback()
    │    Validates state transient via hash_equals()
    │    Exchanges code for access_token + refresh_token
    │    GET /helix/users → fetches broadcaster user_id and login
    │    Creator_Channel_Auth_Repository::save(creator_id, user_id, login, access_token, refresh_token)
    │      — tokens encrypted with AES-256-CBC before storage
    │    Redirects → portal_url?ccmerch_notice=channel_auth_success
    │
    ▼ Creator Portal reloads; notice displayed; "Authorized" badge shown

Creator clicks "Revoke" → ccmerch-utils.js: ccmerchRevokeChannelAuth(btn)
    │  POST admin-ajax.php → Creator_Channel_Auth_Service::handle_revoke()
    │    Auth: creator_access_token
    │    Creator_Channel_Auth_Repository::delete_by_creator_id(creator_id)
    │    Returns JSON success
    │  JS updates DOM in-place: badge → "Not Authorized", shows Authorize button
```

**Health monitor storage model:**

Stored as a single wp_option under `OPTION_KEY = 'ccmerch_chatbot_health'`. Structure:

```json
{
  "bot_status": "connected",
  "last_refresh_at": 1751000000,
  "last_refresh_ok": true,
  "total_sent": 47,
  "total_failed": 2,
  "channels": {
    "creatorlogin1": { "sent": 30, "failed": 1 },
    "creatorlogin2": { "sent": 17, "failed": 1 }
  }
}
```

All write operations go through the public `record_*` / `set_*` methods which invalidate the
in-process cache. Reads within the same PHP process use the cache. Across requests, data is always
read fresh from `wp_options`.

**Security model:**

| Concern | Implementation |
|---|---|
| Creator OAuth tokens | AES-256-CBC encrypted; key = `\substr(\hash('sha256', wp_salt('auth'), true), 0, 32)`; stored as `base64(IV \|\| ciphertext)` |
| Creator OAuth state | Stored as transient `ccmerch_cca_state_{state}` (TTL 600 s); verified via `\hash_equals()` |
| Creator callback auth | State transient carries `creator_id`; no `Auth_Context` token check (creator is not authenticated at callback time — it is a Twitch redirect) |
| Delivery gate | `is_authorized()` checked before every `send_message()` call |
| Revoke auth | `Auth_Context::resolve_creator()` on every revoke request |
| Auth URL auth | `Auth_Context::resolve_creator()` on every auth URL generation request |
| Health data | Stored in wp_options; accessible to admin only; never sent to browser |
| Admin diagnostics | Bot status / channel stats shown on admin Chatbot page only; never exposed to creators |

**New wp_options keys:**

| Key | Owner | Contents |
|---|---|---|
| `ccmerch_chatbot_health` | `Chatbot_Health_Monitor` | Aggregate send/fail counters, token refresh history, bot status |
| `ccmerch_chatbot_token_status` (transient) | `Chatbot_Token_Manager` | `'valid'` or `'invalid'`; cached 3000 s |
| `ccmerch_cca_state_{state}` (transient) | `Creator_Channel_Auth_Service` | `{creator_id, portal_url}`; TTL 600 s per auth attempt |

**Architecture rules established by this version:**

- `Chatbot_Health_Monitor` is the **only** place that writes to `ccmerch_chatbot_health`. No other
  class may call `update_option('ccmerch_chatbot_health', ...)` directly.
- `Chatbot_Token_Manager::run_maintenance()` is the **only** function that proactively validates and
  refreshes the bot account token. All background maintenance flows through this method.
- `Chatbot_Runtime` is the **only** class that registers or clears the `ccmerch_chatbot_runtime_token_check`
  WP-Cron event. No other class may call `wp_schedule_event()` or `wp_clear_scheduled_hook()` for
  this hook.
- `Creator_Channel_Auth_Repository` is the **only** place that stores or retrieves creator OAuth
  tokens for channel authorization. All token storage must use the class's encrypt/decrypt methods.
  No other class may call `wp_options` or database functions with raw channel auth tokens.
- `Creator_Channel_Auth_Service` manages the optional `channel:bot` OAuth flow for creators. It
  is NOT a delivery gate — `Chatbot_Message_Service` does not consult it before sending. The admin's
  channel assignment is the consent mechanism.
- The creator channel OAuth callback (`handle_callback()`) does NOT authenticate the request via
  `Auth_Context::resolve_creator()` — the creator is not logged in when Twitch redirects back. The
  state transient (verified via `hash_equals()`) provides the authentication guarantee for the callback.
- All chatbot operations remain wrapped in `try { ... } catch ( \Throwable $e )`. Neither channel
  authorization failures nor health monitor writes may ever propagate exceptions to WooCommerce or
  WordPress core flows.
- `ccmerchGetChannelAuthUrl()` and `ccmerchRevokeChannelAuth()` in `ccmerch-utils.js` are the **only**
  JS functions for creator channel authorization interactions. No per-page IIFE may re-implement this logic.
- `Chatbot_Runtime::deactivate()` **must** remain a static method so `register_deactivation_hook()` can
  reference the class without requiring an instance.

**New database migration (`ccmerch_creator_channel_auth` table):**

`Chatbot_Installer::DB_VERSION` bumped from `'1.0'` to `'2.0'`. `maybe_install()` triggers
`dbDelta()` on the next request after deployment. `dbDelta()` creates the new
`ccmerch_creator_channel_auth` table non-destructively on existing installations. No existing rows
in `ccmerch_creator_chatbot` are modified.

---

### Beta 2.0.1 patch — Chatbot Transport Layer Refactor and IRC Code Removal

**Goal:** Remove dead IRC implementation code, introduce a transport abstraction layer so
`Chatbot_Message_Service` is decoupled from the concrete Helix API client, and surface the
active transport in admin diagnostics.

**Context:** Four files implementing a persistent TCP IRC chatbot daemon were present in the
codebase but were never loaded by `class-plugin.php` — they were completely dead code with no
runtime impact. The production delivery mechanism has always been the Twitch Helix Chat Messages
REST API. The refactor formalises this through a `ChatTransportInterface` and removes the dead
files to eliminate maintenance confusion.

**Deleted files (dead code — never loaded by WordPress):**
- `includes/chatbot/class-chatbot-irc-client.php` — 467-line TCP IRC client using
  `stream_socket_client`, `PASS oauth:`, `NICK`, `JOIN`, `PRIVMSG`, `PING`/`PONG`
- `includes/chatbot/class-chatbot-queue-repository.php` — SQL message queue for IRC delivery
- `includes/chatbot/class-chatbot-daemon-manager.php` — read-only monitor for the IRC daemon;
  referenced `CCMERCH_ALERT_DIR . '/bin/ccmerch-chatbot-daemon.php'`
- `bin/ccmerch-chatbot-daemon.php` — CLI daemon entry point; never loaded by WordPress

**New files:**
- `includes/chatbot/interface-chat-transport.php` — `CCMerchAlert\Chatbot\ChatTransportInterface`;
  `send(channel, message): bool` + `get_transport_label(): string`; the contract that all chat
  delivery implementations must satisfy
- `includes/chatbot/class-helix-chat-transport.php` — `CCMerchAlert\Chatbot\HelixChatTransport`;
  implements `ChatTransportInterface`; thin adapter that delegates to
  `Twitch_Chat_Service::send_message()`; `get_transport_label()` returns `'Helix Chat API'`;
  sole production transport

**Modified files:**
- `includes/chatbot/class-chatbot-message-service.php` — constructor second parameter changed
  from `Twitch_Chat_Service $twitch_chat_service` to `ChatTransportInterface $transport`;
  send call changed from `$this->twitch_chat_service->send_message()` to
  `$this->transport->send()`; all other behaviour unchanged
- `includes/class-plugin.php` — added `use CCMerchAlert\Chatbot\HelixChatTransport`;
  added `require_once` for `interface-chat-transport.php` and `class-helix-chat-transport.php`
  (before `class-chatbot-message-service.php` in load order); instantiation changed to
  `$helix_transport = new HelixChatTransport($twitch_chat_service)` then
  `new Chatbot_Message_Service($chatbot_settings_service, $helix_transport, ...)`
- `includes/admin/class-chatbot-admin.php` — added Transport row as first `<tr>` inside the
  valid-token diagnostics table: `Helix Chat API (POST /helix/chat/messages)`; hardcoded because
  IRC has been deleted and `HelixChatTransport` is the only transport that can exist

**Architecture rules established by this patch:**

- `ChatTransportInterface` is the **only** type that `Chatbot_Message_Service` may accept for
  message delivery. No other concrete transport class may be injected directly.
- `HelixChatTransport` is the **only** production transport. It must not be bypassed.
- `Chatbot_Admin` retains `Twitch_Chat_Service` as a concrete dependency for diagnostic methods
  (`resolve_broadcaster_id()`, broadcaster-ID cache management). This is separate from the
  delivery path and correct: diagnostics and delivery are different concerns.
- `get_transport_label()` is for human-readable admin display only. It must never be used for
  routing decisions or conditional logic.
- No IRC, `fsockopen`, `stream_socket_client`, `PASS oauth:`, `PRIVMSG`, or any persistent TCP
  socket connection may ever be added to this plugin. The Helix REST API is the sole and
  permanent delivery mechanism.

**No database migration required.** No schema changes. No new tables. The deleted files had no
database tables of their own — the IRC queue repository's table was never created because the
installer was never called.

---

### Beta 2.0.1 patch 2 — Remove channel:bot delivery gate from Chatbot_Message_Service

**Root cause investigated:** Twitch chatbot messages were arriving in channels where the creator
had not completed the `channel:bot` OAuth flow and the bot was not a moderator. Investigation
traced two delivery paths: live WooCommerce orders (`Chatbot_Message_Service::send_for_creator()`,
gated) and the Creator Portal test-send button (`Chatbot_Portal_Controller::handle_test_send()`,
ungated — calls `Twitch_Chat_Service::send_message()` directly with no auth check). The
inconsistency revealed the gate's design assumption was incorrect: Twitch's Helix Chat Messages
API only requires `user:write:chat` on the bot account; `channel:bot` from the broadcaster is NOT
enforced by Twitch for standard channels. The gate created friction without providing meaningful
protection.

**Decision:** Remove the `channel:bot` authorization gate entirely from `Chatbot_Message_Service`.
The admin's channel assignment is the consent mechanism. `Creator_Channel_Auth_Service` and the
creator OAuth UI remain in place — creators can still authorize the bot in their channel if they
wish, and the admin Channel Authorization Overview still displays auth status — but delivery is
no longer gated on it.

**Modified files:**
- `includes/chatbot/class-chatbot-message-service.php` — removed `?Creator_Channel_Auth_Service $channel_auth`
  constructor parameter and the gate block from `send_for_creator()`. Constructor now takes exactly
  three parameters: `Chatbot_Settings_Service`, `ChatTransportInterface`, `Alert_Event_Repository`.
- `includes/class-plugin.php` — removed `$channel_auth_service` as the 4th argument from
  `new Chatbot_Message_Service(...)`.

**Architecture rules updated by this patch:**

- `Chatbot_Message_Service` constructor accepts exactly three parameters:
  `(Chatbot_Settings_Service, ChatTransportInterface, Alert_Event_Repository)`. No auth service
  parameter exists or should be added.
- `Creator_Channel_Auth_Service::is_authorized()` is **not** a delivery gate. It is only used
  for displaying authorization status in admin and portal UI.
- Delivery consent is expressed by the admin assigning a Twitch channel to a creator via
  `Chatbot_Settings_Service::set_creator_channel()`. No secondary OAuth confirmation is required.
- `Chatbot_Portal_Controller::handle_test_send()` and `Chatbot_Message_Service::send_for_creator()`
  now use identical gate logic: enabled check → bot connected check → send.

---

### Beta 2.0.2 — Chatbot: one message per product, live orders and replay

**Goal:** A WooCommerce order with multiple line items must produce one **separate** chatbot
message per product — never a single message combining several products. This must hold for both
live orders and for the Creator Portal's Alert History replay feature, using the same underlying
send logic so the two flows cannot diverge.

**Root cause of the original one-product bug:**

`Alert_Router::process_order()` already writes one `Alert_Event` row per line item (see
`### 3.0.0 — Order Item Routing Engine`), and `Alert_Event_Repository::get_events_for_creator_order()`
already returns every one of those rows for a given creator + order. `Chatbot_Message_Service::send_for_creator()`
called that method correctly but then read only `$events[0]` — the first line item — discarding every
other `Alert_Event` row for the order. Routing, storage, and retrieval were already correct; only the
message-building step was wrong.

**Design decision — one message per event, not a combined summary:**

An earlier revision of this fix combined every product into a single message
(`"Product A, Product B x2"`). That approach was replaced: the product requirement is one message
per product, so `Chatbot_Message_Service` now loops over every matched `Alert_Event` and calls
`transport->send()` once per event. `$Product` is always a single product name — the exact same
value and format the original single-product code path always produced — so single-product orders
are unchanged and multi-product orders now produce N independent messages instead of one merged one.

**Modified files:**
- `includes/chatbot/class-chatbot-message-service.php` — added public
  `send_for_events( int $creator_id, \WC_Order $order, array $events ): void`, the single shared
  implementation of "one message per line item": resolves `$Name`/`$UserName`/`$Amount` once from
  `$order`, then loops `$events` and calls `render_message()` + `transport->send()` once per event.
  `send_for_creator()` (used by the live-order hook) now just resolves the creator's events and
  delegates to `send_for_events()`. The multi-item summary helper (`build_product_summary()`) from
  the previous revision was removed — no longer needed now that every event gets its own message.
- `includes/creators/class-creator-overlay-controller.php` — added
  `use CCMerchAlert\Chatbot\Chatbot_Message_Service;`; added `Chatbot_Message_Service` as a new
  constructor parameter; added private `dispatch_replay_chatbot_messages( int $creator_id, array $events ): void`,
  called from both `handle_replay_event()` (after a successful `Replay_Service::replay_event()`, using
  `Alert_Event_Repository::get_event_by_id_for_creator()` to re-fetch the single replayed event) and
  `handle_replay_order()` (after a successful `Replay_Service::replay_order()`, using the existing
  `Alert_History_Service::get_events_for_order()` pass-through already used elsewhere in this class).
  The helper fetches the order via `wc_get_order( $events[0]->order_id )` and calls
  `Chatbot_Message_Service::send_for_events()` — the identical method the live-order pipeline uses —
  wrapped in `try { ... } catch ( \Throwable $e )` so a chatbot failure can never turn an
  already-successful overlay replay into an error response.
- `includes/class-plugin.php` — captured the previously-discarded `new Chatbot_Message_Service(...)`
  call into `$chatbot_message_service`; passed it as the 7th constructor argument to
  `new Creator_Overlay_Controller(...)`.

**Why replay needed its own dispatch call (not a change inside `Replay_Service`):**

`Replay_Service`'s docblock states it must never rebuild replay payloads from WooCommerce at replay
time (stored snapshots only) — that rule governs the *overlay/visual* replay payload specifically.
Chatbot dispatch is an independent side effect with its own existing WooCommerce dependency (the
live-order path already calls `wc_get_order()` for `$Name`/`$Amount`), so it is triggered from
`Creator_Overlay_Controller` — the orchestration layer that already coordinates the overlay replay
call — rather than folded into `Replay_Service`, keeping that class's single responsibility (visual
overlay replay) intact.

**Example:** an order with Product A × 1, Product B × 2, Product C × 1 now sends three separate
Twitch chat messages — one for Product A, one for Product B, one for Product C — for both a live
order and a replay of that same historical order.

**Architecture rules established by this version:**

- `Chatbot_Message_Service::send_for_events()` is the **only** implementation that turns a
  creator's `Alert_Event[]` into chatbot messages. It always sends one message per event. No other
  code path may re-introduce a combined-message or `$events[0]`-only shortcut.
- Both the live-order pipeline (`handle_order()` → `send_for_creator()`) and the replay pipeline
  (`Creator_Overlay_Controller::handle_replay_event()` / `handle_replay_order()`) must call
  `send_for_events()` — never duplicate its enabled/channel/template logic elsewhere.
- Chatbot dispatch triggered from replay must remain best-effort (try/catch) — it must never cause
  a successful overlay replay to be reported as a failure to the creator.

**No database migration required.** No schema changes; `Alert_Event` rows were already written and
read correctly per line item — only the chatbot message assembly/dispatch logic changed.

---

### Beta 2.0.2 patch — Chatbot message log (legacy-order replay backfill)

**Goal:** Give replay an explicit, persistent way to distinguish "this line item already had a
chatbot message logged" from "this line item never had one" — most relevantly for orders placed
before the one-message-per-product fix above, where the old bug meant only the first line item of
a multi-product order was ever actually announced to Twitch. Replaying such an order must log +
send a message for every product that never had one, while never creating a second logged record
for a product that already has one — and must still resend (replay) every product's message every
time, whether or not it was already logged.

**Investigation finding — no prior persistence existed to be "incomplete":** `Chatbot_Message_Service`
never stored chatbot message text or a "sent" flag anywhere before this patch; every message was
built in memory and handed straight to `ChatTransportInterface::send()`. The record that durably
ties a WooCommerce order line item to "this creator's alert data for it" is `Alert_Event`
(`ccmerch_alert_events`, keyed by `order_item_id`), written by `Alert_Router` on a completely
separate, independently-registered WordPress hook callback from `Chatbot_Message_Service`'s. The
old one-message-per-product bug lived only inside the chatbot *sending* step and never affected
what `Alert_Router` wrote — so `Alert_Event` rows for every line item of every order have always
been complete, even for orders affected by the old chatbot bug. This patch adds a genuinely new
table specifically to track "has a message been logged for this creator + line item," which did
not previously exist in any form.

**New files:**
- `includes/chatbot/class-chatbot-message-log-installer.php` — `CCMerchAlert\Chatbot\Chatbot_Message_Log_Installer`;
  `activate()` + `maybe_install()` pattern; creates `{prefix}ccmerch_chatbot_messages`.
- `includes/chatbot/class-chatbot-message-log-repository.php` — `CCMerchAlert\Chatbot\Chatbot_Message_Log_Repository`;
  `exists( creator_id, order_item_id ): bool`, `record( creator_id, order_id, order_item_id, product_name ): bool`.

**New database table (`{prefix}ccmerch_chatbot_messages`):**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| creator_id | BIGINT UNSIGNED | Part of the dedup key |
| order_id | BIGINT UNSIGNED DEFAULT 0 | WooCommerce order ID |
| order_item_id | BIGINT UNSIGNED DEFAULT 0 | WooCommerce order item ID — same identifier `Alert_Event` uses |
| product_name | VARCHAR(255) DEFAULT '' | Product name at the time the message was logged |
| created_at | DATETIME | UTC |

`UNIQUE KEY creator_item (creator_id, order_item_id)` is the durable dedup guard — the same
two-layer idempotency pattern (application check + DB unique key) already established for
`Alert_Event` by `Alert_Router::process_item()` / `Alert_Event_Repository::event_exists()`.

**Modified files:**
- `includes/chatbot/class-chatbot-message-service.php` — added `Chatbot_Message_Log_Repository` as a
  4th constructor parameter; `send_for_events()` now calls `message_log->exists()` before each send
  and `message_log->record()` when it returns false, then always sends regardless of the outcome.
- `includes/class-plugin.php` — added `require_once` for both new files; instantiates
  `Chatbot_Message_Log_Repository` and passes it into `new Chatbot_Message_Service(...)`.
- `ccmerch-alert.php` — added `require_once` + `register_activation_hook()` for
  `Chatbot_Message_Log_Installer::activate()`; added `maybe_install()` call in `plugins_loaded`.
- `uninstall.php` — added `DROP TABLE IF EXISTS {prefix}ccmerch_chatbot_messages` +
  `delete_option( 'ccmerch_chatbot_message_log_db_version' )`.

**Verified scenarios (traced against the code, no test scripts):**

| Scenario | `exists()` per event | `record()` calls | Messages sent |
|---|---|---|---|
| New order, Product A/B/C | false, false, false | 3 (A, B, C) | 3 |
| Legacy order, only A previously logged | true, false, false | 2 (B, C) | 3 (A, B, C) |
| Legacy order, A/B/C all previously logged | true, true, true | 0 | 3 (A, B, C) |
| Legacy order, A/B previously logged, C not | true, true, false | 1 (C) | 3 (A, B, C) |

All four match the required behaviour: replay always sends one message per product currently
recorded in `Alert_Event` for that creator + order, and a log row is created exactly once per
line item regardless of how many times that line item is replayed afterward.

**Architecture rules established by this patch:**

- `Chatbot_Message_Log_Repository` is the **only** class that reads or writes
  `ccmerch_chatbot_messages`. It is consulted **only** from
  `Chatbot_Message_Service::send_for_events()` — no other class may call `record()` or `exists()`.
- Logging a message (`record()`) must never gate whether that message is sent. `send_for_events()`
  always sends every event passed to it; the log only controls whether a new row is written.
- `order_item_id` (the same identifier `Alert_Event` uses) is the sole identifier for matching a
  line item to its logged message — message text is never compared or used as an identifier.

**No changes to `Alert_Event`, `Alert_Router`, or `Replay_Service`.** This patch only adds a new,
independent record of "was a chatbot message logged for this line item," consulted solely inside
`Chatbot_Message_Service`.

---

### Beta 2.0.2 patch 2 — Chatbot replay: per-event isolation + diagnostics

**Investigation:** Re-traced the full Alert History → Replay Order path side-by-side with the
already-working visual overlay replay, specifically looking for a `[0]`-index, an early `return`,
a single-execution loop, a row-limited query, or a duplicate-prevention mechanism blocking events
after the first. Both paths turned out to read from the exact same, unlimited query —
`Replay_Service::replay_order()` and `Creator_Overlay_Controller::handle_replay_order()` both
resolve their event set via `Alert_Event_Repository::get_events_for_creator_order()`
(no `LIMIT`, `ORDER BY created_at ASC, id ASC`) — and `Chatbot_Message_Service::send_for_events()`
already iterated that full set with a plain `foreach`, no `$events[0]`, no early return. Every
existing call site (`handle_replay_order()` → `dispatch_replay_chatbot_messages()` →
`send_for_events()`) was already structurally capable of sending every event in the batch.

**What this means:** no reproducible "first message only" code path exists in this file tree as
of the Beta 2.0.2 patch 1 changes. The one gap found: `send_for_events()`'s per-event work (message
log check/record, template render, `transport->send()`) ran inside a single loop with **no
per-iteration exception isolation** — if event N ever threw for any reason (a transport failure,
an unexpected `\Throwable` from any dependency), the `foreach` would abort at that point and every
event after N would silently never be attempted, which matches the reported symptom exactly. This
patch closes that gap and adds instrumentation so the real cause is directly visible in the debug
log if it recurs, rather than requiring further static tracing.

**Modified files:**
- `includes/chatbot/class-chatbot-message-service.php` — `send_for_events()`: each loop iteration
  is now wrapped in its own `try { ... } catch ( \Throwable $e )` — a failure sending one event logs
  the failure (`WP_DEBUG_LOG`) and continues to the next event; it can no longer abort the batch.
  Added `WP_DEBUG_LOG`-gated logging of the total event count at the start of `send_for_events()`
  and a per-event `event N/total (order_item_id=..., product="...") sent=true|false` line after
  every send attempt.

**Architecture rule added:** every event passed to `send_for_events()` must be attempted
independently of whether any other event in the same batch failed. No future change to this method
may reintroduce a shared-failure path across events in the same `foreach`.

**No database migration required. Version unchanged — Beta 2.0.2.**

---

### Beta 2.0.2 patch 3 — Twitch rate-limit-safe chatbot dispatch

**Root cause:** Confirmed — sending multiple chatbot messages for one order back-to-back with no
spacing at all triggers Twitch's chat rate limit for regular (non-moderator, non-VIP) accounts.
Twitch documents this limit as 20 messages per rolling 30-second window per channel; a bot account
that is a moderator or VIP in the channel gets a much higher limit (100 / 30 s), which is why the
symptom appeared inconsistent — accounts with elevated status in a channel didn't hit the limit,
regular accounts did. No delay or pacing mechanism existed anywhere in the chatbot module before
this patch — confirmed by search (no `sleep`/`usleep`/rate-limit/backoff logic in any chatbot file).

**Where the delay is applied:** a single new constant and a single new pause, both inside
`Chatbot_Message_Service::send_for_events()` — the one shared method both the live-order pipeline
and the Creator Portal replay pipeline already call for every batch of chatbot messages. No changes
were needed in `Creator_Overlay_Controller`'s replay handlers; replay automatically inherits the
new spacing because it calls `send_for_events()` directly.

**The checkout-safety conflict this patch had to resolve:** `Chatbot_Message_Service`'s own
pre-existing contract states the chatbot "must never break or delay WooCommerce checkout flow."
`handle_order()` runs synchronously on the `woocommerce_order_status_processing` /
`woocommerce_order_status_completed` hooks, which can fire within the same request the customer's
browser is waiting on during checkout. Adding 1.5-second pauses directly inside that hook would
violate the existing contract for any multi-product order (e.g. 13.5 s added for a 10-product
order). Resolution: `handle_order()` no longer sends anything inline. It only calls
`wp_schedule_single_event()` once per matched creator — a fast DB write — for the new
`CRON_DISPATCH_HOOK`. The actual paced sending happens in the new `run_scheduled_dispatch()` method,
invoked by WordPress's own non-blocking cron spawn in a separate request, fully decoupled from
checkout. This is the same deferred-execution pattern `Chatbot_Runtime` already uses in this module
for its hourly token check (`add_action( HOOK, callback ) + wp_schedule_event/wp_schedule_single_event`),
reused here rather than inventing a new async mechanism.

**Modified files:**
- `includes/chatbot/class-chatbot-message-service.php`:
  - Added `public const CRON_DISPATCH_HOOK = 'ccmerch_chatbot_dispatch_order'`.
  - Added `public const MESSAGE_SEND_DELAY_MICROSECONDS = 1_500_000` (1.5 s), documented against
    Twitch's 20-messages/30-seconds-per-channel regular-account limit.
  - Constructor now also registers `add_action( self::CRON_DISPATCH_HOOK, [ $this, 'run_scheduled_dispatch' ], 10, 2 )`.
  - `handle_order()` rewritten to only call `wp_schedule_single_event( time(), self::CRON_DISPATCH_HOOK, [ $creator_id, $order_id ] )`
    per matched creator — no longer fetches the order or sends anything itself.
  - Added `public function run_scheduled_dispatch( int $creator_id, int $order_id ): void` — the
    WP-Cron callback that fetches the order and calls `send_for_creator()`, wrapped in the same
    try/catch + `WP_DEBUG_LOG` pattern used throughout this class.
  - `send_for_events()`'s `foreach` now calls `\usleep( self::MESSAGE_SEND_DELAY_MICROSECONDS )`
    after each event except the last, applied whether that event's send succeeded, failed, or threw
    (a failed attempt still counts toward Twitch's rate-limit window).

**Why replay stays synchronous while live orders are deferred:** replay is a Creator Portal action
the creator explicitly triggers and is already waiting on (a "Replay Order" button click) — there is
no checkout to protect, and the messages are expected to be part of that same replay action. Live
orders run on a WooCommerce hook that can be inside the paying customer's request, where any added
delay is exactly what the pre-existing "must never delay checkout" rule forbids. Both paths still
share one implementation of the actual pacing logic (`send_for_events()`); only the trigger differs.

**Legacy orders, duplicate prevention, any number of products:** unchanged from Beta 2.0.2 patch 1 —
`Chatbot_Message_Log_Repository` still backfills missing per-product log rows the moment a
never-before-logged event is sent, `UNIQUE KEY (creator_id, order_item_id)` still prevents duplicate
log rows, and the `foreach` in `send_for_events()` still runs for however many events are in
`$events`, from 1 to any number. The new spacing is applied uniformly regardless of batch size — a
10-product order simply takes proportionally longer (9 × 1.5 s ≈ 13.5 s) to finish sending, which is
the expected, correct behaviour for staying under Twitch's rate limit rather than a bug.

**Architecture rules established by this patch:**

- `Chatbot_Message_Service::MESSAGE_SEND_DELAY_MICROSECONDS` is the **only** pacing value for
  chatbot sends. No caller may bypass `send_for_events()`'s spacing by calling
  `ChatTransportInterface::send()` directly in a loop.
- `handle_order()` must never send a chatbot message inline. It is a schedule-only entry point;
  `run_scheduled_dispatch()` is the only place live-order messages are actually sent.
- `CRON_DISPATCH_HOOK` is a single-event (`wp_schedule_single_event`), not a recurring schedule —
  it requires no deactivation cleanup, unlike `Chatbot_Runtime::HOOK_TOKEN_CHECK`.

**No database migration required. Version unchanged — Beta 2.0.2.**
