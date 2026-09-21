# Agent interfaces

Two of them, for two different jobs: reading the codebase, and using the running product.

| Job | Interface |
|---|---|
| "Does something like this already exist?" | [`.ai/inventory.json`](../../inventory.json) |
| "Call the API on a customer's behalf" | `app:mcp:serve` - MCP over stdio |

---

## The inventory

A committed, machine-readable index of every module and what it declares: entities,
commands, events, contracts, services, repositories, routes, permissions and searchable
fields.

It exists for one failure mode - **reinvention**. A second date formatter, a third way to
page a list, written because finding the first one meant already knowing its name. A
directory listing does not answer "is there already something for this?"; a flat index of
every declared name does.

```bash
make inventory         # regenerate it
make inventory-check   # fail if the committed copy is stale (runs in `make arch`)
```

Committed rather than generated on demand, because the point is to be *in context before
anything is written*. A file an agent has to run a command to produce is a file it will not
have.

It is built from the filesystem and the router, not by instantiating services: an inventory
that can only be produced by a container that boots is an inventory that is missing exactly
when something is broken.

### The similarity hint

`make inventory` also prints pairs of names close enough to be worth a look -
`InvoiceFormatter` beside `InvoiceFormater`. This is **advisory, permanently**. Two helpers
doing the same job rarely share a signature and often do not share a name, so it can only
ever be a hint; a blocking version would be wrong often enough to get muted, which costs
more than the duplicates it would catch.

---

## The MCP server

```bash
# 1. a scoped credential - never an ambient one
make console CMD="app:apikey:create --tenant=acme --name=agent \
  --permission=example.view --permission=example.manage"

# 2. see what a client would be offered
make console CMD="app:mcp:serve --list"
```

Point a client at it:

```json
{
  "command": "docker",
  "args": ["compose", "exec", "-T", "api",
           "php", "bin/console", "app:mcp:serve", "--key=sk_…"]
}
```

It speaks JSON-RPC over stdio: `initialize`, `tools/list`, `tools/call`.

### What it offers, and what it deliberately does not

Tools are **generated** from three sources, each contributing what only it knows:

- `backend/openapi.json` - which operations are *published* (ADR-0012). A route that exists
  but is not in the committed spec is not offered.
- the router - the path, the method, and which segments are parameters.
- `#[IsGranted]` - the permission a key must hold, which appears in every tool's
  description.

Only operations guarded by a **declared module permission** are offered, and that one rule
does all the filtering. An API key is a scoped tenant credential: it cannot sign in, cannot
refresh a browser session, and is refused by the operator firewall on its `aud` claim
(ADR-0007). Offering those endpoints would only produce an agent that spends its turns
discovering which of its tools are permanently 401.

The server calls the API over HTTP rather than dispatching in-process. That is the whole
point: the agent is a *client*, and goes through the same firewall, tenant scope and
permission checks as any other machine caller. An in-process version would hand an agent
more access than the credential it was given.

A 4xx comes back as tool content with `isError: true`, not as a protocol error - the model
needs to *read* the refusal. "You lack `example.manage`" is the most useful sentence it can
get.

### The known gap

The committed spec records which operations exist but not yet what they accept, so a tool's
`body` is a free-form object. Inventing a schema here would mean a client validating against
this repository's guess rather than the server's actual rules - which is worse than no
schema, because it fails in the client where the reason is invisible. Enriching
`openapi.json` with request schemas is the fix, and it belongs to the endpoints, not here.

`backend/tests/Arch/McpToolCatalogueTest.php` holds the generation to its promises.

---

## The checks an agent should know about

| Command | What it is for |
|---|---|
| `make check` | the inner loop, under 60s - run after every edit |
| `make ci` | the gate - everything, including the browser suite |
| `make arch` | every architectural guardrail on its own |
| `make route-coverage` | every endpoint is exercised by a real test |
| `make dead-code` | unused TypeScript (blocking), unreachable PHP (advisory) |
| `make selftest` | proves the guardrails fail when broken |
| `make modules` | what module discovery actually resolved |

The rule underneath all of them: **when you add a convention, add the check that fails when
it is broken, in the same change.** A convention without a check is gone in three months.
