#!/usr/bin/env bash
# =============================================================================
#  ShellCheck over every script in the repository.
# =============================================================================
#  This project is a lot of shell, and the shell is the part that runs against
#  real servers with root. The failure modes it catches - an unquoted expansion,
#  a `cd` that silently did not happen, a masked exit status - are exactly the
#  ones that turn "provision a box" into "delete the wrong directory".
#
#  Run through Docker rather than requiring a local install: Docker is already a
#  hard requirement here, and a linter that is optional is a linter that is not
#  run.
#
#  A run that cannot happen is a FAILURE, never a pass. The first version of
#  this script treated "no output" as "clean", so a Docker error and a clean
#  tree were indistinguishable - the same shape as the Phase 1 smoke test that
#  reported a dead API as healthy.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

IMAGE="koalaman/shellcheck:stable"

log_step "ShellCheck"

command -v docker >/dev/null 2>&1 || die "docker is required to run shellcheck"

mapfile -t FILES < <(cd "$ROOT" && find scripts docker -name '*.sh' -not -path '*/node_modules/*' | sort)
[ ${#FILES[@]} -gt 0 ] || die "found no shell scripts to check - this script is in the wrong place"

OUT="$(mktemp)"; trap 'rm -f "$OUT"' EXIT

# -S warning: style notes are opinions, warnings are bugs. Raising this to
# `info` would flood the output and train people to ignore it.
docker run --rm -v "$ROOT:/mnt" -w /mnt "$IMAGE" -S warning -x "${FILES[@]}" >"$OUT" 2>&1
status=$?

case "$status" in
  0)
    log_ok "${#FILES[@]} scripts clean at -S warning"
    ;;
  1)
    # Exit 1 is the tool's own code for "found something" - the only non-zero
    # status that means it actually ran.
    cat "$OUT" >&2
    echo
    log_fail "shellcheck found problems in the scripts above" \
      "each one links to an explanation; fix the code rather than adding a disable directive"
    exit 1
    ;;
  *)
    cat "$OUT" >&2
    echo
    log_fail "shellcheck could not run (exit $status)" \
      "a linter that cannot run is not a linter that passed" \
      "check that docker can pull and mount: docker run --rm $IMAGE --version"
    exit 1
    ;;
esac
