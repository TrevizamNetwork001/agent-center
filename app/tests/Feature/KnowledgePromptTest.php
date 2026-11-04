<?php

namespace Tests\Feature;

use App\Knowledge\Versioning;
use App\Llm\PromptRenderer;
use App\Models\AuditEvent;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\PromptVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\IntelligenceTestCase;

class KnowledgePromptTest extends IntelligenceTestCase
{
    public function test_create_edit_publish_supersede_rollback_and_archive(): void
    {
        $s = app(Versioning::class);
        $i = $this->knowledge();
        $v1 = KnowledgeVersion::sole();
        $this->assertSame('DRAFT', $v1->status);
        $v2 = $s->draft($i, ['content' => 'Nova referência.'], $this->admin);
        $this->assertSame('SUPERSEDED', $v1->fresh()->status);
        $i = $s->publish($i, $this->admin);
        $this->assertSame('PUBLISHED', $v2->fresh()->status);
        $v3 = $s->draft($i, ['content' => 'Terceira versão.'], $this->admin);
        $s->publish($i, $this->admin);
        $this->assertSame('SUPERSEDED', $v2->fresh()->status);
        $rolled = $s->rollback($i, $v2->id, $this->admin);
        $latest = KnowledgeVersion::find($rolled->current_version_id);
        $this->assertSame(4, $latest->version_number);
        $this->assertSame($v2->content, $latest->content);
        $this->assertNotSame($v2->id, $latest->id);
        $s->archive($rolled, $this->admin);
        $this->assertSame('ARCHIVED', $latest->fresh()->status);
        $this->assertSame('ARCHIVED', $rolled->fresh()->status);
        foreach (['knowledge.created', 'knowledge.version_created', 'knowledge.published', 'knowledge.superseded', 'knowledge.rollback_created', 'knowledge.archived'] as $e) {
            $this->assertTrue(AuditEvent::where('event_type', $e)->exists(), $e);
        }
    }

    public function test_snapshots_and_lifecycle_events_are_immutable_at_database(): void
    {
        $i = $this->knowledge();
        app(Versioning::class)->publish($i, $this->admin);
        foreach (['knowledge_version_contents', 'version_events'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->update(['created_by' => $this->admin->id]));
                $this->fail('Immutable');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode());
            }
        }$this->assertSame(27, (int) DB::selectOne("SELECT count(*) n FROM pg_class WHERE relnamespace='public'::regnamespace AND relforcerowsecurity")->n);
    }

    public function test_prompt_versioning_preview_unknown_variables_and_no_recursive_interpolation(): void
    {
        $s = app(Versioning::class);
        $p = $this->prompt();
        $v = PromptVersion::sole();
        $preview = app(PromptRenderer::class)->preview($v);
        $this->assertStringContainsString('INCONCLUSIVO', $preview['system']);
        $this->assertStringContainsString('no tools', $preview['system']);
        $p = $s->publish($p, $this->admin);
        $s->draft($p, ['system_template' => '{{classification}} {{target}}', 'user_template' => '{{knowledge}}'], $this->admin);
        $this->assertSame(2, PromptVersion::count());
        $this->assertSame('PUBLISHED', $v->fresh()->status);
        $this->assertSame('{{unknown}}', app(PromptRenderer::class)->render('{{target}}', ['target' => '{{unknown}}']));
        $this->expectException(ValidationException::class);
        app(PromptRenderer::class)->render('{{arbitrary_command}}', []);
    }

    public function test_knowledge_ui_filters_and_read_only_roles(): void
    {
        $i = $this->knowledge();
        $this->actingAs($this->viewer)->get('/knowledge/'.$i->id)->assertNotFound();
        $this->context()->forUser($this->admin);
        app(Versioning::class)->publish($i, $this->admin);
        $this->assertSame('PUBLISHED', $i->fresh()->status);
        $this->context()->forUser($this->viewer);
        $this->assertSame(1, KnowledgeItem::where('status', 'PUBLISHED')->count());
        $response = $this->get('/knowledge')->assertOk();
        $this->assertSame(1, $response->viewData('items')->count());
        $response->assertSee('Referência');
        $this->get('/knowledge?scope=operations')->assertOk()->assertViewHas('items', fn ($items) => $items->isEmpty());
        $this->get('/knowledge/'.$i->id)->assertOk()->assertSee('DNS positivo');
        $this->get('/knowledge/create')->assertForbidden();
        $this->post('/knowledge/'.$i->id.'/archive')->assertForbidden();
    }

    public function test_admin_create_draft_publish_preview_routes_and_mass_assignment(): void
    {
        $this->actingAs($this->admin)->get('/knowledge/create')->assertOk();
        $this->post('/knowledge', ['key' => 'ui-item', 'title' => 'UI Reference', 'category' => 'ops', 'scope' => 'tenant', 'content' => 'Context only', 'tags_text' => 'diagnostic,SITE_LENTO'])->assertRedirect();
        $this->context()->forUser($this->admin);
        $i = KnowledgeItem::where('key', 'ui-item')->sole();
        $this->post('/knowledge/'.$i->id.'/publish', ['expected_draft_id' => $i->draft_version_id])->assertRedirect();
        $this->get('/knowledge/'.$i->id)->assertOk()->assertSee('PUBLISHED');
        $this->get('/advanced/prompts/create')->assertOk();
        $this->post('/advanced/prompts', ['key' => 'ui-prompt', 'name' => 'UI prompt', 'purpose' => 'diagnostic_explanation', 'system_template' => '{{profile}}', 'user_template' => '{{evidence}}'])->assertRedirect();
        $this->context()->forUser($this->admin);
        $v = PromptVersion::sole();
        $this->post('/advanced/prompts/'.$v->prompt_id.'/preview/'.$v->id)->assertOk()->assertSee('Preview determinístico');
    }

    public function test_tenant_isolation_including_security_invoker_views(): void
    {
        $i = $this->knowledge();
        $p = $this->prompt();
        app(Versioning::class)->publish($i, $this->admin);
        $this->context()->forUser($this->other);
        foreach (['knowledge_items', 'knowledge_versions', 'knowledge_version_contents', 'prompts', 'prompt_versions', 'prompt_version_contents', 'version_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }$this->actingAs($this->other)->get('/knowledge/'.$i->id)->assertNotFound();
        $this->get('/advanced/prompts/'.$p->id)->assertNotFound();
        $this->post('/knowledge/'.$i->id.'/publish')->assertNotFound();
    }

    public function test_stale_draft_cannot_publish_unreviewed_concurrent_content(): void
    {
        $item = $this->knowledge();
        $old = $item->draft_version_id;
        app(Versioning::class)->draft($item, ['content' => 'Concurrent new content'], $this->admin);
        $this->actingAs($this->admin)->post('/knowledge/'.$item->id.'/publish', ['expected_draft_id' => $old])->assertConflict();
        $this->context()->forUser($this->admin);
        $this->assertNull($item->fresh()->current_version_id);
    }
}
