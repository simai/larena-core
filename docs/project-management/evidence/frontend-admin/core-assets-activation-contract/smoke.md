# Smoke

Runtime/browser smoke is intentionally deferred.

Reason: this package batch only establishes `core.assets` activation ownership and fail-closed descriptor validation. The current admin smart resource pack still lacks physical carrier sources and publishable asset paths.

Required next smoke inputs:

- runnable Larena admin route with resource pack descriptor,
- package-owned asset publication/serving decision,
- no root frontend runtime copy,
- write-rejection verification.

