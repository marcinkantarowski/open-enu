# Updating a project from the platform

A project is a renamed copy of the platform. This is how it follows the platform afterwards:
kernel fixes, platform modules, scripts, the compose files and everything under
`.ai/platform/`.

```bash
make platform-update                                   # to the platform's current commit
make platform-update REF=v1.4.0                        # to a tag or any other ref
make platform-update FROM=git@github.com:acme/platform.git   # the first time, or to change source
```

`FROM` is a local checkout or a git URL. It is remembered in `.project.json`
(`platform_source`), next to the commit the project has reached (`platform_ref`), so after
the first run the bare command is enough. `make init` records both when a project is created
from a fresh clone.

## What it does

The platform's own diff cannot be applied to a project: `make init` rewrote the slug, the
name and the domain throughout, so the two no longer agree on what any line says. Copying
files across by hand means redoing that rename by hand, differently each time.

So the update is a three-way merge between versions that are spelled the same way:

1. Export the platform at the commit the project last had, and at the one it wants.
2. Run each export through **its own** `init`, with this project's name.
3. Apply the difference between the two to the project.

Using each version's own `init` matters. When `init` itself changes - it once renamed
things inside `.ai/platform/` and now does not - the difference in what it renames is part
of the update, and arrives with it.

## What you get

- A file the project never changed is updated silently.
- A file both sides changed is merged; where they changed the same lines it is a
  **conflict**, marked in the file, and the command exits non-zero naming each one. Edit it,
  then `git add` it.
- `backend/openapi.json`, `ui-kit/types/api.d.ts` and `.ai/inventory.json` are generated
  from the project's own code. On a conflict the project's copy is kept and the command
  says to regenerate: `make openapi types inventory`.
- Everything is **staged and nothing is committed**. `git diff --cached` is the whole
  update, and `git reset --hard` abandons it.

Then prove it the usual way: `make check`, `make ci`.

## What it refuses

- **Uncommitted changes.** The update has to be the only thing in the diff you review.
- **The platform's own repository.** There is nothing to update it from.
- **A commit the platform does not have.** `platform_ref` must name a commit in the source.
  If the platform's history was rewritten, or the commit lived on a branch that was
  squash-merged and deleted, say where the project stands now: `BASE=<commit>`.

## A project made before this existed

It has no `platform_ref`, so the first run asks for one:

```bash
make platform-update FROM=../platform BASE=<commit>
```

`BASE` is the platform commit the project **already matches** - the one it was copied from,
or the last one whose changes were carried across by hand. The command suggests the newest
platform commit older than the project's first, but only suggests: starting from the wrong
commit applies the wrong diff, which is worse than being asked.

When the project already matches the target, nothing changes and the commit is recorded.

## Changing the platform from inside a project

Do not. A fix to the platform is made in the platform's repository - following its rules,
with its docs and its check - merged there, and then brought here with this command. A
platform file edited in a project is a conflict waiting for the next update, and a fix no
other project gets. `.ai/platform/` in particular is replaced by updates and never edited
in a project ([ADR-0023](../adr/0023-platform-and-project-knowledge-are-separate-trees.md)).
