# Day 02 — Product pricing: immutable money and explicit invariants

## 1. Subject, time, and prerequisites

- **Day:** 02 / 60; foundation and product domain block.
- **Time:** 120 minutes: 20 investigate/design, 80 implement/test, 20 review/defend.
- **Prerequisites:** Day 1 Docker environment and PHPUnit suite working. Day 1 implementation received PASS in conversation; its interview defense remains outstanding and the progress table still says REVIEW. Do not treat that table as approval to mark it complete.
- **Scope:** extend this Laravel marketplace with its first reusable product pricing domain code. No endpoint, migration, merchant authentication, or external service today.

## 2. Marketplace user story

A merchant lists two units at EUR 19.99 each. Checkout must quote EUR 39.98 exactly. Another merchant lists products in RON. A future cart must never silently combine those currencies or accept a malformed price.

As the marketplace engineer, provide an immutable money value object and a small product quote operation that later catalog and checkout features can reuse. A quote is a calculation, not an inventory reservation or a promise that a price stays unchanged until payment.

## 3. Functional and nonfunctional requirements

### Functional contract

Design the names and signatures yourself, while satisfying this observable behavior:

- Support exactly EUR and RON through a backed currency enum. Both use two decimal places in this exercise. No conversion or exchange rates.
- Represent money internally using a nonnegative integer number of minor units and a currency. Zero is valid. A negative amount is rejected.
- Offer construction from minor units and from a decimal **string**. The decimal grammar is deliberately strict: `0` or a nonzero digit followed by digits, optionally followed by a dot and one or two digits. Thus `19`, `19.9`, `19.99`, and `0.00` are valid; signs, whitespace, commas, exponent notation, leading zeroes such as `01`, `.50`, and more than two fractional digits are invalid. Do not trim, round, or normalize invalid input into validity.
- Format an amount as a decimal string with exactly two digits after the dot, without locale separators. Example: 1999 EUR formats as `19.99`.
- Support equality by amount and currency, addition within the same currency, and multiplication by a nonnegative integer. Operations return new values and never change either operand.
- Reject mixed-currency addition explicitly, including when one operand is zero. Reject negative multipliers.
- Guard construction, parsing, addition, and multiplication against exceeding `PHP_INT_MAX`. Detect overflow before PHP promotes an arithmetic result to float; never clamp or wrap it. Extremely long digit strings must be rejected safely.
- Provide one small quote operation accepting a money unit price and integer quantity **1–100 inclusive**, returning money for the total. Quantity zero is valid for generic money multiplication but invalid for a product quote.
- Invalid input, currency mismatch, and overflow must fail with intentional exceptions. Decide whether standard exceptions suffice; document their contracts. Do not expose incidental driver errors or accidental return-type errors as the domain contract.

### Engineering constraints

- Use PHP 8.4, explicit types, `declare(strict_types=1)`, and readonly state where appropriate. Explain where strict typing is enforced and why it does not replace validation.
- Keep this code under a small product-domain namespace within `src/app/`, with corresponding PHPUnit tests under `src/tests/Unit/`. The pricing code must work without booting Laravel, querying PostgreSQL, or calling Redis.
- No floats in money parsing or arithmetic, including intermediate results. No money library, new package, repository interface, factory hierarchy, or generic currency framework. Use the existing stack.
- Avoid logging every invalid value at this layer. Explain which future application boundary would record quote failures, with what safe context.
- Do not add persistence, queues, locks, retries, or timeouts to a pure calculation. Explain why these concerns belong elsewhere when the quote eventually becomes an order.

## 4. Failure, edge-case, security, and concurrency scenarios

| Scenario | Required behavior |
| --- | --- |
| Price `19.99`, quantity 2 | Exactly 3998 minor units in the original currency. |
| `0.1` plus `0.2` in EUR | Exactly `0.30`, with no floating-point arithmetic. |
| Merchant submits `19.999`, `1e3`, ` 19.99`, or `01.00` | Explicit rejection, no silent rounding/coercion. |
| EUR plus RON | Explicit mismatch failure; original values remain unchanged. |
| Amount at `PHP_INT_MAX`, then add one | Overflow rejected; no float or corrupted result. |
| Huge decimal string or unit price times quantity overflows | Safe failure before producing a total; never return a partial or rounded quote. |
| Two callers share the same unit price object | Neither calculation can mutate the other's price. No locks needed for immutable values. |
| Merchant changes a stored price while a buyer checks out | Explain why today's object alone cannot ensure order-price consistency. State the future transaction/snapshot boundary; do not implement it today. |

