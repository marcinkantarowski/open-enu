---
tags: [guardrails, phpstan, architecture]
date: 2026-09-11
phase: 2b
---
# A guardrail firing on legitimate code is a decision point, not a bug

## What happened
`PersistenceBoundaryRule` - "writes happen in a command handler, nowhere else" - was added
to make audit coverage structural. It immediately failed on two things that were not
mistakes:

- `App\Module\Demo\Setup\DemoSetup`, seeding a tenant's defaults.
- `App\Module\Demo\Tests\Functional\DemoApiTest`, building the fixture it asserts on.

`RawSqlRule` then failed on the same test, which reads `internal_note` straight from the
driver specifically to prove the column holds ciphertext.

## The wrong instincts, and why
Three responses were available, two of them bad:

1. **Mute per-file** (`@phpstan-ignore`). Cheap, invisible in review, and the exemption
   carries no reason - so the next person cannot tell a considered exception from a
   shortcut. `AGENTS.md` bans it for exactly this.
2. **Weaken the rule** - for instance allow writes in any `Service/`. That deletes the
   guarantee to fix two files.
3. **Exempt the category, in the rule, with the reasoning written down.** Correct.

## What the exemptions are, and why each is justified
Bootstrap paths sit outside the request lifecycle: no actor to attribute a write to, no
client-supplied version to lock against, no caller awaiting a result.

| Path | Why |
|---|---|
| `Migrations/` | Doctrine's own generated output |
| `Fixtures/` | loading data is the entire purpose |
| `Setup/` | runs once at tenant provisioning; routing it through the bus means a command per module's default rows, for an audit entry with a null actor |
| `Tests/` | a test builds the state it asserts on; forcing that through the bus tests the bus, not the behaviour |

`RawSqlRule` exempts `Tests/` for a sharper reason: verifying that an `#[Encrypted]` column
really holds ciphertext **requires** bypassing the ORM, because the ORM is what would
decrypt it. Refusing raw SQL there would make the encryption guarantee unverifiable.

## The rule
When a guardrail fires on code you believe is correct, the question is never "how do I get
past this?" - it is **"is this a category the rule should never have covered, or am I about
to do the thing the rule exists to prevent?"**

Answer it in the rule, in a comment, once. Then it applies to everyone, and the reasoning
survives.

## Where else this applies
- Every rule added from here: expect it to catch something legitimate on its first run.
- `RawSqlRule`'s `#[Unscoped]` attribute is the same idea taken further - an exemption that
  requires a written reason at each site and is greppable across the codebase.
- Adding a category exemption means adding a test that the rule still fires *outside* it.
