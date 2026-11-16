<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentTask;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

/** Real panel controller -> old companion timer -> HTTPS API -> Lifecycle -> new timer. */
class PanelNativeLifecycleTest extends IdentityTestCase
{
    public function test_old_065_companion_updates_both_packages_through_panel_without_ssh(): void
    {
        $this->exercise(false);
    }

    public function test_failed_final_package_rolls_back_without_ssh(): void
    {
        $this->exercise(true);
    }

    public function test_panel_restores_original_065_with_a_new_signed_sequence_without_ssh(): void
    {
        $this->exercise(false);
        $agent = Agent::firstOrFail();
        $identity = hash_file('sha256', '/lab/agent-private/state/identity.json');
        $config = hash_file('sha256', '/lab/agent-private/config.json');
        $connections = count(file('/lab/connections.jsonl'));
        $root = '/lab/panel-packages/restore';
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true);
        $this->post('/agents/releases', ['manifest' => file_get_contents($root.'/manifest.json'), 'signature' => trim(file_get_contents($root.'/signature.base64')), 'artifact' => UploadedFile::fake()->createWithContent('agent.pyz', file_get_contents($root.'/agent.pyz'))])->assertRedirect('/agents/releases');
        $this->post('/agents/'.$agent->id.'/operations', ['kind' => 'update', 'reason' => 'failed_release', 'release_sha' => $manifest['sha256'], 'install_after_check' => '1'])->assertRedirect();
        $this->waitFor(function () use ($agent) {
            $operations = AgentOperation::where('agent_id', $agent->id)->get();
            $this->assertNull($operations->firstWhere('status', 'FAILED'));

            $current = $agent->fresh();

            return $operations->where('status', 'COMPLETED')->count() === 3 && $current->version === '0.6.5' && ($current->metadata['updater']['version'] ?? null) === '0.6.5';
        }, 'original 0.6.5 restored through native Lifecycle', 140);
        $this->assertSame(9, $agent->fresh()->metadata['lifecycle_release_sequence']);
        $this->assertSame($identity, hash_file('sha256', '/lab/agent-private/state/identity.json'));
        $this->assertSame($config, hash_file('sha256', '/lab/agent-private/config.json'));
        $this->assertSame($connections, count(file('/lab/connections.jsonl')));
        $this->assertSame(0, AgentTask::count());
        $proof = json_decode(file_get_contents('/lab/panel-transition-result.json'), true);
        file_put_contents('/lab/panel-transition-result.json', json_encode([...$proof, 'restored_original_065' => true, 'version' => '0.6.5', 'sequence' => 9, 'native_operations_completed' => 3, 'package_protocol' => 1], JSON_PRETTY_PRINT));
    }

    private function exercise(bool $failure): void
    {
        if (getenv('PANEL_TRANSITION_LAB') !== '1') {
            $this->markTestSkipped('Run PANEL_TRANSITION_LAB=1 isolated systemd runner.');
        }
        $this->assertSame('agent_center_test', DB::connection()->getDatabaseName());
        DB::commit();
        $this->context()->forUser($this->admin);
        config(['agent_releases.public_key' => trim(file_get_contents('/lab/panel-packages/public-raw.base64'))]);
        file_put_contents('/lab/central-scope.json', json_encode(['tenants' => [$this->a->id, $this->b->id]]));
        $id = (string) Str::uuid();
        $token = $id.'.'.bin2hex(random_bytes(32));
        $agent = (new Agent)->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'name' => 'Native panel lab', 'hostname' => 'isolated-lab', 'status' => 'active', 'version' => '0.6.5', 'token_hash' => hash('sha256', $token), 'last_seen_ip' => '127.0.0.1', 'capabilities' => [], 'metadata' => ['task_protocol' => 1, 'lifecycle_release_sequence' => 6]]);
        $agent->save();
        $this->privateFile('state/identity.json', ['agent_id' => $id, 'token' => $token]);
        $this->privateFile('config.json', ['central_url' => 'https://127.0.0.1:8443', 'state_dir' => '/lab/agent-private/state', 'identity_file' => '/lab/agent-private/state/identity.json', 'log_dir' => '/lab/agent-private/logs', 'heartbeat_interval' => 2, 'poll_interval' => 1, 'timeout' => 3, 'auto_public_targets' => false, 'allowed_networks' => [], 'allowed_dns_names' => [], 'allow_managed_settings' => false, 'device_operations_frozen' => true]);
        $this->waitFor(fn () => $agent->fresh()->last_poll_at !== null && ($agent->fresh()->metadata['updater']['version'] ?? null) === '0.6.5', 'old companion authenticated HTTPS poll');
        $identity = hash_file('sha256', '/lab/agent-private/state/identity.json');
        $config = hash_file('sha256', '/lab/agent-private/config.json');
        $connections = count(file('/lab/connections.jsonl'));
        foreach (['bridge', 'target'] as $name) {
            $root = '/lab/panel-packages/'.$name;
            $this->actingAs($this->admin)->post('/agents/releases', ['manifest' => file_get_contents($root.'/manifest.json'), 'signature' => trim(file_get_contents($root.'/signature.base64')), 'artifact' => UploadedFile::fake()->createWithContent('agent.pyz', file_get_contents($root.'/agent.pyz'))])->assertRedirect('/agents/releases');
        }
        $target = json_decode(file_get_contents('/lab/panel-packages/target/manifest.json'), true);
        $this->post('/agents/'.$id.'/operations', ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $target['sha256'], 'install_after_check' => '1'])->assertRedirect();
        $this->waitFor(function () use ($id, $failure) {
            $operations = AgentOperation::where('agent_id', $id)->get();
            $failed = $operations->firstWhere('status', 'FAILED');
            if ($failure) {
                return $operations->where('status', 'COMPLETED')->count() === 1 && $failed !== null;
            }
            $this->assertNull($failed, $failed ? 'Native Lifecycle: '.$failed->status.' / '.$failed->error : '');

            return $operations->where('status', 'COMPLETED')->count() === 2;
        }, 'native Lifecycle transition terminates', 140);
        $agent->refresh();
        $this->assertSame('0.6.6', $agent->version);
        $this->assertSame($failure ? 7 : 8, $agent->metadata['lifecycle_release_sequence']);
        $this->assertSame(3, $agent->metadata['updater']['runtime_package_protocol']);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame($identity, hash_file('sha256', '/lab/agent-private/state/identity.json'));
        $this->assertSame($config, hash_file('sha256', '/lab/agent-private/config.json'));
        $this->assertSame($connections, count(file('/lab/connections.jsonl')));
        foreach (AgentOperation::where('agent_id', $id)->orderBy('created_at')->get() as $operation) {
            if ($failure && $operation->status === 'FAILED') {
                $this->assertTrue($operation->evidence['rollback']);
            } else {
                $this->assertTrue($operation->evidence['postcheck']);
            }
            $this->assertTrue($operation->evidence['freeze_preserved']);
            $this->assertSame($operation->id, $operation->evidence['backup']);
        }
        file_put_contents('/lab/panel-transition-result.json', json_encode(['passed' => true, 'panel_requests' => 1, 'native_operations_completed' => $failure ? 1 : 2, 'failure_injected' => $failure, 'rollback_verified' => $failure, 'from_version' => '0.6.5', 'version' => $agent->version, 'sequence' => $failure ? 7 : 8, 'package_protocol' => 3, 'identity_preserved' => true, 'config_preserved' => true, 'ssh_connections_during_update' => 0, 'device_tasks' => 0, 'freeze_preserved' => true, 'target_sha256' => $target['sha256']], JSON_PRETTY_PRINT));
    }

    private function privateFile(string $name, array $data): void
    {
        $file = '/lab/agent-private/'.$name;
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
            chown(dirname($file), 65534);
            chgrp(dirname($file), 65534);
        }
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        chown($file, 65534);
        chgrp($file, 65534);
    }

    private function waitFor(callable $condition, string $label, int $seconds = 45): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $this->context()->forUser($this->admin);
            if ($condition()) {
                return;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);
        $this->fail('Timed out: '.$label);
    }
}
