# Webhook

Outbound only. A tenant registers a URL, subscribes it to event names, and this module
signs and delivers every matching domain event - with retries and a log of what happened.

Inbound webhooks are deliberately absent: receiving someone else's events is a per-provider
problem (signature scheme, replay window, payload shape), and a generic inbound endpoint is
a generic security hole.

> Budget: 8 KB. A module that needs more explaining than that is too big -
> split it rather than writing more here. Checked by `make agents-budget`.

## Owns

- `WebhookEndpoint` - a URL, the event names it wants, and an **encrypted** signing secret
  shown exactly once at creation. Tenant-scoped and versioned.
- `WebhookDelivery` - one event's attempt history against one endpoint: payload, status,
  attempt count, response code and a truncated response body. The log is the product:
  "did you send it?" is the only question anyone asks about webhooks.

## Public contracts

Other modules may import **only** what is listed here (from `Contract/`).
Everything else is internal and may change without notice.

- _(none - nothing subscribes to this module; it subscribes to everything.)_

## Events

_(none - this module consumes events rather than emitting them.)_

## Permissions

- `webhook.view` - see endpoints and the delivery log
- `webhook.manage` - add an endpoint, deactivate one, redeliver

## Notes for agents

- **Nothing here knows what events exist.** `WebhookPublisher` subscribes to `DomainEvent`
  itself, so a module that adds an event gets webhooks with no change here and no change
  there. Subscription lives in the tenant's endpoint row.
- **Signing is Standard Webhooks** (standardwebhooks.com): `webhook-id`,
  `webhook-timestamp`, `webhook-signature: v1,<base64 HMAC-SHA256>` over
  `{id}.{timestamp}.{payload}`. A published scheme, because the receiver is somebody else's
  code and should be able to verify with an off-the-shelf library.
- **Retries are Messenger's**, not this module's. `DeliverWebhookHandler` throws, and the
  `jobs` transport's `retry_strategy` (5 attempts, exponential) does the backoff. Re-queuing
  by hand would be a second, worse retry mechanism running beside the real one.
- A delivery stays `pending` until its attempts are spent, then becomes `failed`. Showing a
  queued retry as a failure sends people chasing a non-problem.
- Endpoints are **deactivated, never deleted**: the delivery log points at them, and
  "which URL did we send that to?" is what an incident asks.
- `https://` only. The payload is signed, not encrypted - plain HTTP publishes every event
  to anyone on the path.
- The publisher **must not throw**. It runs on the outbox worker beside `ClientBroadcaster`,
  and a webhook nobody is listening for must not fail the event that carried it.
