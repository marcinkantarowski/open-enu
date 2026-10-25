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
#  which is the same reason the Nuxt apps poll. The polling is kernel/bin/
#  watch-source, which lists the tree once and then only stats it: `find` here
#  re-listed every directory every two seconds, about half a second a time on a
#  macOS bind mount, in each worker - and the API's requests queued behind it.
#
#  The restart is a SIGTERM, which Messenger honours by finishing the message in
#  hand first. Production does not use this file: an image's code cannot change
#  underneath it, and nothing there should be walking the source tree
#  (`make prod-check`).
# =============================================================================
set -u

TRANSPORT="${1:?usage: worker-dev.sh <transport>}"
POLL_SECONDS="${WORKER_WATCH_SECONDS:-2}"
CHANGED="/tmp/worker-${TRANSPORT}.changed"
WORKER=""
WATCHER=""

stop() {
  [ -n "$WATCHER" ] && kill -TERM "$WATCHER" 2>/dev/null
  [ -n "$WORKER" ] && kill -TERM "$WORKER" 2>/dev/null
  wait "$WORKER" 2>/dev/null
  exit 0
}
trap stop TERM INT

while true; do
  STARTED="$(date +%s)"
  php /app/bin/console messenger:consume "$TRANSPORT" --time-limit=3600 --memory-limit=256M -v &
  WORKER=$!

  # In the background and waited for, not run in the foreground: a trapped
  # signal interrupts `wait`, but waits for a foreground command to finish.
  php /app/kernel/bin/watch-source "$WORKER" "$STARTED" "$POLL_SECONDS" /app/src /app/kernel/src /app/config > "$CHANGED" &
  WATCHER=$!

  wait "$WATCHER"
  case $? in
    0) printf '[worker:%s] %s changed - restarting\n' "$TRANSPORT" "$(sed 's|^/app/||' "$CHANGED")"
       kill -TERM "$WORKER" 2>/dev/null ;;
    1) ;; # the worker left by itself
    *) printf '[worker:%s] the source watcher failed - edits will NOT restart this worker until it exits\n' "$TRANSPORT" >&2 ;;
  esac

  # Also reached when the worker left by itself (time or memory limit): the
  # loop starts the next one, so the container never needs restarting for it.
  wait "$WORKER" 2>/dev/null
done
