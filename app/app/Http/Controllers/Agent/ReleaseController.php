<?php

namespace App\Http\Controllers\Agent;

use App\Agent\Protocol;
use App\Agent\ReleaseQuarantine;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentRelease;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ReleaseController extends Controller
{
    public function index()
    {
        Gate::authorize('create', Agent::class);

        return view('agents.releases', ['releases' => AgentRelease::orderByDesc('sequence')->get()]);
    }

    public function store(Request $r, Audit $audit)
    {
        Gate::authorize('create', Agent::class);
        $data = $r->validate(['manifest' => ['required', 'string', 'max:65536'],
            'signature' => ['required', 'string', 'max:128'],
            'artifact' => ['required', 'file', 'max:8192']]);
        $key = base64_decode(config('agent_releases.public_key'), true);
        $signature = base64_decode($data['signature'], true);
        abort_unless(is_string($key) && strlen($key) === 32 && is_string($signature) && strlen($signature) === 64, 422, 'Chave ou assinatura inválida.');
        abort_unless(sodium_crypto_sign_verify_detached($signature, $data['manifest'], $key), 422, 'Assinatura inválida.');
        $manifest = json_decode($data['manifest'], true);
        $fields = ['schema_version', 'sequence', 'version', 'platform', 'sha256', 'size'];
        abort_unless(is_array($manifest) && ! array_diff(array_keys($manifest), [...$fields, 'components', 'runtime_package_protocol', 'bootstrap_sha256']) && ! array_diff($fields, array_keys($manifest)), 422);
        if (array_key_exists('runtime_package_protocol', $manifest) || array_key_exists('bootstrap_sha256', $manifest)) {
            abort_unless(in_array($manifest['runtime_package_protocol'] ?? null, [2, 3], true) && is_string($manifest['bootstrap_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $manifest['bootstrap_sha256']) && $manifest['bootstrap_sha256'] !== $manifest['sha256'], 422);
        }
        abort_unless($manifest['schema_version'] === 1 && is_int($manifest['sequence']) && $manifest['sequence'] > 0 && $manifest['sequence'] <= 2147483647, 422);
        abort_unless(is_string($manifest['version']) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $manifest['version']) && $manifest['platform'] === 'debian13', 422);
        abort_unless(in_array($manifest['version'], Protocol::VERSIONS, true), 422, 'Versão não suportada pela Central.');
        if (array_key_exists('components', $manifest)) {
            $components = $manifest['components'];
            abort_unless(is_array($components) && array_is_list($components) && count($components) <= 1, 422);
            foreach ($components as $component) {
                abort_unless(is_array($component) && count($component) === 5 && isset($component['id'], $component['version'], $component['profile'], $component['dependencies'], $component['helper_sha256']), 422);
                abort_unless($component['id'] === 'mtr' && $component['profile'] === 'network-raw-helper' && is_string($component['version']) && strlen($component['version']) <= 32 && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $component['version']) && version_compare($manifest['version'], '0.6.2', '>='), 422);
                abort_unless($component['dependencies'] === ['python3.13', 'mtr0.95'] && is_string($component['helper_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $component['helper_sha256']), 422);
            }
        }
        $bytes = $r->file('artifact')->get();
        abort_unless(is_int($manifest['size']) && $manifest['size'] > 0 && $manifest['size'] === strlen($bytes) && is_string($manifest['sha256']) && hash_equals(hash('sha256', $bytes), $manifest['sha256']), 422);
        DB::transaction(function () use ($manifest, $data, $bytes, $r, $audit) {
            // Serialize publications per tenant, including the first release.
            DB::select('SELECT pg_advisory_xact_lock(190621, ?::integer)', [app(TenantContext::class)->tenantId]);
            abort_if(AgentRelease::where('sequence', '>=', $manifest['sequence'])->exists(), 409);
            if (isset($manifest['runtime_package_protocol'])) {
                $bridge = AgentRelease::where('sha256', $manifest['bootstrap_sha256'])->first();
                abort_unless($bridge && $bridge->version === $manifest['version'] && $bridge->platform === $manifest['platform'] && $bridge->sequence < $manifest['sequence'] && ! isset(json_decode($bridge->manifest, true)['runtime_package_protocol']), 422, 'Publique primeiro a transição assinada compatível deste tenant.');
                app(ReleaseQuarantine::class)->assertAllowed($bridge->descriptor());
            }
            $release = new AgentRelease;
            $release->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => app(TenantContext::class)->tenantId,
                'published_by' => $r->user()->id, 'version' => $manifest['version'], 'platform' => $manifest['platform'],
                'sequence' => $manifest['sequence'], 'sha256' => $manifest['sha256'], 'manifest' => $data['manifest'],
                'signature' => $data['signature'], 'artifact_base64' => base64_encode($bytes), 'published_at' => now()])->save();
            $audit->record('agent.release_published', $r->user(), $release->tenant_id, 'agent_release', null, ['release_id' => $release->id]);
        });

        return redirect()->route('agents.releases.index');
    }

    public function artifact(Request $r, string $operation)
    {
        return DB::transaction(function () use ($r, $operation) {
            app(ReleaseQuarantine::class)->lock();
            $operation = AgentOperation::whereKey($operation)->firstOrFail();
            $agent = $r->attributes->get('agent');
            abort_unless($operation->agent_id === $agent->id && in_array($operation->status, ['PRECHECKING', 'PLANNED', 'APPROVED', 'RUNNING'], true), 404);
            $release = AgentRelease::whereKey($operation->release['id'] ?? null)->firstOrFail();
            app(ReleaseQuarantine::class)->assertAllowed(['sha256' => $release->sha256]);
            abort_unless(hash_equals($operation->release['sha256'], $release->sha256), 409);

            $bytes = base64_decode($release->getRawOriginal('artifact_base64'), true);
            $headers = ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store', 'Accept-Ranges' => 'bytes'];
            if ($range = $r->header('Range')) {
                abort_unless(preg_match('/^bytes=(0|[1-9][0-9]{0,7})-(0|[1-9][0-9]{0,7})$/D', $range, $match), 416);
                $start = (int) $match[1];
                $end = (int) $match[2];
                abort_unless($start <= $end && $end < strlen($bytes) && $end - $start < 524288, 416);

                return response(substr($bytes, $start, $end - $start + 1), 206, $headers + ['Content-Range' => 'bytes '.$start.'-'.$end.'/'.strlen($bytes), 'Content-Length' => $end - $start + 1]);
            }

            return response($bytes, 200, $headers + ['Content-Length' => strlen($bytes)]);
        });
    }
}
