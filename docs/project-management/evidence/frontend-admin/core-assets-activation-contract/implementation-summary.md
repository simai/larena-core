# Implementation Summary

- Added `Larena\Core\Starter\CoreAssetActivationContract`.
- The contract accepts descriptor-only asset requirements and returns a JSON-serializable activation plan.
- The plan keeps `physical_publication_ready=false`, `writes_database=false`, `copies_to_root=false`, and `uses_hardcoded_cdn=false`.
- Unsafe drift fails closed when a descriptor contains a root copy path, CDN URL, publishable path, final physical path, or non-core-owned final path.

This does not publish JavaScript or CSS files and does not make the admin resource pack browser-runnable.

