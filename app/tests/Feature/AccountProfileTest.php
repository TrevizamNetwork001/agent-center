<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\IdentityTestCase;

class AccountProfileTest extends IdentityTestCase
{
    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }

    public function test_account_owner_can_change_name_and_avatar_without_changing_permissions(): void
    {
        Storage::fake('local');
        $before = $this->inspect($this->viewer);
        $this->actingAs($this->viewer)->patch('/account/profile', ['name' => 'Meu nome', 'avatar' => $this->image()])->assertRedirect(route('account.edit'));
        $after = $this->inspect($this->viewer);
        $this->assertSame('Meu nome', $after->name);
        $this->assertSame($before->role, $after->role);
        $this->assertSame($before->tenant_id, $after->tenant_id);
        $this->assertSame($before->email, $after->email);
        $this->assertStringStartsWith('accounts/'.$this->viewer->id.'/avatars/', $after->avatar_path);
        Storage::disk('local')->assertExists($after->avatar_path);
        $this->actingAs($after)->get('/account/avatar')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/account/profile')->assertOk()->assertSee('Seu perfil, do seu jeito.')->assertSee('Meu nome')->assertSee($after->email);
        $this->assertArrayNotHasKey('avatar_path', $after->toArray());
    }

    public function test_avatar_is_only_served_to_its_owner_and_not_by_a_guessed_user_id(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin)->patch('/account/profile', ['name' => $this->admin->name, 'avatar' => $this->image()])->assertRedirect();
        $owner = $this->inspect($this->admin);
        $this->flushSession();
        $this->actingAs($this->other)->get('/account/avatar?user_id='.$owner->id)->assertNotFound();
        $this->get('/account/profile?user_id='.$owner->id)->assertOk()->assertDontSee($owner->avatar_path)->assertDontSee($owner->email);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get('/account/avatar')->assertRedirect(route('login'));
        $this->patch('/account/profile', ['name' => 'Anonymous'])->assertRedirect(route('login'));
    }

    public function test_role_tenant_email_and_other_account_cannot_be_changed_from_profile(): void
    {
        $original = $this->inspect($this->operator);
        $this->actingAs($this->operator)->patchJson('/account/profile', ['name' => 'Bad', 'role' => 'platform_admin', 'tenant_id' => $this->b->id, 'user_id' => $this->other->id, 'email' => 'changed@example.test'])->assertUnprocessable();
        $fresh = $this->inspect($this->operator);
        $this->assertSame($original->name, $fresh->name);
        $this->assertSame($original->role, $fresh->role);
        $this->assertSame($original->tenant_id, $fresh->tenant_id);
        $this->assertSame($original->email, $fresh->email);
    }

    public function test_svg_non_image_and_oversized_files_are_rejected_without_creating_files(): void
    {
        Storage::fake('local');
        foreach ([UploadedFile::fake()->createWithContent('avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->create('large.png', 1025, 'image/png'), UploadedFile::fake()->createWithContent('avatar.jpg', '<?php echo 123;')] as $file) {
            $this->actingAs($this->admin)->patchJson('/account/profile', ['name' => 'Keep name', 'avatar' => $file])->assertUnprocessable();
        }
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertNull($this->inspect($this->admin)->avatar_path);
    }

    public function test_removing_avatar_preserves_account_and_deletes_only_the_owned_file(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin)->patch('/account/profile', ['name' => $this->admin->name, 'avatar' => $this->image()])->assertRedirect();
        $path = $this->inspect($this->admin)->avatar_path;
        Storage::disk('local')->put('accounts/other/keep.txt', 'Keep');
        $this->actingAs($this->admin)->patch('/account/profile', ['name' => $this->admin->name, 'remove_avatar' => '1'])->assertRedirect();
        $this->assertNull($this->inspect($this->admin)->avatar_path);
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists('accounts/other/keep.txt');
    }

    public function test_platform_owner_profile_works_inside_a_selected_tenant(): void
    {
        $this->actingAs($this->platform)->withSession(['active_tenant_id' => $this->a->id])->patch('/account/profile', ['name' => 'Administrador da plataforma'])->assertRedirect();
        $this->assertSame('Administrador da plataforma', $this->inspect($this->platform)->name);
        $this->assertNull($this->inspect($this->platform)->tenant_id);
    }

    public function test_avatar_revalidation_saves_image_bytes_and_still_requires_its_owner(): void
    {
        Storage::fake('local');
        $this->actingAs($this->viewer)->patch('/account/profile', ['name' => $this->viewer->name, 'avatar' => $this->image()])->assertRedirect();
        $owner = $this->inspect($this->viewer);
        $response = $this->actingAs($owner)->get('/account/avatar')->assertOk();
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        $this->withHeaders(['If-None-Match' => $etag])->get('/account/avatar')->assertNotModified();
        $this->get('/account/profile')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['If-None-Match' => $etag])->get('/account/avatar')->assertRedirect(route('login'));
        $this->actingAs($this->other)->withHeaders(['If-None-Match' => $etag])->get('/account/avatar')->assertNotFound();
    }

    public function test_avatar_replacement_and_removal_invalidate_the_previous_validator(): void
    {
        Storage::fake('local');
        $this->actingAs($this->viewer)->patch('/account/profile', ['name' => $this->viewer->name, 'avatar' => $this->image()])->assertRedirect();
        $owner = $this->inspect($this->viewer);
        $etag = $this->actingAs($owner)->get('/account/avatar')->headers->get('ETag');
        $this->patch('/account/profile', ['name' => $owner->name, 'avatar' => $this->image()])->assertRedirect();
        $owner = $this->inspect($owner);
        $response = $this->actingAs($owner)->withHeaders(['If-None-Match' => $etag])->get('/account/avatar')->assertOk();
        $this->assertNotSame($etag, $response->headers->get('ETag'));
        $this->patch('/account/profile', ['name' => $owner->name, 'remove_avatar' => '1'])->assertRedirect();
        $this->actingAs($this->inspect($owner))->get('/account/avatar')->assertNotFound();
    }
}
