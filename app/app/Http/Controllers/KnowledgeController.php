<?php

namespace App\Http\Controllers;

use App\Http\Requests\KnowledgeFilterRequest;
use App\Http\Requests\KnowledgeRequest;
use App\Http\Requests\PublishContentRequest;
use App\Knowledge\Versioning;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Profile;
use Illuminate\Support\Facades\Gate;

class KnowledgeController extends Controller
{
    public function index(KnowledgeFilterRequest $r)
    {
        $q = KnowledgeItem::query();
        if (! Gate::allows('manage-intelligence')) {
            $q->where('status', 'PUBLISHED');
        }foreach (['scope', 'status', 'profile_id'] as $k) {
            if ($r->validated($k)) {
                $q->where($k, $r->validated($k));
            }
        }if ($r->validated('tag')) {
            $q->whereJsonContains('tags', $r->validated('tag'));
        }

        return view('intelligence.knowledge', ['items' => $q->with('author')->orderBy('key')->paginate(25)->withQueryString(), 'profiles' => Profile::orderBy('display_name')->get()]);
    }

    public function create()
    {
        Gate::authorize('manage-intelligence');

        return view('intelligence.create', ['kind' => 'knowledge', 'profiles' => Profile::orderBy('display_name')->get()]);
    }

    public function store(KnowledgeRequest $r, Versioning $v)
    {
        if ($r->validated('profile_id')) {
            Profile::findOrFail($r->validated('profile_id'));
        }$item = $v->create('knowledge', $r->validated(), $r->user());

        return redirect()->route('knowledge.show', $item);
    }

    public function show(KnowledgeItem $knowledge)
    {
        Gate::authorize('read-knowledge');
        abort_unless(Gate::allows('manage-intelligence') || $knowledge->status === 'PUBLISHED', 404);
        $q = KnowledgeVersion::where('knowledge_item_id', $knowledge->id);
        if (! Gate::allows('manage-intelligence')) {
            $q->whereKey($knowledge->current_version_id);
        }

        return view('intelligence.detail', ['kind' => 'knowledge', 'item' => $knowledge, 'versions' => $q->with('author')->orderByDesc('version_number')->paginate(20), 'current' => KnowledgeVersion::find($knowledge->current_version_id), 'draft' => Gate::allows('manage-intelligence') ? KnowledgeVersion::find($knowledge->draft_version_id) : null, 'preview' => null]);
    }

    public function draft(KnowledgeRequest $r, KnowledgeItem $knowledge, Versioning $v)
    {
        $v->draft($knowledge, $r->validated(), $r->user());

        return back()->with('success', 'Novo snapshot de draft criado.');
    }

    public function publish(KnowledgeItem $knowledge, Versioning $v, PublishContentRequest $r)
    {
        Gate::authorize('manage-intelligence');
        $v->publish($knowledge, $r->user(), $r->validated('expected_draft_id'));

        return back();
    }

    public function archive(KnowledgeItem $knowledge, Versioning $v)
    {
        Gate::authorize('manage-intelligence');
        $v->archive($knowledge, request()->user());

        return back();
    }

    public function rollback(KnowledgeItem $knowledge, string $version, Versioning $v)
    {
        Gate::authorize('manage-intelligence');
        $v->rollback($knowledge, $version, request()->user());

        return back()->with('success', 'Rollback publicado como nova versão.');
    }
}
