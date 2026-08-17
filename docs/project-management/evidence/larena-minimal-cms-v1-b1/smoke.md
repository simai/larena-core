# Smoke

The deterministic workspace report reads the real package manifest and returns `selected_direct=[]`, no deferred mandatory edge and no forbidden edge for Core. The report still exits nonzero because later batches remain open, which is the expected B1 state.
