# Observability

What this project ships, what it deliberately does not, and how to turn the rest on.

## What is already here

**Structured logs with correlation.** Monolog writes JSON carrying `request_id`,
`tenant_id`, `user_id`, `command` and `route`. `RequestIdStamp` carries the id into workers,
and every response echoes it as `X-Request-Id` - which is what a user quotes when reporting
a problem, and what ties a browser error to a line in a worker's log twenty minutes later.

**Mail, captured.** Mailpit takes every outbound message in dev at `https://mail.${DOMAIN}`.
Nothing leaves the machine, and "did the email go, and what did it say in Polish?" is a
click rather than a guess.

**Queue depth and dead letters.** The operator console's Workers page shows what is waiting
and what has failed; `messenger:failed:show` and `messenger:failed:retry` inspect and replay
the Doctrine failure transport.

**The scheduler's output** goes to stdout like every other container, so `make logs` is the
whole story.

## Error tracking (Sentry)

`SENTRY_DSN` already flows from `.env` to all four applications. **The SDK is deliberately
not installed**: a boilerplate that ships a vendor client nobody configured adds weight to
every project built from it, and adding a production dependency is an `Ask First` here.

To turn it on:

```bash
make composer CMD="require sentry/sentry-symfony"       # backend
npm install --workspace frontend @sentry/nuxt           # and manager / landing
```

The DSN is already in the environment on both sides, so neither needs new configuration.
Two things worth doing at the same time:

- **Send the request id** as a Sentry tag. Without it a Sentry issue and a log line about the
  same failure cannot be joined.
- **Scrub the encrypted columns and the refresh cookie** from the payload before it leaves.
  An error report is an outbound copy of application state.

## Traces (OpenTelemetry)

`OTEL_EXPORTER_OTLP_ENDPOINT` is read and, when empty, nothing is exported - which is the
default. Tracing earns its keep once there is a second service to trace *between*; with one
API and a worker, the correlated logs above answer the same questions for none of the setup.

When that changes, the span boundaries worth instrumenting first are already the seams this
design names: the command bus, the outbox handler, and the webhook delivery job.

## What to reach for, by question

| Question | Where |
|---|---|
| What happened to *this* request? | `X-Request-Id` from the response, then `make logs-api` |
| Did the email send, and in which language? | `https://mail.${DOMAIN}` |
| Is anything stuck? | Operator console → Workers, or `make console CMD="messenger:failed:show"` |
| Did we deliver that webhook? | Settings → Webhooks → Deliveries, per attempt with the response code |
| Why did a background job stop? | `messenger:failed:show {id} --transport=failed -vv` |
| Did the nightly tasks run? | `make logs` - the `scheduler` container prints each run |
