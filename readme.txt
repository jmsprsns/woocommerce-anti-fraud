=== WooCommerce Anti Fraud ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: woocommerce, fraud, anti-fraud, card testing, fraud prevention
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
WC requires at least: 6.0
WC tested up to: 11.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Catches fraudulent WooCommerce orders before you ship them: device fingerprints, WebRTC proxy leak checks, copy-paste detection, IP intelligence and a 0-100 risk score on every order.

== Description ==

Every order gets a fraud score from 0 to 100 and a Fraud Risk box on its order screen that shows exactly why: which signals looked wrong, how many points each took off, and the evidence behind it. A Fraud column on the orders list shows the score at a glance.

= What it checks =

* **VPN, proxy, Tor and data center IPs**, from the IP's network (ipinfo.io; works without a key).
* **WebRTC leak check**: the browser asks a STUN server for its own public address. Many VPNs and proxies don't cover that request, so a different address there is usually the customer's real connection, shown with its location and network.
* **Copy and paste detection**: whether name, address, email and phone were typed, pasted or autofilled. Fraudsters paste stolen details from a list. Only the method is recorded, never what was typed.
* **Device fingerprint**: recognises the same device across orders even when it changes IP, email or name.
* **Failed payments** from the same device or IP: the mark of card testing. Recorded from any gateway, plus every attempt on WooPayments and Stripe.
* **Order velocity**: several orders from one device or IP in 48 hours.
* **IP changes**: the IP switched while the customer was on the checkout page, one IP is used by many devices, or a device suddenly shows up in a new country.
* **IP location vs billing address**: a different country, or state.
* **Browser timezone vs IP location**: residential proxies sell US addresses to browsers whose clocks are set elsewhere.
* **Addresses per IP**: one IP shipping to many addresses across several states (a reshipping pattern).
* **Phone area code vs billing state** for US and Canadian numbers.

Mobile carriers and iCloud Private Relay share IPs between strangers and rotate them by design, so those networks are never used to link one customer's orders to another's.

= What it can do about it (all optional) =

* **Hold high-risk orders**: paid orders that score high risk go to On hold instead of Processing, so nothing ships until you've looked.
* **Stop card testing**: refuse checkout, before the payment gateway is called, once a device or IP has failed too many payments. Clear genuine customers in one click.
* **Email alerts** for high-risk orders and repeated failed payments.

= Built to be safe =

* No new public endpoints: the browser checks ride along with the checkout submission itself.
* The visitor IP can't be spoofed with fake headers: X-Forwarded-For and CF-Connecting-IP are only believed from Cloudflare (checked against its published ranges) or from proxies you list.
* Nothing a visitor can fake counts against another customer: a claimed IP or a typed-in email can't be used to flag or block someone else's orders.
* The card-testing block never keys on mobile carrier, iCloud Private Relay or other shared addresses, so one tester can't lock out everyone behind them. A VPN or data center address is blocked only once its failures come from two or more devices.
* Fails open: if anything goes wrong, the order goes through normally.
* Works with the classic and block checkouts, pay-for-order pages, and both WooCommerce order storage modes (HPOS and posts).
* Personal data export and erase support, suggested privacy-policy text, and automatic clean-up of old records.

Find it under Content Powered > WooCommerce Anti Fraud in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* IP data from [ipinfo.io](https://ipinfo.io).
* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `woocommerce-anti-fraud` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress. WooCommerce has to be active.
3. Go to Content Powered > WooCommerce Anti Fraud. Everything works out of the box; check the IP & Location tab if your site is behind a load balancer or proxy.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Does it block orders on its own? =

Not unless you ask it to. Out of the box it only scores and explains. Holding high-risk orders and stopping repeated failed payments are switches on the Actions tab.

= Do I need an API key? =

No. IP lookups use ipinfo.io, which answers a modest number of lookups without a key. A free ipinfo.io token raises that to 50,000 a month; add it on the IP & Location tab.

= Can it see card numbers? =

No. Card fields live inside the payment gateway's own frame. The plugin never sees or records card details, or what customers type in any field.

= My site is behind Cloudflare or a load balancer. Will it see the right IP? =

Cloudflare is recognised automatically. For a load balancer or a firewall service such as Sucuri, add its IP or range under IP & Location > Trusted proxies. That tab shows what the server sees for your own visit, so you can confirm it.

= What about customers on a VPN? =

A VPN alone takes 30 points off, enough to flag the order for review but not to call it high risk. If the WebRTC check also finds a real connection in a different country from the billing address, that takes 45 off instead, and with one or two other warning signs (a browser clock set to another timezone, pasted details) the order becomes high risk.

== Changelog ==

See https://github.com/jmsprsns/woocommerce-anti-fraud/releases for the full release history.
