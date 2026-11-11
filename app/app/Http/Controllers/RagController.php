<?php

namespace App\Http\Controllers;

use App\Rag\Index;
use App\Rag\Retrieval;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RagController extends Controller
{
    public function index(Request $r, Retrieval $retrieval)
    {
        Gate::authorize('manage-intelligence');
        $data = $r->isMethod('post') ? $r->validate(['question' => 'required|string|max:2000', 'tenant_id' => 'prohibited']) : [];
        $result = isset($data['question']) ? $retrieval->search($r->user(), $data['question']) : null;
        if ($result) {
            app(Audit::class)->record('rag.searched', $r->user(), app(TenantContext::class)->tenantId, 'rag', metadata: ['sources' => count($result['sources'])]);
        }
        $documents = DB::table('knowledge_items as k')->leftJoin('rag_documents as d', 'd.id', '=', 'k.id')->select('k.id', 'k.title', 'k.status', 'k.current_version_id', 'd.version_id', 'd.chunk_count', 'd.indexed_at')->orderBy('k.title')->paginate(25);
        $stats = ['documents' => DB::table('rag_documents')->count(), 'chunks' => DB::table('rag_chunks')->count(), 'updated' => DB::table('rag_documents')->max('indexed_at')];

        return response()->view('intelligence.rag', compact('result', 'documents', 'stats'))->header('Cache-Control', 'no-store, private');
    }

    public function reindex(Request $r, Index $index)
    {
        Gate::authorize('manage-intelligence');
        $r->validate(['tenant_id' => 'prohibited']);
        try {
            $index->rebuild($r->user());
        } catch (\Throwable) {
            return back()->withErrors(['index' => 'INDEX_UNAVAILABLE: reindexação não concluída. Os índices já válidos foram preservados; tente novamente.']);
        }

        return back()->with('success', 'Índice atualizado para este tenant.');
    }

    public function source(Request $r, string $source, Retrieval $retrieval)
    {
        $item = $retrieval->source($r->user(), $source);
        abort_unless($item, 404);

        return response()->view('intelligence.rag-source', ['source' => $item])->header('Cache-Control', 'no-store, private');
    }
}
