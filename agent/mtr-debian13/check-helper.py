"""Run as agent-center-agent after enabling the socket. No probes are sent."""

import json
import sys

sys.path.insert(0, "/opt/agent-center-mtr/helper.pyz")
from agent_center.mtr_client import ready

healthy = ready()
print(json.dumps({"mtr_helper_ready": healthy}))
raise SystemExit(0 if healthy else 2)
