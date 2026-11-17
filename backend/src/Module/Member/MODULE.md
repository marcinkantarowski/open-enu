# Member

A tenant's people, administered (ADR-0025): who is in, at which role, reaching which units;
inviting someone; suspending and reactivating. It stores nothing of its own. Memberships and
accounts are Identity's, reached through `Identity\Contract\MembershipsInterface`; roles are
the Role module's, units the Unit module's.

> Budget: 8 KB. A module that needs more explaining than that is too big -
> split it rather than writing more here. Checked by `make agents-budget`.

## Owns

- The members API: `GET /api/members`, `POST /api/members` (invite),
  `POST /api/members/{id}/access` (role and units together, with the version read - a stale
  edit is a 409), `/suspend`, `/reactivate`.
- The invitation link: `GET` and `POST /api/auth/invitation` - public, the token is the
  credential, rate-limited like every public auth endpoint. A new account sets its password
  there and counts as verified; an existing one just joins.
- `Service/AccessGuard`, the rules, called from every handler:
  - nobody gives a role holding a permission they lack, nor changes someone whose role
    holds one - an admin neither makes nor touches an owner;
  - someone narrowed to some units gives only those units, never the whole tenant;
  - a tenant always keeps one active owner.

## Public contracts

Other modules may import **only** what is listed here (from `Contract/`).
Everything else is internal and may change without notice.

- _(none yet)_

## Events

Events this module emits, which other modules may subscribe to. This list is
cross-checked against `Event/` by `make docs:check`.

- _(none yet)_

## Permissions

Declared in `Acl/permissions.php`:

- `member.view` - see who is a member, with their role and units
- `member.invite` - invite people, at a role the inviter could give
- `member.manage` - change a member's role and units, suspend, reactivate

## Notes for agents

- **Rows are created through Identity and saved here.** `MembershipsInterface` changes
  memberships in memory and hands back new rows as `unsaved`; the handler persists and
  flushes, so the write stays inside the audited command (ADR-0017).
- An invitation grants nothing until accepted: the membership is `invited`, and an invited
  or suspended membership is not one a session can be in.
- Infrastructure this module needs (cache, storage, events, search, progress,
  flags) comes from the kernel - see `.ai/platform/PLAN.md` §6.9. Do not add a local version.
- Cross-module access goes through `Contract/` or an event, never a direct
  import. Enforced by `make arch`.
