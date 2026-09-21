---
tags: [messenger, transactions, observability, workers]
date: 2026-09-11
phase: 7
---
# A handler that records its own failure records nothing

## What happened
The webhook delivery handler was written the obvious way: POST, and if the receiver refuses,
write the attempt to the delivery log and throw so Messenger retries.

```php
$this->record($delivery, $status, $body);   // attempts++, response code, body
throw new \RuntimeException(sprintf('Webhook endpoint answered %d.', $status));
```

Live, against a receiver that refused five times in a row, the worker logged this:

```
Sending for retry #1 using 2131 ms delay
Sending for retry #2 using 6003 ms delay
Sending for retry #3 using 17838 ms delay
```

and the delivery row said:

```
 status  | attempts | response_code | body
---------+----------+---------------+------
 pending |        0 |               |
```

Zero attempts, no response code, nothing. The log that exists *specifically* to answer "did
you send it?" was empty about five real HTTP requests.

## Why it happened
The bus is configured with `doctrine_transaction`, so every handler runs inside a database
transaction. The write and the throw are in the same one. The throw rolls it back - and
takes the record of the attempt with it.

Both halves are correct on their own. Wrapping handlers in a transaction is right: it makes
a handler atomic. Throwing is right: it is how Messenger's retry strategy is triggered, and
re-queueing by hand would be a second, worse retry mechanism beside the real one. They are
simply mutually exclusive, and nothing says so.

## The rule
**Inside a transaction, a write followed by a throw did not happen.** So anything that must
survive a failure has to be written outside the failing transaction.

Messenger gives you the seam: `WorkerMessageFailedEvent` fires *after* the rollback. The
handler throws an exception carrying what the log needs (status code, truncated body), and a
listener persists it in a transaction of its own.

That also settles where such an exception class lives: it is part of the contract between a
handler and its failure listener, not an internal detail of either.

## How it was caught
Not by a test - the functional suite recorded the attempt and asserted on it, passing,
because PHPUnit's kernel does not run the Messenger middleware stack. It was caught by
pointing a webhook at a URL that refuses and reading the row afterwards: the worker's log and
the delivery log disagreed about how many requests had been made.

The suite now replays `WorkerMessageFailedEvent` itself, so the two cannot drift again.

## Where else this applies
- Any handler that wants to record *why* it failed: delivery logs, import error reports,
  retry counters, "last attempted at" columns.
- Any counter incremented on a path that then throws - a rate limiter written this way
  would never count a rejection.
- The inverse is fine and needs no ceremony: recording a **success** and returning normally
  commits with the transaction, which is why the delivered path in that same handler is a
  plain `flush()`.
