=== Dahu Store Credit ===
Contributors: hugovialjaime
Tags: woocommerce, store credit, coupons, refunds, vouchers
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
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

= 1.1.2 =
* Fixed: a credit spent down to exactly zero kept its full value, so it could be spent a second time; refunding such an order doubled it. What is left is now written whatever that amount is.
* Fixed: refunding an order gave back the amount the credit line still showed rather than what the order had actually taken, which created money whenever the order consumed more than the credit held.
* Fixed: what a charge took is now recorded under the credit's code rather than its internal id. A shop that deleted a credit and recreated it under the same code lost that record, and the refund fell back on the order line, handing back more than the credit had ever held.
* Fixed: the order note and the credit's history printed the order line rather than the amount actually returned, so the audit trail could be wrong by the clipped difference. A credit already empty when charged now stays silent instead of announcing a return of nothing.
* Fixed: the credit was debited before the order recording the charge was saved. An interruption between the two left a debited credit with no record of what had been taken, and a second pass could debit it again.
* Fixed: the same applied in reverse to a refund, which credited the coupon before saving the order that marks the refund as done. An interruption between the two let the refund run again, adding the same amount every time. The record is now spent before the credit is given back, so a refund is worth exactly once.
* Fixed: a record this plugin cannot read — a meta re-encoded by an import, a failed serialization — was treated as a record of zero, and the refund silently returned nothing at all. It now falls back to the order line, which may return more but says so in the order note.
* Fixed: on an order carrying more than one credit, an interruption part-way through left the remaining credits untouched for good — never charged, or never given back — because the order-wide marker was written from the first credit onwards. Each credit is now tracked on its own.
* Fixed: the token saying a refund has happened now lives on the credit itself, written by the same save that moves the money, so an interruption can no longer either lose a refund or repeat it.
* Fixed: a record holding a value too large to be a number gave the credit an infinite value, able to pay any order.
* Fixed: a refund used to require the order-wide marker, which is the last thing written when an order is charged. An interruption just before it left every credit charged and the refund refusing to run — taken from the customer and never returned, with nothing on the order to find it by. A refund now runs whenever there is a record of what was taken.
* The list of active store credits is now shown fifty at a time. A shop that issues a credit on every refund held thousands of them, and the screen read and loaded every single one before drawing the table.

= 1.1.1 =
* The screen a shop sees before it has any data now says what will appear there, and offers the link to the action that fills it.

= 1.1.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 1.0.5 =
* Fixed: a balance of exactly one cent was destroyed instead of being carried
  forward, and the order note said the credit had been spent in full.
* Fixed: the optional uninstall wipe left the processed and restored markers
  on orders behind on shops running high-performance order storage.

= 1.0.4 =
* First public release.

== Upgrade Notice ==

= 1.0.5 =
Corrects a one-cent balance being lost when a store credit is almost fully
spent.

= 1.0.4 =
First public release.
