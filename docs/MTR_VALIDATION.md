# MTR validation — 2026-11-05

## Final status

**MTR PATH ANALYSIS BLOCKED: real activation on Agent `teste` needs a validated least-privilege execution boundary and administrative access to provision it. The current service has an empty network capability bounding set; no SSH/admin path to that client is available in this session.**

Implementation, integration and live collection were validated in the isolated laboratory. This is not a production deployment or a successful end-to-end test from the customer Agent. Production remains on Agent 0.6.1. The unpublished 0.6.2 runtime and Laravel changes are retained on a local laboratory branch, without modifying the existing dirty working tree, client policy, installer or production image.

## Inventory and reuse

Inspected AGENTS.md, Agent 0.6.1, six executable capabilities, prepared operational Skill definitions, MtrReport numeric JSON reader, Catalog, Profiles, Planner, Normalizer, Classifier, Task Engine, Conversation state machine, local policy and diagnostic views. Kept MtrReport and existing fences, lease rules, idempotence, tenant RLS and device freeze. The older `mtr:v1` prepared reader remains disabled; live collection has the distinct capability `mtr_path_analysis:v1` and Skill `mtr_path`. No tcpdump or Nmap execution was added.

The default Agent has no CAP_NET_RAW. The runtime now advertises live MTR only when non-root, with effective CAP_NET_RAW and a compatible installed executable. Legacy self-check intentionally keeps the six-capability updater contract. This does not grant a capability. Granting raw networking to the entire existing Agent service is not a deployment recommendation; a narrow execution boundary still needs provisioning and validation on the client.

## Tests

- Full Laravel / PostgreSQL suite: **228 tests, 1,791 assertions passed**.
- Final focused MTR contract regression after stricter integer validation and standalone-profile handling: **7 tests, 51 assertions passed**.
- Python suite: **90 tests passed**. Final focused collector regression: **9 tests passed**.
- Separate-process PostgreSQL concurrency harness: concurrent claims, worker/result race, exclusive execution lane, expired-claim fencing, duplicate idempotence and DNS prerequisites passed.
- Migrations up and rollback passed against disposable PostgreSQL databases; production schema was not modified.
- Agent API, Task Engine, cancellation, leases, Profiles, Conversation, device freeze and tenant isolation are included in the full suites. Added MTR-specific start/result protocol binding, stale generation rejection and identical-result idempotence tests.
- Fixtures: healthy IPv4, IPv6, isolated intermediate loss, end-destination loss, isolated RTT peak, sustained RTT elevation, unknown hop, filtered destination, short sample and ECMP-like address sequence. ASN absent is exercised throughout. Error tests cover empty/invalid/nonfinite/oversized JSON, missing binary, root refusal, operational failure, timeout, unauthorized targets, arbitrary flags, UDP rejection and DNS rebinding/mixed private answers.
- ICMP filtered vs TCP responding comparison is explicitly tested; it never claims identical routes or service failure from ICMP alone.
- Ruff passed; Pint passed on 27 relevant PHP files. Runtime build and isolated `--self-check` passed, with device freeze true.
- Six screenshots of actual live MTR evidence rendered by Blade at 390/900/1440 px in light and dark themes; no page overflow or JavaScript errors. Sanitized JSON remains outside the operational table, in the existing diagnostic technical-details flow.
- Public installer browser check passed: the copy button writes exactly the two commands to the clipboard, does not navigate, and executes nothing.
- `git diff --check` passed. Pattern-based added-content secret scan passed for private keys, AWS access identifiers, GitHub tokens and literal bearer credentials; no env/key/secret paths staged. This is a bounded pattern scan, not a guarantee against all secret formats.

## Live laboratory collection

Executed a trusted Debian MTR package in a disposable container under UID 987, with no-new-privileges and only CAP_NET_RAW remaining after privilege setup. Environment excludes MTR_OPTIONS and MTR_PACKET. Target was a public IPv4 address resolved from the previously authorized `google.com`; authorization was explicit and confined to the laboratory process. No customer allowlist was edited. No router SSH/API access or external ASN query occurred.

First validated pair:

| Protocol | Duration | Hops | Destination | Laravel interpretation |
|---|---:|---:|---|---|
| ICMP | 6,042.85 ms | 7 | Confirmed | INDICIO_DE_FALHA |
| TCP/443 | 6,376.17 ms | 7 | Confirmed | INDICIO_DE_FALHA |

Both samples contained loss of responses at the destination. The analysis asks to confirm persistence and correlate with the application; it does not blame any router or AS. These are laboratory vantage-point observations, not diagnoses of the client's access circuit.

A subsequent bounded resource sample took 6,861.73 ms for ICMP (destination not confirmed; therefore inconclusive) and 6,555.94 ms for TCP. Aggregate child CPU after both collectors was 0.2858 seconds; maximum child RSS was 13,636 KiB. These RUSAGE_CHILDREN values are cumulative laboratory snapshots, not measurements of production Agent overhead. No high-rate stress test was performed.

Negative live control under UID 987 with no network capabilities and no-new-privileges failed operationally for both protocols; it was never reported as network failure. Capability negotiation under the minimum laboratory capability advertised mtr_path_analysis:v1 while legacy self-check omitted the extension, preserving compatibility.

The laboratory also exposed a Debian option-order issue: `--json --report` returned text. Corrected to `--report --json` and verified structured output. A rotating DNS answer was rejected when it did not contain the pinned address, demonstrating fail-closed authorization. The controlled collection subsequently used an explicitly authorized literal laboratory IP.

## Health and deployment boundary

At the final production check: `/health/ready` returned ready; web, queue worker, diagnostic worker and PostgreSQL containers were running and healthy. Native queue-health and worker-health commands exited successfully. Agent `teste` remained active at 0.6.1, with the original six capabilities and heartbeat age 18 seconds at the recorded sample. Its local config, CIDRs, DNS names, managed settings and service permissions were not modified.

Runtime build SHA-256: `a2e6a2f58a2320691a0ccaa4caa4ff371d0dfefe57989ec0fd492704d09396b7` (unpublished lab artifact).

Evidence logs and screenshots are retained locally under `/root/agent-center-mtr/`. The production installer still distributes 0.6.1. Do not present its current repair command as enabling MTR.

## Residual limits

Real client activation and a diagnostic from that Agent remain unvalidated. No narrow privileged worker has been provisioned on the client; broad elevation of the Agent is intentionally avoided. Actual IPv6 collection was not performed; IPv6 parsing and policy contracts are tested. Five-cycle samples are short and do not establish persistence or exclude intermittent faults. RTT elevation is an observation, not proof of a faulty hop or geographic cause. Aggregate MTR does not identify per-flow ECMP paths or the forwarding BGP path. ASN is unavailable and never inferred. Hostnames and received counts absent from the tool remain null.

No changes were made to R2, retention, GitHub remote, unrelated services or equipment.
