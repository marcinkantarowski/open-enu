#!/usr/bin/env bash
# Context budgets (.ai/platform/PLAN.md §12.1). A file past its budget is a design signal, not a
# formatting problem: AGENTS.md past 32 KB is silently truncated by tooling, and the
# rules in the truncated part stop applying without any error.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

rc=0
check() { # <path> <max-bytes> <why>
  local f="$1" max="$2" why="$3"
  [ -f "$ROOT/$f" ] || { log_skip "$f (does not exist yet)"; return 0; }
  local n; n=$(wc -c < "$ROOT/$f")
  local pct=$(( n * 100 / max ))
  if [ "$n" -gt "$max" ]; then
    log_fail "$f is ${n}B, over its ${max}B budget" "$why"; rc=1
  elif [ "$pct" -ge 85 ]; then
    log_warn "$f is ${n}B - ${pct}% of its ${max}B budget"
  else
    log_ok "$f ${n}B (${pct}% of ${max}B)"
  fi
}

log_step "Context budgets"
check AGENTS.md 32768 "move long-form procedure into .ai/platform/docs/ and link it from the Task Router"

while IFS= read -r f; do
  check "${f#$ROOT/}" 8192 "a module needing more than 8KB of explanation is too big - split it"
done < <(find "$ROOT/backend/src/Module" -name MODULE.md 2>/dev/null | sort)

# ── module size ─────────────────────────────────────────────────────────────
# A module is the unit an agent holds in working memory (.ai/platform/PLAN.md §12.1). Over the
# line is a design signal, not a documentation problem: the fix is to split it.
#
# `Example` gets a much tighter budget than the rest because it is read on EVERY
# task, not only when working on it. The number is also the module's own
# argument - if one of every platform concern fits in it, a cross-cutting concern
# here costs about a line.
#
# 850, raised once from the 800 PLAN §12.1 set before the module existed. Phase 6
# measured it at exactly 800 for fourteen platform services; Phase 7 added search
# as a fifteenth thing it must demonstrate. The SPECIFICATION grew, not the
# module's waste - which is the only reason this number may ever move, and it
# needs a line in the changelog saying so.
MODULE_LOC=2500
MODULE_FILES=40
EXAMPLE_LOC=850

# Migrations are excluded, deliberately. The budget measures what an agent has to
# hold in working memory; a migration is an append-only ledger nobody reads to
# understand a module, and it grows forever. Counting them would mean a module
# eventually fails this check purely from its own history, which would teach
# people to raise the number rather than to split the module.
module_files() { # <dir>
  find "$1" -name '*.php' -not -path '*/Migrations/*' 2>/dev/null
}

module_loc() { # <dir>
  module_files "$1" -print0 2>/dev/null | tr '\n' '\0' \
    | xargs -0 -r grep -hcve '^\s*$' -e '^\s*\*' -e '^\s*//' -e '^\s*/\*' \
    | awk '{s+=$1} END {print s+0}'
}

log_step "Module size"
while IFS= read -r dir; do
  name="$(basename "$dir")"
  loc=$(module_loc "$dir")
  files=$(module_files "$dir" | wc -l)

  if [ "$name" = Example ]; then
    if [ "$loc" -gt "$EXAMPLE_LOC" ]; then
      log_fail "Example is ${loc} lines, over its ${EXAMPLE_LOC}-line budget (tests included)" \
        "it is read on every task, so every line here is paid for on every task" \
        "cut the demonstration, not the comments - the comments are what it is for"
      rc=1
    elif [ $(( loc * 100 / EXAMPLE_LOC )) -ge 85 ]; then
      # Warned before it fails, as the byte budgets are: the next person to add
      # a demonstration should know they are spending the last of the budget.
      log_warn "Example is ${loc} lines - $(( loc * 100 / EXAMPLE_LOC ))% of its ${EXAMPLE_LOC}-line budget"
    else
      log_ok "Example ${loc} lines ($(( loc * 100 / EXAMPLE_LOC ))% of ${EXAMPLE_LOC}), ${files} files"
    fi
    continue
  fi

  if [ "$loc" -gt "$MODULE_LOC" ] || [ "$files" -gt "$MODULE_FILES" ]; then
    log_fail "$name is ${loc} lines across ${files} files, over ${MODULE_LOC}/${MODULE_FILES}" \
      "a module past this is two modules; splitting it is the fix, not a bigger budget"
    rc=1
  else
    log_ok "$name ${loc} lines, ${files} files"
  fi
done < <(find "$ROOT/backend/src/Module" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sort)

# ── kernel service size ─────────────────────────────────────────────────────
# The kernel is read by every agent working on any module, and its whole claim
# is that each platform service is a THIN contract. A file drifting past this
# cap means an implementation is growing where a module should be - which is how
# a framework turns into an application nobody can replace.
KERNEL_FILE_LOC=260
KERNEL_TOTAL_LOC=4500

log_step "Kernel size"
over=0
total=0
while IFS= read -r f; do
  loc=$(grep -cve '^\s*$' -e '^\s*\*' -e '^\s*//' -e '^\s*/\*' "$f" 2>/dev/null || echo 0)
  total=$(( total + loc ))
  if [ "$loc" -gt "$KERNEL_FILE_LOC" ]; then
    log_fail "${f#$ROOT/} is ${loc} lines, over the ${KERNEL_FILE_LOC}-line cap for a kernel file" \
      "platform services are contracts, not implementations - if this one needs that much code," \
      "the behaviour probably belongs in a module that implements the contract"
    over=$((over + 1)); rc=1
  fi
done < <(find "$ROOT/backend/kernel/src" -name '*.php' 2>/dev/null | sort)

if [ "$total" -gt "$KERNEL_TOTAL_LOC" ]; then
  log_fail "the kernel is ${total} lines, over its ${KERNEL_TOTAL_LOC}-line budget" \
    "every agent reads this package; growth here is paid on every task"
  rc=1
elif [ "$over" -eq 0 ]; then
  pct=$(( total * 100 / KERNEL_TOTAL_LOC ))
  log_ok "kernel ${total} lines (${pct}% of ${KERNEL_TOTAL_LOC}), largest file under ${KERNEL_FILE_LOC}"
fi

echo
[ $rc -eq 0 ] && log_ok "all budgets within limits" || log_fail "fix the files above"
exit $rc
