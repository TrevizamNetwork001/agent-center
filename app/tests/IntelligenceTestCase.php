<?php

namespace Tests;

use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Knowledge\Versioning;
use App\Llm\Configuration;
use App\Models\Agent;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\Profile;
use Illuminate\Support\Str;

abstract class IntelligenceTestCase extends IdentityTestCase
{
    protected string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context()->forUser($this->admin);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'llm-key-');
        file_put_contents($this->keyFile, base64_encode(random_bytes(32)));
        chmod($this->keyFile, 0600);
        config(['llm.key_file' => $this->keyFile, 'llm.previous_key_files' => [], 'llm.allowed_hosts' => ['provider.example.test']]);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    protected function knowledge(array $extra = [])
    {
        return app(Versioning::class)->create('knowledge', ['key' => 'scope-reference', 'title' => 'Referência', 'category' => 'network', 'scope' => 'diagnostic', 'tags' => [], 'content' => 'DNS positivo não prova conectividade HTTPS.', ...$extra], $this->admin);
    }

    protected function prompt(array $extra = [])
    {
        return app(Versioning::class)->create('prompt', ['key' => 'test-prompt', 'name' => 'Prompt', 'purpose' => 'diagnostic_explanation', 'system_template' => 'Classification: {{classification}}', 'user_template' => '{{evidence}} {{knowledge}}', ...$extra], $this->admin);
    }

    protected function provider(array $extra = [])
    {
        return app(Configuration::class)->provider(null, ['name' => 'Lab compatible', 'base_url' => 'https://provider.example.test/v1', 'timeout' => 1, 'enabled' => true, 'api_key' => 'fixture-provider-secret-value', ...$extra], $this->admin);
    }

    protected function model($p)
    {
        return app(Configuration::class)->model(['provider_id' => $p->id, 'name' => 'Lab model', 'model_identifier' => 'fixture-model', 'enabled' => true, 'context_limit' => 65536], $this->admin);
    }

    protected function diagnostic(): Diagnostic
    {
        app(Catalog::class)->ensure();
        $a = new Agent;
        $a->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Lab', 'hostname' => 'lab', 'status' => 'active', 'version' => '0.5.2', 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => [], 'metadata' => [], 'enrolled_at' => now()])->save();
        $d = new Diagnostic;
        $d->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'agent_id' => $a->id, 'profile_id' => Profile::where('key', 'SITE_LENTO')->sole()->id, 'created_by' => $this->admin->id, 'target' => 'example.test', 'status' => 'COMPLETED', 'started_at' => now(), 'finished_at' => now()])->save();
        $c = new DiagnosticConclusion;
        $c->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, 'classification' => 'NORMAL', 'summary' => Classifier::NORMAL, 'evidence' => ['dns_a' => ['ok' => true, 'data' => ['answers' => ['127.0.0.1'], 'record_type' => 'A', 'duration_ms' => 1]]], 'next_steps' => ['Amplie o escopo se o sintoma persistir.'], 'created_at' => now()])->save();

        return $d;
    }
}
