=== Dahu Store Credit ===
Contributors: hugovialjaime
Tags: woocommerce, store credit, coupons, refunds, vouchers
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: MIT
License URI: https://opensource.org/licenses/MIT

Turns a WooCommerce fixed-cart coupon into real store credit: whatever is left over is carried forward instead of being lost.

== Description ==

A WooCommerce fixed-cart coupon is spent whole. A customer holding a 100 credit
who orders for 40 loses the other 60. That is fine for a discount voucher and
wrong for store credit, a refund or a gift card.

Dahu Store Credit fixes exactly that. Tick one box on a coupon and, once an
order using it is paid, the plugin writes the remaining balance back onto the
same coupon. The customer keeps the same code, with less on it.

= How it works =

* The balance is written back to the **same coupon**, so the customer's code
  never changes.
* The usage count is reset and the customer is removed from the coupon's "used
  by" list, so they can spend the rest.
* Expiry can be pushed back by a configurable number of days on each partial use.
* Cancelling or refunding an order puts the consumed amount back on the coupon.
* Every movement is recorded in a history visible on the coupon screen.

= Concurrent orders =

Two unpaid orders could otherwise each reserve the full balance. Before a
coupon is accepted, the plugin subtracts what pending and on-hold orders have
already reserved on it, and caps the coupon to what is genuinely left — in
memory, without touching the stored amount.

= Features =

* One checkbox per coupon, in the coupon's General tab
* Automatic carry-forward of the remaining balance after payment
* Restitution on cancellation or refund
* Optional extension of the expiry date on each partial use
* Protection against concurrent unpaid orders reserving the same balance
* History of movements on the coupon screen
* A list of active store credits with their balances
* Compatible with High-Performance Order Storage (HPOS)

= External services =

This plugin does not contact any external service.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the
   Plugins screen.
2. Activate it through the Plugins screen.
3. Create a coupon of type "Fixed cart discount" and tick **Store credit
   (balance carries forward)** in its General tab.

Settings live under WooCommerce > Dahu Store Credit.

== Frequently Asked Questions ==

= Does it work with percentage or per-product coupons? =

No. Carrying a balance forward only makes sense for a fixed amount, so only
"Fixed cart discount" coupons are handled. A coupon ticked but of another type
is ignored, and the coupon screen says so.

= What happens if the customer cancels? =

The consumed amount goes back onto the coupon, and the movement is recorded in
the history.

= Can two orders spend the same credit at once? =

No. Amounts already reserved by pending and on-hold orders are subtracted
before the coupon is accepted.

= What happens to my coupons if I delete the plugin? =

Settings are removed; coupon balances, flags and history are kept. Removing
them would quietly turn every store credit back into an ordinary coupon spent
whole. Set `dahu_store_credit_remove_data_on_uninstall` to a truthy value
before deleting if you really want them gone.

== Screenshots ==

1. The checkbox on a coupon.
2. The movement history on the coupon screen.
3. The list of active store credits.

== Changelog ==

= 1.0.4 =
* First public release.

== Upgrade Notice ==

= 1.0.4 =
First public release.
