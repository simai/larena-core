# Core-Owned CLI Starter Evidence

This evidence package covers the batch that moved Larena starter CLI commands
from the `simai/larena` entry app into the package-owned `larena/core`
implementation.

## Scope

- Register `larena:doctor`, `larena:install`, and
  `larena:runtime-security-smoke` from `larena/core`.
- Keep the entry application as a thin Laravel starter.
- Keep actual install mutations out of scope.

## Source Of Truth

- Package implementation: `larena/core`.
- Runtime integration proof: `simai/larena` entry app.
- Graph/spec updates: proposal only in this evidence package.
