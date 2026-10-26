# WooCommerce Anti Fraud

Catches fraudulent WooCommerce orders before you ship them: device fingerprints, WebRTC proxy leak checks, copy-paste detection, IP intelligence and a 0-100 risk score on every order.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## Features

Every order starts at 100. Each signal that looks wrong takes points off, up to its own maximum. Below 80 an order **needs review**; below 50 it's **high risk** (both adjustable). The order screen gets a **Fraud Risk** box with every signal, the points it took and the evidence behind it, and the orders list gets a **Fraud** column.

| Signal | Up to | What it looks for |
|---|---|---|
| VPN, proxy & WebRTC leak | -50 | Tor (-50), VPN or proxy (-30), data center (-25) IPs; a WebRTC address that differs from the order IP (-35, or -45 when that real connection is in another country from the billing address) |
| Failed payments | -40 | Declines from the same device or IP in the failed-payment window (2: -15, 3: -25, 4+: -40) |
| IP changes | -40 | IP changed between loading checkout and placing the order (-20), a switch within the last hour (-10), 4+ devices on one IP (-20), a device suddenly in a new country (-10) |
| Order velocity | -30 | Orders from the same device or IP in 48 hours (2: -10, 3: -20, 4+: -30) |
| Addresses per IP | -30 | 3+ addresses across 3+ states or regions from one IP in a week |
| IP location vs billing | -25 | Different country (-25) or state/region (-10) |
| IPs per device | -20 | Distinct IPs for one device in the window (3: -8, 5: -14, 8+: -20) |
| Pasted details | -15 | Name, address, email or phone pasted (1: -3, 2-3: -8, 4+: -15) |
| Browser timezone | -15 | Browser clock an hour or more off the IP location's timezone |
| Phone area code | -5 | US/Canadian area code from a different state than the billing address |

Mobile carrier and iCloud Private Relay addresses are shared between strangers and rotate by design, so they never link one person's orders to another's, never count as IP changes and never count towards IPs per device. The same goes for any IP that 20 or more devices have used in a day: an office, a big VPN exit, or a CDN that isn't set up as a trusted proxy.

Data a visitor can make up never counts against anyone else. The real IP the WebRTC check finds links the order that reported it to other orders, but another order's claimed IP is never matched against a customer's verified IP, so nobody can drag a stranger's orders down by faking one. The billing email isn't used to count failed payments for the same reason: anyone can type anyone's email.

Optional actions on the **Actions** tab, all off until switched on:

- **Hold high-risk orders**: paid orders that score high risk go to On hold instead of Processing (any gateway that calls `payment_complete()`, plus cash on delivery).
- **Stop card testing**: refuse checkout, before the gateway is called, once a device or IP reaches N failed payments in the window. Mobile carrier, relay and crowded IPs never block; VPN and data center IPs block only once their failures come from two or more devices. Clear false positives on the Overview tab.
- **Email alerts**: every high-risk order, and N failed payments or N orders in 48 hours from one device or IP. One email per person, trigger and window, and no more than 10 an hour.

Settings live under **Content Powered > WooCommerce Anti Fraud**: Overview (counts, orders to review, repeated failed payments), Detection (browser checks, which signals count, risk levels, which orders), IP & Location (trusted proxies, ipinfo.io, STUN server), Actions and Advanced (retention, hooks).

## How the data gets in

The checkout and pay-for-order pages load `assets/collector.min.js` (3.5 KB gzipped, no dependencies). It never reads field values or card fields. It collects:

- a device fingerprint: canvas and audio traits (hashed in the browser), WebGL renderer, fonts, screen, languages, platform, CPU and memory, plus a random device ID in localStorage so two people with the same phone model aren't treated as one device;
- the public IPs WebRTC gets back from the STUN server (`stun:stun.l.google.com:19302` by default);
- how each checkout field was filled in: typed, pasted or autofilled;
- the browser's timezone, the IP from its last checkout visit, and a signed note of the IP the page was served to;
- the seconds spent on the page.

There's no AJAX or REST endpoint. The data rides along with the order: a hidden `wcaf_payload` field on the classic checkout and pay-for-order forms, and Store API extension data (namespace `woocommerce-anti-fraud`) on the block checkout. The server checks every part (size cap, JSON depth limit, whitelisted keys and values, IP validation) before using it.

