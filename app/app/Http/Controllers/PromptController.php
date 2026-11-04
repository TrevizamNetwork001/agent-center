<?php

namespace App\Http\Controllers;

use App\Http\Requests\PromptRequest;
use App\Http\Requests\PublishContentRequest;
use App\Knowledge\Versioning;
use App\Llm\PromptRenderer;
use App\Models\Prompt;
use App\Models\PromptVersion;
use Illuminate\Support\Facades\Gate;

class PromptController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-intelligence');

        return view('intelligence.prompts', ['items' => Prompt::with('author')->orderBy('key')->paginate(25)]);
    }

    public function create()
    {
        Gate::authorize('manage-intelligence');

        return view('intelligence.create', ['kind' => 'prompt', 'profiles' => collect()]);
    }

    public function store(PromptRequest $r, Versioning $v)
    {
        $item = $v->create('prompt', $r->validated(), $r->user());

        return redirect()->route('prompts.show', $item);
    }

    public function show(Prompt $prompt)
    {
        Gate::authorize('manage-intelligence');

        return $this->detail($prompt);
    }

    private function detail(Prompt $p, ?array $preview = null)
    {
        return view('intelligence.detail', ['kind' => 'prompt', 'item' => $p, 'versions' => PromptVersion::where('prompt_id', $p->id)->with('author')->orderByDesc('version_number')->paginate(20), 'current' => PromptVersion::find($p->current_version_id), 'draft' => PromptVersion::find($p->draft_version_id), 'preview' => $preview]);
    }

    public function draft(PromptRequest $r, Prompt $prompt, Versioning $v)
    {
        $v->draft($prompt, $r->validated(), $r->user());

        return back()->with('success', 'Novo snapshot de draft criado.');
    }

    public function publish(Prompt $prompt, Versioning $v, PublishContentRequest $r)
    {
        Gate::authorize('manage-intelligence');
        $v->publish($prompt, $r->user(), $r->validated('expected_draft_id'));

        return back();
    }

    public function archive(Prompt $prompt, Versioning $v)
    {
        Gate::authorize('manage-intelligence');
        $v->archive($prompt, request()->user());

        return back();
    }

    public function preview(Prompt $prompt, string $version, PromptRenderer $r)
    {
        Gate::authorize('manage-intelligence');
        $v = PromptVersion::where('prompt_id', $prompt->id)->findOrFail($version);

        return $this->detail($prompt, $r->preview($v));
    }
}
