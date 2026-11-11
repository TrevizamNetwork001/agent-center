<?php

namespace App\Rag;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Index
{
    // Same row lock as Versioning: publication and index switch commit atomically.
    public function refresh(KnowledgeItem $item): void
    {
        DB::transaction(function () use ($item) {
            $item = KnowledgeItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $v = $item->status === 'PUBLISHED' ? KnowledgeVersion::where('status', 'PUBLISHED')->find($item->current_version_id) : null;
            $old = DB::table('rag_documents')->where('id', $item->id)->first();
            $digest = $v ? hash('sha256', $v->content.'|'.$item->title.'|1') : null;
            if ($v && $old && $old->version_id === $v->id && $old->digest === $digest && $old->algorithm === 1 && DB::table('rag_chunks')->where('document_id', $item->id)->count() === $old->chunk_count) {
                return;
            }
            DB::table('rag_chunks')->where('document_id', $item->id)->delete();
            if (! $v) {
                DB::table('rag_documents')->where('id', $item->id)->delete();

                return;
            }
            $chunks = [];
            $section = $item->title;
            // Offsets are Unicode character positions in the immutable original version.
            for ($offset = 0,$pos = 0,$length = mb_strlen($v->content); $offset < $length; $offset += 1000,$pos++) {
                $text = mb_substr($v->content, $offset, 1000);
                if (preg_match_all('/^#{1,6}\s+(.+)$/m', $text, $m) && $m[1]) {
                    $section = mb_substr(end($m[1]), 0, 200);
                }
                $chunks[] = ['id' => (string) Str::uuid(), 'tenant_id' => $item->tenant_id, 'document_id' => $item->id, 'version_id' => $v->id, 'position' => $pos, 'offset_start' => $offset, 'offset_end' => $offset + mb_strlen($text), 'title' => $item->title, 'section' => $section, 'content' => $text];
            }
            DB::table('rag_documents')->updateOrInsert(['id' => $item->id], ['tenant_id' => $item->tenant_id, 'version_id' => $v->id, 'digest' => $digest, 'algorithm' => 1, 'chunk_count' => count($chunks), 'indexed_at' => now()]);
            foreach (array_chunk($chunks, 40) as $batch) {
                DB::table('rag_chunks')->insert($batch);
            }
        });
    }

    public function rebuild(User $user): int
    {
        Gate::forUser($user)->authorize('manage-intelligence');
        $count = 0;
        KnowledgeItem::orderBy('id')->chunk(50, function ($items) use (&$count) {
            foreach ($items as $item) {
                $this->refresh($item);
                $count++;
            }
        });
        app(Audit::class)->record('rag.reindexed', $user, app(TenantContext::class)->tenantId, 'rag', metadata: ['documents' => $count]);

        return $count;
    }
}
