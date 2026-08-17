# Concept alignment: Larena Core

## Accepted role

Core is the dependency-free Larena foundation. It owns package bootstrap, shared lifecycle primitives, diagnostics and contracts that do not encode a higher-level domain.

Accepted Target State: `larena.target.minimal_cms_v1` at semantic digest `sha256:2793f61ba9563839831d57e87ac5cd6399c37a3183f1a68981b6fc7f941a1ad2`.

## Dependency and ownership boundary

- Mandatory Larena dependencies: none.
- Core must not import Access, Audit, Licensing or any presentation/application package.
- Authentication, authorization, storage, rendering and administration remain owned by their packages.

## Continuation strategy

This repository is a continuation repository. Existing compatible contracts stay in place. Higher-level integrations are detached from the mandatory runtime instead of deleting historical code. Optional compatibility adapters may remain only when they do not re-enter the minimal Composer closure.

## B4 alignment status

The mandatory Composer dependency set is empty and Core product source has no upper-package imports. Optional install-audit and post-migration behavior now enters through Core-owned fail-closed contracts; the pure runtime-security diagnostic constructs only Core contracts.

## Install and rollback baseline

Install through the Root Composer lock and Laravel package discovery; do not encode a workstation PHP path. B0 adds documentation only and rolls back by reverting these files. Any later migration-bearing batch must document down/reapply evidence; application rollback restores the previous verified Root lock.

## Verification

Run package tests, `composer validate`, the workspace dependency report and a source-import scan. The package is aligned when its Larena dependency list is empty and its tests remain green.
