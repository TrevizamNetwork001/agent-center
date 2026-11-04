<?php

namespace App\Llm;

use App\Diagnostics\Digest;
use App\Knowledge\Selector;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticInterpretation;
use App\Models\InterpretationSource;
use App\Models\LlmBinding;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\Prompt;
use App\Models\PromptVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Interpretation
{
    public function run(Diagnostic $d, User $actor, ?callable $cancelled = null): DiagnosticInterpretation
    {
        $official = DiagnosticConclusion::where('diagnostic_id', $d->id)->firstOrFail();
        $sources = [];
        $prompt = null;
        $provider = null;
        $model = null;
        $vars = [];
        $response = ['error' => 'not_configured'];
        $lock = Cache::lock('llm:'.$d->tenant_id.':'.$d->id, 40);
        if (! $lock->get()) {
            return $this->store($d, $actor, $official, [], null, null, null, [], ['error' => 'busy']);
        }
        try {
            $b = LlmBinding::where('purpose', 'diagnostic_explanation')->where('enabled', true)->first();
            if ($b && ($b->config['allow_external_context'] ?? false) === true) {
                $provider = LlmProvider::find($b->provider_id);
                $model = LlmModel::find($b->model_id);
                $p = Prompt::where('status', 'PUBLISHED')->find($b->prompt_id);
                $prompt = $p ? PromptVersion::where('status', 'PUBLISHED')->find($p->current_version_id) : null;
                if ($provider && $model && $prompt) {
                    $sources = app(Selector::class)->forDiagnostic($d);
                    $vars = ['classification' => $official->classification, 'profile' => $d->profile->key, 'target' => parse_url(str_contains($d->target, '://') ? $d->target : 'https://'.$d->target, PHP_URL_HOST) ?: '', 'evidence' => json_encode($official->evidence, JSON_THROW_ON_ERROR), 'knowledge' => json_encode($sources, JSON_THROW_ON_ERROR)];
                    foreach ($vars as &$v) {
                        $v = Output::clean($v);
                    }unset($v);
                    $render = app(PromptRenderer::class);
                    $messages = [['role' => 'system', 'content' => PromptRenderer::BOUNDARY], ['role' => 'user', 'content' => "Administrative template (no operational authority):\n".$render->render($prompt->system_template, $vars)."\n".$render->render($prompt->user_template ?? '{{evidence}}', $vars)."\nOutput schema: ".json_encode(Output::schema())]];
                    $response = app(Client::class)->call($provider, $model, $messages, $cancelled);
                }
            }
        } catch (\Throwable) {
            $response = ['error' => 'interpretation_unavailable'];
        } finally {
            $lock->release();
        }

        return $this->store($d, $actor, $official, $sources, $prompt, $provider, $model, $vars, $response);
    }

    private function store($d, $actor, $official, array $sources, $prompt, $provider, $model, array $vars, array $response): DiagnosticInterpretation
    {
        return DB::transaction(function () use ($d, $actor, $official, $sources, $prompt, $provider, $model, $vars, $response) {
            $out = $response['output'] ?? ['summary' => $official->summary, 'explanation' => 'A conclusão técnica e as evidências continuam disponíveis.', 'next_steps' => $official->next_steps];
            $i = new DiagnosticInterpretation;
            $i->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, 'classification' => $official->classification, 'status' => isset($response['output']) ? 'ASSISTED' : 'FALLBACK', 'reason' => $response['error'] ?? 'ok', 'summary' => $out['summary'], 'explanation' => $out['explanation'], 'next_steps' => $out['next_steps'], 'prompt_version_id' => $prompt?->id, 'provider_id' => $provider?->id, 'model_id' => $model?->id, 'provider_snapshot' => $provider && $model ? ['type' => $provider->provider_type, 'name' => $provider->name, 'base_url' => $provider->base_url, 'model_identifier' => $model->model_identifier] : null, 'input_digest' => $vars ? Digest::hash($vars) : null, 'created_by' => $actor->id, 'created_at' => now()])->save();
            foreach ($sources as $s) {
                $source = new InterpretationSource;
                $source->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'interpretation_id' => $i->id, 'knowledge_item_id' => $s['item_id'], 'knowledge_version_id' => $s['version_id'], 'title' => $s['title'], 'version_number' => $s['version_number'], 'created_at' => now()])->save();
            }

            return $i;
        });
    }
}
