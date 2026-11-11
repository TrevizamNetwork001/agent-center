<?php

namespace Tests\Feature;

use App\Llm\Configuration;
use App\Llm\IdentityRegistry;
use App\Models\LlmProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IntelligenceTestCase;

class LlmIdentityTest extends IntelligenceTestCase
{
    public function test_administrative_command_records_only_in_selected_tenant_and_clears_context(): void
    {
        config(['llm.allowed_hosts' => ['api.openai.com']]);
        $p = $this->provider(['base_url' => 'https://api.openai.com/v1']);
        $options = ['--tenant' => (string) $this->a->id, '--actor' => (string) $this->admin->id,
            '--provider' => $p->id, '--organization' => 'org-lab', '--project' => 'proj_lab',
            '--tracking' => 'key_lab', '--secret-event' => (string) $this->data($p->id)['secret_event_id']];
        $this->artisan('agent-center:register-llm-identity', $options)->assertSuccessful();
        $this->assertNull($this->context()->tenantId);
        $this->context()->forUser($this->admin);
        $this->assertSame('key_lab', $p->fresh()->config['administrative_identity']['tracking_id']);
        $this->artisan('agent-center:register-llm-identity', ['--actor' => (string) $this->other->id, ...array_diff_key($options, ['--actor' => true])])->assertFailed();
        $this->assertNull($this->context()->tenantId);
    }

    private function data(string $provider): array
    {
        return ['organization_id' => 'org-lab', 'project_id' => 'proj_lab', 'tracking_id' => 'key_lab',
            'secret_event_id' => (int) DB::table('audit_events')->where('event_type', 'llm.secret_updated')
                ->whereRaw("metadata->>'provider_id' = ?", [$provider])->max('id')];
    }

    public function test_registers_attestation_without_reading_or_changing_credential_and_invalidates_after_rotation(): void
    {
        config(['llm.allowed_hosts' => ['api.openai.com']]);
        $p = $this->provider(['base_url' => 'https://api.openai.com/v1']);
        $cipher = $p->getRawOriginal('secret_ciphertext');
        // Registration must work even when the master key is unavailable.
        config(['llm.key_file' => '/nonexistent']);
        $result = app(IdentityRegistry::class)->register($this->admin, $p->id, $this->data($p->id));
        $this->assertSame('operator_attestation', $result['verification']);
        $this->assertSame($cipher, $p->fresh()->getRawOriginal('secret_ciphertext'));
        $event = DB::table('audit_events')->where('event_type', 'llm.identity_registered')->sole();
        $metadata = json_decode($event->metadata, true);
        $this->assertSame($p->id, $metadata['provider_id']);
        $this->assertSame($result['secret_event_id'], $metadata['secret_event_id']);
        $this->assertSame('key_lab', $metadata['tracking_id']);
        $this->assertStringNotContainsString('fixture-provider-secret-value', $event->metadata);
        config(['llm.key_file' => $this->keyFile]);
        app(Configuration::class)->provider($p->fresh(), ['name' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1', 'timeout' => 1, 'enabled' => true, 'api_key' => 'new-synthetic-provider-value'], $this->admin);
        $this->assertArrayNotHasKey('administrative_identity', $p->fresh()->config);
        try {
            app(IdentityRegistry::class)->register($this->admin, $p->id, ['secret_event_id' => $result['secret_event_id'], ...array_diff_key($this->data($p->id), ['secret_event_id' => true])]);
            $this->fail('Stale event accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_other_tenant_cannot_register_or_read_identity_even_with_valid_ids(): void
    {
        config(['llm.allowed_hosts' => ['api.openai.com']]);
        $p = $this->provider(['base_url' => 'https://api.openai.com/v1']);
        $data = $this->data($p->id);
        $this->context()->forUser($this->other);
        $this->assertNull(LlmProvider::find($p->id));
        $this->expectException(ModelNotFoundException::class);
        app(IdentityRegistry::class)->register($this->other, $p->id, $data);
    }

    public function test_viewer_cannot_attest_identity(): void
    {
        config(['llm.allowed_hosts' => ['api.openai.com']]);
        $p = $this->provider(['base_url' => 'https://api.openai.com/v1']);
        $data = $this->data($p->id);
        $this->context()->forUser($this->viewer);
        $this->expectException(AuthorizationException::class);
        app(IdentityRegistry::class)->register($this->viewer, $p->id, $data);
    }

    public function test_missing_tracking_id_does_not_write_partial_identity(): void
    {
        config(['llm.allowed_hosts' => ['api.openai.com']]);
        $p = $this->provider(['base_url' => 'https://api.openai.com/v1']);
        try {
            app(IdentityRegistry::class)->register($this->admin, $p->id, [...$this->data($p->id), 'tracking_id' => '']);
            $this->fail('Missing tracking ID accepted');
        } catch (ValidationException) {
            $this->assertSame([], $p->fresh()->config);
            $this->assertSame(0, DB::table('audit_events')->where('event_type', 'llm.identity_registered')->count());
        }
    }
}
