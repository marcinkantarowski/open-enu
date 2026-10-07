#!/bin/sh
# =============================================================================
#  The scheduler: the tasks that run on a clock rather than on a request.
# =============================================================================
#  A loop and not cron, deliberately. cron in a container needs its own daemon,
#  its own log plumbing and its own copy of the environment, and it reports
#  failures by email to a mailbox nobody reads. This writes to stdout, which is
#  where every other container's output already goes.
#
#  Two cadences. Housekeeping runs once per SCHEDULER_INTERVAL_SECONDS (a day).
#  The loop itself turns every SCHEDULER_TICK_SECONDS (a minute), for the tasks
#  whose whole job is to notice that a moment has arrived.
#
#  A module adds a task by declaring it, not by editing this file: one line in
#  `backend/src/Module/<Name>/clock`, either
#
#      minute app:something:run
#      daily  app:something:sweep
#
#  Tasks are idempotent and safe to miss: a container restart skips a run rather
#  than doubling one, and a tick that takes longer than a minute delays the next
#  one instead of overlapping it. So a task must decide what is DUE from its own
#  data ("everything scheduled up to now"), never from "this is the 14:30 run".
#  Nothing here is allowed to be the only thing standing between the system and
#  correctness - if a task must not be missed, it is a job on a queue.
# =============================================================================
set -u

INTERVAL="${SCHEDULER_INTERVAL_SECONDS:-86400}"
TICK="${SCHEDULER_TICK_SECONDS:-60}"
MODULES="${SCHEDULER_MODULES_DIR:-/app/src/Module}"

run() {
  printf '[scheduler] %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
  # Never `set -e` around these: one failing task must not stop the others, and
  # a scheduler that dies on a transient error is one that silently stops.
  php /app/bin/console "$@" 2>&1 || printf '[scheduler] FAILED: %s\n' "$*"
}

# A task read from a file is one string; xargs turns it into arguments the way a
# shell would, quotes included, without this script evaluating anything.
run_line() {
  printf '[scheduler] %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1"
  printf '%s\n' "$1" | xargs php /app/bin/console 2>&1 || printf '[scheduler] FAILED: %s\n' "$1"
}

# Every module's tasks of one cadence. Read on each pass, so a module added to a
# running development stack is picked up without restarting this container.
module_tasks() {
  for file in "$MODULES"/*/clock; do
    [ -f "$file" ] || continue
    # `|| [ -n "$cadence" ]` keeps a last line that has no newline after it.
    while read -r cadence task || [ -n "$cadence" ]; do
      case "$cadence" in ''|'#'*) continue ;; esac
      [ "$cadence" = "$1" ] && [ -n "$task" ] && run_line "$task"
    done < "$file"
  done
}

printf '[scheduler] ticking every %ss, housekeeping every %ss\n' "$TICK" "$INTERVAL"

since_housekeeping="$INTERVAL"

while true; do
  if [ "$since_housekeeping" -ge "$INTERVAL" ]; then
    run app:tenant:purge-unverified
    run app:attachment:sweep-orphans
    module_tasks daily
    since_housekeeping=0
  fi

  module_tasks minute

  sleep "$TICK"
  since_housekeeping=$((since_housekeeping + TICK))
done
