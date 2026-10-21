<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrowserSessionTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('umss_testing', DB::connection()->getDatabaseName());
    }
    public function test_browser_login_uses_session_and_logout_invalidates_it(): void {
        $user = User::factory()->create(['is_admin' => true, 'password' => 'TestPassword123!']);
        $this->withHeader('Origin', 'http://127.0.0.1:5174');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'TestPassword123!', 'browser_session' => true])
            ->assertOk()->assertJsonPath('data.user.id', $user->id)->assertJsonMissingPath('data.access_token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
    public function test_browser_writes_require_csrf(): void {
        $this->app->instance('env', 'local');
        $this->withHeader('Origin', 'http://127.0.0.1:5174')
            ->postJson('/api/v1/auth/login', ['browser_session' => true])->assertStatus(419);
    }
    public function test_import_permission_is_independent_and_legacy_access_is_preserved(): void {
        Storage::fake('local');
        $legacy = User::factory()->create(['is_admin' => true, 'can_view_contacts' => true]);
        $this->assertTrue($legacy->can_import_files);
        $user = User::factory()->create(['is_admin' => true, 'can_view_contacts' => false, 'can_import_files' => true]);
        Sanctum::actingAs($user, ['admin']);
        $id = $this->postJson('/api/v1/datasets', ['name' => 'Test'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/imports', ['dataset_id' => $id, 'file' => UploadedFile::fake()->createWithContent('test.csv', "Name,Answer\nTest,Yes\n"), 'csv_delimiter' => ','])->assertCreated();
        $this->assertFalse($user->can_view_contacts);
        $user->forceFill(['can_import_files' => false, 'can_view_contacts' => true])->save();
        $this->postJson('/api/v1/imports', ['dataset_id' => $id, 'file' => UploadedFile::fake()->createWithContent('test.csv', "Name,Answer\nTest,Yes\n")])->assertForbidden();
    }
}
