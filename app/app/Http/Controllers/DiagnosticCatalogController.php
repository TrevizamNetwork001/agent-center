<?php

namespace App\Http\Controllers;

use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Http\Requests\CatalogRequest;
use App\Models\Profile;
use App\Models\Skill;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DiagnosticCatalogController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-diagnostic-catalog');
        app(Catalog::class)->ensure();

        return view('diagnostics.catalog', ['profiles' => Profile::orderBy('display_name')->get(), 'skills' => Skill::orderBy('key')->get(), 'fixtures' => session('fixtures', [])]);
    }

    public function profiles()
    {
        Gate::authorize('manage-diagnostic-catalog');
        app(Catalog::class)->ensure();

        return view('diagnostics.profiles', ['profiles' => Profile::orderBy('display_name')->get()]);
    }

    public function skills()
    {
        Gate::authorize('manage-diagnostic-catalog');
        app(Catalog::class)->ensure();

        return view('diagnostics.skills', ['skills' => Skill::orderBy('display_name')->get()]);
    }

    public function show(Profile $profile)
    {
        Gate::authorize('manage-diagnostic-catalog');

        return view('diagnostics.profile', ['profile' => $profile, 'fixtures' => session('fixtures', []), 'skills' => Skill::whereIn('key', match ($profile->key) {
            'PROBLEMA_DNS' => ['dns_lookup'],'PERDA_LATENCIA' => ['dns_lookup', 'icmp_ping', 'traceroute'],default => ['dns_lookup', 'tcp_connect', 'http_probe', 'icmp_ping', 'traceroute']
        })->get()]);
    }

    public function profile(CatalogRequest $r, Profile $profile, Audit $audit)
    {
        DB::transaction(function () use ($r, $profile, $audit) {
            $profile = Profile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $profile->forceFill(['enabled' => $r->boolean('enabled'), 'version' => $profile->version + 1])->save();
            $audit->record('profile.updated', $r->user(), $profile->tenant_id, 'profile', metadata: ['profile_id' => $profile->id]);
        });

        return back();
    }

    public function skill(CatalogRequest $r, Skill $skill, Audit $audit)
    {
        DB::transaction(function () use ($r, $skill, $audit) {
            $skill = Skill::whereKey($skill->id)->lockForUpdate()->firstOrFail();
            $skill->forceFill(['enabled' => $r->boolean('enabled'), 'version' => $skill->version + 1])->save();
            $audit->record($skill->enabled ? 'skill.enabled' : 'skill.disabled', $r->user(), $skill->tenant_id, 'skill', metadata: ['skill_id' => $skill->id]);
        });

        return back();
    }

    public function fixtures(Profile $profile, Classifier $classifier)
    {
        Gate::authorize('manage-diagnostic-catalog');
        $fixtures = json_decode(file_get_contents(resource_path('fixtures/diagnostics.json')), true, flags: JSON_THROW_ON_ERROR);
        $results = [];
        foreach ($fixtures as $f) {
            if ($f['profile'] === $profile->key) {
                $got = $classifier->classify($f['profile'], $f['evidence'], $f['thresholds'] ?? [], $f['cancelled'] ?? false);
                $results[] = ['name' => $f['name'], 'expected' => $f['expected'], 'actual' => $got['classification'], 'pass' => $got['classification'] === $f['expected']];
            }
        }

        return back()->with('fixtures', $results);
    }
}