Scoring runs on `woocommerce_checkout_order_processed` and `woocommerce_store_api_checkout_order_processed`, before payment, and again on each pay-for-order attempt. Orders placed some other way are scored on their first status change, without browser data. When a new order or a failed payment comes in, related orders from the same device or IP are re-scored in the background (WP-Cron) and get an order note if their score changes.

## The visitor IP

`REMOTE_ADDR`, unless the connection comes from:

- **Cloudflare**, checked against Cloudflare's published ranges (shipped, refreshed weekly): then `CF-Connecting-IP`;
- **a trusted proxy**, listed on the IP & Location tab, or any private or loopback address when "Trust proxies on private networks" is on (the default): then the chosen header, with `X-Forwarded-For` read right to left past your own proxies.

WooCommerce's own `customer_ip_address` believes `X-Forwarded-For` from anyone, so it's shown for comparison but never used to link orders or count failed payments.

## IP lookups

ipinfo.io gives each IP's location, timezone, network and AS number, and the plugin works out the connection type from it: mobile carrier, home ISP, business, hosting/data center, VPN, Tor or iCloud Private Relay. Paid ipinfo plans add privacy and carrier flags, which are used automatically. Lookups work without a token at low volume; a free token raises the limit to 50,000 a month. Results are cached for a day per IP (a failed lookup for 10 minutes), every lookup has a 3-second timeout, and the token is sent in an `Authorization` header, never in a URL.

Without lookups, location falls back to Cloudflare's visitor location headers and WooCommerce's MaxMind integration.

## For developers

| Hook | Type | What it does |
|---|---|---|
| `woocommerce_anti_fraud_deduction` | filter | Points a signal takes off: `( $points, $key, $report, $order )` |
| `woocommerce_anti_fraud_score` | filter | The final score: `( $score, $report, $order )` |
| `woocommerce_anti_fraud_order_scored` | action | After an order is scored or re-scored: `( $order, $score, $risk, $report )` |
| `woocommerce_anti_fraud_ip_info` | filter | What's known about an IP; add your own data source here: `( $info, $ip )` |
| `woocommerce_anti_fraud_client_ip` | filter | The visitor IP: `( array $client )` with `ip`, `source`, `remote` |
| `woocommerce_anti_fraud_in_scope` | filter | Whether an order or cart is checked: `( $in_scope, $order_or_cart )` |
| `woocommerce_anti_fraud_block_checkout` | filter | Whether a checkout is refused: `( $block, $failures, $identity )` |
| `woocommerce_anti_fraud_alert_recipients` | filter | Who gets alert emails |
| `woocommerce_anti_fraud_alert_email` | filter | An alert email before it's sent: `( $mail, $trigger, $order )` |
| `woocommerce_anti_fraud_shared_ip_devices` | filter | Devices on one IP in 24 hours that make it a shared address (default 20) |
| `woocommerce_anti_fraud_lookups_per_hour` | filter | Fresh IP lookups one visitor may cause per hour (default 30) |
| `woocommerce_anti_fraud_alerts_per_hour` | filter | Alert emails per hour, site-wide (default 10) |
| `woocommerce_anti_fraud_skip_created_via` | filter | `created_via` values never scored after the fact (default `admin`, `subscription`, `rest-api`) |
| `woocommerce_anti_fraud_vpn_keywords`, `_hosting_asns`, `_hosting_keywords`, `_mobile_keywords`, `_relay_asns` | filters | The network lists behind the connection type |

Define `WOOCOMMERCE_ANTI_FRAUD_DISABLED` as `true` in `wp-config.php` to switch every check off.

Order meta: `_wcaf_score` (0-100), `_wcaf_risk` (`low`, `medium`, `high`), `_wcaf_report` (everything, as JSON), `_wcaf_initial` (the first score), `_wcaf_fingerprint`, `_wcaf_visitor`, `_wcaf_ip` (verified IPs only), `_wcaf_leak` (the real IP behind a proxy, from WebRTC), `_wcaf_scored_at`, `_wcaf_held`.

