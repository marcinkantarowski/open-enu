# A freshness check runs on every request

**What happened.** On a macOS host every API call in the dev stack took about 0.8 s, a 404
included, and two browser tests started timing out once a project grew to some twenty
modules. Nothing was wrong in the code being requested: handling took 0.05 s. Booting the
kernel took the rest, and almost all of it was one line in the kernel's module discovery:

```php
$container->addResource(new DirectoryResource($modulesDir, '/module\.yaml$/'));
```

It meant "rebuild when a manifest changes". What it did was walk the whole module tree on
every request and stat every file and directory in it - the pattern only decides which
files *count*, not which are visited. Through the Docker Desktop bind mount that was
0.65-0.85 s for about a thousand files, and it grew with every file any module added.

**Why it is easy to miss.** In debug, Symfony checks every resource the container recorded
on every request, before routing. The cost never shows in a test (a test kernel is built
once), never shows on Linux (a stat is nearly free there), and never shows in production
(no debug, no check). It shows on the host most developers use, as "the dev stack is slow",
which is easy to accept.

**The rule.** A resource added to the container is paid for on every dev request, so it
must watch exactly the files it depends on:

- a known set of files: a `FileResource` each;
- "a file of this name appeared or vanished": a `GlobResource` with a non-recursive
  pattern (it fingerprints the list of paths, not their contents);
- never a `DirectoryResource` on a source tree. It is recursive, with or without a pattern.

Module discovery now does the first two, and
`kernel/tests/DependencyInjection/ModuleDiscoveryFreshnessTest.php` fails if editing a
module's code - rather than its manifest - invalidates the container.

**How to recognise it.** A dev request that is slow even when it answers 404. Time
`$kernel->boot()` separately from `$kernel->handle()`; if boot dominates, call `isFresh()`
on each entry of `var/cache/dev/*Container.php.meta` and see which one costs.

**The second time (2026-10-24).** After that fix a dev request still took about 0.3 s, and
the same `isFresh()` walk found the rest: 196 `GlobResource`s from service discovery -
`registerClasses()` adds one per module directory (`Service/`, `Handler/`, `Security/`, ...)
- each listing its directories again on every request, about a millisecond a directory
through the bind mount. A stat there is close to free; a listing is not. So a glob now sits
behind `GuardedGlobResource` (added by `GuardGlobResourcesPass`): while every directory
under it keeps its build-time mtime nothing can have appeared or vanished, and the listing
is skipped; otherwise the glob decides, as before. `/health` went from 0.30 s to 0.07 s,
and signing in to the app's first screen from 3.4 s to 1.4 s.
`kernel/tests/DependencyInjection/GuardedGlobResourceTest.php` holds the cases where the
guard must hand the question back. Known cost: Docker Desktop shows a directory's new mtime
up to a second after a file appears in it, so a request inside that second may still see
the old container.
