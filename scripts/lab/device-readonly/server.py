"""Isolated SSH protocol simulator. No subprocess, shell, forwarding or external connections."""
import base64
import json
import os
import socket
import threading
import time
from pathlib import Path

import paramiko

STATE = Path('/lab')
FIXTURES = Path('/fixtures.json')
LOCK = threading.Lock()
ACTIVE = 0
MAX_ACTIVE = 0


def setup():
    os.umask(0o077)
    for name in ('host', 'client'):
        path = STATE / name
        if not path.exists():
            paramiko.RSAKey.generate(2048).write_private_key_file(str(path))
        key = paramiko.RSAKey.from_private_key_file(str(path))
        (STATE / (name + '.pub')).write_text(f'{key.get_name()} {key.get_base64()}')
    if not (STATE / 'vault').exists():
        (STATE / 'vault').write_bytes(base64.b64encode(os.urandom(32)))
    (STATE / 'scenario').write_text('normal')
    (STATE / 'ready').write_text('ready')


class Session(paramiko.ServerInterface):
    def __init__(self, scenario):
        self.scenario = scenario
        self.command = None
        self.event = threading.Event()

    def get_allowed_auths(self, username):
        return 'publickey'

    def check_auth_publickey(self, username, key):
        expected = paramiko.RSAKey.from_private_key_file(str(STATE / 'client'))
        if username == 'lab-readonly' and key == expected and self.scenario != 'auth':
            return paramiko.AUTH_SUCCESSFUL
        return paramiko.AUTH_FAILED

    def check_channel_request(self, kind, chanid):
        return paramiko.OPEN_SUCCEEDED if kind == 'session' else paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED

    def check_channel_exec_request(self, channel, command):
        try:
            self.command = command.decode('ascii')
        except UnicodeDecodeError:
            return False
        self.event.set()
        return True

    # Paramiko defaults reject PTYs, shell, subsystems and all forwarding requests.


def handle(sock, platform):
    global ACTIVE, MAX_ACTIVE
    scenario = (STATE / 'scenario').read_text().strip()
    if scenario == 'unavailable':
        sock.close()
        return
    transport = paramiko.Transport(sock)
    with LOCK:
        ACTIVE += 1
        MAX_ACTIVE = max(MAX_ACTIVE, ACTIVE)
    try:
        if scenario == 'negotiation':
            sock.sendall(b'NOT-SSH\r\n')
            return
        transport.add_server_key(paramiko.RSAKey.from_private_key_file(str(STATE / 'host')))
        session = Session(scenario)
        transport.start_server(server=session)
        fixtures = json.loads(FIXTURES.read_text())[platform]
        query_number = 0
        while transport.is_active():
            channel = transport.accept(2)
            if channel is None:
                continue
            session.event.wait(2)
            command = session.command
            session.event.clear()
            if command not in fixtures:
                channel.send_exit_status(126)
                channel.close()
                continue
            output = fixtures[command]
            query_number += 1
            started_ns = time.monotonic_ns()
            if scenario == 'timeout' and query_number > 1:
                time.sleep(6)
            elif scenario == 'oversize' and query_number > 1:
                output = 'X' * 70000
            elif scenario == 'invalid' and query_number > 1:
                output = 'not vendor output\n'
            elif scenario == 'truncate' and query_number > 1:
                output = output[:-4]
            elif scenario == 'identity':
                output = output.replace('NE8000 M4', 'NE8000 M8').replace('7.16.2', '7.17.2')
            elif scenario == 'slow':
                time.sleep(0.1)
            channel.sendall(output.encode())
            channel.send_exit_status(0)
            channel.close()
            if scenario == 'slow' and query_number == 2:
                with LOCK:
                    with (STATE / 'query-intervals.jsonl').open('a') as intervals:
                        intervals.write(json.dumps({'start': started_ns, 'end': time.monotonic_ns()}) + '\n')
    except (EOFError, OSError, paramiko.SSHException):
        pass
    finally:
        transport.close()
        sock.close()
        with LOCK:
            ACTIVE -= 1
            (STATE / 'stats.json').write_text(json.dumps({'active': ACTIVE, 'max_active': MAX_ACTIVE}))


def listen(platform, port):
    server = socket.socket()
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(('127.0.0.1', port))
    server.listen(8)
    while True:
        sock, _ = server.accept()
        threading.Thread(target=handle, args=(sock, platform), daemon=True).start()


if __name__ == '__main__':
    setup()
    threading.Thread(target=listen, args=('huawei', 2222), daemon=True).start()
    listen('routeros', 2223)
