# Dahu Store Credit

Turns a WooCommerce fixed-cart coupon into real store credit: whatever is left
over is carried forward instead of being lost.

Developed and maintained by [Hugo Vial-Jaime — Dahu-Concept](https://dahu-concept.fr).

## The problem

A WooCommerce fixed-cart coupon is spent whole. A customer holding a 100 credit
who orders for 40 loses the other 60. That is fine for a discount voucher and
wrong for store credit, a refund or a gift card.

## What it does

Tick one box on a coupon. Once an order using it is paid, the plugin writes the
remaining balance back onto the **same** coupon — the customer keeps the same
code, with less on it — resets the usage count, removes the customer from the
"used by" list so they can spend the rest, and records the movement in a history
on the coupon screen.

Cancelling or refunding an order puts the consumed amount back.

## Concurrent orders

Two unpaid orders could otherwise each reserve the full balance. Before a coupon
is accepted, the plugin subtracts what pending and on-hold orders have already
reserved on it and caps the coupon to what is genuinely left — in memory, never
touching the stored amount.

That lookup starts from the coupon, not from the order queue: it reads the
coupon lines carrying this code and inspects only those orders. Walking every
pending order instead would make checkout slower the longer a shop has been
trading.

## Features

- One checkbox per coupon, in the coupon's General tab
- Automatic carry-forward of the remaining balance after payment
- Restitution on cancellation or refund
- Optional extension of the expiry date on each partial use
- Protection against concurrent unpaid orders reserving the same balance
- History of movements on the coupon screen
- A list of active store credits with their balances
- Compatible with High-Performance Order Storage (HPOS)

## Requirements

| | |
|---|---|
| WordPress | 6.0 or later |
| PHP | 7.4 or later |
| WooCommerce | 7.0 or later |
| HPOS | Compatible |

## Limitations

Only **fixed cart discount** coupons are handled. Carrying a balance forward
makes no sense for a percentage or per-product coupon, so a coupon ticked but of
another type is ignored, and the coupon screen says so.

## Uninstalling

Deleting the plugin removes its settings. Coupon balances, flags and history are
**kept**: removing them would quietly turn every store credit back into an
ordinary coupon spent whole, changing real customer balances. Set
`dahu_store_credit_remove_data_on_uninstall` to a truthy value before deleting
to wipe everything.

## External services

This plugin does not contact any external service.

## License

MIT — see [LICENSE](LICENSE).
