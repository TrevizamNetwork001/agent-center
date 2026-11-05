<?php

namespace App\Knowledge;

use App\Llm\Output;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;

class Selector
{
    public function forDiagnostic($diagnostic): array
    {
        $tags = [$diagnostic->profile->key, 'diagnostic'];
        $items = KnowledgeItem::where('status', 'PUBLISHED')->whereNotNull('current_version_id')->where(function ($q) use ($diagnostic) {
            $q->whereIn('scope', ['global', 'diagnostic', 'tenant'])->orWhere(fn ($p) => $p->where('scope', 'profile')->where('profile_id', $diagnostic->profile_id));
        })->where(function ($q) use ($tags) {
            $q->whereRaw("tags='[]'::jsonb");
            foreach ($tags as $tag) {
                $q->orWhereJsonContains('tags', $tag);
            }
        })->orderBy('key')->limit(5)->get();
        $versions = KnowledgeVersion::whereIn('id', $items->pluck('current_version_id'))->where('status', 'PUBLISHED')->get()->keyBy('id');
        $out = [];
        $bytes = 0;
        foreach ($items as $item) {
            $v = $versions[$item->current_version_id] ?? null;
            if (! $v) {
                continue;
            }$content = Output::clean($v->content);
            if ($bytes + strlen($content) > 12000) {
                continue;
            }$bytes += strlen($content);
            $out[] = ['item_id' => $item->id, 'version_id' => $v->id, 'version_number' => $v->version_number, 'title' => $item->title, 'content' => $content];
        }

        return $out;
    }
}
