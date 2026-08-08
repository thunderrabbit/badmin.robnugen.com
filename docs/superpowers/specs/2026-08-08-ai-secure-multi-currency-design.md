# ai_secure: per-receipt currency

**Date:** 2026-08-08
**Status:** design approved, not yet implemented

## Problem

Nothing on a filed secure document records its currency. The record written by
`ai_secure/confirm_item.php` is `item, file, bucket, account_tag, category, view,
name, description, captured, orig` — no currency field.

Currency exists in only two weak places:

1. `ai_secure/index.php:9-10` reads `cash_active.json` to choose which quick
   category chips render. UI-only; never persisted.
2. `ai_secure/name_item.php:110` asks Claude for `"total + currency"` inside a
   free-prose English `description`. Unstructured and best-effort.

Rob moves from Japan to Australia and starts scanning AUD receipts. A filed
record is ambiguous by construction — `Needs: 🛒 Groceries` with
`account_tag: cash` says nothing about whether the amount was ¥1,200 or $12, so
the downstream YNAB agent must guess from prose. That is the failure being
fixed: plausible amounts landing in the wrong budget currency, silently.

Assumptions:

- **One currency per scanned receipt.**
- **One 📍active currency at a time** (Rob's constraint — he is in one country).

## Decisions

- Currency comes from the 📍active currency. Claude also reads the photo, and its
  read is used to **detect a mismatch**, not to choose.
- When the read agrees with the active currency, or is unreadable, the selector
  is **hidden** — zero taps, no screen real estate.
- A mismatch is **elevated**: the selector expands with Claude's read
  preselected, one tap to confirm or correct. Never blocking.
- Metadata is stored **split by currency**: one manifest per currency.
- Category chips and account chips both follow the single active currency.

## Flow

`/ai_secure/` shows the active currency read-only at the top, above the bucket
chips. It is not editable there; Rob changes it on `/cash_balance/`.

```
🇦🇺 AUD                                    change on 💵 cash →
──────────────────────────────────────────
Which bucket?    [receipts ✓] [bills_paid] [taxes_filed] [statements]
Which account?   [unknown ✓] [cash] [wise] [paypal]        <- SHARED + AUD
Which category?  [🛒 Groceries] [🍽️ Hunbun dates] …        <- AUD list, no union
Photo(s)         …
[ Name it ✨ ]
```

Stage 1 (`name_item.php`) sends the photos **and the active currency** to Claude.
Review then normally shows no currency UI at all — just description, name, and
Confirm.

### Selector states

| Claude read | Result |
|---|---|
| matches active | hidden — file as active currency |
| `""` (unreadable) | hidden — file as active currency |
| differs from active | **elevated**, Claude's read preselected, flagged |
| _(defensive)_ no active currency set | expanded, nothing selected, Confirm disabled |

The mismatch row is deliberate: a currency Rob is not currently using is either a
stale receipt he is catching up on (the read is right) or a misread `$`. Both
deserve one glance; neither deserves a block.

The last row should not occur in normal use — it guards a missing or hand-edited
`cash_active.json`.

Expanded shows all of `CASH_CURRENCIES` in config order, matching the cash board
row order.

### The `$` problem

`$` is ambiguous across AUD, USD, and NZD, all three of which are in
`CASH_CURRENCIES`. The prompt therefore names the active currency and asks for an
ISO 4217 code, instructing Claude to return `""` rather than guess when genuinely
ambiguous. Language, vendor, address, and GST-vs-consumption-tax wording usually
resolve it. Since `""` files as the active currency, an ambiguous `$` in
Australia correctly becomes AUD instead of a coin-flip against USD.

### Residual case

Rob picks a category in capture (AUD chips), then Claude flags the receipt as
JPY. On correcting the currency the chips re-render for JPY; the already-picked
category stays selected, since it validates against `SECURE_CATEGORIES_ALL`
regardless. Rare — stale receipts only — and visible rather than silent.

## Data shape

Every filed record — both the per-image `<image>.json` sidecar and the manifest
line — gains `currency`:

```json
{
  "item": "coles-milk-and-bread",
  "file": "receipts/2026-aug-08-coles-milk-and-bread.jpg",
  "currency": "AUD",
  "bucket": "receipts",
  "account_tag": "unknown",
  "category": "Needs: 🛒 Groceries",
  "view": null,
  "name": "coles milk and bread",
  "description": "Coles, milk and bread, $12.40, 8 Aug 2026",
  "captured": "2026-08-08T11:04:22+09:00",
  "orig": "IMG_1234.jpg"
}
```

Images are **not** moved: no currency level in the path, no change to
`secure_bucket_dir()`. The record carries the path and the currency together; the
manifest split does the rest.

### Split manifest

`SECURE_BIN_MANIFEST` is retired and replaced by a whitelisted path builder that
mirrors `cash_snapshot_path()` (`secure_config_sample.php:219`) — the idiom
already in this file — reusing `cash_currency_ok()` rather than inventing a
second vocabulary:

```php
function secure_manifest_path(string $cur): ?string
{
    return cash_currency_ok($cur) ? SECURE_BIN_ROOT . "/secure_manifest_{$cur}.jsonl" : null;
}
```

The split is not cosmetic. `name_item.php:225` calls
`recent_item_names(25, SECURE_BIN_MANIFEST)` to feed Claude Rob's naming style.
Against a single manifest, a Coles receipt in Adelaide is read while Claude is
primed on `lawson snacks` and `jr east suica charge`. Splitting fixes that: stage
1 reads the active currency's manifest, so the style examples are always from the
right country.

## Config restructure

`secure_common_categories()` stops taking an array and stops deduping — with one
active currency there is no union:

```php
function secure_common_categories(string $cur): array
{
    return secure_categories()['common'][$cur] ?? [];
}
```

Account tags gain the same per-currency shape:

```php
const ACCOUNT_TAGS_SHARED = ['unknown', 'cash', 'wise', 'paypal'];

const ACCOUNT_TAGS_BY_CURRENCY = [
    'JPY' => ['mufg-bank', 'mufg-card', 'paypay', 'google-wallet'],
    'AUD' => [ /* TODO Rob: fill on landing */ ],
];
```

Chips render as `ACCOUNT_TAGS_SHARED + ACCOUNT_TAGS_BY_CURRENCY[$active]`, with
`unknown` still first and the default. `account_tag_ok()` validates against the
union of SHARED and **every** currency's list, not just the active one — a
corrected currency or an older sidecar can legitimately carry another country's
tag. An empty per-currency list (AUD before Rob fills it) simply yields the
shared chips.

## Contract changes

- `cash_balance/set_active.php` becomes single-select: setting a currency clears
  the others (today it appends at line 48). The file format stays
  `{"active":[...],"updated":"..."}` with exactly one element, so existing
  readers keep working — they take `[0]`.
- `name_item.php` passes the active currency into the prompt, adds `"currency"`
  to the strict-JSON keys it requests, validates the result with
  `cash_currency_ok()`, and returns `currency` (a code, or `""`) in its response.
- `confirm_item.php` accepts `currency` as a POST param and validates it with
  `cash_currency_ok()`. `bucket`, `account_tag`, and `category` remain
  authoritative from the staging sidecar, as today.
- **Currency is the first field in this system that fails hard.** `account_tag`
  and `category` silently degrade to `'unknown'` / `''`
  (`name_item.php:146-157`); a missing or invalid currency at confirm time is a
  hard `fail()`. The client also disables Confirm without one — both, because the
  client is never trusted.

## Migration

The existing `secure_manifest.jsonl` is entirely JPY. Two one-off steps against
live data under `/home/thundergoblin/secure_bin/`, scripted here and **run by
Rob**:

1. `mv secure_manifest.jsonl secure_manifest_JPY.jsonl`
2. Backfill `"currency":"JPY"` into every existing per-image `<image>.json`
   sidecar and every line of the renamed manifest.

After this, nothing downstream ever sees a record with the field missing.

If more than one currency is 📍active in `cash_active.json` when the single-select
change ships, the first entry wins and the rest are dropped.

## Out of scope

Multi-currency receipts (one document, two currencies). Explicitly excluded by
the one-currency-per-receipt assumption.
