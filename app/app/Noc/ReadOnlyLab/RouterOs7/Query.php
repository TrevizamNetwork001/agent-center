<?php

namespace App\Noc\ReadOnlyLab\RouterOs7;

use App\Llm\SecretVault;
use App\Models\NocDevice;
use App\Models\User;
use App\Noc\Analysis\Contracts;
use App\Noc\Analysis\SimulationScope;
use App\Noc\ReadOnlyLab\Failure;
use App\Noc\ReadOnlyLab\Platform;
use App\Noc\ReadOnlyLab\Ssh;
use Illuminate\Validation\ValidationException;

/** Existing Inventory transaction owns locking, idempotency, persistence, audit and native RBAC. */
final class Query
{
    public function result(User $actor, NocDevice $device, string $key, array $parameters, SimulationScope $scope): array
    {
        $operation = Operation::tryFrom(substr($key, strlen('ssh-ros7-')));
        abort_unless($scope === SimulationScope::LaboratoryConversation && $operation !== null, 403);
        $c = app(Contracts::class)->require($device, $operation->contract());
        $params = app(Protocol::class)->parameters($operation, $parameters);
        $access = app(Approval::class)->check($actor, $device, $operation, $params);
        $secret = app(SecretVault::class)->decrypt($access);
        if ($secret === null) {
            throw new Failure('LAB_CREDENTIAL_UNAVAILABLE');
        }
        try {
            // Revalidate immediately before opening the fixed loopback connection.
            app(Approval::class)->check($actor, $device, $operation, $params);
            $wire = app(Ssh::class)->collect(Platform::RouterOs, $operation, $params, $access->host_public_key, $secret, $device);
            $e = app(Evidence::class)->parse($operation, $wire, $params);
        } catch (ValidationException) {
            throw new Failure('INVALID_EVIDENCE');
        } finally {
            unset($secret, $wire);
        }
        $data = $e['data'];
        $text = match ($operation) {
            Operation::Identity => 'Identidade declarada no cenário: '.$data['name'].', RouterOS '.$data['version'].'.',
            Operation::Bgp => 'Encontrei '.count($data['items']).' sessões BGP no cenário de laboratório; '.count(array_filter($data['items'], fn ($r) => $r['state'] === 'ESTABLISHED')).' estabelecidas. Ausência de sessão não comprova falha nem ausência de configuração.',
            Operation::Route => 'Tabela '.$data['table'].': '.($data['found'] ? 'prefixo ativo correspondente observado.' : 'nenhuma rota ativa correspondente observada.').' Encaminhamento efetivo não verificado.',
            Operation::Interfaces => 'Encontrei '.count($data['items']).' interfaces no cenário. Velocidade não disponível nesta consulta.',
            Operation::Counters => 'Encontrei '.count($data['items']).' interfaces. Contadores acumulados não comprovam erros atuais; não há duas amostras comparáveis.',
            Operation::Resources => 'CPU: '.($data['cpu_percent'] ?? 'indisponível').'%; memória: '.($data['memory_percent'] ?? 'indisponível').'%. Sem referência operacional não é possível afirmar saturação.',
        };

        return ['id' => $key, 'label' => 'RouterOS 7 · LAB / SIMULADO · '.$operation->value, 'capability_id' => $c['capability_id'], 'contract_id' => $operation->value,
            'schema_version' => 1, 'adapter_version' => 1, 'simulation_scope' => $scope->value, 'display_mode' => 'SIMULADO', 'real_evidence' => false, 'task_eligible' => false, 'health_eligible' => false,
            'mode' => 'SIMULATED', 'environment' => 'LAB', 'simulation' => true, 'source' => 'FIXTURE', 'transport' => 'SSH_LOOPBACK_LAB', 'operational_enabled' => false,
            'specialist' => 'ccr-routeros7', 'device_id' => $device->id, 'device_revision' => $device->revision, 'access_revision' => $access->revision,
            'parameters' => $params, 'generated_at' => now()->toIso8601String(), 'observed_at' => null, 'evidence' => $e, 'reuses_fixture' => null,
            'classification' => 'INCONCLUSIVE', 'summary' => 'LAB / SIMULADO: '.$text, 'facts' => [['key' => 'routeros_evidence', 'text' => $text, 'plain' => $text]],
            'limitations' => ['LAB / SIMULADO. Não são medições reais.', 'Dialeto fechado RouterOS 7.16.2; CHR e hardware não testados.', 'Não comprova FIB, política de roteamento, causa raiz ou taxas de erro.'],
            'coverage' => ['status' => 'PARTIAL', 'scope' => 'SIMULATED / LAB', 'operational' => 'NOT_EXECUTED'], 'root_cause' => null];
    }
}
