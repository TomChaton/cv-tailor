<?php

namespace Tests\Feature\Review;

use App\Filament\Resources\Postings\PostingResource;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_url_leads_to_the_review_ui(): void
    {
        $this->get('/')->assertRedirect('/review');
    }

    public function test_the_review_ui_is_not_served_without_a_login(): void
    {
        $this->get('/review')->assertRedirect('/review/login');
    }

    public function test_the_login_screen_takes_its_words_from_the_catalogue(): void
    {
        $this->get('/review/login')
            ->assertSuccessful()
            ->assertSee(__('panel.brand'));
    }

    public function test_the_owner_can_reach_the_review_ui(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(PostingResource::getUrl())
            ->assertSuccessful();
    }

    public function test_the_review_ui_lands_on_the_postings_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/review')
            ->assertRedirect(PostingResource::getUrl());
    }

    /**
     * Two hops: Filament sends a new session to the panel's root, and the
     * root sends it on to the first item in the navigation.
     */
    public function test_signing_in_arrives_at_the_postings_list(): void
    {
        $user = User::factory()->create();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect('/review');

        $this->get('/review')->assertRedirect(PostingResource::getUrl());
    }
}
