<?php

namespace Tests\Support;

use App\Models\Agent;
use App\Models\Diagnostic;
use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\WebUxTest;

// Opt-in capture harness; outside the default suites to avoid duplicate tests.
class LayoutAuditSnapshotTest extends WebUxTest
{
    public function test_capture_layout_audit_pages(): void
    {
        $directory = storage_path('framework/testing/layout-review');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $this->actingAs($this->admin);
        $this->context()->forUser($this->admin);
        $agent = Agent::firstOrFail();
        $diagnostic = Diagnostic::firstOrFail();
        $profile = Profile::firstOrFail();
        $pages = [
            'dashboard' => '/dashboard', 'agents' => '/agents',
            'agent-detail' => '/agents/'.$agent->id, 'diagnostics' => '/diagnostics',
            'agent-updating' => '/agents/'.$agent->id.'?updating=1',
            'agent-repair' => '/agents/'.$agent->id.'/repair-updater',
            'releases' => '/agents/releases',
            'diagnostic-create' => '/diagnostics/create', 'history' => '/diagnostics/history',
            'diagnostic-result' => '/diagnostics/'.$diagnostic->id,
            'profiles' => '/profiles', 'profile' => '/profiles/'.$profile->id,
            'skills' => '/skills', 'knowledge' => '/knowledge', 'knowledge-create' => '/knowledge/create',
            'guide-mtr' => '/skills/guides/mtr', 'guide-tcpdump' => '/skills/guides/tcpdump',
            'guide-nmap' => '/skills/guides/nmap', 'catalog' => '/diagnostic-catalog',
            'prompts' => '/advanced/prompts', 'prompt-create' => '/advanced/prompts/create',
            'llm' => '/advanced/llm', 'audit' => '/audit', 'users' => '/users',
            'operations' => '/operations', 'conversations' => '/conversations', 'password' => '/password',
            'user-edit' => '/users/'.$this->operator->id.'/edit',
        ];
        foreach ($pages as $name => $url) {
            $response = $this->get($url)->assertOk();
            file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        }
        $this->context()->forUser($this->admin);
        $agent->forceFill(['name' => 'teste'])->save();
        $response = $this->get('/agents/'.$agent->id.'/maintenance')->assertOk();
        file_put_contents($directory.'/agent-maintenance.html', $response->getContent());
        $this->flushSession();
        $this->actingAs($this->platform);
        foreach (['tenants' => '/tenants', 'tenant-edit' => '/tenants/'.$this->a->id.'/edit', 'agent-requests' => '/agent-requests', 'backups' => '/admin/backups'] as $name => $url) {
            $response = $this->get($url)->assertOk();
            file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        }
        $this->flushSession();
        Auth::forgetGuards();
        $response = $this->get('/login')->assertOk();
        file_put_contents($directory.'/login.html', $response->getContent());
    }
}
