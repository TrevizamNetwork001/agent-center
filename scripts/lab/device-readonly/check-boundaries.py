"""Protocol negative tests against loopback simulators, never physical devices."""
import base64
import json
from pathlib import Path

import paramiko


def run():
    root = Path('/lab')
    _, encoded = (root / 'host.pub').read_text().split()
    pin = paramiko.RSAKey(data=base64.b64decode(encoded))
    client_key = paramiko.RSAKey.from_private_key_file(str(root / 'client'))
    results = []
    for port in (2222, 2223):
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.RejectPolicy())
        client.get_host_keys().add(f'[127.0.0.1]:{port}', pin.get_name(), pin)
        client.connect('127.0.0.1', port=port, username='lab-readonly', pkey=client_key,
                       look_for_keys=False, allow_agent=False, timeout=1, auth_timeout=1)
        transport = client.get_transport()
        try:
            for request in ('pty', 'shell', 'forwarding'):
                channel = None
                try:
                    if request == 'forwarding':
                        channel = transport.open_channel('direct-tcpip', ('127.0.0.1', 1),
                                                         ('127.0.0.1', 0), timeout=1)
                    else:
                        channel = transport.open_session(timeout=1)
                        if request == 'pty':
                            channel.get_pty()
                        else:
                            channel.invoke_shell()
                except paramiko.SSHException:
                    results.append({'port': port, 'request': request, 'denied': True})
                else:
                    raise RuntimeError('Unsafe protocol request accepted')
                finally:
                    if channel is not None:
                        channel.close()
        finally:
            client.close()
    (root / 'protocol-boundaries.json').write_text(json.dumps({'passed': True,
                                                             'results': results}, indent=2) + '\n')
    print('Protocol boundaries PASS: both endpoints reject PTY, shell and forwarding.')


if __name__ == '__main__':
    run()
