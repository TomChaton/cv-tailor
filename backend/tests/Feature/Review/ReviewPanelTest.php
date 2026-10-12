<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->get('/review')
            ->assertSuccessful();
    }
}
