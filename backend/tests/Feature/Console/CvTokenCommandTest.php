<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Owner\IssueExtensionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CvTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    private const GIVEN_TOKEN = 'fictional-extension-token-0123456789';

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

    public function test_it_stores_the_token_the_environment_named_rather_than_generating_one(): void
    {
        User::factory()->create();

        Config::set('cv.credentials.extension_token', self::GIVEN_TOKEN);

        $this->artisan('cv:token')
            ->doesntExpectOutputToContain(self::GIVEN_TOKEN)
            ->assertSuccessful();

        $this->assertSame(
            hash('sha256', self::GIVEN_TOKEN),
            DB::table('personal_access_tokens')->sole()->token,
        );
    }

    public function test_a_token_the_environment_named_replaces_rather_than_accumulates(): void
    {
        User::factory()->create();

        Config::set('cv.credentials.extension_token', self::GIVEN_TOKEN);

        $this->artisan('cv:token')->assertSuccessful();
        $this->artisan('cv:token')->assertSuccessful();

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_it_refuses_a_token_short_enough_to_guess(): void
    {
        User::factory()->create();

        Config::set('cv.credentials.extension_token', 'short');

        $this->artisan('cv:token')->assertFailed();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    /**
     * Sanctum reads a token containing a pipe as `id|secret` and finds it by
     * that id. A database discarded and built again does not hand out the same
     * ids, which is the one thing a token meant to outlive one must not need.
     */
    public function test_it_refuses_a_token_carrying_a_pipe(): void
    {
        User::factory()->create();

        Config::set('cv.credentials.extension_token', '7|'.self::GIVEN_TOKEN);

        $this->artisan('cv:token')->assertFailed();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }
}
