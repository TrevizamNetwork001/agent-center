<?php

namespace Tests\Feature;

use App\Conversation\DiagnosticExperience;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Knowledge\Versioning;
use App\Llm\Client;
use App\Llm\Configuration;
use App\Models\ConversationSetting;
use App\Noc\Inventory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\IntelligenceTestCase;

class DeviceAnalysisExplanationTest extends IntelligenceTestCase
{
    public function test_llm_only_selects_approved_simulated_facts_after_explicit_opt_in_and_cannot_reclassify(): void
    {
        Http::preventStrayRequests();
        config(['device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$this->a->id]]);
        $prompt = app(Versioning::class)->publish($this->prompt(), $this->admin);
        $p = $this->provider();
        $m = $this->model($p);
        app(Configuration::class)->binding(['purpose' => 'diagnostic_explanation', 'model_id' => $m->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true, 'allow_conversation_language' => true], $this->admin);
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $d = app(Inventory::class)->save($this->admin, ['name' => 'Private NE8000', 'hostname' => 'private.lab.example', 'management_ip' => '192.0.2.33', 'manufacturer' => 'huawei', 'platform' => 'huawei-vrp', 'model' => 'NE8000 M4', 'os_version' => 'V800R023C00SPC500', 'category' => 'router', 'network_role' => 'Borda', 'pop' => 'LAB', 'environment' => 'lab', 'admin_status' => 'active']);
        $sim = app(Inventory::class)->simulate($this->admin, $d, 'da-ne8000-bgp-idle', (string) Str::uuid(), [], 1);
        $original = $sim->fresh()->getRawOriginal();
        $identity = Identity::web($this->admin);
        $c = app(Engine::class)->create($identity, (string) Str::uuid());
        $client = new class extends Client
        {
            public array $inputs = [];

            public bool $malicious = false;

            public function call($p, $m, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
            {
                $data = json_decode($messages[1]['content'], true);
                $this->inputs[] = $data;
                if ($this->malicious) {
                    return ['output' => ['summary' => 'Equipamento real normal', 'classification' => 'NORMAL', 'explanation' => 'facts', 'next_steps' => []]];
                }

                return ['output' => ['summary' => 'lab', 'explanation' => 'facts', 'next_steps' => array_map(fn ($i) => $i.':plain', array_keys($data['facts']))]];
            }
        };
        app()->instance(Client::class, $client);
        $presenter = app(DiagnosticExperience::class);
        $r = $presenter->simulationResult($c, $sim, $identity);
        $this->assertCount(0, $client->inputs);
        $this->assertSame('LOCAL', $r['metadata']['result_status']);
        $r = $presenter->simulationResult($c, $sim, $identity, true);
        $this->assertSame('ASSISTED', $r['metadata']['result_status']);
        $this->assertSame('ATTENTION', $r['metadata']['classification']);
        $this->assertStringContainsString('SIMULATED / LAB', $r['text']);
        $this->assertSame([], $client->inputs[0]['recent_user_turns']);
        $this->assertStringNotContainsString('private.lab.example', json_encode($client->inputs));
        $client->malicious = true;
        $r = $presenter->simulationResult($c, $sim, $identity, true);
        $this->assertSame('FALLBACK', $r['metadata']['result_status']);
        $this->assertStringNotContainsString('Equipamento real normal', $r['text']);
        $this->assertSame('ATTENTION', $r['metadata']['classification']);
        $this->assertSame($original, $sim->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }
}
