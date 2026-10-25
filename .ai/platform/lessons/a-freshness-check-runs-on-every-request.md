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
through the bind mount. A stat there is close to free; a listing is not.

The first fix skipped the listing while every directory kept its build-time mtime, and it
was wrong: **through Docker Desktop's file sharing a directory's mtime can stay stale for
hours after a file appears in it**, while its listing is current at once. A new service
class was simply not registered. Measured, not assumed: the host showed the directory's new
mtime, both containers the old one, minutes later. So the globs are now checked together
by `ThrottledGlobResources`, at most once every two seconds, with the time of the last clean
check kept in the cache directory (a volume, where mtimes hold). A new file is noticed up to
two seconds late; `/health` went from 0.30 s to 0.05 s, and
`kernel/tests/DependencyInjection/ThrottledGlobResourcesTest.php` holds the cases.

**The rule, again:** on a bind mount, trust a listing and a file's own stat - never a
directory's mtime as a summary of what is in it. The dev workers' change watcher
(`OpenEnu\Kernel\Dev\SourceWatcher`) made the same mistake in its first version; a real
file added on the host caught it, where a unit test in a temporary directory inside the
container - where mtimes behave - had passed.
