# Decisions

Decisions taken while building v0.1, when the brief left a choice or the platform
did something the brief did not expect. The owner was away, so each was decided by
default and written down here. Each entry says what, and why.

## What the platform does that shaped the plugin

These were read from the platform's code (`src/api/v1/business.ts`, `cards.ts`,
`src/app/routes/hooks.ts`, `src/modules/ecommerce/service.ts`) and the published
OpenAPI document, not assumed.

**D1. `issuePass` neither de-duplicates nor replays on an `Idempotency-Key`.**
In the platform, `issuePass` is not declared with `idempotency` or `replay`, so
the header is ignored, and `issuePassIn` inserts a new card on every call (no
look-up by person). The brief asks for "at most once per paid order, with its
idempotency key" and for "an existing card is not a failure". The API gives no
way to learn that a card exists. So the guarantee is the plugin's own (`Issuer`):

1. an **atomic claim** on the order before anything is sent: a per-order options
   row added with `INSERT IGNORE` (`Lock`, the way WordPress core takes its own
   upgrade lock), which affects one row for the one process that inserted it and none
   for any other. `add_option()` is not used: its `ON DUPLICATE KEY UPDATE` can report
   success to both of two racing callers when the values differ. A process that dies
   between the claim and the result leaves the claim behind, and the order is then never
   invited: the safe side;
2. the order's **state meta** (`queued`, `issued`, `failed`, `unknown`, `exists`),
   read before the claim and again after it;
3. the call is **never retried**, whatever the failure (see D12). When the answer
   is unclear (no answer, a 5xx, a 2xx that is not the documented JSON) the state
   becomes `unknown`, final: a possibly-issued card is not asked for again, and the
   order note says to look for the customer in the Rewloy panel;
4. **one e-mail, one card, per shop**: an address that another order of this shop
   already invited (`issued` or `unknown`) is not invited again (`exists`, with a
   note naming that order). Only this plugin's own invitations are known to it:
   a card the customer got elsewhere is invisible to it, because the API cannot say.

