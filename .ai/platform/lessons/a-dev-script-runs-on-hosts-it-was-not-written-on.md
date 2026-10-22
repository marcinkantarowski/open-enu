---
tags: [tooling, shell, macos, portability]
date: 2026-10-22
phase: 1
---
# A dev script runs on hosts it was not written on

## What happened
The first `make builddev` on a Mac died in its second step:

```
  ✓ created .env from .env.example
sed: 1: "/Users/marcin.kantarows ...": invalid command code m
make: *** [env] Error 1
```

`envgen.sh` wrote a value with `sed -i "s|^KEY=.*|KEY=val|" .env`. That is GNU sed. BSD sed,
which macOS ships, requires a backup suffix after `-i` (an empty one is allowed: `sed -i ''`),
so it took the expression as the suffix and the file path as the script. `/Users/` parsed as
an address and the `m` of the user name as a command. The error names the path, not the
flag, so it does not look like a portability problem.

The same call sat in `init.sh` and two dozen times in `selftest.sh`, next to a `grep -oP`
that BSD grep does not have either. Every one of them had passed `make shell-check` for a
year: ShellCheck checks syntax and quoting, not which implementation of `sed` is installed.

## Why it happened
Every host the scripts had run on was Linux or WSL2, which is Linux. `os_kind` already knew
about macOS, and `install-dev-tools.sh` already spoke Homebrew, so the platform *claimed*
macOS support without any step having executed there.

## The rule
**A script that will run on a developer's host is written for the lowest common
implementation, and the difference is wrapped once.** `scripts/lib/os.sh` has `sed_inplace`;
nothing under `scripts/dev` or `scripts/lib` calls `sed -i` directly. `make shell-check` fails
on a bare call, and `make selftest` proves that it does.

`scripts/remote/` is exempt on purpose: it runs over ssh on the server, which is Debian.

## How it was caught
By a person, on the first Mac. Nothing in `make ci` could have caught it, because CI runs on
Linux too. A portability rule has to be a static check, not a test.

## Where else this applies
- `grep -P`, `readlink -f`, `date -d`, `stat -c`, `\|` in a basic regex and `sed`'s `0,/re/`
  address: all GNU-only. "First match only" is an `awk` one-liner here.
- BSD `wc -l` pads its count with spaces: compare it through `tr -d ' '`, or use `grep -c`.
- `timeout` is not on macOS by default; `check-ports.sh` already falls back when it is absent.
- Anything inside a container is fine: the containers are Linux whatever the host is.
- `make platform-update` renders OLD commits with their own `init`, so the fix has to reach
  back: `platform-update.sh` falls back to the target's `init` (`INIT_ROOT`) when a version
  cannot rename itself here. A project made before the fix still needs the new
  `platform-update.sh` copied in by hand once, because its own copy is the old one.
