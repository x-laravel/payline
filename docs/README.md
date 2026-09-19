# Payline Documentation

- [Reading Order](#reading-order)
- [Pages](#pages)
- [Conventions](#conventions)

Payline sits between a Laravel application and one or more payment providers. The application always talks to the same API; each provider is a separate driver package that implements small, operation specific contracts.

## Reading Order

Start with [Installation](installation.md) to get a working setup, then read [Architecture](architecture.md) to understand what Payline records and why. After that, go to whichever task you have in front of you.

If you are writing a driver package rather than an application, read [Architecture](architecture.md) and then [Writing a Driver](writing-a-driver.md).

## Pages

| Page | Type | Answers |
|------|------|---------|
| [Installation](installation.md) | Setup guide | How do I get Payline running in an application? |
| [Architecture](architecture.md) | Explanation | What does Payline record, and which class does what? |
| [Payments](payments.md) | How-to | How do I charge or authorize a card? |
| [Follow-up Operations](follow-up-operations.md) | How-to | How do I capture, refund, void or reconcile a payment? |
| [Callbacks and Webhooks](callbacks-and-webhooks.md) | Explanation | How does Payline receive 3DS returns and provider notifications? |
| [Gateway Routing](gateway-routing.md) | Explanation | How is a gateway chosen when the application does not name one? |
| [Writing a Driver](writing-a-driver.md) | How-to | How do I add support for a new provider? |
| [Configuration](configuration.md) | Reference | Which configuration keys exist and what do they do? |
| [Events](events.md) | Reference | Which events are dispatched and what do they carry? |
| [Database](database.md) | Reference | Which tables, columns and statuses exist? |
| [Decisions](decisions/README.md) | Decision records | Why is the package built this way? |

## Conventions

Amounts are integers in the minor unit of the currency. `10000` in `TRY` is 100.00 TRY. Payline never converts between currencies and never rounds.

Currencies are three letter ISO codes and are compared case insensitively.

Identifiers written as `payline_payments`, `PendingPayment` or `payline.routes.enabled` refer to a database table, a class and a configuration key respectively.

Narrative pages describe current behaviour only. When a design choice needs a justification, it lives in a [decision record](decisions/README.md).
