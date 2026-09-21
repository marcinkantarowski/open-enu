# Skills

End-to-end checklists that turn a convention into a procedure. Canonical here and
vendor-neutral; `.claude/skills/` symlinks to this directory so Claude Code can read it.

| Skill | Use it when |
|-------|-------------|
| [`create-module`](create-module.md) | adding a feature - backend module, frontend layer, ACL, tests, docs, migration, Task Router row |
| [`add-endpoint`](add-endpoint.md) | adding a route - permission, command, functional test, OpenAPI, i18n keys |
| [`review-change`](review-change.md) | before proposing anything - the checklist a change must pass |

A skill is a procedure, not a reference. Where it needs to explain *why*, it links to
[`../docs/`](../docs/) rather than restating it, so there is one copy to keep true.
