# Long-form guides

`AGENTS.md` holds hard rules and routing, under a 32 KB budget. Procedure that does not fit
lives here, and the Task Router points at it.

Each guide is written by the phase that builds its subject - a guide written ahead of the
code documents what someone intended, which is worse than no guide.

| Guide | Read it when |
|---|---|
| [`platform-services.md`](platform-services.md) | **before** adding any infrastructure to a module |
| [`module-development.md`](module-development.md) | adding or changing a module |
| [`extension-surfaces.md`](extension-surfaces.md) | extending something without modifying it |
| [`tenancy.md`](tenancy.md) | anything touching scope, isolation or `runUnscoped()` |
| [`auth.md`](auth.md) | sessions, tokens, realms, permissions, impersonation |
| [`commands-and-audit.md`](commands-and-audit.md) | writing data, and what the trail records |
| [`events-and-outbox.md`](events-and-outbox.md) | announcing something, or doing work later |
| [`i18n.md`](i18n.md) | any user-facing string |
| [`frontend-conventions.md`](frontend-conventions.md) | anything in the three Nuxt apps |
| [`landing.md`](landing.md) | changing the marketing site - it ships as a "coming soon" template |
| [`testing.md`](testing.md) | writing a test, or wondering which suite it belongs in |
| [`env-vars.md`](env-vars.md) | adding configuration, or chasing a derived env file |
| [`observability.md`](observability.md) | logs, correlation ids, health, queue depth |
| [`deployment.md`](deployment.md) | going to a server |
| [`agent-interfaces.md`](agent-interfaces.md) | the inventory and the MCP server |
| [`troubleshooting.md`](troubleshooting.md) | something in the stack is broken - symptoms first |
