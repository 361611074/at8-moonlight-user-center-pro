=== AT8 Moonlight User Center Pro ===
Contributors: 361611074
Tags: membership, paywall, payments, user center
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.6.2
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Commercial add-on for AT8 Moonlight User Center: online card payments (PayPal, Stripe), tiered upgrade pricing, paywall resources, purchase and level content gates, demo videos and downloadable materials.

== Description ==

AT8 Moonlight User Center Pro extends the free plugin with the commercial half of a user center. It is a separate plugin with a separate code base: install and activate the free plugin first, then activate Pro and enter your license key.

**Membership**

* Membership levels are defined in the free plugin's Membership Levels settings section, with no cap on the number of tiers (label, colour, price, validity in days, sort order, point cost)
* Optional free base level; paid levels expire automatically and downgrade on expiry
* Membership tab in the account dashboard with a level comparison table and a buy / upgrade card
* Admin UI on the user profile page to set a level and its expiry

**Payments**

* The order engine, the bank-transfer gateway and the membership grant ship with the free plugin; Pro adds the online gateways
* PayPal (Smart Buttons, server-side capture)
* Stripe (hosted Checkout with webhook fallback)
* Order management section on the free plugin's settings screen; order records with auto-close for abandoned pending orders
* Purchase history in the account dashboard and the `[at8_mlucp_purchases]` shortcode
* Refunds made in the Stripe or PayPal dashboard do not revoke membership or unlocks automatically — an administrator removes the access by hand

**Content control**

* Paywall: sell access to individual posts and pages, with one-off unlock and optional expiry, several free-read levels per post and video / download / gallery resources attached to the post; orders reuse the free plugin's order engine
* Membership and purchase gates for the free plugin's `[at8_mluc_hide]` shortcode (login and comment gates ship with the free plugin)
* Demo video library and downloadable materials, both gated by membership level

**Licensing**

* Activation through the AT8 license center (www.at8.fun): domain binding, site limits and daily re-checks handled by the embedded AT8 License SDK
* Grace period keeps Pro alive when the license center is unreachable
* License-gated updates: new Pro packages are offered in the admin only while the license is valid and carries update access
* Every Pro module (gateways, tier ladders, paywall resources, content gates, videos, materials) stays dormant until a valid license is active; the free plugin keeps working on its own

== Installation ==

1. Install and activate AT8 Moonlight User Center (free) 2.5.0 or newer.
2. Upload this plugin to `wp-content/plugins/at8-moonlight-user-center-pro/` and activate it.
3. Open User Center → License (Pro) and enter your license code from the AT8 license center.
4. Configure the online gateways under User Center → Settings → Online Payment (PayPal / Stripe); membership levels, offline payments and orders live on the same screen.

== Changelog ==

= 1.6.2 =
* Unified: every remaining traditional-Chinese UI string is now simplified (materials and videos permission notices, metabox labels, download button texts, one Stripe error message — 62 characters across three files). The test report's earlier "zero traditional strings" claim was wrong and has been corrected.
* Hardened: metabox nonce checks and meta fields in the materials, videos and paywall classes run through `wp_unslash()` before sanitization.
* Hardened: the remote-file download redirect only allows http/https URLs; anything else is rejected with an error message instead of being followed.
* Documentation: the readme states that dashboard refunds do not revoke membership or unlocks automatically, and the Stable tag now tracks the released version.

= 1.6.1 =
* Fixed: the Pro comparison table on the free plugin's settings screen now corrects the row keys that exist in Free 2.5.0. The previous callback still patched the removed `membership_levels` and `avatars` rows, so the gateway row silently kept its generic claim — it now names the online gateways actually configured on this site (both configured, one configured, or not configured yet).
* Removed: a stale `membership_levels` default written to the options on activation that nothing ever read.
* Documentation: the README boundary list no longer advertises the three ceiling-lifting filters that Free 2.5.0 deleted, and the install requirement reads 2.5.0+.

= 1.6.0 =
* Requires the free plugin 2.5.0. Pro no longer raises any free-plugin ceiling — the free plugin has no artificial caps left — so the `at8_mluc_membership_level_limit`, `at8_mluc_limit_avatar_count` and `at8_mluc_limit_gateway_count` integrations are removed. Pro now contributes only what it actually adds: online gateways, tiered upgrade pricing, paywall resources, level and purchase content gates, videos, materials and the purchases shortcode.
* Documentation no longer describes "lifting the free cap" as a selling point.

