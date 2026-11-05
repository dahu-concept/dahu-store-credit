=== Ubac Store Credit ===
Contributors: dahuconcept
Tags: woocommerce, store credit, coupons, refunds, vouchers
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
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

= And if the order is completed again afterwards? =

The credit is charged again, for what that order consumes. A cancellation puts
the amount back and clears the record of the charge, so the order starts from a
clean slate; the credit is never charged twice for the same completion.

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

= 1.2.0 =
* Fixed: an interruption between the record of a charge and the charge itself could create money. The record was written on the order and the money taken from the credit immediately after, so a request that died in between left a record saying "took 40" in front of a credit that still held its 100: the next pass skipped the credit on the strength of that record, the goods went out against it, and cancelling the order then returned 40 that had never been taken — 140 on a credit of 100. What each order took is now recorded on the credit itself, written by the very save that debits it, so the two can no longer come apart. Measured across every cut rank of both datastores and both operations, on a fresh credit and on one that had already been through a full cycle: 48 ranks out of 48, a gap of 0.00.
* Fixed: an order that had been refunded once and was then completed again could not be charged, and a cut at the wrong moment made that permanent — the credit debited, the money taken, and no pass able to give it back. The two order-wide markers are no longer gates, and setting one clears the other; what prevents a double charge or a double refund is now a record carried by the credit, one credit at a time.
* Fixed: the credit's history recorded the order line rather than the amount actually taken, so a 120 line on a credit of 100 read "consumed 120, new balance 0" — wrong by 20, and no longer summing to the balance printed beside it.
* Fixed: between the moment a charge took the money and the moment the order was marked as charged, that order's line was counted a second time against the credit's available balance, so a customer could see less credit than they held.
* Changed: where a credit has been deleted and recreated under the same code since it was charged, the refund still returns what was taken, clipped, and now says so on the order: the recreated credit may end up holding more than it ever lent, and that is the shop's own bookkeeping rather than something this plugin can know. It used to happen silently.
* Fixed: a refund consulted the coupon line before the record of the charge, so an order whose coupon line had been edited away — a recalculation in the admin — was cancelled without giving anything back: 40.00 kept from the customer, with the record of the charge still saying 40 and no note anywhere. The record is now read first, and the line only ever serves as the fallback amount.
* Fixed: the record on the credit was believed only while a second record, carried by the order, agreed with it. An import that re-encoded the order's metas therefore made both sides misread the same state: the refund gave nothing back, and a second completion charged the same 40.00 again, of which only half could ever be returned. The credit's own record is the money's own record and is now believed on its own; the order's record answers one question only, and it is a question about silence.
* Fixed: the record is keyed by order id, and an id can come back after an import or a renumbering, which left no way to tell this order from a renumbered namesake — and the two want opposite answers. The record now also says which order it is about, by the order key, written in the same save as the balance.
* Fixed: a credit consumed to the last cent kept the expiry date it had, so cancelling that order returned the money onto a credit that had meanwhile expired: a balance of 100.00 and not a cent of it spendable. A refund now pushes the expiry out the same way a charge does, and never pulls it in. Where no extension is configured there is no date to invent, so the order says that the credit needs a new one.
* Fixed: taking a coupon off an order in the admin deletes its line rather than zeroing it, and the refund walked those lines — so cancelling such an order gave nothing back: 40.00 kept from the customer, no note, no history, and the record of the charge still plainly readable. The refund now walks everything that claims the order took something, not only what the order still shows.
* Fixed: the refund decided at its own door whether to run at all, on two records that both live on the order — the very datastore this plugin treats as unreliable. An import that dropped an order's metas therefore kept 40.00 from the customer while the charge side, reading the same state, correctly refused to charge again. Nothing is decided at the door any more: each credit is asked about itself.
* Fixed: charging a credit posted the configured expiry window as the credit's new expiry date, whatever date it already had. A credit good for a year, spent against one order with a thirty-day window set, saw the remainder expire in thirty days instead of three hundred and sixty-five — 335 days of a customer's credit destroyed — and a credit with no expiry at all was given one. An extension now only ever extends, and says nothing about a credit that never expires.
* Changed: where a charge is recorded against an order id but cannot be matched to the order in hand, or the credit has been deleted and recreated since, the order note now says which of the two happened instead of claiming the credit in hand was the one charged. A refund in that position still pays out rather than keeping a customer's money on a doubt, and says so.
* Fixed: where a charge was recorded against an order id but could not be tied to the order in hand — an import that renumbers orders and loses their keys — the refund paid it out anyway. That took a credit back to its full balance while the goods of two orders had left the shop. The order's own records are now asked to corroborate: an order that merely lost its key still carries them and is refunded as before, while one that cannot corroborate is left alone and the order says why, because the shop knows something this plugin cannot.
* Fixed: a credit that had been deleted since, or a record that could not be read, produced an order note announcing a refund in cases where nothing had in fact been returned. Every such note now sits after the decision it describes.
* Fixed: an order charged by an earlier version, whose record cannot be read and whose coupon line has been removed, gave nothing back and said nothing. It still gives nothing back — there is no amount to be read anywhere — but it now says so.
* Fixed: the note about a coupon that no longer exists was written afresh on every pass, so cancelling and refunding an order repeatedly filled its notes with the same line. It is written once, and only where a record claims that order took something from a credit.
* Changed: charging an order whose id already carried a charge made by a different order — possible only after a renumbering — now records on the order which claim was displaced, instead of replacing it silently.
* Fixed: asking the order to corroborate a charge whenever the two keys could not be compared put the credit's own record back at the mercy of the order's — the mistake this version exists to undo. Where the key map holds nothing for an entry at all there is no renumbering to fear, so the record is simply believed; corroboration is now asked for only where a key IS recorded and the order cannot produce one, which is the one real signal of a reused id. Measured: 40.00 of a customer's credit no longer destroyed when an order's metas are gone.
* Fixed: the reserved amount read the record differently from the charge, comparing order ids alone. A pending order sharing its id with a vanished one was therefore left out of the reserved total — 0.00 reserved instead of 30.00 — while the charge side held that same order to be chargeable, so a customer could commit a balance that was already promised. Both now read it through one method.
* Fixed: the marker that keeps an order note from being repeated was only saved when something moved, so a pass that merely refused never persisted it and said the same thing again on the next request. Measured at five identical notes over five requests.
* Fixed: two order notes about a refund were written before the one write that actually returns the money. An interruption on that write left the credit untouched while the order stated the amount had been returned — and since the note's own marker is now saved, that false statement was made permanent. The notes follow the money, so an interruption loses a true note instead of keeping a false one.
* Fixed: the reserved balance counted a coupon line that had already been charged, where the charge could no longer be tied to its order. A credit really holding 60.00 offered 20.00, and with one more pending order the credit was refused at the basket altogether. A line belonging to a genuinely new order that reused an id is still reserved, because there nothing has charged it.
* Fixed: the basket and the charge read one state with different vocabularies, so where a charge could no longer be tied to its order the basket allowed the credit to be spent on an order the charge would then refuse to debit — 60.00 of goods leaving with nothing taken. Both now ask the same question. Where such a claim cannot be corroborated by the order's own records the amount is held back rather than spent, which blocks a balance an administrator can release instead of losing goods that cannot be recovered.
* Fixed: a credit carrying a per-customer usage limit refused the customer their own remaining balance. The plugin had tried to remove them from the coupon's list of users, and measurement against WooCommerce itself showed that attempt did nothing — it wrote a stray record WooCommerce does not read, while the real list still named the customer. A credit's limit is its balance, not a count of uses, and that is now answered where WooCommerce asks the question. An ordinary coupon keeps its per-customer limit.
* New: a partial refund now returns the credit's share of it. An order paid partly by credit carries a total already net of that credit, so refunding a quarter of what the card paid means a quarter of the order went back — and a quarter of what the credit lent is owed. Refunds arriving in instalments accumulate without ever returning more than was taken, and a credit is freed for re-use exactly when the whole of its charge is back, whether that happened in instalments or in one go.
* New: an order moved to "failed" after being charged gives the credit back, exactly as a cancelled one does. An order that failed without ever being charged is left alone.
* Fixed: the share of a partial refund is measured on the goods, not on the order. An order total includes shipping, and a cart-level coupon never pays for shipping — so refunding a late parcel's shipping on an order paid entirely by credit returned the whole credit while the customer kept the goods, and a full return with the shipping kept returned less than was taken, permanently and without a word. Both sides of the calculation now read the item lines alone.
* Changed: where an order is marked refunded and what was actually refunded covers no goods — because its items were paid entirely by the credit — the amount still goes back, since a shop that marks an order refunded has said something. But the order now says so, so the shop can check that the goods really came back.
* Fixed: the record of what had already been returned is bounded like every other amount here. A damaged record holding a negative value could carry a credit far above what it ever lent, and one holding an infinite value made a refund return nothing at all, in silence. It is now read within bounds, and an unreadable one is announced.
* Changed: deleting a refund in WooCommerce lowers the refunded total, and the credit is not taken back off the customer — it may already have been spent. The balance stands.
* Changed: where an order is marked refunded and nothing that was actually refunded covers any goods — only shipping, fees, or an amount with no line attached — the order now says so. The amount still goes back, because a shop that marks an order refunded has said something; but 40.00 could return with not a cent of goods having come back, and nothing pointed it out.
* Changed: a partial refund that cannot be tied to its order now says so on the order instead of passing over in silence. Nothing is paid out in that case — it could hand over an amount another order took — and an amount owed that goes unpaid without a word is what this plugin refuses everywhere else.
* Changed: uninstalling now also removes the records this version adds. The bench that checks the wipe reads the plugin's own meta constants, so a key added later cannot be forgotten.

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
* Fixed: a charge skipped because the order already carries a record for that credit now says so on the order too. It was silent, while the refund that followed announced returning money the credit had never been charged — the order told the shop two things that could not both be true.
* Fixed: messages that report a refusal are written once per order instead of once per hook. A refusal is repeatable by nature, so five passes of a cancelled order wrote the same note five times, and a note that repeats stops being read.
* Fixed: the refusal message no longer claims nothing was returned. That was true for an unrelated order reusing an id, and false in the case this guard exists for — a refund whose money was credited and whose order save was lost — where it sent the shop to check a credit that was already whole.
* Fixed: a refund refused because the order was already recorded against that credit now says so on the order. The refusal was silent, and an order id that comes back after an import or a migration could keep a refund from a customer with nothing anywhere to show it.
* Fixed: the charge guard compared its own record to the text "0" rather than to zero, so the same amount written differently did not mean the same thing. Every spelling of zero now means the credit was given back and can be charged again, and a record this plugin cannot read never passes for one.
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

= 1.2.0 =
Corrects defects that moved real money, each one measured: a refund that could
return more than a credit ever lent, a charge that could be taken twice after
an interrupted request, a credit refused to the customer who owned it, and a
remaining balance whose expiry date was cut short. Partial refunds now return
the credit's share. Worth updating before the next refund.

= 1.0.5 =
Corrects a one-cent balance being lost when a store credit is almost fully
spent.

= 1.0.4 =
First public release.
