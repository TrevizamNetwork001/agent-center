<?php

namespace App\Rag;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Retrieval
{
    public function live()
    {
        return DB::table('rag_chunks as c')->join('knowledge_items as k', function ($j) {
            $j->on('k.id', '=', 'c.document_id')->on('k.tenant_id', '=', 'c.tenant_id')->on('k.current_version_id', '=', 'c.version_id');
        })->join('knowledge_versions as v', function ($j) {
            $j->on('v.id', '=', 'c.version_id')->on('v.tenant_id', '=', 'c.tenant_id');
        })->join('rag_documents as d', 'd.id', '=', 'c.document_id')
            ->where('c.tenant_id', app(TenantContext::class)->tenantId)
            ->where('k.status', 'PUBLISHED')->where('v.status', 'PUBLISHED')->whereColumn('d.version_id', 'c.version_id')->where('d.algorithm', 1);
    }

    public function source(User $user, string $id): ?array
    {
        Gate::forUser($user)->authorize('read-knowledge');
        $s = $this->live()->where('c.id', $id)->select('c.*', 'v.version_number', 'v.status as version_status')->first();

        return $s ? (array) $s : null;
    }

    public function search(User $user, string $question, int $limit = 6, int $bytes = 10000): array
    {
        Gate::forUser($user)->authorize('read-knowledge');
        abort_unless(app(TenantContext::class)->tenantId !== null, 403);
        $question = mb_substr($question, 0, 2000);
        // OR terms support natural-language questions with partial lexical overlap.
        preg_match_all('/[\p{L}\p{N}_-]{2,60}/u', $question, $words);
        $terms = array_values(array_unique(array_filter($words[0], fn ($w) => ! in_array(mb_strtolower($w), ['como', 'devo', 'qual', 'nosso', 'nossa', 'para', 'uma', 'um', 'que', 'do', 'da', 'os', 'as', 'no', 'na', 'de', 'ao', 'em', 'por', 'com', 'nao', 'procedimento', 'investigar'], true))));
        foreach ($terms as $term) {
            foreach (match (Str::ascii(mb_strtolower($term))) {
                'lento', 'lenta', 'lentidao' => ['lento', 'lentidão'], 'site', 'web' => ['site', 'web'], default => []
            } as $alias) {
                $terms[] = $alias;
            }
        }
        $terms = array_values(array_unique($terms));
        if (! $terms) {
            return ['status' => 'no_match', 'sources' => []];
        }
        $query = implode(' OR ', array_slice($terms, 0, 32));
        $ts = "(websearch_to_tsquery('portuguese', ?) || websearch_to_tsquery('simple', ?))";
        $rows = $this->live()->whereRaw("c.search @@ $ts", [$query, $query])->select('c.*', 'v.version_number', 'v.status as version_status')->selectRaw("ts_rank_cd(c.search,$ts) as score", [$query, $query])->orderByDesc('score')->orderBy('c.id')->limit(40)->get();
        $out = [];
        $used = 0;
        $perDocument = [];
        foreach ($rows as $r) {
            if (($perDocument[$r->document_id] ?? 0) >= 2 || $used + strlen($r->content) > min(12000, max(0, $bytes))) {
                continue;
            }
            $out[] = (array) $r;
            $used += strlen($r->content);
            $perDocument[$r->document_id] = ($perDocument[$r->document_id] ?? 0) + 1;
            if (count($out) >= min(8, max(1, $limit))) {
                break;
            }
        }
        $missing = DB::table('knowledge_items as k')->leftJoin('rag_documents as d', 'd.id', '=', 'k.id')->where('k.status', 'PUBLISHED')->where(function ($q) {
            $q->whereNull('d.id')->orWhereColumn('d.version_id', '<>', 'k.current_version_id')->orWhere('d.algorithm', '<>', 1)
                ->orWhereRaw('d.chunk_count <> (SELECT count(*) FROM rag_chunks rc WHERE rc.document_id=k.id AND rc.tenant_id=k.tenant_id)');
        })->exists();

        return ['status' => $missing ? 'index_incomplete' : ($out ? 'ok' : 'no_match'), 'sources' => $out];
    }
}
