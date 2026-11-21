<?php
namespace Tests\Feature;

use App\Models\WorkHour;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntegrationSafetyTest extends TestCase
{



    public function test_write_get_is_rejected_and_post_requires_csrf_outside_testing_bypass(): void
    {
        // The Sheets sync button is gone; the same contract holds for the write endpoints that remain.
        $this->actingAs($this->createAdmin());
        $this->getJson('/erp/administration/teams')->assertStatus(405);
        $this->getJson('/upload-result')->assertStatus(405);
        $this->app->instance('env', 'local');
        try {
            $this->postJson('/erp/administration/teams', ['name' => 'No token'])->assertStatus(419);
            $this->postJson('/upload-result', [])->assertStatus(419);
        } finally { $this->app->instance('env', 'testing'); }
        $this->assertDatabaseMissing('erp_units', ['name' => 'No token']);
    }

    public function test_successful_login_redirects_to_existing_dashboard(): void
    {
        $admin = $this->createAdmin();
        $response = $this->postJson('/login', ['email' => $admin->email, 'password' => 'Local-Testing-Only-2026']);
        $response->assertOk()->assertJsonPath('redirect', url('/app'));
        $this->get($response->json('redirect'))->assertOk()->assertSee('"kind":"staff"', false);
        $this->get('/')->assertRedirect('/app');
    }

    public function test_unsupported_delete_dialogs_are_not_advertised_or_enabled(): void
    {
        // The old records tools' delete dialogs and actions were removed; nothing can delete through them.
        $this->actingAs($this->createAdmin()); $subject = $this->createSubject();
        $row = WorkHour::create(['teacher_name' => 'QA Teacher']);
        $this->getJson('/admin/delete/subjects/'.$subject->id)->assertStatus(404);
        $this->getJson('/admin/delete/marklist/1')->assertStatus(404);
        $this->deleteJson('/admin/subjects/'.$subject->id)->assertStatus(404);
        $this->deleteJson('/admin/workhour/'.$row->id)->assertStatus(404);
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
        $this->assertDatabaseHas('work_hours', ['id' => $row->id]);
        $this->getJson('/app-api/records/subjects')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