Tables: `{prefix}wcaf_visitors` (devices), `{prefix}wcaf_ips` (each device's IPs) and `{prefix}wcaf_declines` (failed payments and refused checkouts; emails stored as SHA-256 hashes). Records older than the retention period (12 months by default) are deleted daily.

After editing `assets/collector.js`, rebuild the minified copy with `bin/build.sh` (needs Node; `npx` fetches esbuild). Define `SCRIPT_DEBUG` to load the unminified file.

## Privacy

The plugin adds suggested text to Settings > Privacy > Policy Guide, and hooks into WordPress's Export Personal Data and Erase Personal Data tools (by billing email). It never records field values or card data. Deleting the plugin removes its tables, settings, cached lookups and order meta, unless "Keep the plugin's data and settings" is ticked on the Advanced tab.

## Automatic updates

Updates come from https://github.com/jmsprsns/woocommerce-anti-fraud through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo for a newer version. When there is one, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `woocommerce-anti-fraud.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `woocommerce-anti-fraud.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Tag that version and push the tag:

   ```bash
   git tag -a v1.0.1 -m 1.0.1
   git push origin v1.0.1
   ```

   Or publish a GitHub release with that tag (`gh release create v1.0.1 --title 1.0.1 --generate-notes`); its notes become the changelog in the "View details" popup.

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

PUC installs the latest GitHub release; with no releases, the highest version tag; with neither, the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'WOOCOMMERCE_ANTI_FRAUD_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'WOOCOMMERCE_ANTI_FRAUD_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
woocommerce-anti-fraud.php                    Plugin header, constants, boot, HPOS/blocks compatibility
uninstall.php                                 Removes tables, settings and order meta (every site on a multisite)
readme.txt                                    WordPress readme; feeds the "View details" popup
includes/
  class-woocommerce-anti-fraud-plugin.php     Main singleton, settings, daily housekeeping
  class-woocommerce-anti-fraud-install.php    Tables (dbDelta), schema upgrades, pruning
  class-woocommerce-anti-fraud-request.php    The real visitor IP: Cloudflare ranges, trusted proxies, CIDR helpers
  class-woocommerce-anti-fraud-ip-intel.php   ipinfo.io lookups, connection type, Cloudflare/MaxMind fallbacks
  class-woocommerce-anti-fraud-checks.php     WebRTC verdict, geo, timezone, phone area codes, paste count
  class-woocommerce-anti-fraud-identity.php   Devices and their IP history
  class-woocommerce-anti-fraud-declines.php   Failed payments, the card-testing block, clearing
  class-woocommerce-anti-fraud-scorer.php     Signals, the score, the saved report, background re-scoring
  class-woocommerce-anti-fraud-collector.php  Checkout script, payload intake (classic, blocks, pay-for-order)
  class-woocommerce-anti-fraud-actions.php    Holding high-risk orders, email alerts
  class-woocommerce-anti-fraud-privacy.php    Policy text, personal data exporter and eraser
  class-woocommerce-anti-fraud-order-admin.php  Fraud Risk box, detail dialogs, Fraud column
  class-woocommerce-anti-fraud-admin.php      Content Powered > WooCommerce Anti Fraud page and Plugins-screen link
  class-woocommerce-anti-fraud-menu.php       Shared top-level "Content Powered" menu
  class-woocommerce-anti-fraud-feedback.php   Shared Submit Feedback and Report a Bug pages
  class-woocommerce-anti-fraud-updater.php    GitHub update checks (PUC wiring)
  class-woocommerce-anti-fraud-icons.php      Inline Lucide icon helper
assets/
  collector.js, collector.min.js              Checkout browser checks (source and the built copy that ships)
  admin.css, admin.js                         Settings page only
  order.css, order.js                         Order screens only
  icon.svg                                    Icon on the update screens
  content-powered.svg                         Sumo logo for the Content Powered menu
bin/build.sh                                  Rebuilds collector.min.js (not shipped)
icons/                                        The Lucide icons in use (plus LICENSE)
plugin-update-checker/                        Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every Content Powered plugin puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > WooCommerce Anti Fraud, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-woocommerce-anti-fraud-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page.

## Feedback and bug reports

Every plugin also carries `class-woocommerce-anti-fraud-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.
- IP data from [ipinfo.io](https://ipinfo.io), under its terms of service.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
