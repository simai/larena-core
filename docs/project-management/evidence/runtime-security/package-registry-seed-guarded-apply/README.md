# Package Registry Seed Guarded Apply Evidence

This evidence package covers the batch that adds a guarded apply path for the
first Larena starter mutation: `package_registry_seed`.

The batch keeps `larena:install` fail-closed by default. A mutation can run only
when a valid launch record is supplied and the operator explicitly confirms the
same mutation with `--confirm=package_registry_seed`.

## Scope

- Validate `larena.install_apply_launch_record.v1`.
- Allow only `package_registry_seed`.
- Write a local runtime registry artifact under `storage/app/larena`.
- Create backup and rollback evidence before the write.
- Keep database migrations, admin bootstrap and update server integration out
  of scope.