= 1.5.0 =
* Requires the free plugin 2.4.1. The paywall module no longer ships its own content gate: the free plugin's paywall core is the single gate and the single delivery handler, and Pro extends it through the `at8_mluc_paywall_enabled`, `at8_mluc_paywall_levels`, `at8_mluc_paywall_price`, `at8_mluc_paywall_can_purchase`, `at8_mluc_paywall_unlock_expire`, `at8_mluc_paywall_locked_html` and `at8_mluc_paywall_unlocked_html` filters. A post can therefore never be double-gated and an order can never be claimed twice.
* New: the tiered upgrade path. Members moving up pay only the difference between the target level and the level they hold, credited in full; the card renders next to the points and purchase cards in the Membership tab and orders still flow through the core engine.
* New: free-read levels are now a multi-select. Tick the membership levels that read a post for free; the core gate opens for any of them, on top of the existing per-level member prices.
* New: Pro takes over the paywall meta box (the free plugin's plain Paywall box steps aside through the `at8_mluc_paywall_meta_box` filter), so one box carries mode, price, member prices, free levels, purchase permission, order expiry and the mode resources.
* Improved: the locked card and the download / gallery / video blocks are injected through the core's HTML filters, so feeds, REST responses and the teaser behaviour all follow the core.
* Improved: the members-only hidden-content gate (`[at8_mluc_hide type="vip1"]` / `payshow`) now runs on the free plugin's level API with a configurable minimum level (User Center → Settings → Hidden Content, default Monthly). Deleting that level closes the gate for everyone instead of silently opening it.
* Fixed: the inline purchase script is returned instead of printed, so it can no longer land outside the card.

= 1.4.0 =
* Requires the free plugin 2.3.0. Pro now raises every free ceiling through one boundary API instead of duplicating logic: the membership level cap goes from three to eight, the avatar library becomes unlimited and the payment gateway shelf grows from bank transfer only to bank transfer, PayPal and Stripe.
* New: Pro contributes its own shortcodes to the free plugin's cheat sheet on the settings screen, so `[at8_mlucp_purchases]`, `[at8_mlucp_videos]`, `[at8_mlucp_materials]` and the level-gated `[at8_mlucp_hide]` are documented where admins already look.
* New: the shared Pro comparison table now reports what this installation can really do — once the licence is active it shows the raised limits instead of a generic sales column.
* Fixed: the gateway ceiling declared by the free plugin is now enforced, so a site without a licence can never end up with more gateways than it is entitled to. Bank transfer always survives the cut.
* Improved: the licence bridge is a plain function and the boot sequence keeps the free plugin's menu construction ahead of Pro's, which is what keeps the License screen reachable.

= 1.3.1 =
* Fixed: the retired `at8-mlucp-settings` slug (the Pro settings screen that existed up to 1.2.0) now redirects to the free plugin's settings screen instead of hitting WordPress's "Sorry, you are not allowed to access this page" (HTTP 403). The redirect hooks `admin_page_access_denied`, which is the only one that fires before core renders that error.
* Fixed: with the free plugin 2.2.1 the User Center menu is built before child pages are added, so the License screen no longer 403s when Pro boots earlier than the free plugin.

= 1.3.0 =
* Architecture: the membership system, the order engine and the offline bank-transfer gateway moved into the free plugin 2.2.0. Pro is now a pure add-on that lifts the level cap from three to eight, contributes the Online Payment and Orders sections to the free plugin's settings screen, and registers the PayPal / Stripe gateways.
* Pro no longer ships its own settings page or its own membership / payments classes; all configuration happens on the free plugin's User Center → Settings screen.
* Paywall orders now complete through the free plugin's order engine (`at8_mluc_complete_paywall_order`), keeping one atomic claim path for every payment type.
* Gateway registration and order responses are gated by the license both in the bootstrap and at the hook level (defence in depth).

= 1.2.0 =
* Free/Pro feature split rebalanced: social login, Elementor widgets, the login/comment hidden-content gates and the editor button moved into the free plugin (2.1.0). Pro now focuses purely on monetisation: membership, payments and purchase gates.
* Hidden content is one shortcode: `[at8_mluc_hide]` handles every type, with Pro adding membership/purchase gates through the free plugin's extension points. The legacy `[at8_mlucp_hide]` name keeps working.
* The OAuth credentials settings block moved to the free plugin's settings screen.

= 1.1.0 =
* Licensing moved to the embedded AT8 License SDK talking to the AT8 license center: activation, domain binding, daily re-checks, grace periods and license-gated updates.
* Removed the 1.0.x offline key scheme and the self-hosted update channel; keys issued before 1.1.0 must be re-issued from the license center (existing data is kept).
* The license screen stays under User Center → License (Pro), now powered by the SDK.

= 1.0.0 =
* First release: membership levels, paywall, hidden content, bank transfer / PayPal / Stripe payments, demo videos, materials, purchases, social login, Elementor widgets, license client with offline and server modes, self-hosted update channel.
