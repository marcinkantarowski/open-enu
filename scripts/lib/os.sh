#!/usr/bin/env bash
# Host detection. WSL2 is a first-class dev host here (.ai/platform/PLAN.md §8.1): the browser
# and the trust store live on Windows while the shell lives on Linux, so several
# steps must reach across that boundary or they silently do nothing useful.

# os_kind -> linux | macos | wsl2
os_kind() {
  case "$(uname -s)" in
    Darwin) echo macos ;;
    Linux)
      if grep -qiE 'microsoft|wsl' /proc/version 2>/dev/null; then echo wsl2; else echo linux; fi ;;
    *) echo unknown ;;
  esac
}

is_wsl2() { [ "$(os_kind)" = wsl2 ]; }

# Path to the Windows hosts file as seen from WSL. Empty when not on WSL2.
win_hosts_path() {
  is_wsl2 || return 0
  local drive="/mnt/c"
  [ -d "$drive/Windows/System32/drivers/etc" ] && echo "$drive/Windows/System32/drivers/etc/hosts"
}

# Locate a Windows-side executable from WSL (mkcert.exe, powershell.exe, ...).
win_exe() {
  is_wsl2 || return 1
  command -v "$1" 2>/dev/null && return 0
  return 1
}

# inotify watch limit - Nuxt hot reload dies silently below ~256k with several
# apps running. Both reference projects hit this; check it rather than debug it.
inotify_watches() {
  cat /proc/sys/fs/inotify/max_user_watches 2>/dev/null || echo 0
}
