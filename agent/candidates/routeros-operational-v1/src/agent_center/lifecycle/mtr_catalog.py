"""Pinned files from the approved MTR executor; update only with Lifecycle code."""

DESTINATIONS = {
    "helper.pyz": "/opt/agent-center-mtr/helper.pyz",
    "check-helper.py": "/opt/agent-center-mtr/check-helper.py",
    "agent-center-mtr.socket": "/opt/agent-center-mtr/agent-center-mtr.socket",
    "agent-center-mtr@.service": "/opt/agent-center-mtr/agent-center-mtr@.service",
}

FILES = {
    "check-helper.py": b'"""Run as agent-center-agent after enabling the socket. No probes are sent."""\n\nimport json\nimport sys\n\nsys.path.insert(0, "/opt/agent-center-mtr/helper.pyz")\nfrom agent_center.mtr_client import ready\n\nhealthy = ready()\nprint(json.dumps({"mtr_helper_ready": healthy}))\nraise SystemExit(0 if healthy else 2)\n',
    "agent-center-mtr.socket": b"[Unit]\nDescription=Agent Center restricted MTR socket\n[Socket]\nListenStream=/run/agent-center-mtr.sock\nSocketUser=agent-center-agent\nSocketGroup=agent-center-agent\nSocketMode=0600\nAccept=yes\nMaxConnections=2\nBacklog=2\nRemoveOnStop=yes\n[Install]\nWantedBy=sockets.target\n",
    "agent-center-mtr@.service": b"[Unit]\nDescription=Agent Center restricted MTR executor\n[Service]\nType=exec\nUser=agent-center-mtr\nGroup=agent-center-mtr\nExecStart=/usr/bin/python3 -I /opt/agent-center-mtr/helper.pyz\nStandardInput=socket\nStandardOutput=null\nStandardError=null\nLoadCredential=local-policy:/etc/agent-center-agent/config.json\nLoadCredential=ceiling:/etc/agent-center-mtr/policy.json\nStateDirectory=agent-center-mtr\nStateDirectoryMode=0700\nUMask=0077\nWorkingDirectory=/\nEnvironment=PATH=/usr/bin:/usr/sbin:/bin:/sbin\nNoNewPrivileges=yes\nCapabilityBoundingSet=CAP_NET_RAW\nAmbientCapabilities=CAP_NET_RAW\nPrivateDevices=yes\nPrivateTmp=yes\nProtectSystem=strict\nProtectHome=yes\nProtectKernelTunables=yes\nProtectKernelModules=yes\nProtectKernelLogs=yes\nProtectControlGroups=yes\nProtectClock=yes\nProtectHostname=yes\nProtectProc=invisible\nRestrictAddressFamilies=AF_UNIX AF_INET AF_INET6 AF_NETLINK\nRestrictNamespaces=yes\nRestrictSUIDSGID=yes\nLockPersonality=yes\nRestrictRealtime=yes\nSystemCallArchitectures=native\nSystemCallFilter=~@mount @reboot @swap @module @obsolete @debug @cpu-emulation\nRuntimeMaxSec=20\nTimeoutStopSec=1\nKillMode=control-group\nSendSIGKILL=yes\nTasksMax=8\nMemoryMax=64M\nCPUQuota=25%\nLimitNOFILE=64\n",
}

CATALOG = {
    "id": "mtr",
    "minimum_runtime": "0.6.2",
    "platform": "debian13",
    "dependencies": ["python3.13", "mtr0.95"],
    "profile": "network-raw-helper",
    "healthcheck": "authenticated_socket_ready_v1",
    "rollback": "restore_known_files_v1",
    "limits": {
        "concurrency": 1,
        "cycles_min": 5,
        "cycles_max": 10,
        "hops_max": 16,
        "interval_seconds": 1,
        "cooldown_seconds": 10,
        "unit_timeout_seconds": 20,
    },
}
