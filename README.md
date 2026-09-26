# Erpy for Sage

An **[Erpy](https://justinholt.com/plugins/craft-erpy)** connector for Sage Intacct, Sage 200, Sage X3 and Sage Business Cloud Accounting.

Free. Erpy itself is the paid part — it owns the sync engine, the identity map, the field
mapping, the queue, the dead letters and the log. This package's whole job is to translate one
vendor's API into Erpy's canonical documents.

## Installing

```sh
composer require justinholtweb/craft-erpy-sage
php craft plugin/install erpy-sage
```

Then add a connection under **Erpy → Connections** and pick it from the ERP list.

## What you need to know

### Four connectors, one add-on

Sage sells four unrelated products under one name. Installing this gives you all four in the connector picker; pick the one you actually run.

### Sage Intacct

The XML gateway. Two sets of credentials at once — a sender id issued to the integration and a Web Services user in the customer’s company — plus a Web Services Authorization for the sender id, without which correct credentials still fail. Paging is by `resultId`, not by offset.

### Sage 200

Needs three things on every request: a bearer token, the Ocp-Apim subscription key, and an `X-Site` header. A missing X-Site is the usual reason an authenticated request returns nothing.

### Sage X3

Addresses representations directly — ITMMASTER, BPCUSTOMER, SORDER — and is strongly folder-scoped: the same credentials against two folders are two different datasets.

### Sage Accounting

A bookkeeping product, not an ERP: no sales orders, no warehouses, no stock ledger. This connector deliberately does not advertise inventory or order status. A completed order is posted as a **sales invoice**, which is what merchants actually ask for.

## What it syncs

The connection screen shows exactly which entities and directions this connector supports —
it is generated from the connector's own declaration, so it can never advertise a flow it has
not implemented.

## A field is wrong

Correct it on the mapping screen: a rule whose target is a canonical field (`sku`, `unitPrice`,
`customerCode`) overrides what the connector read, before anything reaches Commerce. No fork,
no wait for a release.

## Documentation

The full documentation for this add-on is at
https://justinholt.com/plugins/craft-erpy/docs/sage, and Erpy's own is at
https://justinholt.com/plugins/craft-erpy/docs.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+, and Erpy 5.0+.

## Support

justin@justinholt.com

## License

The Craft License. See `LICENSE.md`. Erpy for Sage is free: no editions and no licence key of its own.
It needs a licensed copy of [Erpy](https://justinholt.com/plugins/craft-erpy), which is the paid part.
