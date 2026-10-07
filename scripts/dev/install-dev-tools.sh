#!/usr/bin/env bash
# =============================================================================
#  make installdevtools [DEPLOY=1]
# =============================================================================
#  Installs what check-tools.sh asks for, from the same table (lib/tools.sh):
#  the tools `make builddev` cannot run without, and with DEPLOY=1 the ones
#  staging and production need as well.
#
#  It installs only what is missing, so it is safe to re-run, and it finishes by
#  running check-tools - the installer does not get to grade its own work.
#
#  What it will not do:
#    • install docker. That choice is the host's (see lib/tools.sh).
#    • anything on a package manager it does not know. It names the tool and the
#      manual step, and exits non-zero, rather than half-installing.
#
#  It needs sudo on Linux, and asks for it once, up front and visibly: a
#  password prompt halfway through a quiet install looks like a hang.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$HERE/../lib/log.sh"; . "$HERE/../lib/os.sh"; . "$HERE/../lib/tools.sh"

WITH_DEPLOY="${DEPLOY:-}"
kind="$(os_kind)"

case "$kind" in
  linux|wsl2)
    command -v apt-get >/dev/null 2>&1 || die "apt-get not found" \
      "this installer knows apt (Debian, Ubuntu) and Homebrew" \
      "install by hand what 'make check-tools' lists, then re-run it"
    PM=apt ;;
  macos)
    command -v brew >/dev/null 2>&1 || die "Homebrew not found" "install it from https://brew.sh, then re-run"
    PM=brew ;;
  *) die "unsupported host: $kind" "install by hand what 'make check-tools' lists" ;;
esac

# ── what is missing ─────────────────────────────────────────────────────────
log_step "Looking for missing tools"
packages=(); manual=()
while IFS= read -r row; do
  bin="$(tool_field "$row" 1)"; when="$(tool_field "$row" 2)"
  [ "$when" = deploy ] && [ -z "$WITH_DEPLOY" ] && continue
  if command -v "$bin" >/dev/null 2>&1; then log_ok "$bin"; continue; fi
  if [ "$PM" = apt ]; then pkg="$(tool_field "$row" 3)"; else pkg="$(tool_field "$row" 4)"; fi
  if [ -n "$pkg" ]; then
    packages+=("$pkg"); log_info "$bin  ->  will install $pkg"
  elif [ "$PM" = brew ] && [ "$bin" = dig ]; then
    log_ok "$bin (ships with macOS)"
  else
    manual+=("$bin: $(tool_field "$row" 5)")
  fi
done < <(host_tools)
[ -z "$WITH_DEPLOY" ] && log_skip "deploy tools not requested (make installdevtools DEPLOY=1 adds dig, rclone, age, gh, rsync)"

watches="$(inotify_watches)"
fix_inotify=""
if [ "$PM" = apt ] && [ "$watches" -lt 262144 ] 2>/dev/null; then
  fix_inotify=1; log_info "fs.inotify.max_user_watches = $watches  ->  will raise to 524288"
fi

# ── install ─────────────────────────────────────────────────────────────────
rc=0
if [ ${#packages[@]} -gt 0 ] || [ -n "$fix_inotify" ]; then
  if [ "$PM" = apt ]; then
    SUDO=""
    if [ "$(id -u)" -ne 0 ]; then
      command -v sudo >/dev/null 2>&1 || die "sudo not found and not running as root"
      log_step "Asking for sudo (to install: ${packages[*]:-nothing}${fix_inotify:+, and raise the inotify limit})"
      sudo -v || die "sudo was refused" "run this in a terminal where you can type the password: make installdevtools"
      SUDO=sudo
    fi
    if [ ${#packages[@]} -gt 0 ]; then
      log_step "Installing ${packages[*]}"
      $SUDO apt-get update -qq || log_warn "apt-get update failed - installing from the existing package lists"
      if $SUDO env DEBIAN_FRONTEND=noninteractive apt-get install -y "${packages[@]}"; then
        log_ok "installed ${#packages[@]} package(s)"
      else
        log_fail "apt-get install failed" "the output above names the package"; rc=1
      fi
    fi
    if [ -n "$fix_inotify" ]; then
      log_step "Raising fs.inotify.max_user_watches"
      if echo 'fs.inotify.max_user_watches=524288' | $SUDO tee /etc/sysctl.d/60-inotify.conf >/dev/null \
         && $SUDO sysctl -q -p /etc/sysctl.d/60-inotify.conf; then
        log_ok "fs.inotify.max_user_watches = $(inotify_watches)"
      else
        log_warn "could not raise the inotify limit - Nuxt hot reload may die with three apps running"
      fi
    fi
  else
    log_step "Installing ${packages[*]}"
    if brew install "${packages[@]}"; then log_ok "installed ${#packages[@]} formula(e)"
    else log_fail "brew install failed" "the output above names the formula"; rc=1; fi
  fi
else
  log_ok "nothing to install"
fi

# Nothing is installed on the Windows side of a WSL2 host. The browser there
# needs to trust the dev CA, and `make certs` does that with certutil.exe, which
# Windows already has: one confirmation dialog, no mkcert.exe.

if [ ${#manual[@]} -gt 0 ]; then
  log_step "Install by hand"
  for m in "${manual[@]}"; do log_warn "$m"; done
fi

# ── the verdict belongs to the checker ──────────────────────────────────────
echo
"$HERE/check-tools.sh" || rc=1
exit $rc
