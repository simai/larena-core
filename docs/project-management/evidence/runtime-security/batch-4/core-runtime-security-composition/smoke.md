# Smoke

Smoke expectations:

- Access denial prevents capability and handler execution.
- Capability denial prevents handler execution.
- Successful access and capability decisions allow handler execution.
- Successful execution records decision and result audit metadata.
- Handler failure is surfaced and still records result audit metadata.

Result: passed.

Evidence:

- `RuntimeSecurityCompositionTest` proves the positive flow order:
  access, capability, audit decision, handler, audit result.
- `RuntimeSecurityCompositionFailsClosedTest` proves access denial stops before
  capability and handler execution.
- `RuntimeSecurityCompositionFailsClosedTest` proves capability denial stops
  before handler execution.
- `RuntimeSecurityCompositionFailsClosedTest` proves handler failures are
  surfaced and result audit metadata is still recorded.
