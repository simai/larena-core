# Changelog

## Unreleased

### Added

- Shared external-process runner: `Larena\Core\Process\ProcessExecutor` with a shell-free
  `proc_open` implementation (argv only, exact environment, stdin bytes or stream, timeout with
  SIGTERM then SIGKILL, output caps with a truncation flag, exit code and duration in an immutable
  result) and a recording executor for tests. Bound in the container.
- Generic external command gateway contract: `Larena\Core\Gateway\GatewayAdapter` declares a
  manifest (id, protocol, protocol version, allowed commands); `GatewayRunner` refuses undeclared
  commands, unfinished processes and answers in another protocol or version.

### Changed

- Added a deployment-time route receipt for immutable asset bundles so HTTP
  requests can fail closed on the exact renderable files without recursively
  hashing the complete published source trees on every page load.
- Removed Access, Audit and Licensing from Core's mandatory Composer runtime; they remain development-only compatibility fixtures until B4 purifies historical integration code.
- Replaced remaining upper-package source imports with Core-owned fail-closed adapter and lifecycle-hook contracts.
- Made the runtime-security smoke exercise only Core operation contracts and sanitization.

### Documentation

- Bind the continuation repository to the accepted Minimal CMS v1 Core role and dependency-free boundary.

### Non-claims

- No Accepted Target State, package version or mandatory dependency change is introduced by B4.