Money safety is not authorization: explain why valid pricing input does not establish that a caller owns a product. There is no public write endpoint in this assignment.

## 5. Acceptance criteria and required tests

- [ ] Currency enum, immutable money value, and quote operation implement the contract above without framework coupling.
- [ ] Data-driven tests cover all valid and invalid decimal forms listed above, including empty input, negative strings, and an extremely long digit string.
- [ ] Round-trip formatting preserves the minor-unit value and always prints two fractional digits.
- [ ] Tests cover equality, currency mismatch, zero, multiplication by zero, negative multiplier rejection, and immutability after success and failure.
- [ ] Boundary tests prove `PHP_INT_MAX` minor units are accepted, adding zero succeeds, adding one fails, multiplying by one succeeds, and overflowing multiplication fails. Derive platform boundaries without floating-point arithmetic or hardcoding a 64-bit maximum into production code.
- [ ] Quote tests cover EUR 19.99 × 2, zero-priced products, quantities 1 and 100, rejection of 0, negative quantities and 101, and overflow with a valid quantity.
- [ ] Unit tests extend PHPUnit's base TestCase and run without Laravel bootstrap or external services. Existing Day 1 feature tests still pass when the stack is running.
- [ ] Tests assert the intended exception category and relevant value behavior, rather than private methods or exact incidental error messages.
- [ ] A short design note states invariants, exception choices, parsing approach, overflow strategy, supported currencies, and deferred checkout consistency concerns.

Do not implement a race test for a pure immutable value object. Defend why that is appropriate; later persistence work will require real concurrency tests.

## 6. Deliverables and Docker checks

Deliver your domain code, unit tests, `docs/architecture/0002-product-pricing.md`, and actual check output in `reviews/day-02.md`. Leave progress completion statuses unchanged until review and approval.

Run these from the **repository root**, using the existing root Compose entry point:

```bash
docker compose up -d
docker compose exec app php -v
docker compose exec app php artisan test --testsuite=Unit
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test
```

To demonstrate that unit tests do not boot Laravel, use PHPUnit directly with its existing autoloader bootstrap:

```bash
docker compose exec app vendor/bin/phpunit --testsuite=Unit
```

Do not stop shared services merely to prove independence; inspection of test inheritance and dependencies plus direct PHPUnit execution is sufficient for today. Do not claim checks passed unless you ran them.

## 7. Senior interview questions

1. Why use integer minor units instead of float or a decimal string everywhere? What changes for a currency with zero or three decimal places, and what belongs outside today's scope?
2. A caller without `strict_types` passes a numeric string into a method declaring an integer parameter. What can PHP coerce, and which invariants must your object enforce regardless? How do readonly properties and value equality differ from identity?
3. Two checkout requests read a price while a merchant updates it. What does immutability guarantee, what remains a database consistency problem, and when would you snapshot the amount and currency into the order? Would retries be safe before and after charging a provider?

## 8. Review checklist and definition of done

- [ ] Invariants hold through every public construction and operation path.
- [ ] Decimal grammar is enforced exactly; no float-based conversion or overflow checks.
- [ ] Overflow detection handles equality at the maximum and zero without division errors.
- [ ] Money is immutable; currency is retained; failure leaves operands unchanged.
- [ ] Domain code and unit tests have no Laravel/database/Redis dependency.
- [ ] Quote quantity policy is distinct from generic money multiplication.
- [ ] Existing health and timeout tests still pass; style checks pass.
- [ ] Evidence and design note exist; the learner can defend all three interview questions.

**Definition of done:** reviewed code satisfies the contract, Docker check output supports the result, and review issues a PASS or PASS WITH ISSUES. Silent rounding, mixed-currency arithmetic, unchecked overflow, or missing critical failure tests yields FAIL. The learner writes the implementation; ask for design feedback before asking for a solution.
