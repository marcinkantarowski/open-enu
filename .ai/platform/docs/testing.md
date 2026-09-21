# Testing

Six suites, each answering a question the others cannot. The split is not organisational -
`make check` runs the fast ones after every edit, `make ci` runs all of them plus the
browser.

| Suite | Command | Answers |
|---|---|---|
| `kernel` | `make test-kernel` | does the framework work, and do its arch rules still fire? |
| `unit` | `make test-unit` | pure logic, no container, no database |
| `arch` | `make test-arch` | what is *missing* - coverage a single-file analyser cannot see |
| `security` | `make test-security` | tenant isolation, realm isolation, credential handling |
| `search` | `phpunit --testsuite search` | a system property: declaration → listener → SQL |
| `functional` | `make test-functional` | real HTTP, real database - the only kind that proves an endpoint works |
| browser | `make e2e` | what functional tests cannot see: CORS, cookies, SSE, uploads |

---

## Writing a functional test

Extend `App\Tests\Support\ApiTestCase`. It owns the fixture every one of them needs - a
tenant, a person in it, a session - because it had been written four times, identically,
and each copy had drifted.

```php
final class ProjectApiTest extends ApiTestCase
{
    /** Wiped before each test, in deletion order. Listed, never inferred. */
    protected function fixtures(): array
    {
        return [Project::class];
    }

    public function testAProjectIsCreated(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/projects', ['name' => 'Harbour refit']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Harbour refit', $this->json()['name']);
    }
}
```

Available: `givenATenant()`, `givenAPendingTenant()`, `givenIAmSignedIn($role, $email)`,
`makeUser($email, $role, $tenantId, $verified)`, `givenAnApiKey($permissions)`,
`givenIAmAnOperator()`, `reloadTenant()`, and `post/put/patch/get/delete` + `json()`.

One kernel serves the whole test (`disableReboot()`): a reboot between requests invalidates
every reference the test holds - including `ScopeContext`, so a fixture written through the
stale manager is encrypted under a scope the next request does not have.

---

## What to assert

**Never assert only a status code.** A crashed process usually still returns *something*,
and a 200 has already hidden a fatal error here once -
[`status-codes-alone-are-not-a-health-check`](../lessons/status-codes-alone-are-not-a-health-check.md).

**Re-read from the database when the point is that something was written.** An assertion
against an object already in the identity map passes whether or not any SQL ran; that is
exactly how a silently-discarded flush stayed hidden here -
[`unflushed-work-does-not-survive-rununscoped`](../lessons/unflushed-work-does-not-survive-rununscoped.md).

**Never mock a collaborator whose rules are what you are testing.** Caches, storage and
transports all have in-memory implementations that enforce the real constraints -
[`test-contracts-against-the-real-implementation`](../lessons/test-contracts-against-the-real-implementation.md).
Mock the things that genuinely live outside the process: an HTTP receiver, a clock.

**Assert the refusals.** Most of the value in these suites is in what the endpoint says no
to: another tenant's row is *not found* rather than forbidden; a member may read but not
manage; a replayed token fails.

---

## Every endpoint has a test, and it is measured

A listener records every `_route` the suite hits; afterwards, `router ∖ hit` must be empty.
Grepping the tests for route names would measure whether somebody wrote the name down -
this measures what actually ran, and it notices when a test is deleted.

```bash
make route-coverage
```

A route that genuinely cannot have one carries `#[NoTestRequired(reason: '…')]` and is
reported as an exemption, never counted as coverage. There are currently none.

---

## The browser suite

Playwright, against the real stack, for the things a `KernelBrowser` cannot see: a test
client never asks the browser's permission, so CORS, cookie attributes, `EventSource`
credentials and exposed headers are all invisible to PHPUnit -
[`a-test-client-never-asks-for-permission`](../lessons/a-test-client-never-asks-for-permission.md).

```bash
make e2e-fixtures   # the accounts it signs in as (idempotent)
make e2e
```

It runs in `make ci`, never in `make check`.

Do not assert on transient UI. A progress bar that disappears on completion makes
"is the bar visible?" a test of worker speed; assert the completion toast, which only fires
from the bar's own `@done`.

---

## Proving the guardrails

```bash
make selftest
```

Copies the tree, **breaks** each guardrail in the copy, and asserts that the check fails.
A guardrail nobody has ever seen fail is a guardrail nobody can trust - and several here
were caught doing nothing by exactly this.

---

## The database

```bash
make test-db         # create and migrate the isolated test database
make test-db-reset   # drop and rebuild the schema from scratch
```

Tests run against a separate database, migrated the same way production is - so a migration
that does not apply cleanly fails the suite rather than the deploy.
