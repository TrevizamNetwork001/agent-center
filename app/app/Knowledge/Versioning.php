<?php

namespace App\Knowledge;

use App\Llm\Output;
use App\Llm\PromptRenderer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Prompt;
use App\Models\PromptVersion;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Versioning
{
    private function kind($item): string
    {
        return $item instanceof KnowledgeItem ? 'knowledge' : 'prompt';
    }

    private function model(string $kind): string
    {
        return $kind === 'knowledge' ? KnowledgeItem::class : Prompt::class;
    }

    private function versions(string $kind): string
    {
        return $kind === 'knowledge' ? KnowledgeVersion::class : PromptVersion::class;
    }

    private function parentKey(string $kind): string
    {
        return $kind === 'knowledge' ? 'knowledge_item_id' : 'prompt_id';
    }

    private function event($item, string $event, User $u, ?string $version = null): void
    {
        app(Audit::class)->record($this->kind($item).'.'.$event, $u, $item->tenant_id, $this->kind($item), metadata: [$this->kind($item) === 'knowledge' ? 'knowledge_id' : 'prompt_id' => $item->id, 'version_id' => $version]);
    }

    private function transition($item, string $version, string $status, User $u): void
    {
        DB::table('version_events')->insert(['tenant_id' => $item->tenant_id, ($this->kind($item) === 'knowledge' ? 'knowledge_version_id' : 'prompt_version_id') => $version, 'status' => $status, 'created_by' => $u->id, 'created_at' => now()]);
    }

    public function create(string $kind, array $data, User $u)
    {
        return DB::transaction(function () use ($kind, $data, $u) {
            $class = $this->model($kind);
            $item = new $class;
            $allowed = $kind === 'knowledge' ? ['key', 'title', 'category', 'scope', 'tags', 'profile_id'] : ['key', 'name', 'purpose'];
            $item->forceFill(array_intersect_key($data, array_flip($allowed)) + ['id' => (string) Str::uuid(), 'tenant_id' => app(TenantContext::class)->tenantId, 'created_by' => $u->id, 'status' => 'DRAFT'])->save();
            $this->event($item, 'created', $u);
            $this->draft($item, $data, $u);

            return $item->fresh();
        });
    }

    public function draft($item, array $data, User $u)
    {
        return DB::transaction(function () use ($item, $data, $u) {
            $item = $item->newQuery()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $kind = $this->kind($item);
            $versions = $this->versions($kind);
            $parent = $this->parentKey($kind);
            $next = ($versions::where($parent, $item->id)->max('version_number') ?? 0) + 1;
            $id = (string) Str::uuid();
            if ($item->draft_version_id) {
                $this->transition($item, $item->draft_version_id, 'SUPERSEDED', $u);
                $this->event($item, 'superseded', $u, $item->draft_version_id);
            }
            $content = $kind === 'knowledge' ? ['content' => $data['content']] : ['system_template' => $data['system_template'], 'user_template' => $data['user_template'] ?? null, 'expected_output_schema' => json_encode(Output::schema(), JSON_THROW_ON_ERROR)];
            if ($kind === 'prompt') {
                app(PromptRenderer::class)->validate($data['system_template'], $data['user_template'] ?? '');
            }
            DB::table($kind.'_version_contents')->insert(['id' => $id, 'tenant_id' => $item->tenant_id, $parent => $item->id, 'version_number' => $next, ...$content, 'change_summary' => $data['change_summary'] ?? null, 'created_by' => $u->id, 'created_at' => now()]);
            $this->transition($item, $id, 'DRAFT', $u);
            $item->forceFill(['draft_version_id' => $id, 'status' => $item->status === 'PUBLISHED' ? 'PUBLISHED' : 'DRAFT'])->save();
            $this->event($item, 'version_created', $u, $id);

            return $versions::findOrFail($id);
        });
    }

    public function publish($item, User $u, ?string $expectedDraft = null)
    {
        return DB::transaction(function () use ($item, $u, $expectedDraft) {
            $item = $item->newQuery()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($item->draft_version_id, 409, 'Nenhum draft disponível.');
            abort_if($expectedDraft !== null && $expectedDraft !== $item->draft_version_id, 409, 'Draft alterado; revise a nova versão antes de publicar.');
            $version = $item->draft_version_id;
            if ($item->current_version_id && $item->status === 'PUBLISHED') {
                $this->transition($item, $item->current_version_id, 'SUPERSEDED', $u);
                $this->event($item, 'superseded', $u, $item->current_version_id);
            }
            $this->transition($item, $version, 'PUBLISHED', $u);
            $item->forceFill(['current_version_id' => $version, 'draft_version_id' => null, 'status' => 'PUBLISHED'])->save();
            $this->event($item, 'published', $u, $version);

            return $item->fresh();
        });
    }

    public function rollback($item, string $versionId, User $u)
    {
        return DB::transaction(function () use ($item, $versionId, $u) {
            $item = $item->newQuery()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $kind = $this->kind($item);
            $class = $this->versions($kind);
            $v = $class::where($this->parentKey($kind), $item->id)->findOrFail($versionId);
            abort_unless(DB::table('version_events')->where($kind === 'knowledge' ? 'knowledge_version_id' : 'prompt_version_id', $v->id)->where('status', 'PUBLISHED')->exists(), 422, 'Rollback exige versão previamente publicada.');
            $data = $kind === 'knowledge' ? ['content' => $v->content] : ['system_template' => $v->system_template, 'user_template' => $v->user_template];
            $new = $this->draft($item, $data + ['change_summary' => 'Rollback de v'.$v->version_number], $u);
            $this->event($item, 'rollback_created', $u, $new->id);

            return $this->publish($item->fresh(), $u);
        });
    }

    public function archive($item, User $u): void
    {
        DB::transaction(function () use ($item, $u) {
            $item = $item->newQuery()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status === 'ARCHIVED') {
                return;
            }foreach (array_filter([$item->current_version_id, $item->draft_version_id]) as $id) {
                $this->transition($item, $id, 'ARCHIVED', $u);
            }$item->forceFill(['status' => 'ARCHIVED', 'draft_version_id' => null])->save();
            $this->event($item, 'archived', $u);
        });
    }
}
