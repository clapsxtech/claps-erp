<?php

namespace Tests\Feature;

use App\Models\MarklistReport;
use App\Models\TimeTable;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use App\Modules\Integrations\Services\LegacySnapshotSync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccessPrivacyTest extends TestCase
{
    private function mark(array $attributes = []): MarklistReport
    {
        return MarklistReport::create(array_merge([
            'teacher_name' => 'QA Teacher', 'teacher_code' => 'T-QA',
            'student_name' => 'QA Student', 'student_code' => 'S-QA',
            'subject' => 'Mathematics', 'chapter' => 'Algebra',
            'examination_date' => '2026-10-20', 'date_submitted' => '2026-10-21 12:00:00',
            'mark_scored' => '8', 'total_mark' => '10', 'dob' => '01/01/1990',
        ], $attributes));
    }

    private function timetable(): TimeTable
    {
        return TimeTable::create([
            'student_name' => 'QA Student', 'monday_teacher' => 'QA Teacher',
            'monday_time' => '09:00', 'monday_hour' => '1', 'monday_subject' => 'Mathematics',
            'monday_number' => 'secret-phone', 'tuesday_teacher' => 'Another Teacher',
            'tuesday_time' => 'other-teacher-time', 'tuesday_subject' => 'other-teacher-subject',
            'link' => 'https://meet.example.invalid/private-lesson', 'student_number' => 'student-phone',
        ]);
    }

    public function test_public_timetable_html_never_embeds_private_rows_or_directory(): void
    {
        $this->createMaster(); $this->timetable();
        foreach (['/timetable', '/timetableReport'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('QA Student')->assertDontSee('QA Teacher')
                ->assertDontSee('private-lesson')->assertDontSee('secret-phone')->assertDontSee('01/01/1990');
        }
    }

    public function test_timetable_requires_nonempty_valid_credentials(): void
    {
        $this->createMaster(); $this->timetable();
        $this->postJson('/get-teacher-dob', ['teacher_name' => 'QA Teacher'])->assertStatus(422);
        $this->postJson('/get-teacher-dob', ['teacher_name' => 'QA Teacher', 'dob' => 'wrong'])->assertStatus(403)->assertDontSee('private-lesson');
        $this->getJson('/get-teacher-dob?teacher_name=QA%20Teacher&dob=01%2F01%2F1990')->assertStatus(405);
    }

    public function test_timetable_projects_only_verified_teachers_own_day_fields(): void
    {
        $this->createMaster(); $this->timetable();
        $response = $this->postJson('/get-teacher-dob', ['teacher_name' => 'QA Teacher', 'dob' => '01/01/1990']);
        $response->assertOk()->assertJsonPath('days.monday.0.student_name', 'QA Student')
            ->assertJsonPath('days.monday.0.time', '09:00')->assertJsonPath('days.tuesday', [])
            ->assertDontSee('secret-phone')->assertDontSee('student-phone')->assertDontSee('other-teacher-time')
            ->assertDontSee('Another Teacher')->assertDontSee('01/01/1990');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_name_only_timetable_rejects_ambiguous_identity_even_with_code(): void
    {
        $this->createMaster(); $this->createMaster(['teacher_code' => 'T-OTHER']); $this->timetable();
        $this->postJson('/get-teacher-dob', ['teacher_name' => 'QA Teacher', 'teacher_code' => 'T-QA', 'dob' => '01/01/1990'])->assertStatus(403);
    }

    public function test_timetable_does_not_render_executable_meeting_links(): void
    {
        $this->createMaster(); $this->timetable()->update(['link' => 'javascript:alert(1)']);
        $this->postJson('/get-teacher-dob', ['teacher_name' => 'QA Teacher', 'dob' => '01/01/1990'])
            ->assertOk()->assertJsonPath('days.monday.0.link', null);
    }

    /** The classic /markreport and /get-students-mark are the records "assessment-reports" set (filters teacher/student). */
    private const REPORT = '/app-api/records/assessment-reports';

    public function test_report_routes_still_require_a_login(): void
    {
        $this->getJson(self::REPORT)->assertStatus(401);
        $this->getJson(self::REPORT.'?teacher=QA+Teacher')->assertStatus(401);
        $this->get(self::REPORT.'/export')->assertRedirect('/login');
    }

    public function test_staff_cannot_fetch_legacy_reports_even_with_teacher_credentials(): void
    {
        $this->actingAs($this->createMember()); $this->createMaster(); $this->mark();
        $this->getJson(self::REPORT)->assertForbidden()->assertDontSee('QA Student');
        $this->getJson(self::REPORT.'?teacher=QA+Teacher&dob=01%2F01%2F1990')->assertForbidden()->assertDontSee('QA Student');
        $this->get(self::REPORT.'/export?teacher=QA+Teacher')->assertForbidden()->assertDontSee('QA Student');
    }

    public function test_superadmin_teacher_name_filter_includes_matching_names_and_hides_credentials(): void
    {
        $this->actingAs($this->createAdmin()); $this->createMaster(); $this->mark();
        $this->mark(['teacher_name' => 'Another Teacher', 'teacher_code' => 'T-OTHER', 'student_name' => 'Private Student']);
        $this->mark(['teacher_code' => 'T-DUPLICATE', 'student_name' => 'Same Name Different Teacher']);
        $response = $this->getJson(self::REPORT.'?teacher=QA+Teacher&sort=studentName&dir=asc')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.studentName', 'QA Student')->assertJsonPath('data.1.studentName', 'Same Name Different Teacher')
            ->assertDontSee('01/01/1990')->assertDontSee('"dob"');
        $this->assertNotContains('Private Student', array_column($response->json('data'), 'studentName'));
        $csv = $this->get(self::REPORT.'/export?teacher=QA+Teacher')->assertOk()->streamedContent();
        $this->assertStringContainsString('Same Name Different Teacher', $csv);
        $this->assertStringNotContainsString('Private Student', $csv);
        $this->assertStringNotContainsString('01/01/1990', $csv);
    }

    public function test_student_filter_is_intersected_with_verified_teacher_scope(): void
    {
        $this->actingAs($this->createAdmin()); $this->createMaster(); $this->mark();
        $this->mark(['teacher_name' => 'Another Teacher', 'teacher_code' => 'T-OTHER', 'student_name' => 'Private Student']);
        $this->getJson(self::REPORT.'?teacher=QA+Teacher&student=Private+Student')
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data')->assertJsonPath('totals.0.value', '0');
        $scoped = $this->getJson(self::REPORT.'?teacher=QA+Teacher')->assertOk()->assertDontSee('01/01/1990');
        $this->assertSame(['QA Student'], array_values(array_unique(array_column($scoped->json('data'), 'studentName'))));
    }

    public function test_only_site_administrator_can_fetch_all_marks(): void
    {
        $this->actingAs($this->createAdmin()); $this->mark();
        $this->mark(['teacher_name' => 'Another Teacher', 'teacher_code' => 'T-OTHER']);
        $this->getJson(self::REPORT)->assertOk()->assertJsonPath('meta.total', 2)->assertDontSee('"dob"')->assertDontSee('01/01/1990');
    }

    public function test_foreign_business_admin_one_is_rejected_by_admin_and_report_gates(): void
    {
        $this->actingAs($this->createAdmin(['business_id' => 2])); $this->mark();
        $this->getJson('/app-api/records')->assertStatus(403);
        $this->getJson(self::REPORT)->assertStatus(403)->assertDontSee('QA Student');
        $this->get(self::REPORT.'/export')->assertStatus(403);
    }

    public function test_policy_denies_missing_tenant_inactive_and_deleted_users(): void
    {
        $policy = app(SiteAccessPolicy::class);
        $admin = $this->createAdmin();
        $this->assertTrue($policy->isAdministrator($admin));
        $admin->business_id = null; $this->assertFalse($policy->isAdministrator($admin));
        $admin->business_id = 1; $admin->status = 0; $this->assertFalse($policy->isAdministrator($admin));
        $admin->status = 1; $admin->deleted_at = now(); $this->assertFalse($policy->isAdministrator($admin));
        $admin->deleted_at = null; config(['erp.business_id' => 0]); $this->assertFalse($policy->isAdministrator($admin));
    }

    public function test_admin_marklist_search_has_no_unrelated_subject_action_buttons(): void
    {
        $this->actingAs($this->createAdmin()); $this->mark();
        $this->mark(['teacher_name' => 'Another Teacher', 'teacher_code' => 'T-OTHER', 'student_name' => 'Private Student', 'subject' => 'History']);
        $this->getJson(self::REPORT.'?q=QA')
            ->assertOk()->assertJsonCount(1, 'data')->assertDontSee('subjects/')->assertDontSee('"action"')->assertDontSee('01/01/1990');
    }

    public function test_superadmin_markreport_page_has_no_embedded_results_or_password_prompt(): void
    {
        $this->actingAs($this->createAdmin()); $this->createMaster(); $this->mark();
        // The report page is the React records screen; results load only from the JSON read.
        $this->get('/app/records/assessment-reports')->assertOk()->assertDontSee('QA Student')->assertDontSee('type="password"', false)
            ->assertDontSee('01/01/1990');
    }

    public function test_header_only_import_does_not_remove_existing_data(): void
    {
        $this->mark(); $this->timetable();
        foreach (['importTimetable', 'importMarklist'] as $import) {
            try { app(LegacySnapshotSync::class)->$import([['header']]); $this->fail('Header-only import was accepted.'); }
            catch (ValidationException $exception) { $this->assertNotEmpty($exception->errors()); }
        }
        $this->assertDatabaseCount('marklist_reports', 1); $this->assertDatabaseCount('time_tables', 1);
    }

    public function test_canonical_mark_snapshot_accepts_twelve_fields_and_discards_legacy_credentials(): void
    {
        $row = ['QA Teacher','T-QA','QA Student','S-QA','Mathematics','Algebra','8','10','Online','20/10/2026','Good','21/10/2026 12:00:00'];
        app(LegacySnapshotSync::class)->importMarklist([['header'], $row]);
        $this->assertDatabaseHas('marklist_reports', ['examination_date' => '2026-10-20', 'dob' => null]);
        $row[] = 'legacy-credential';
        app(LegacySnapshotSync::class)->importMarklist([['header'], $row]);
        $this->assertDatabaseCount('marklist_reports', 1);
        $this->assertDatabaseHas('marklist_reports', ['dob' => null]);
        $this->assertDatabaseMissing('marklist_reports', ['dob' => 'legacy-credential']);
    }

    public function test_malformed_later_row_preserves_whole_existing_mark_snapshot(): void
    {
        $this->mark();
        try {
            app(LegacySnapshotSync::class)->importMarklist([['header'],
                ['QA Teacher','T-QA','QA Student','S-QA','Mathematics','Algebra','8','10','Online','20/10/2026','Good','21/10/2026 12:00:00'],
                ['invalid'],
            ]);
            $this->fail('Malformed import was accepted.');
        } catch (ValidationException $exception) { $this->assertDatabaseCount('marklist_reports', 1); }
        $this->assertDatabaseHas('marklist_reports', ['dob' => '01/01/1990']);
    }

    public function test_failed_timetable_insert_rolls_back_snapshot_delete(): void
    {
        $this->timetable();
        // Inject once inside the service transaction, without DDL that implicitly commits on MySQL.
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            $sql = str_replace(['`', '"'], '', strtolower($query->sql));
            if ($armed && strpos($sql, 'insert into time_tables ') === 0) {
                $armed = false;
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new \RuntimeException('Synthetic insert failure'));
            }
        });
        try {
            app(LegacySnapshotSync::class)->importTimetable([['header'], ['New Student', '', '', '10:00', 'Mathematics', 'QA Teacher', '1']]);
            $this->fail('The insert should fail.');
        } catch (QueryException $exception) { $this->assertDatabaseCount('time_tables', 1); }
        finally { $armed = false; }
        $this->assertDatabaseHas('time_tables', ['student_name' => 'QA Student']);
    }

    public function test_unconfigured_sync_never_calls_an_external_service_or_deletes_data(): void
    {
        // The manual sync buttons were removed; the scheduled fetch:sheet-data is the only sync left.
        $this->mark(); $this->timetable(); $this->createMaster();
        config(['integrations.sheets.enabled' => true, 'integrations.sheets.spreadsheet_id' => '', 'integrations.sheets.credentials_path' => '/must/not/be/read.json']);
        $this->assertSame(1, Artisan::call('fetch:sheet-data'));
        $this->assertDatabaseCount('marklist_reports', 1); $this->assertDatabaseCount('time_tables', 1); $this->assertDatabaseCount('masters', 1);
        $this->actingAs($this->createAdmin());
        $this->postJson('/admin/syncMarklistReportData')->assertNotFound();
        $this->postJson('/admin/syncTimeTableData')->assertNotFound();
        $this->assertDatabaseCount('marklist_reports', 1); $this->assertDatabaseCount('time_tables', 1);
    }
}
