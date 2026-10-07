# A worker runs the code it started with

**What happened.** A new module added a job and its handler. The request that queues the job
answered `202` with a job id, the progress bar appeared, and nothing else ever did. The
functional tests were green, because they call the handler directly. The browser test timed
out waiting for a result.

The worker log had the answer:

```
Error thrown while handling message ...ImportContactsJob. Sending for retry #1
Error: "No handler for message ...ImportContactsJob"
```

The worker had been running for forty minutes. It was started before the handler existed,
and a PHP process does not learn about classes written after it booted.

**Why it is easy to miss.** Everything else in the dev stack picks an edit up by itself: PHP-FPM
starts a fresh process per request, and the Nuxt apps hot-reload. A queue worker is the one
long-lived PHP process, so it is the one place where "I saved the file" and "the code is
running" are different statements. The same gap exists for an *edited* handler, and that
case is worse: nothing fails, the old behaviour simply continues, and the person debugging
it is reading code that is not the code being executed.

The same shape as a frontend module: editing a file in a Nuxt layer hot-reloads, adding a
layer does not, because the list of layers is read once at startup.

**The rule.** Anything that boots once and runs for a long time must be restarted by the
thing that changes its code, not by the person who changed it.

- Development workers run under `docker/api/worker-dev.sh`, which polls the source tree and
  restarts the worker on a change. `make prod-check` fails if a dev worker calls
  `messenger:consume` directly.
- `make module` restarts the frontend, because a new layer is only discovered at startup.
- Production is the opposite case and stays that way: an image's code cannot change
  underneath a worker, so nothing there watches anything.

**How to recognise it.** A change that works in a test and not in the running stack, where
the difference between the two is the age of a process. Check the process's uptime before
reading the code again.
