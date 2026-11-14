<?php

namespace App\Noc\ReadOnlyLab;

use App\Models\NocDevice;
use App\Models\User;
use App\Noc\Analysis\Contracts;
use App\Tenancy\TenantContext;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/** Bounded SSH exec channels only, never a PTY, shell, script or caller-supplied CLI. */
final class Ssh
{
    public function collect(Platform $platform, Operation|RouterOs7\Operation $operation, array $parameters, string $hostKey, #[\SensitiveParameter] string $privateKey, ?NocDevice $device = null): array
    {
        abort_unless(app(Access::class)->enabled(), 403);
        if ($platform === Platform::RouterOs && RouterOs7\Integration::enabled()) {
            throw new Failure('ROUTEROS_AGENT_REQUIRED');
        }
        $router = $operation instanceof RouterOs7\Operation;
        if ($router && $platform !== Platform::RouterOs) {
            throw new Failure('PLATFORM_NOT_SUPPORTED');
        }
        if ($router) {
            if ($device === null) {
                throw new Failure('ROUTEROS_APPROVAL_UNKNOWN_DENIED_OR_STALE');
            }
            $actor = User::withoutGlobalScopes()->findOrFail(app(TenantContext::class)->userId);
            $approved = app(RouterOs7\Approval::class)->check($actor, $device, $operation, $parameters);
            if (! hash_equals($approved->host_public_key, $hostKey)) {
                throw new Failure('HOST_IDENTITY_REJECTED');
            }
            $commands = app(RouterOs7\Protocol::class)->commands($operation, $parameters);
        } else {
            app(Contracts::class)->parameters(app(Contracts::class)->all()[$operation->value], $parameters);
            $commands = [$platform->command($operation, $parameters)];
        }
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
            if ($router) {
                $resource = app(RouterOs7\Protocol::class)->resource($identity);
                $id = ['model' => $resource['board-name'], 'os_version' => RouterOs7\Protocol::VERSION];
            } else {
                $id = app(Parser::class)->identity($platform, $identity);
            }
            if ($device !== null && (strcasecmp($device->model, $id['model']) !== 0 || preg_replace('/^RouterOS | \(stable\)$/i', '', $device->os_version) !== $id['os_version'])) {
                throw new Failure('HOST_IDENTITY_REJECTED');
            }
            $outputs = [];
            $bytes = strlen($identity);
            $records = 0;
            foreach ($commands as $command) {
                $output = $this->read($ssh, $command, $started, $router);
                $bytes += strlen($output);
                if ($bytes > 65536) {
                    throw new Failure('OUTPUT_LIMIT');
                }
                $outputs[] = $output;
                if ($router && $operation === RouterOs7\Operation::Route && $output !== '') {
                    // Full candidate validation and longest-prefix selection occur in the parser.
                    // Keep querying ancestors if only inactive candidates were returned.
                    $rows = app(RouterOs7\Protocol::class)->rows($output, ['dst-address', 'routing-table', 'gateway', 'immediate-gw', 'distance', 'active', 'dynamic', 'disabled', 'bgp', 'ospf', 'static', 'connect', 'belongs-to', 'afi', 'contribution', 'pref-src', 'scope', 'target-scope', 'vrf-interface', 'suppress-hw-offload', 'local-address', 'debug.fwp-ptr', 'rpki', 'bgp.peer-cache-id', 'bgp.as-path', 'bgp.origin']);
                    $records += count($rows);
                    if ($records > 128) {
                        throw new Failure('ROW_LIMIT');
                    }
                    if (array_filter($rows, fn ($r) => (str_contains($r['flags'], 'A') || ($r['active'] ?? '') === 'true') && ! str_contains($r['flags'], 'X') && ($r['disabled'] ?? '') !== 'true')) {
                        break;
                    }
                }
            }

            return $router ? ['identity' => $identity, 'outputs' => $outputs] : ['identity' => $identity, 'output' => $outputs[0]];
        } catch (Failure $e) {
            throw $e;
        } catch (\Throwable) {
            throw new Failure('SSH_UNAVAILABLE_OR_NEGOTIATION_FAILED');
        } finally {
            $ssh->disconnect();
        }
    }

    private function read(SSH2 $ssh, string $command, float $started, bool $allowEmpty = false): string
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
        if ($ok === false || $ssh->getExitStatus() !== 0 || (! $allowEmpty && $output === '')) {
            throw new Failure('INVALID_RESPONSE');
        }

        return $output;
    }
}
