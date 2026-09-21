# Notification

Telling someone that something happened - in the app, and by email when the type asks for
it. One registry of types, one row per person, read state per person.

> Budget: 8 KB. A module that needs more explaining than that is too big -
> split it rather than writing more here. Checked by `make agents-budget`.

## Owns

- `Notification` - one thing one person should know about. Stores the **type and its
  arguments**, never a rendered sentence: the reader's language is a property of the reader,
  and a feed written in English at write time can never be read in Polish (ADR-0020).
- `PersistentNotifier` - decorates the kernel's logging notifier, so every
  `NotifierInterface` call written before this module existed now produces a real
  notification with no call site changing.

## Public contracts

Other modules may import **only** what is listed here (from `Contract/`).
Everything else is internal and may change without notice.

- _(none - the kernel's `NotifierInterface` is the contract; this module implements it.)_

## Events

_(none - this module is a consumer.)_

## Permissions

- `notification.view` - read and mark your own feed. There is no second permission: a feed
  is personal, so "whose" is never a parameter.

## Notes for agents

- **To raise one, depend on `OpenEnu\Kernel\Notification\NotifierInterface`** - never on
  this module. `toUser()` is "this concerns you personally"; `toTenant()` is "this concerns
  the workspace" and fans out to its members here, so the caller does not have to decide who
  that is.
- **Declare the type first**, in a `NotificationProviderInterface` in your own module.
  `Webhook\Service\WebhookNotifications` is the example. An undeclared type is dropped with
  a log line rather than stored: a row whose type has no declaration has no title key to
  translate, so it would be permanently unreadable.
- Channels are on the declaration. The feed is always included; email is opt-in, and worth
  it only when the point is to reach somebody who is *not* looking at the app.
- Read state is a service, not a command. The bus exists to audit changes somebody might
  have to answer for, and "opened their notifications" would add a row per glance and bury
  the trail that matters.
- The controller reads the user through `getUserIdentifier()` rather than type-hinting
  Identity's `User`: importing another module's entity is a build failure (ADR-0002), and
  the identifier is the uuid here by design.
