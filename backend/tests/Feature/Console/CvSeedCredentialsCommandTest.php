<?php

namespace Tests\Feature\Console;

use App\Jobs\ExtractPostingFields;
use App\Models\User;
use App\Observability\EventLogger;
use App\Owner\SeedCredentials;
use App\Owner\UpsertOwner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PDOException;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;
use Throwable;

class CvSeedCredentialsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'Fictional Owner';

    private const EMAIL = 'owner@example.test';

    private const PASSWORD = 'a-long-enough-secret';

    /** No pipe, and long enough: see StoreExtensionToken. */
    private const TOKEN = 'fictional-extension-token-0123456789';

    private RecordingEventLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $this->log);
    }

    public function test_it_seeds_the_owner_and_the_token_from_the_environment(): void
    {
        $this->environment(owner: true, token: true);

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $owner = User::query()->sole();

        $this->assertSame(self::NAME, $owner->name);
        $this->assertSame(self::EMAIL, $owner->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $owner->password));

        $token = DB::table('personal_access_tokens')->sole();

        $this->assertSame('extension', $token->name);
        $this->assertSame(hash('sha256', self::TOKEN), $token->token);

        $this->assertSame($owner->getKey(), $this->context('owner.seeded')['user_id']);
        $this->assertSame($token->id, $this->context('token.seeded')['token_id']);
        $this->assertSame('extension', $this->context('token.seeded')['token_name']);
    }

    /**
     * The seeded token is the whole point of seeding it: an extension
     * configured once goes on capturing against a database that was discarded
     * and built again.
     */
    public function test_the_seeded_token_authenticates_against_the_capture_endpoint(): void
    {
        Queue::fake([ExtractPostingFields::class]);

        $this->environment(owner: true, token: true);

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->postJson(
            '/api/v1/postings',
            [
                'url' => 'https://jobs.example.com/vacancy/7781',
                'source_site' => 'jobs.example.com',
                'page_text' => 'Senior PHP Engineer at Example Ltd.',
                'json_ld' => null,
                'has_hidden_text' => false,
            ],
            ['Authorization' => 'Bearer '.self::TOKEN],
        )->assertCreated();
    }

    public function test_an_environment_naming_nothing_seeds_nothing_and_says_nothing(): void
    {
        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame([], $this->log->events());
    }

    public function test_the_owner_variables_alone_seed_the_owner_only(): void
    {
        $this->environment(owner: true, token: false);

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(['owner.created', 'owner.seeded'], $this->log->events());
    }

    public function test_the_token_variable_alone_seeds_the_token_only(): void
    {
        $owner = User::factory()->create();

        $this->environment(owner: false, token: true);

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(
            $owner->getKey(),
            DB::table('personal_access_tokens')->sole()->tokenable_id,
        );
        $this->assertSame(['token.seeded'], $this->log->events());
    }

    public function test_seeding_twice_leaves_one_owner_and_one_token(): void
    {
        $this->environment(owner: true, token: true);

        $this->artisan('cv:seed-credentials')->assertSuccessful();
        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_it_refuses_where_the_environment_is_production(): void
    {
        Config::set('app.env', 'production');

        $this->environment(owner: true, token: true);

        $this->artisan('cv:seed-credentials')
            ->expectsOutputToContain('refused')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        $this->assertSame(['credentials.seeding_refused'], $this->log->events());
        $this->assertSame('production', $this->context('credentials.seeding_refused')['app_env']);
    }

    /**
     * Two thirds of an account is not two thirds of a way in, and silence here
     * would look exactly like the feature not working.
     */
    public function test_it_names_the_owner_variables_the_environment_left_out(): void
    {
        Config::set('cv.credentials.owner_email', self::EMAIL);

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(
            ['CV_OWNER_NAME', 'CV_OWNER_PASSWORD'],
            $this->context('credentials.seeding_incomplete')['missing'],
        );
    }

    public function test_a_token_with_no_owner_to_hang_it_on_is_reported(): void
    {
        $this->environment(owner: false, token: true);

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(['owner'], $this->context('credentials.seeding_incomplete')['missing']);
    }

    public function test_a_password_short_enough_to_guess_is_refused_and_the_stack_still_starts(): void
    {
        $this->environment(owner: true, token: false);
        Config::set('cv.credentials.owner_password', 'short');

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertTrue($this->log->has('credentials.seeding_invalid'));
    }

    /**
     * Sanctum reads a token containing a pipe as `id|secret` and finds it by
     * that id, which a database built again does not hand out.
     */
    public function test_a_token_carrying_a_pipe_is_refused(): void
    {
        $this->environment(owner: true, token: false);
        Config::set('cv.credentials.extension_token', '7|'.self::TOKEN);

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertTrue($this->log->has('credentials.seeding_invalid'));
    }

    public function test_a_value_the_environment_left_a_space_on_is_the_same_credential(): void
    {
        Config::set('cv.credentials.owner_name', ' '.self::NAME.' ');
        Config::set('cv.credentials.owner_email', ' '.self::EMAIL.' ');
        Config::set('cv.credentials.owner_password', ' '.self::PASSWORD.' ');
        Config::set('cv.credentials.extension_token', ' '.self::TOKEN.' ');

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(self::EMAIL, User::query()->sole()->email);
        $this->assertSame(hash('sha256', self::TOKEN), DB::table('personal_access_tokens')->sole()->token);
    }

    /**
     * The one thing this must never do. A password or a token in a log line is
     * a credential in a public bug report.
     */
    public function test_neither_the_password_nor_the_token_reaches_the_log_or_the_output(): void
    {
        $this->environment(owner: true, token: true);

        $this->artisan('cv:seed-credentials')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->doesntExpectOutputToContain(self::TOKEN)
            ->assertSuccessful();

        $written = json_encode($this->log->lines);

        $this->assertIsString($written);
        $this->assertStringNotContainsString(self::PASSWORD, $written);
        $this->assertStringNotContainsString(self::TOKEN, $written);
    }

    /**
     * The entrypoint runs this under `set -e`, so a database that is locked or
     * not writable must leave the stack coming up with the reason in its log
     * rather than not coming up at all.
     */
    public function test_a_failure_the_action_throws_leaves_the_command_successful(): void
    {
        $this->seedingThrows($this->queryFailureQuoting(self::PASSWORD));

        $this->artisan('cv:seed-credentials')->assertSuccessful();

        $this->assertSame(['credentials.seeding_failed'], $this->log->events());
        $this->assertSame(
            QueryException::class,
            $this->context('credentials.seeding_failed')['exception'],
        );
    }

    /**
     * The class of the failure is all that is logged. A query exception quotes
     * the statement it came from, and the statement that writes an owner
     * carries the password.
     */
    public function test_the_message_of_a_thrown_failure_does_not_reach_the_log(): void
    {
        $failure = $this->queryFailureQuoting(self::PASSWORD);

        $this->assertStringContainsString(self::PASSWORD, $failure->getMessage());

        $this->seedingThrows($failure);

        $this->artisan('cv:seed-credentials')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();

        $written = json_encode($this->log->lines);

        $this->assertIsString($written);
        $this->assertStringNotContainsString(self::PASSWORD, $written);
        $this->assertStringNotContainsString($failure->getMessage(), $written);
    }

    /**
     * Only the exit code is protected, and only where it is owned. The action
     * still reports a failure by throwing, so every other caller gets one.
     */
    public function test_the_action_itself_still_throws(): void
    {
        $this->environment(owner: true, token: false);

        $failure = $this->queryFailureQuoting(self::PASSWORD);

        $this->mock(UpsertOwner::class, function (MockInterface $mock) use ($failure): void {
            $mock->shouldReceive('handle')->once()->andThrow($failure);
        });

        $this->expectExceptionObject($failure);

        $this->app->make(SeedCredentials::class)->handle();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(string $event): array
    {
        $line = $this->log->first($event);

        $this->assertNotNull($line, $event.' was never written.');

        return $line['context'];
    }

    private function seedingThrows(Throwable $failure): void
    {
        $this->mock(SeedCredentials::class, function (MockInterface $mock) use ($failure): void {
            $mock->shouldReceive('handle')->once()->andThrow($failure);
        });
    }

    private function queryFailureQuoting(string $password): QueryException
    {
        return new QueryException(
            'sqlite',
            'insert into "users" ("name", "email", "password") values (?, ?, ?)',
            [self::NAME, self::EMAIL, $password],
            new PDOException('attempt to write a readonly database'),
        );
    }

    private function environment(bool $owner, bool $token): void
    {
        if ($owner) {
            Config::set('cv.credentials.owner_name', self::NAME);
            Config::set('cv.credentials.owner_email', self::EMAIL);
            Config::set('cv.credentials.owner_password', self::PASSWORD);
        }

        if ($token) {
            Config::set('cv.credentials.extension_token', self::TOKEN);
        }
    }
}
