#!/bin/sh
# =============================================================================
#  The scheduler: the tasks that run on a clock rather than on a request.
# =============================================================================
#  A loop and not cron, deliberately. cron in a container needs its own daemon,
#  its own log plumbing and its own copy of the environment, and it reports
#  failures by email to a mailbox nobody reads. This writes to stdout, which is
#  where every other container's output already goes.
#
#  Tasks are idempotent and safe to miss: a container restart skips a run rather
#  than doubling one. Nothing here is allowed to be the only thing standing
#  between the system and correctness - if a task must not be missed, it is a
#  job on a queue, not a line in this file.
# =============================================================================
set -u

INTERVAL="${SCHEDULER_INTERVAL_SECONDS:-86400}"

run() {
  printf '[scheduler] %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
  # Never `set -e` around these: one failing task must not stop the others, and
  # a scheduler that dies on a transient error is one that silently stops.
  php /app/bin/console "$@" 2>&1 || printf '[scheduler] FAILED: %s\n' "$*"
}

printf '[scheduler] every %ss\n' "$INTERVAL"

while true; do
  run app:tenant:purge-unverified
  run app:attachment:sweep-orphans
  sleep "$INTERVAL"
done
