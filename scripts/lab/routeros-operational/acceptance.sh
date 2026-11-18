#!/bin/bash
# Fixed, disposable scenarios; never invoke against operational Agents or databases.
set -euo pipefail
cd "$(dirname "$0")/../../.."
umask 077
acceptance_mode=${1:-tasks}
case "$acceptance_mode" in
  tasks)
    : "${ROUTEROS_PACKAGED_CANDIDATE:?Signed temporary candidate is required}"
    cases=(test_complete_runtime_api_ssh_and_conversation_flow_with_security_boundaries test_identity_only_physical_preparation_stays_simulated_and_blocks_other_capabilities)
    ;;
  panel)
    : "${ROUTEROS_BASELINE_BUNDLE:?Original laboratory baseline is required}"
    : "${PANEL_TRANSITION_PACKAGES:?Signed laboratory transition packages are required}"
    : "${PANEL_TRANSITION_FAULT_PACKAGES:?Signed fault fixture is required}"
    cases=(test_old_065_companion_updates_both_packages_through_panel_without_ssh test_failed_final_package_rolls_back_without_ssh test_panel_restores_original_065_with_a_new_signed_sequence_without_ssh)
    ;;
  *) echo 'Usage: acceptance.sh tasks|panel'; exit 2 ;;
esac
: "${TEST_FRONTEND_BUILD_DIR:?Explicit frontend assets are required}"
if [ "$acceptance_mode" = panel ]; then
  bundles=("$ROUTEROS_BASELINE_BUNDLE" "$PANEL_TRANSITION_PACKAGES/bridge" "$PANEL_TRANSITION_PACKAGES/target" "$PANEL_TRANSITION_PACKAGES/restore" "$PANEL_TRANSITION_FAULT_PACKAGES/bridge" "$PANEL_TRANSITION_FAULT_PACKAGES/target")
else
  bundles=("${ROUTEROS_PACKAGED_CANDIDATE%/agent.pyz}")
  test -d "${bundles[0]}/ssh"
fi
for bundle_dir in "${bundles[@]}"; do
  for public_file in agent.pyz manifest.json signature.base64 signer-public.pem; do
    if [ ! -f "$bundle_dir/$public_file" ] || [ -L "$bundle_dir/$public_file" ]; then
      echo "Incomplete or symlinked laboratory bundle: $public_file"
      exit 2
    fi
  done
done
acceptance_state=$(mktemp -d /tmp/routeros-acceptance.XXXXXXXX)
echo "Private laboratory logs: $acceptance_state"
# HTTP callbacks require committed rows and the Agent has persistent local state.
# Each method therefore owns a fresh database, network, identity and service.
for test_name in "${cases[@]}"; do
  if [ "$acceptance_mode" = panel ]; then
    package_dir=$PANEL_TRANSITION_PACKAGES
    if [ "$test_name" = test_failed_final_package_rolls_back_without_ssh ]; then
      package_dir=$PANEL_TRANSITION_FAULT_PACKAGES
    fi
    PANEL_TRANSITION_LAB=1 PANEL_TRANSITION_PACKAGES="$package_dir" \
    ROUTEROS_PACKAGED_CANDIDATE="$package_dir/target/agent.pyz" \
    ROUTEROS_TASK_LAB=1 ROUTEROS_SYSTEMD_LAB=1 \
    DEVICE_READONLY_STATE="$acceptance_state/$test_name" \
      ./scripts/lab/device-readonly/run.sh --filter "$test_name" > "$acceptance_state/$test_name.log" 2>&1
  else
    ROUTEROS_TASK_LAB=1 ROUTEROS_SYSTEMD_LAB=1 \
    DEVICE_READONLY_STATE="$acceptance_state/$test_name" \
      ./scripts/lab/device-readonly/run.sh --filter "$test_name" > "$acceptance_state/$test_name.log" 2>&1
  fi
  echo "PASS: $test_name"
done
