<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_user_uploads_replaces_views_and_removes_their_own_photo(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);

        $first = $this->post('/api/v1/profile/photo', ['photo' => UploadedFile::fake()->image('me.png', 200, 200)], ['Accept' => 'application/json'])
            ->assertOk()->json('photoUrl');
        $firstPath = $user->refresh()->profile_photo_path;
        $this->assertStringStartsWith('/profile/photo?v=', $first);
        Storage::disk('local')->assertExists($firstPath);
        $this->get('/profile/photo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->post('/api/v1/profile/photo', ['photo' => UploadedFile::fake()->image('new.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($user->refresh()->profile_photo_path);

        $this->deleteJson('/api/v1/profile/photo')->assertOk()->assertJson(['photoUrl' => null]);
        $this->assertNull($user->refresh()->profile_photo_path);
        $this->get('/profile/photo')->assertNotFound();
    }

    public function test_upload_rejects_non_images_oversized_files_and_svg(): void
    {
        $this->signIn(User::factory()->create());

        foreach ([
            UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->image('huge.png', 200, 200)->size(3000),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->image('tiny.png', 10, 10),
        ] as $file) {
            $this->post('/api/v1/profile/photo', ['photo' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_photo_routes_require_sign_in_and_path_is_never_serialized(): void
    {
        $this->get('/profile/photo')->assertRedirect();
        $this->postJson('/api/v1/profile/photo')->assertUnauthorized();
        $this->deleteJson('/api/v1/profile/photo')->assertUnauthorized();

        $user = User::factory()->create();
        $user->forceFill(['profile_photo_path' => 'profile-photos/1/secret.png'])->save();
        $this->assertArrayNotHasKey('profile_photo_path', $user->toArray());
    }
}
