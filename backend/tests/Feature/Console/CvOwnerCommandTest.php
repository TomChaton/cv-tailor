<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CvOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_owner_account(): void
    {
        $this->artisan('cv:owner', [
            '--name' => 'Fictional Owner',
            '--email' => 'owner@example.test',
            '--password' => 'a-long-enough-secret',
        ])->assertSuccessful();

        $owner = User::query()->sole();

        $this->assertSame('Fictional Owner', $owner->name);
        $this->assertSame('owner@example.test', $owner->email);
        $this->assertTrue(Hash::check('a-long-enough-secret', $owner->password));
    }

    public function test_it_updates_the_one_account_rather_than_adding_a_second(): void
    {
        $this->artisan('cv:owner', [
            '--name' => 'Fictional Owner',
            '--email' => 'owner@example.test',
            '--password' => 'a-long-enough-secret',
        ])->assertSuccessful();

        $this->artisan('cv:owner', [
            '--name' => 'Fictional Owner',
            '--email' => 'moved@example.test',
            '--password' => 'another-long-secret',
        ])->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame('moved@example.test', User::query()->sole()->email);
    }

    public function test_it_refuses_a_password_short_enough_to_guess(): void
    {
        $this->artisan('cv:owner', [
            '--name' => 'Fictional Owner',
            '--email' => 'owner@example.test',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_refuses_something_that_is_not_an_email_address(): void
    {
        $this->artisan('cv:owner', [
            '--name' => 'Fictional Owner',
            '--email' => 'not-an-email',
            '--password' => 'a-long-enough-secret',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_reads_the_environment_when_its_flags_are_absent(): void
    {
        Config::set('cv.credentials.owner_name', 'Fictional Owner');
        Config::set('cv.credentials.owner_email', 'owner@example.test');
        Config::set('cv.credentials.owner_password', 'a-long-enough-secret');

        $this->artisan('cv:owner')->assertSuccessful();

        $owner = User::query()->sole();

        $this->assertSame('owner@example.test', $owner->email);
        $this->assertTrue(Hash::check('a-long-enough-secret', $owner->password));
    }

    public function test_a_flag_beats_the_environment(): void
    {
        Config::set('cv.credentials.owner_name', 'Fictional Owner');
        Config::set('cv.credentials.owner_email', 'owner@example.test');
        Config::set('cv.credentials.owner_password', 'a-long-enough-secret');

        $this->artisan('cv:owner', [
            '--email' => 'moved@example.test',
            '--password' => 'another-long-secret',
        ])->assertSuccessful();

        $owner = User::query()->sole();

        $this->assertSame('Fictional Owner', $owner->name);
        $this->assertSame('moved@example.test', $owner->email);
        $this->assertTrue(Hash::check('another-long-secret', $owner->password));
    }

    public function test_it_still_asks_when_it_has_neither_flags_nor_environment(): void
    {
        $this->artisan('cv:owner')
            ->expectsQuestion('Name', 'Fictional Owner')
            ->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password', 'a-long-enough-secret')
            ->assertSuccessful();

        $this->assertSame('owner@example.test', User::query()->sole()->email);
    }
}
