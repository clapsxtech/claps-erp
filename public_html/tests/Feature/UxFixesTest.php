<?php
namespace Tests\Feature;

use Tests\TestCase;

class UxFixesTest extends TestCase
{
    public function test_forbidden_pages_explain_and_offer_a_way_back(): void
    {
        // An administrator without a linked teacher record opening the teacher workspace.
        $this->actingAs($this->createAdmin())->get('/erp/teacher')->assertForbidden()
            ->assertSee("You don't have access to this page", false)
            ->assertSee('An active, explicitly linked teacher account is required.')
            ->assertSee('Go to home')->assertSee('Go back');
    }

    public function test_administrators_without_teams_are_sent_to_create_one(): void
    {
        $this->actingAs($this->createAdmin())->get('/')->assertOk()
            ->assertSee('No business units yet')->assertSee(route('erp.administration'), false)
            ->assertDontSee('Ask your administrator');
        $this->actingAs($this->createMember())->get('/')->assertOk()->assertSee('Ask your administrator');
    }

    public function test_the_broken_api_user_route_is_gone(): void
    {
        $this->getJson('/api/user', ['Authorization' => 'Bearer anything'])->assertNotFound();
    }
}
