=== AT8 Moonlight User Center Pro ===
Contributors: 361611074
Tags: membership, paywall, payments, user center
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.3.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Commercial add-on for AT8 Moonlight User Center: membership levels beyond the free cap, paywall, purchase gates, PayPal and Stripe payments, demo videos, downloadable materials and purchases.

== Description ==

AT8 Moonlight User Center Pro extends the free plugin with the commercial half of a user center. It is a separate plugin with a separate code base: install and activate the free plugin first, then activate Pro and enter your license key.

**Membership**

* Up to eight membership levels, defined in the free plugin's Membership Levels settings section (label, colour, price, validity in days, sort order); the free plugin alone supports three
* Optional free base level; paid levels expire automatically and downgrade on expiry
* Membership tab in the account dashboard with a level comparison table and a buy / upgrade card
* Admin UI on the user profile page to set a level and its expiry

**Payments**

* The order engine, the bank-transfer gateway and the membership grant ship with the free plugin; Pro adds the online gateways
* PayPal (Smart Buttons, server-side capture)
* Stripe (hosted Checkout with webhook fallback)
* Order management section on the free plugin's settings screen; order records with auto-close for abandoned pending orders
* Purchase history in the account dashboard and the `[at8_mlucp_purchases]` shortcode

**Content control**

* Paywall: sell access to individual posts and pages, with one-off unlock and optional expiry; orders reuse the free plugin's order engine
* Membership and purchase gates for the free plugin's `[at8_mluc_hide]` shortcode (login and comment gates ship with the free plugin)
* Demo video library and downloadable materials, both gated by membership level

**Licensing**

* Activation through the AT8 license center (www.at8.fun): domain binding, site limits and daily re-checks handled by the embedded AT8 License SDK
* Grace period keeps Pro alive when the license center is unreachable
* License-gated updates: new Pro packages are offered in the admin only while the license is valid and carries update access
* Every monetisation feature (gateways, paywall, level cap) stays dormant until a valid license is active; the free plugin keeps working on its own

== Installation ==

1. Install and activate AT8 Moonlight User Center (free) 2.2.0 or newer.
2. Upload this plugin to `wp-content/plugins/at8-moonlight-user-center-pro/` and activate it.
3. Open User Center → License (Pro) and enter your license code from the AT8 license center.
4. Configure the online gateways under User Center → Settings → Online Payment (PayPal / Stripe); membership levels, offline payments and orders live on the same screen.

== Changelog ==

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
