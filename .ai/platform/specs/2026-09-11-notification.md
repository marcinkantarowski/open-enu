# Notification - the in-app feed and the mail behind it

**Status:** accepted · **Date:** 2026-09-11 · **Phase:** 7

## Problem

Things happen that a person needs to know about and is not looking at: a webhook endpoint
stops responding, an import finishes, somebody is invited. Without somewhere to put them,
each module invents its own - an email here, a banner there - and none of them can be
turned off, listed, or read later.

## Approach

A type registry plus one row per person. A module declares the notifications it raises in a
`NotificationProviderInterface` beside the code that raises them, and raises them through
the **kernel's** `NotifierInterface` - so raising one never means depending on this module.

`toUser()` and `toTenant()` are separate because they are genuinely different questions.
Which people count as "the workspace" is this module's problem, and a module raising a
notification should not have to answer it.

**The row stores the type and its arguments, never a rendered sentence.** The reader's
language is a property of the reader; a feed written in English at write time can never be
read in Polish (ADR-0020). The client translates the key.

## Rejected

- **A `notifications` column on the user.** Read state, per-type preferences and a feed that
  can be paginated are all rows, not a blob.
- **Rendering the message at write time.** Faster, and permanently monolingual.
- **Streaming the feed over SSE.** A notification is not urgent by definition - if it were,
  it would be an email - and a second open connection per tab costs more than a minute of
  latency. The bell polls.
- **Auditing read state through the command bus.** The bus exists for changes somebody might
  have to answer for; "opened their notifications" would add a row per glance and bury the
  trail that matters.
- **Storing an undeclared type.** It has no title key to translate, so the row would be
  permanently unreadable. Dropped with a log line instead.

## Surface

- API: `GET /api/notifications`, `POST /api/notifications/{id}/read`,
  `POST /api/notifications/read-all`
- UI: `/notifications`, and the bell in the shell header
- Permissions: `notification.view` - one, because a feed is personal and "whose" is never a
  parameter

## Tests that ship with it

- [x] Arch: the module holds `user_id`, so it must implement `GdprSubjectInterface` - the
      guardrail caught this on the first run after the entity was written
- [x] Functional: covered through the Webhook suite, which raises the one declared type

## Changelog

- 2026-09-11 - scaffolded and written
