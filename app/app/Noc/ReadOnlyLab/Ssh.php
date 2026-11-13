<?php

namespace App\Noc\ReadOnlyLab;

use App\Models\NocDevice;
use App\Noc\Analysis\Contracts;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/** Bounded SSH exec channels only, never a PTY, shell, script or caller-supplied CLI. */
final class Ssh
{
    public function collect(Platform $platform, Operation $operation, array $parameters, string $hostKey, #[\SensitiveParameter] string $privateKey, ?NocDevice $device = null): array
    {
        abort_unless(app(Access::class)->enabled(), 403);
        app(Contracts::class)->parameters(app(Contracts::class)->all()[$operation->value], $parameters);
        $command = $platform->command($operation, $parameters);
        $ssh = new SSH2('127.0.0.1', $platform->port(), 1);
        $ssh->setPreferredAlgorithms(['hostkey' => ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'rsa-sha2-512', 'rsa-sha2-256']]);
        $started = microtime(true);
        try {
            $actual = $ssh->getServerPublicHostKey();
            if (! is_string($actual)) {
                throw new Failure('SSH_UNAVAILABLE_OR_NEGOTIATION_FAILED');
            }
            // The RSA signature algorithm name may differ from the public-key type.
            // Compare the complete signed key blob (includes key type), never the signature label.
            $approved = preg_split('/\s+/', trim($hostKey));
            $presented = preg_split('/\s+/', trim($actual));
            if (count($approved) < 2 || count($presented) < 2 || ! hash_equals($approved[1], $presented[1])) {
                throw new Failure('HOST_IDENTITY_REJECTED');
            }
            if (! $ssh->login('lab-readonly', PublicKeyLoader::loadPrivateKey($privateKey))) {
                throw new Failure('AUTHENTICATION_FAILED');
            }
            $identity = $this->read($ssh, $platform->identityCommand(), $started);
            $id = app(Parser::class)->identity($platform, $identity);
            if ($device !== null && (strcasecmp($device->model, $id['model']) !== 0 || preg_replace('/^RouterOS | \(stable\)$/i', '', $device->os_version) !== $id['os_version'])) {
                throw new Failure('HOST_IDENTITY_REJECTED');
            }
            $output = $this->read($ssh, $command, $started);

            return ['identity' => $identity, 'output' => $output];
        } catch (Failure $e) {
            throw $e;
        } catch (\Throwable) {
            throw new Failure('SSH_UNAVAILABLE_OR_NEGOTIATION_FAILED');
        } finally {
            $ssh->disconnect();
        }
    }

    private function read(SSH2 $ssh, string $command, float $started): string
    {
        $remaining = 5 - (microtime(true) - $started);
        if ($remaining <= 0) {
            throw new Failure('TIMEOUT');
        }
        $ssh->setTimeout($remaining);
        $output = '';
        $overflow = false;
        $ssh->disableQuietMode();
        $ok = $ssh->exec($command, function (string $chunk) use (&$output, &$overflow) {
            if (strlen($output) + strlen($chunk) > 65536) {
                $overflow = true;

                return true;
            }
            $output .= $chunk;
        });
        if ($overflow) {
            throw new Failure('OUTPUT_LIMIT');
        }
        if ($ssh->isTimeout() || microtime(true) - $started > 5) {
            throw new Failure('TIMEOUT');
        }
        if ($ok === false || $ssh->getExitStatus() !== 0 || $output === '') {
            throw new Failure('INVALID_RESPONSE');
        }

        return $output;
    }
}
