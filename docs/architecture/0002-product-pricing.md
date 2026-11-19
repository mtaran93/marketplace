# ADR 0002 — Product pricing: immutable integer money

- **Status:** Proposed (2026-11-19), pending Day 02 review
- **Code:** `src/app/Product/Pricing/` (`Currency`, `Money`, `CurrencyMismatch`, `ProductQuote`); tests in `src/tests/Unit/Product/Pricing/`.

## Context

Catalog and checkout need exact prices that can be added and multiplied safely. Floats can't represent
`0.1` exactly. A decimal string everywhere would need its own arithmetic. Merchants price in EUR or RON,
and a cart must never mix the two without anyone noticing.

## Decision

- **Representation:** `final readonly class Money { int $minor; Currency $currency; }`. The amount is
  a nonnegative integer count of minor units (cents/bani). The constructor is private, and all entry
  points (`ofMinor`, `zero`, `parse`, `add`, `multiply`) validate first.
- **Currencies:** a backed enum `Currency` with only `EUR` and `RON`, both with 2 minor-unit digits. There is
  no conversion and no currency registry. A new currency is a code change that forces a choice of exponent.
  `minorUnitDigits()` is the single source of truth; `minorUnitsPerMajor()` is derived as `10 ** digits`
  so the two can never disagree (a hand-maintained `100` next to `3` would silently misprice by 10×).
- **Invariants:** `minor >= 0`; `minor <= PHP_INT_MAX`, and it always stays an int, never a float; the currency never changes;
  arithmetic only combines equal currencies; operations return new instances and never change their inputs.
- **Parsing:** `Money::parse(string, Currency)` accepts only `\A(0|[1-9][0-9]*)(?:\.([0-9]{1,N}))?\z`,
  where N is the currency's minor-unit digits (2 for EUR/RON). For a 0-digit currency the fraction
  group is omitted entirely, because `{1,0}` does not compile.
  `\z` rejects a trailing newline (which `$` would accept). `[0-9]` accepts only ASCII digits. Nothing is trimmed,
  rounded, or normalised. The integer part is checked against `(string) PHP_INT_MAX` by length and then
  `strcmp` before the `(int)` cast. The value is then built with checked `× minorUnitsPerMajor` and checked `+ fraction`.
  No float appears at any step, and a 100 000-digit string fails fast with `OverflowException`.
- **Overflow strategy:** check *before* the operation, using only integers:
  `a > PHP_INT_MAX - b` for addition; `n !== 0 && a > intdiv(PHP_INT_MAX, n)` for multiplication.
  This is exact for nonnegative operands, accepts results equal to the maximum, and never divides by zero.
  It depends on `PHP_INT_MAX`, not on a hardcoded 64-bit value. Results are never clamped or wrapped.
- **Formatting:** `format()` returns `intdiv(minor, perMajor) . '.' . remainder padded to N digits`, and just
  the integer for a 0-digit currency. It uses no locale
  separators, and `parse(format(x))` equals `x` for every valid amount, including `PHP_INT_MAX`.
- **Quote:** `ProductQuote::total(Money $unit, int $quantity)` enforces the product rule
  quantity ∈ [1, 100], then calls `Money::multiply`. `multiply` still accepts 0 because it is a general operation.

## Exception contract

| Situation | Exception | Rationale |
| --- | --- | --- |
| Malformed decimal, negative amount, negative multiplier, quantity outside 1..100 | `InvalidArgumentException` | The caller passed a value outside the domain. The SPL type is enough, and boundaries map it to 422. |
| EUR combined with RON (including zero) | `CurrencyMismatch extends DomainException` | A broken business rule or inconsistent data, not bad input. It is a named type so checkout can catch it separately. |
| A result would exceed `PHP_INT_MAX` minor units | `OverflowException` | It is a different category from malformed input. It is unrealistic for real prices, so seeing it means abuse or a bug. |
| The parse pattern itself fails to compile or run (`preg_match` returns `false`) | `LogicException` | A bug in our code, not bad input. It must surface as a 500 and be logged, never as a 422 that blames the caller. Not reachable with today's currencies. |

Tests assert exception *classes* and resulting values, never message text. Messages never include
the rejected input, so echoing them cannot leak data or reflect attacker-controlled strings.

## Strict types vs validation

`declare(strict_types=1)` only applies to calls *made from* a file that declares it. A caller without it
can pass `"19"` to an `int` parameter, and PHP coerces it. `int` types also say nothing about sign or range.
So types guard the shape of a value, and the explicit checks guard the domain: `minor >= 0`, the
multiplier is at least 0, and quantity is between 1 and 100. Decimal input is a `string` on purpose, so the grammar can be enforced exactly.

## Observability

This layer does not log. A pure value object has no request context, and logging every rejected
value would flood the logs with user input. The future quote/checkout HTTP or command boundary will
record failures: the exception class, product id, merchant id, currency, quantity and request/correlation
id. It will not record raw submitted strings beyond a length cap. Mismatch and overflow will be counted as metrics, since they point to bugs or abuse.

## Concurrency and deferred checkout consistency

`Money` is immutable, so two callers can share a unit price without locks: neither can change it.
That is why there is no race test today. A pure value has no shared mutable state to race on.

Immutability does **not** keep the price consistent while an order is placed. A buyer can read
`19.99` while a merchant updates the stored price to `24.99`. The order must therefore **snapshot**
`unit_price_minor`, `currency` and `quantity` into its order lines. That snapshot is written in the same
database transaction that creates the order. It reads the product row under the right isolation level or with
`SELECT … FOR UPDATE`, or checks the version the buyer saw and rejects a stale quote. Retries are safe before
the payment-provider call. After a charge, they need an idempotency key, so a retry cannot charge twice. Persistence, locks,
timeouts and retries belong to that application/infrastructure layer (Days 11–30), not to a calculation.

A valid price is not authorization. `Money::parse` says nothing about whether the caller owns the
product whose price they are setting. That check belongs to a policy at the future write endpoint (Days 31–40).

## Consequences

- (+) Exact arithmetic, explicit failures, no framework coupling; the tests run in about 10 ms without Laravel.
- (+) The quantity rule is separate from the general arithmetic, so each can change independently.
- (−) Parsing and formatting support 0 or 3 decimal places (JPY, BHD), but no such currency exists yet, so
  those branches only run once one is added. `CurrencyTest` runs the format→parse round trip and the
  `10 ** digits` check for every `Currency::cases()` entry, so a new currency is tested automatically.
  No test-only currency was added to reach them today.
- (−) The maximum depends on the platform (`PHP_INT_MAX`). This is acceptable for the targeted 64-bit images.
- (−) `ofMinor(int)` called from a file without `strict_types` silently truncates `"19.99"` to 19 (PHP emits
  only a deprecation). Boundary rule: user-supplied decimals are validated by a Form Request and enter via
  `Money::parse`; controllers declare `strict_types`. Enforced when the first write endpoint is built.
