# Smoke

The focused transaction smoke proves one successful commit, result-Audit
rollback after one handler call, decision-Audit rollback before the handler and
handler-exception rollback after a simulated partial write, plus fail-closed
behavior when a required boundary is absent. It also proves that only one
separate rollback-phase event is recorded after a confirmed rollback and that
no result event is attempted after a transactional handler failure; the Rest
composition separately proves that an ambient transaction is rejected before
the handler or Audit recorder can run.
