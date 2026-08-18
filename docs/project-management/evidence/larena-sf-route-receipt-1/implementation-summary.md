# Implementation summary

Core now creates a deterministic receipt only after a complete immutable bundle inspection. HTTP consumers can validate activation state, immutable manifest and the exact renderable file graph without recursively hashing unrelated source files on each request.
