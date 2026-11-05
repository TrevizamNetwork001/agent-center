# MTR Path Analysis

## Scope and architecture

`mtr_path_analysis:v1` is a closed, read-only Agent capability. Central Laravel owns planning, authorization, fenced Task Engine delivery, normalization and deterministic conclusions. Python collects evidence only. The LLM never receives an execution interface. Device freeze and local policy remain unchanged. UDP, tcpdump, Nmap, device access, active BGP and external integrations are excluded.

The current production Agent remains 0.6.1. This source builds an unpublished 0.6.2 laboratory runtime. Nothing installs packages, rewrites a local allowlist or grants privileges automatically. The ordinary Agent unit retains an empty capability bounding set. Live activation is blocked until an independently reviewed narrow execution permission boundary is provisioned on the client. A runtime self-check preserves the legacy six-capability contract required by updater 0.5.8; normal capability negotiation advertises extensions only when their prerequisites exist.

## Collection

Supported protocols: ICMP and TCP destination port 443. Fixed argv, no shell and no arbitrary flags. Verify installed MTR version and help parameters. `--report --json` order is significant on the Debian laboratory binary: putting report last selects text output. Numeric addressing, no reverse DNS, no ASN queries, cycles 5 (contract 5–10), maximum 16 hops, minimum interval 1 second, grace 1 second. Version/help each have a one-second limit; collection has the remainder of an 18-second internal budget. Existing Task Engine watchdog remains 20 seconds, lease 45 seconds and renewal 10 seconds. One execution lane; a private locked state file imposes a 10-second cooldown between MTR starts. Central also delays claims within that cooldown. Cancellation kills the inherited process group, including MTR and its packet child. No new sessions are created by the collector.

Output and stderr are drained concurrently and bounded to 49,152 bytes; no unbounded communicate buffer. Errors are stable codes; raw stderr, environment, tokens, source hostname and command strings are never logged. The output contains normalized hops plus a sanitized numeric JSON report in technical details. The bounded top-level Agent result remains under 65,536 bytes for 16 hops.

## Local authorization and DNS

Unlike generic public probes, MTR requires explicit `allowed_dns_names` or `allowed_networks`; `auto_public_targets` alone never authorizes it. For a name, the exact canonical allowed name is resolved again on the Agent. The task's pinned address must still appear in the answer set. Every non-global answer requires explicit network authorization; mixed public/private rebinding fails closed. Execution receives only the authorized numeric address, never a hostname. A literal IP requires an explicit matching CIDR. Multicast, unspecified, link-local and IPv4-mapped IPv6 targets are refused. Private or loopback targets require explicit CIDR authorization. No local policy is modified.

## Contract

Fields: destination, resolved_ip, protocol, cycles, total_duration_ms, destination_reached, hops, status, error, raw_sanitized. Hop fields: hop_index, address, hostname, sent, received, loss_percent, avg_ms, best_ms, worst_ms, stdev_ms, responded, asn. Absent hostname/ASN/received are null; received is not invented from rounded loss percentages. Non-responding hops have null RTT statistics. RTT units are milliseconds. `status=collected` means evidence was collected, not that the network is healthy.

Laravel reuses `MtrReport` for numeric raw validation, then compares normalized fields against it. It binds target, protocol and cycles to the fenced task before accepting the result. Unknown metadata is discarded; forged normalized hops are rejected. ASN is currently always null and displayed as “Não identificado”. Future enrichment must supply provenance and explicit authorization before displaying associations. An address-to-AS association does not establish the BGP forwarding path or prove traffic crossed an AS.

## Deterministic interpretation, thresholds version 1

Minimum sample: five probes at the final observed hop. Missing destination or shorter sample: INCONCLUSIVO. End-destination response loss above 0% with adequate sample: INDICIO_DE_FALHA requiring confirmation of persistence. This is loss of probe responses, not proof that application traffic failed. No individual hop, operator or AS is blamed.

Intermediate loss that disappears later is compatible with rate limiting or deprioritization and does not establish forwarding loss. `???` followed by responding hops does not establish a fault. If loss persists to the destination, report an end-to-end symptom; a similar-loss region may guide further investigation but is not causal attribution.

RTT elevation requires both +50 ms and at least twice the preceding observed mean. A sustained finding additionally requires at least one following hop, uninterrupted responding hops with adequate sample, and elevation through a responding destination. An isolated high intermediate RTT is not a forwarding fault. RTT is round trip, not one-way latency; StDev is not universal application jitter. Geographic distance, asymmetric paths, ECMP, MPLS, tunnels, changes of route and filtering remain limitations. Aggregate MTR cannot reconstruct per-flow ECMP routes.

NORMAL retains “Não foram encontradas anormalidades no escopo testado.” Operational executable failures are ERRO_DE_EXECUCAO, never network failure. A small zero-loss sample cannot rule out intermittent problems. TCP/443 vs ICMP is a controlled comparison; different protocols need not take identical paths. HTTPS functionality is separate evidence, never inferred from MTR.

Thresholds are captured in the immutable Profile plan snapshot (`mtr_threshold_version`, `mtr_min_cycles`, `mtr_loss_percent`, `mtr_rtt_increase_ms`, `mtr_rtt_ratio`). Existing administrator Profile definitions take precedence; no silent edits of their settings.

## Profiles and Conversation

Two dedicated Profiles, MTR_ICMP and MTR_TCP, require MTR explicitly. Site lento, Site não abre, Perda/Latência, Google/YouTube and Conteúdo lento can opt in to ICMP/TCP comparison through the form. No default diagnostic adds MTR. DNS dependencies remain intact. Capability and Skill enablement are checked by planning/claiming; local destination policy is enforced before collection.

Conversation recognizes requests for route, packet-loss path or MTR through deterministic intent matching. It preserves target, Profile and diagnostic history, asks for scope confirmation and links the new diagnostic to its predecessor. An unavailable capability creates no MTR task. Running diagnostics are not replaced. The DNS-only Profile explains its incompatible scope instead of silently changing Profile.

## UI and rollback

Operational hop table includes address, unavailable ASN, sent/received, loss, average/best/worst RTT, StDev and interpreted state. A synthesis precedes data; raw sanitized JSON stays in existing technical details. Existing sidebar, responsive table container and themes are retained. Installation command buttons only copy text; they never execute a command.

This laboratory branch is not a production release. Rollback: retain production image and 0.6.1 artifacts; do not install the lab runtime. The additive database capability constraint is deliberately retained on migration rollback to preserve tenant rows and avoid bypassing forced RLS. To disable MTR in an applied environment, disable mtr_path and dedicated Profiles through native admin controls; preserve evidence and task records.

## References

- https://manpages.debian.org/trixie/mtr-tiny/mtr.8.en.html
- https://github.com/traviscross/mtr/blob/master/SECURITY

Primary documentation was checked on 2026-11-05. No external ASN lookup or device action is part of this implementation.
