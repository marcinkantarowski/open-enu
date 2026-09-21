---
tags: [generators, tooling, module]
date: 2026-09-11
phase: 2a
---
# A generator must validate what it emits

## What happened
`make module NAME=Demo` produced a controller containing:

```php
public function __construct(private DemoService $2735344{SLUG}) {}
```

In an *unquoted* bash heredoc, `$$` is the shell's PID. The template said
`private ${NAME}Service $${SLUG}` intending `$demo`; it emitted the process id instead.

The generator reported success. The failure surfaced three commands later as a 500 from an
unrelated-looking endpoint, with `syntax error, unexpected integer "2735344"` - a message
that says nothing about where the integer came from.

## Why it happened
A generator's output is not covered by anything that checks the repository: linters,
analysers and tests all run on committed code, and this code did not exist until the
generator ran. So the generator sat in the one blind spot in an otherwise well-guarded
system, and emitted a syntax error confidently.

## The rule
**A tool that writes code validates the code it writes, before reporting success.**
`make module` now runs `php -l` over every generated PHP file and refuses - loudly, naming
the file - if any of them does not parse.

This generalises past syntax: whatever a generator's output must satisfy, the generator is
the last place that can check it cheaply and the only place that can attribute the failure
correctly.

## Bash specifics worth remembering
In an unquoted heredoc: `$$` is the PID, `$(...)` executes, `${X}` expands. A literal
dollar needs `\$`. Quoting the delimiter (`<<'EOF'`) disables all of it - the right default
for templates, and only workable when nothing needs interpolating.

## Where else this applies
- `make module` today; `make init` already self-checks (it verifies the kernel reference
  survived its own rewrite).
- Any future scaffolder, codemod or migration generator.
- Same family as [[status-codes-alone-are-not-a-health-check]]: the tool reported success
  because it only checked the thing that was easy to check.
