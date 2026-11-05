# Updater CAP_SETUID and single confirmation — 2026-11-05

On the Debian 13 KVM host, UID switching failed only when seccomp protections were enabled. CAP_SETUID in the bounding set was insufficient. AmbientCapabilities=CAP_SETUID passed the packaged dry run with the protections enabled and the operator confirmed a completed 0.5.3 -> 0.5.6 update.

The verified installer kit now includes the corrected service. Repair accepts only the pinned original service or the corrected service, backs up the installed unit with the updater components, writes the corrected unit, and reloads systemd before activating the timer. Failure restores the previous service, catalog and components. Identity, configuration, runtime, and existing drop-ins are preserved. Runtime and updater zipapp hashes are unchanged; this is an installer packaging revision of 0.5.6. Download a fresh instalar-agent.sh, since its pinned kit/bootstrap hashes changed.

The web update form asks for one initial confirmation bound to a selected release. It records the authorizer and requires successful evidence validation before continuing. Expired or revoked authorization returns to manual review. Rollback retains manual plan approval. Reports are idempotent. The progress screen returns to the agent page only on completion; failures remain visible. No real agent maintenance operation is initiated by deployment.

Validation: 22 Laravel tests / 243 assertions on separate PostgreSQL database, migration up/down; 12 installer/repair tests including empty lab installation and failed reload recovery; 7 updater tests in Python container; browser tests for completion navigation, initial completed state, failure and tenant mismatch; previous progress/reload browser regressions; real systemd dry-run with protections and CAP_SETUID ambient.

Deployment: back up each changed file and the web runtime file contents. Build a persistent image from the current production web image, change only the web image reference, and apply the reviewed files atomically in the running PHP container to avoid a container replacement. Static files are served from the public bind mount. Clear compiled views. Do not restart unrelated services.

Rollback: use the publication snapshot rollback manifest to restore existing files, remove only files created by this publication, restore the compose image reference and captured runtime files, then clear compiled views. No database migrations or tenant data changes are required. Existing downloaded installers pinned to the previous hashes must be re-downloaded rather than bypassing checksums.
