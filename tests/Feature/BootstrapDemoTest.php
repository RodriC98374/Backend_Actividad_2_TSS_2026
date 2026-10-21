<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_creates_once_and_never_replaces_a_password(): void
    {
        putenv('BOOTSTRAP_ADMIN_EMAIL=demo@example.test');
        putenv('BOOTSTRAP_ADMIN_PASSWORD=OnlyForTesting123!');
        try {
            $this->artisan('app:bootstrap-demo')->assertSuccessful();
            $user = User::firstOrFail();
            $this->assertTrue(Hash::check('OnlyForTesting123!', $user->password));
            $this->assertTrue($user->can_import_files);
            $this->assertFalse($user->can_view_contacts);
            putenv('BOOTSTRAP_ADMIN_PASSWORD=DifferentTestPassword!');
            $this->artisan('app:bootstrap-demo')->assertSuccessful();
            $this->assertSame(1, User::count());
            $this->assertSame($user->password, $user->fresh()->password);
        } finally {
            putenv('BOOTSTRAP_ADMIN_EMAIL');
            putenv('BOOTSTRAP_ADMIN_PASSWORD');
        }
    }

    public function test_missing_credentials_do_not_create_an_account(): void
    {
        putenv('BOOTSTRAP_ADMIN_EMAIL');
        putenv('BOOTSTRAP_ADMIN_PASSWORD');
        $this->artisan('app:bootstrap-demo')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
