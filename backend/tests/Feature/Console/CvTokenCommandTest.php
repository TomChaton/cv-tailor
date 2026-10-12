<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Owner\IssueExtensionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CvTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_when_there_is_no_owner_yet(): void
    {
        $this->artisan('cv:token')->assertFailed();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_it_issues_a_token_for_the_owner(): void
    {
        User::factory()->create();

        $this->artisan('cv:token')->assertSuccessful();

        $token = DB::table('personal_access_tokens')->sole();

        $this->assertSame('extension', $token->name);
    }

    public function test_it_names_the_token_when_asked(): void
    {
        User::factory()->create();

        $this->artisan('cv:token', ['--name' => 'laptop'])->assertSuccessful();

        $this->assertSame('laptop', DB::table('personal_access_tokens')->sole()->name);
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $owner = User::factory()->create();

        $plainText = app(IssueExtensionToken::class)->handle($owner, 'extension');
        [, $secret] = explode('|', $plainText, 2);

        $stored = DB::table('personal_access_tokens')->sole()->token;

        $this->assertNotSame($secret, $stored);
        $this->assertSame(hash('sha256', $secret), $stored);
    }
}
