#!/bin/sh
# =============================================================================
#  A queue worker that notices the code changed. Development only.
# =============================================================================
#  usage: worker-dev.sh <transport>
#
#  A worker is one long-lived PHP process: the classes it loaded at startup are
#  the ones it runs until it exits. Left alone, an edited handler keeps running
#  its old code, and a NEW handler does not exist at all - the message fails
#  with "No handler for message", is retried with a growing delay, and the
#  screen that is waiting for the job shows nothing. Both look like a bug in the
#  code that was just written.
#
#  So this watches the source and restarts the worker when it changes. Polling,
#  not inotify: file events do not cross a bind mount reliably on WSL2 or macOS,
#  which is the same reason the Nuxt apps poll.
#
#  The restart is a SIGTERM, which Messenger honours by finishing the message in
#  hand first. Production does not use this file: an image's code cannot change
#  underneath it, and nothing there should be walking the source tree
#  (`make prod-check`).
# =============================================================================
set -u

TRANSPORT="${1:?usage: worker-dev.sh <transport>}"
POLL_SECONDS="${WORKER_WATCH_SECONDS:-2}"
STAMP="/tmp/worker-${TRANSPORT}.started"
WORKER=""

changed() {
  find /app/src /app/kernel/src /app/config -type f \
    \( -name '*.php' -o -name '*.yaml' -o -name '*.yml' -o -name '*.json' \) \
    -newer "$STAMP" -print -quit 2>/dev/null
}

stop() {
  [ -n "$WORKER" ] && kill -TERM "$WORKER" 2>/dev/null
  wait "$WORKER" 2>/dev/null
  exit 0
}
trap stop TERM INT

while true; do
  touch "$STAMP"
  php /app/bin/console messenger:consume "$TRANSPORT" --time-limit=3600 --memory-limit=256M -v &
  WORKER=$!

  # The interval is how often the tree is looked at, not a wait for anything to
  # become ready - there is no state to poll for instead.
  while kill -0 "$WORKER" 2>/dev/null; do
    file="$(changed)"
    if [ -n "$file" ]; then
      printf '[worker:%s] %s changed - restarting\n' "$TRANSPORT" "${file#/app/}"
      kill -TERM "$WORKER" 2>/dev/null
      break
    fi
    sleep "$POLL_SECONDS"
  done

  # Also reached when the worker left by itself (time or memory limit): the
  # loop starts the next one, so the container never needs restarting for it.
  wait "$WORKER" 2>/dev/null
done