The `Idempotency-Key` (`woo-<10 hex of the home URL's sha1>-<order id>`, under the
API's 64 characters) is sent anyway: it is what the brief and the API's conventions
ask, it costs nothing, and it starts to protect the moment the platform honours it.

A *clear refusal* (a 4xx: Rewloy said no, nothing was opened) is `failed`, final
too, but the order screen offers "Rewloy: try opening the card again", which clears
the state and runs once more. It is offered for `failed` only, never for `unknown`.

**D2. The API key's permissions.** The calls need `programs.read` (the card list),
`settings.read` (the link and its orders), `apikeys.manage` (create, pause, delete
a link: "a shop link has an API key's power") and `passes.issue`. The key screen and
the 403 message say so. The standard "Yönetici" role does not include
`apikeys.manage`, so the key must be made with a role that has it.

**D3. KVKK at checkout is a notice, not a consent box.** `issuePass`'s description
says the e-mail needs `kvkkConsent: true`, "the business's declaration that it
presented its own notice to the customer ... do not ask it as a consent box". The
join form shows the controller note ("Veri sorumlusu: ...") as information. So the
checkout box is the *invitation* ("open the card for me"), unticked and never
required, and the notice sits beside it: the join form's own controller note, in the
shop's name, with the link to the notice's card-holder part
(`rewloy.com/gizlilik#kart-sahipleri`). `kvkkConsent: true` states that the notice
was shown, which it was, in the same breath as the box. The controller's name is a
setting (default: the site name); the contact e-mail is optional and never defaults
to the admin address.

**D4. The card link is e-mailed, not stored.** `issuePass` returns `cardUrl`, the
customer's private link carrying the viewing key (`?k=`); "give it to the customer,
do not write it to records". The plugin mails it to the billing address with
`wp_mail` and keeps only the serial (the card's public id) on the order and in its
note. If the mail cannot be sent the note says so; the link is gone and the card is
found in the Rewloy panel. The platform does not mail an API-issued link itself.

**D5. Only the e-mail goes to `issuePass`.** No name, phone or address, though the
API accepts them. Data minimisation: the card works with an e-mail.

**D6. The webhook body is cut down.** WooCommerce's `order.updated` payload carries
names, addresses, phone numbers and line items. `handleOrder` reads only `id`,
`status`, `currency`, `total` and `billing.email`. The plugin hooks
`woocommerce_webhook_payload` and, for its own webhook only (matched by id), sends
exactly those fields (plus `number`). The signature is computed over what is sent.
This is more than the brief asked for and it makes the privacy section true: "nothing
else of the order". It depends on that filter's signature (`$payload, $resource,
$resource_id, $webhook_id`); see "Not verified on real WordPress" below.

## The plugin

**D7. Where the settings live: WooCommerce › Rewloy (a submenu), not a tab of
WooCommerce › Settings.** The screen has independent actions (save the key, connect,
pause, disconnect, forget, reactivate, save options), each with its own nonce and its
own result. A WooCommerce settings tab is one form with one Save button and would
force them into one submit. Capability `manage_woocommerce`; a "Settings" link sits on
the plugin row.

**D8. Name, slug, text domain**: `rewloy-for-woocommerce`, as the brief says.
Namespace `Rewloy\WooCommerce`, a tiny PSR-4 loader (`src/autoload.php`); no Composer
at runtime.

**D9. The key.** Its own option `rewloy_wc_api_key`, written with autoload off; the
settings (no secret) are another option. A `REWLOY_API_KEY` constant wins and then
nothing is stored. A pasted key is saved only after Rewloy accepts it (a harmless
`listPrograms` read), so a typo is caught at once and a refused key is never kept. It
must look like `rwk_...`; a staff session (`rws_`) is refused with a clear message. The
mask is `rwk_` plus the ten characters Rewloy itself lists as the key's public prefix
(API.md), then dots: it identifies the key without showing any of the secret. The key
is in no message, note, log line, exception or `__debugInfo` (a test pins that). The
key cannot be changed while connected (disconnect first), so a link is never left
pointing at a key that cannot manage it.

**D10. The rule.** As in the panel: for stamp and points cards `rule` (`order` or
`amount`), the amount threshold (typed as `99,50` or `100`, converted to kuruş, 1 to
100,000) and `step` (1 to 100); for a VIP card the rule is not sent (a visit per
order) and for a cashback card the card's own rate applies, so the form says that and
the server ignores `rule`/`step` for them. Only active stamp, points, VIP and cashback
cards are offered (the other types cannot be linked).

**D11. Connect.** `listPrograms` (to check the card and take its `joinUrl`), then
`createShop` (platform `woocommerce`), then the WooCommerce webhook with the returned
address and secret: topic `order.updated`, API version `wp_api_v3`, status active,
the connecting user as its user. Safeguards:

- the delivery address must be `https`, on the API's own host, with the path
  `/hooks/store/<this link's id>` (no user info, query or fragment); otherwise no
  webhook (so the secret goes nowhere) and the link is deleted again;
- if WooCommerce cannot save the webhook the link is deleted again, since the secret
  was shown once and cannot be recreated;
- the secret lives only inside the WooCommerce webhook, in no option of ours;
- ids that go into API paths must be UUIDs.

**D12. The client's retry rules** follow rewloy-php's list: network errors, 429,
502-504, 520-524 (and 409 `IDEMPOTENCY_IN_PROGRESS`), up to two retries with jittered
backoff, and a `Retry-After` longer than 10 s (rewloy-php waits up to 60; this runs
inside an admin request) is not waited for. Only requests that are safe to repeat are
retried: GET, PUT, DELETE and PATCH (here PATCH only sets `enabled`, so repeating it
changes nothing more). **A POST is never retried, even with an `Idempotency-Key`**,
unlike rewloy-php: the key does not protect `issuePass` or `createShop` on the server
today (D1). Timeouts are 15 s; redirects are not followed, so the key can never go to
another host. HTTPS is required (plain HTTP only for localhost); a `REWLOY_API_URL`
constant can point at a staging origin.

**D13. Pause** is `setShopEnabled` only; the WooCommerce webhook stays active, because
the platform answers a paused link with 200 on purpose (WooCommerce would otherwise
disable the webhook after repeated failures). **Disconnect** deletes the link in
Rewloy first and then the webhook and the local state. "Already gone" (404
`SHOP_NOT_FOUND`) counts as done; any other failure keeps the connection so a link is
never orphaned in Rewloy. If Rewloy cannot be reached (or the key is gone) a separate
"forget on this site only" removes the webhook and the local state without an API call
and says the link remains in Rewloy.

**D14. Health.** The screen reads `getShop` and `listShopOrders` (the last 10) on each
load and the WooCommerce webhook's status and failure count, in the panel's words
(`Karta işlendi`, `Kartı yok`, `Eşiğin altında`, `Bağlantı kapalıyken`, `Başka para
birimi`, with the panel's explanations). A webhook WooCommerce disabled can be turned
on again with one button (failure count reset). A *deleted* webhook cannot be rebuilt:
the secret is only shown at creation, so the screen says to disconnect and connect
again. A failed API call is shown as an error without breaking the page.

**D15. Issuing runs off the payment request**, through Action Scheduler (bundled with
WooCommerce) with `as_enqueue_async_action(..., unique = true)`: a slow Rewloy must not
hold a payment gateway's callback. If the function is missing the issue runs inline.
The hooks are `woocommerce_order_status_processing` and `..._completed` only (the two
paid states); nothing else triggers it. Switching the invitation off or disconnecting
before the action runs stops it.

**D16. Block checkout.** Declared compatible (`cart_checkout_blocks`). The box is
registered through WooCommerce's additional checkout fields API
(`woocommerce_register_additional_checkout_field`, WooCommerce 8.9+) as an optional
checkbox; its label carries the notice as plain text (the field API's label is plain)
plus the notice's address. Below 8.9 the block checkout has no box, and the settings
screen says so. The classic checkout's box uses `woocommerce_after_order_notes` (not
inside `#payment`, which WooCommerce re-renders and would clear the tick).

**D17. My Account.** The endpoint is `loyalty-card`, titled "My loyalty card" /
"Sadakat kartım". It shows a text, a button to `https://rewloy.com/cuzdan/` and the
programme's join link (taken from `listPrograms.joinUrl` when connecting, kept only if
it is `https` on `rewloy.com`). It reads nothing about the user, calls nothing and
shows no card data (tests assert no HTTP function is called and a logged-in user's
e-mail and name never appear). The rewrite rules are flushed once after the tab is
turned on or off.

**D18. Uninstall** removes the plugin's options, its per-order claim rows, its flash
transients, its scheduled actions and its webhook (only if the webhook's delivery
address names our link, so a reused id never deletes someone else's), per site on a
network. It never calls Rewloy. It **keeps the invitation state on orders**: it is
the shop's own record, and it is what stops a card being opened twice if the plugin is
installed again. The settings screen says that nothing in Rewloy is deleted.

**D19. Language.** English in the code, Turkish in `languages/` (`.pot`, `.po`, `.mo`
all committed, so a GitHub zip works without a build). The Turkish follows the panel's
`views/shops.ts`: its outcome labels and explanations are used word for word, its
rule wording is kept (without a Turkish suffix after an amount, which a variable
cannot take). `bin/make-pot` (xgettext) and `bin/make-mo` (msgfmt) rebuild them; CI
checks the catalogue covers exactly what the code says; tests check every message is
translated, every placeholder survives, and the compiled `.mo` says what the `.po`
says.

**D20. Tests.** PHPUnit 10.5 (it still runs on PHP 8.1) with Brain Monkey (maintained,
works with PHPUnit 10; WP_Mock is not). WordPress functions are stubbed in
`tests/TestCase.php` over an in-memory options table; the few WooCommerce classes
(`WC_Order`, `WC_Webhook`, `FeaturesUtil`) are small doubles in `tests/`. The Client
is given a scripted transport: no test touches the network. PHPStan level 8 runs over
the plugin with `szepeviktor/phpstan-wordpress` and `php-stubs/woocommerce-stubs`
(the tests and doubles are not analysed: the doubles stand in for what the stubs
describe). Static tests pin what the brief demands: every file guards against direct
access, no network function outside the Client, no `eval`/`exec`-like call, only
rewloy.com hosts in the code, no `get_post_meta`-style call (HPOS), every
translatable string uses the text domain.

**D21. Packaging.** `bin/build-zip` copies an allowlist (main file, `uninstall.php`,
`readme.txt`, `LICENSE`, `src/`, `languages/`) into a folder named after the slug, so
tests, `vendor/`, docs, CI and tooling cannot leak in; it refuses to build if
`readme.txt`'s Stable tag differs from the plugin's version. A PHPUnit test builds it
and checks the contents.

**D22. Requirements as headers**: WordPress 6.4, PHP 8.1, `WC requires at least: 8.0`,
`Requires Plugins: woocommerce` (WordPress 6.5+ enforces it; older versions ignore it
and the plugin shows a notice if WooCommerce is missing). `Tested up to` (6.8) and
`WC tested up to` (9.4) are stated as targets, not as a result: see below.

## Known limits

- **The order that earns the invitation may be recorded "Kartı yok".** The card is
  opened by one scheduled action and the order is delivered by the webhook's own
  scheduled action; whichever runs first wins. If the webhook runs first, Rewloy finds
  no card for that order and records it (an order counts once), so that order earns
  nothing; later orders do. The platform could close this (see the report).
- **One card per e-mail per shop is enforced only against this plugin's own
  invitations** (D1.4).
- **A delivered order's e-mail is matched exactly as WooCommerce gives it**; nothing
  here normalises it.
- **A refunded or cancelled order** after a card was opened does not close the card;
  that is the business's call.
- **`queued` is not retried**: if Action Scheduler never runs the action, the card is
  not opened and the order shows no note. WooCommerce's own scheduled actions would
  be stuck too.

## Not verified on real WordPress

Everything runs under PHPUnit with stubs. Nothing has run in a real WordPress or
WooCommerce. Before publishing, install it on a test shop and check, in particular:

- that `woocommerce_webhook_payload` hands `($payload, $resource, $resource_id,
  $webhook_id)` and that the cut-down body still verifies at Rewloy (the privacy text
  depends on it; if the filter does not act, the full order would be sent);
- that `WC_Webhook::set_api_version( 'wp_api_v3' )` accepts the string;
- the block checkout's additional field and its stored meta key
  (`_wc_other/rewloy-for-woocommerce/invite`);
- that the My Account endpoint and its menu entry appear after the rewrite flush;
- `Tested up to` and `WC tested up to` in `readme.txt` and the plugin header.
