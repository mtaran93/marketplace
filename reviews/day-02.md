# Day 02 — Evidence (awaiting review)

Collected 2026-11-19, from the repository root, against the running `marketplace` stack. This file holds
execution evidence only; no verdict has been issued.

Note: the implementation was written by Claude Code at the learner's explicit request ("build it").
The interview defense (questions 1–3 in `challenges/day-02.md`) is still the learner's to give.

| Check | Command | Result |
| --- | --- | --- |
| PHP version | `docker compose exec app php -v` | `PHP 8.4.27 (cli) (built: Nov  5 2026 19:02:14) (NTS)` |
| Unit tests, plain PHPUnit (no Laravel boot) | `docker compose exec app vendor/bin/phpunit --testsuite=Unit` | PHPUnit 12.5.44: **OK (82 tests, 122 assertions)** in 0.010s |
| Unit suite via artisan | `docker compose exec app php artisan test --testsuite=Unit` | **82 passed (122 assertions)** |
| Full suite (Day 01 feature tests included) | `docker compose exec app php artisan test` | **92 passed (145 assertions)** |
| Style | `docker compose exec app vendor/bin/pint --test` | PASS (35 files) |
| No framework / float in domain | `grep -rnE 'Illuminate\|Laravel\|float\|round\(' src/app/Product src/tests/Unit` | Comment and test-name matches only; no code references |

## Independence from Laravel

- Both test classes extend `PHPUnit\Framework\TestCase`, not `Tests\TestCase`.
- `src/phpunit.xml` bootstrap is `vendor/autoload.php`, so plain `vendor/bin/phpunit` loads only the
  Composer autoloader.
- `src/app/Product/Pricing/*` imports nothing outside the PHP core (`InvalidArgumentException`,
  `OverflowException`, `DomainException`).

## Coverage map (acceptance criteria → tests)

| Criterion | Tests |
| --- | --- |
| Valid/invalid decimal grammar | `MoneyTest::test_parses_valid_decimal` (10 cases), `test_rejects_malformed_decimal` (26 cases: empty, signs, whitespace, `\n`, `19.999`, `1e3`, `01`, `01.00`, `.50`, commas, non-ASCII digits…) |
| Extremely long digit string | `test_rejects_extremely_long_digit_string_as_overflow`, `test_rejects_extremely_long_malformed_string_as_invalid` |
| Round-trip and two-digit formatting | `test_formats_with_exactly_two_fraction_digits`, `test_format_then_parse_round_trips` (incl. `PHP_INT_MAX`) |
| Equality, mismatch (incl. zero), immutability | `test_equality_compares_amount_and_currency`, `test_rejects_mixed_currency_addition_and_leaves_operands_unchanged` (4 cases), `test_add_returns_new_value_and_leaves_operands_unchanged`, `test_state_cannot_be_modified` |
| Multiplication by zero / negative | `test_multiplies_by_nonnegative_integer`, `test_rejects_negative_multiplier_and_leaves_value_unchanged` |
| `PHP_INT_MAX` boundaries | `test_accepts_zero_and_php_int_max_minor_units`, `test_adding_zero_to_max_succeeds`, `test_adding_one_to_max_overflows…`, `test_multiplying_max_by_one_succeeds`, `test_multiplication_at_exact_boundary…`, `test_multiplying_max_by_two_overflows…`, `test_parses_exactly_php_int_max_minor_units`, `test_rejects_decimal_one_minor_unit_above_php_int_max` |
| `0.1 + 0.2` | `test_adds_without_floating_point_error` → `0.30` |
| Quote | `ProductQuoteTest`: 19.99 × 2 = 3998, zero price, quantities 1/100 accepted, 0/−1/101/`PHP_INT_MIN`/`PHP_INT_MAX` rejected, overflow at quantity 100, largest safe unit price at quantity 100 |

Design note: `docs/architecture/0002-product-pricing.md`.

## Round 2 — interview findings fixed (2026-11-19)

Implemented by Claude Code at the learner's explicit request ("please do what day 2 is asking and do it
correctly and i will look over it"), after the learner's own attempt left the suite red
(`minorUnitsPerMajor()` returned `100 * digits`). Awaiting the learner's review; no verdict issued.

Changes:
- `Currency::minorUnitsPerMajor()` is now `10 ** minorUnitDigits()`: one source of truth.
- `Money::parse` omits the fraction group for 0-digit currencies (`{1,0}` does not compile); a `preg_match`
  failure throws `LogicException` (our bug) instead of `InvalidArgumentException` (caller's input).
- `Money::format` prints no dot for 0-digit currencies.
- Docblocks restored (invariants, failure contract, overflow reasoning) and updated for scale per currency.
- New `CurrencyTest`: hand-written ISO expectations, coverage guard over `Currency::cases()`,
  `10 ** digits` check, format→parse round trip (0, 1, 123456, `PHP_INT_MAX`) and format shape per currency.
- ADR 0002: per-currency grammar/format, single source of truth, `LogicException` row, `ofMinor` coercion
  boundary rule, note that the 0/3-digit branches have no test with a real currency yet.

| Check | Command | Result |
| --- | --- | --- |
| Unit, plain PHPUnit | `docker compose exec app vendor/bin/phpunit --testsuite=Unit` | **OK (93 tests, 141 assertions)** |
| Full suite | `docker compose exec app php artisan test` | **103 passed (164 assertions)** |
| Style | `docker compose exec app vendor/bin/pint --test` | PASS (36 files) |
| 0/3-digit branches (throwaway copy with JPY=0, KWD=3; not committed) | `docker compose run --rm --no-deps -v <scratch>:/s app php /s/run.php` | JPY `500`→500/`"500"`, `500.5` rejected; KWD `1.5`→1500/`"1.500"`, `1.5000` rejected; `PHP_INT_MAX` round trip true for EUR, RON, JPY, KWD |

Known limitation: the 0/3-digit branches are verified only by that throwaway run; they gain permanent
coverage automatically when a real currency with that scale is added to the enum.

## Verdict — PASS WITH ISSUES (2026-11-20)

Re-check before recording: `docker compose exec app php artisan test` → **103 passed (164 assertions)**.

Final interview (on the code as signed off):

| Question | Score | Gap |
| --- | --- | --- |
| Q1 `LogicException` vs input errors, HTTP mapping | Adequate+ | Said 400 instead of 422; did not describe the client-facing response |
| Q2 What the round-trip test proves | Weak | Claimed it catches `100 * digits`; it does not (format and parse share the wrong factor) |
| Q3 `ofMinor` vs `parse` entry points | Weak | Questioned whether `ofMinor` is needed; DB `BIGINT` and Stripe-style integer amounts enter via `ofMinor` |

Earlier rounds: first interview Weak / Adequate− / Adequate−; retake Adequate− / Adequate / Adequate+.

Unresolved issues (carried forward):
1. Defense incomplete: Q2 and Q3 not reasoned independently. Re-explain both in two sentences each before Day 03.
2. Implementation mostly written by Claude Code at the learner's explicit request; Day 02 is "learned with help", not built independently.
3. Low: `CurrencyTest` round-trip amounts (0, 1, 123456, `PHP_INT_MAX`) have no remainder ≥ 100, so the round trip cannot detect a wrong `minorUnitsPerMajor()`. Add `99` or `199`. Learner to fix.

Lessons to keep: a passing round-trip proves consistency, not correctness; data shape decides the entry point
(decimal string → `parse`, integer minor units → `ofMinor`); validate at the boundary, then build domain values;
no DB transaction around a payment-provider call; idempotency key saved before the first attempt.
