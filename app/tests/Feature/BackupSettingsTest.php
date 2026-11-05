<?php

namespace Tests\Feature;

use App\Backups\R2Settings;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Tests\IdentityTestCase;

class BackupSettingsTest extends IdentityTestCase
{
    protected function tearDown(): void
    {
        File::delete(app(R2Settings::class)->path());
        parent::tearDown();
    }

    private function data(): array
    {
        return ['r2_account_id' => str_repeat('a', 32), 'r2_access_key_id' => 'SyntheticR2AccessKey123', 'r2_secret_access_key' => 'SyntheticR2SecretValue123456'];
    }

    public function test_only_platform_admin_can_read_or_write_global_backup_configuration(): void
    {
        $this->get('/admin/backups')->assertRedirect('/login');
        foreach ([$this->admin, $this->other, $this->operator, $this->viewer] as $user) {
            $this->flushSession();
            $this->actingAs($user)->get('/admin/backups')->assertForbidden();
            $this->put('/admin/backups/r2', $this->data())->assertForbidden();
            $this->post('/admin/backups/request')->assertForbidden();
            $this->get('/admin/backups?fragment=1')->assertForbidden();
        }
        $this->assertFileDoesNotExist(app(R2Settings::class)->path());
        $this->flushSession();
        $this->actingAs($this->platform)->get('/admin/backups')->assertOk()->assertSee('Configuração R2 ainda não salva.')->assertSee('Bucket: agent-center')->assertSee('Backups · R2')->assertDontSee("@include('backups.navigation')", false);
    }

    public function test_credentials_are_authenticated_encrypted_and_never_rendered_or_audited(): void
    {
        $this->actingAs($this->platform)->put('/admin/backups/r2', $this->data())->assertRedirect('/admin/backups');
        $path = app(R2Settings::class)->path();
        $encrypted = File::get($path);
        $this->get('/admin/backups?fragment=1')->assertOk()->assertHeader('X-AC-Fragment', 'r2-status')->assertHeader('X-AC-Tenant', 'platform')->assertSee('Configuração R2 salva.')->assertDontSee('SyntheticR2SecretValue123456');
        $this->get('/admin/backups')->assertOk()->assertSee('Configuração R2 salva.')->assertSee('Último salvamento:')->assertSee('(São Paulo)')->assertSee('data-r2-saved', false);
        $this->flushSession();
        $this->actingAs($this->platform)->get('/admin/backups')->assertOk()->assertSee('Configuração R2 salva.');
        foreach ($this->data() as $value) {
            $this->assertStringNotContainsString($value, $encrypted);
            $this->get('/admin/backups')->assertDontSee($value, false);
        }
        $this->assertSame($this->data()['r2_secret_access_key'], json_decode(Crypt::decryptString($encrypted), true)['secret_access_key']);
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->context()->forUser($this->platform);
        $event = AuditEvent::where('event_type', 'backup.settings_updated')->sole();
        $this->assertNull($event->metadata);
        $this->assertNull($event->tenant_id);
    }

    public function test_blank_fields_preserve_credentials_and_partial_configuration_cannot_queue(): void
    {
        $this->actingAs($this->platform)->put('/admin/backups/r2', $this->data())->assertRedirect();
        $this->put('/admin/backups/r2', [])->assertRedirect();
        $this->assertSame($this->data()['r2_secret_access_key'], app(R2Settings::class)->read()['secret_access_key']);
        $this->post('/admin/backups/request')->assertSessionHasErrors('r2_recipient');
        $this->assertNull(Cache::get('platform:backups:requested'));
        $this->put('/admin/backups/r2', ['r2_recipient' => 'age1'.str_repeat('q', 58), 'r2_offsite_ack' => 1])->assertRedirect();
        $this->post('/admin/backups/request')->assertRedirect();
        $this->assertTrue(Cache::get('platform:backups:requested'));
    }

    public function test_private_keys_endpoint_and_tenant_override_are_rejected_without_secret_flash(): void
    {
        $this->actingAs($this->platform)->put('/admin/backups/r2', $this->data() + ['endpoint' => 'https://unapproved.example', 'tenant_id' => $this->b->id, 'private_key' => 'must-never-be-flashed'])->assertSessionHasErrors(['endpoint', 'tenant_id', 'private_key'])->assertSessionMissing('_old_input.r2_secret_access_key')->assertSessionMissing('_old_input.private_key');
        $this->assertFileDoesNotExist(app(R2Settings::class)->path());
        $this->put('/admin/backups/r2', $this->data() + ['r2_recipient' => 'AGE-SECRET-KEY-not-a-public-recipient'])->assertSessionHasErrors('r2_recipient');
    }

    public function test_csrf_is_enforced_with_real_request_middleware(): void
    {
        $this->app['env'] = 'production';
        $this->actingAs($this->platform)->put('/admin/backups/r2', $this->data())->assertStatus(419);
        $this->assertFileDoesNotExist(app(R2Settings::class)->path());
    }
}
