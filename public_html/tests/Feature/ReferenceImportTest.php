<?php
namespace Tests\Feature;

use App\Models\Students;
use App\Models\Teacher;
use App\Models\TeacherReport;
use App\Modules\Integrations\Services\LegacySnapshotSync;
use App\Modules\Integrations\Services\SheetSnapshotReader;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReferenceImportTest extends TestCase
{
    private function masterRow(array $changes = []): array
    {
        return array_replace(['S-QA', 'QA Student', 'T-QA', 'QA Teacher', '01/01/1990', '100', '20', '80', 'QA'], $changes);
    }

    private function values(array $rows): array
    {
        return array_merge([['Header']], $rows);
    }

    private function rejected(callable $operation): void
    {
        try { $operation(); $this->fail('Malformed snapshot was accepted.'); }
        catch (ValidationException $exception) {
            $this->assertStringNotContainsString('01/01/1990', json_encode($exception->errors()));
        }
    }

    public function test_disabled_integrations_never_attempt_credentials_or_google_network(): void
    {
        config(['integrations.sheets.enabled' => false, 'integrations.sheets.credentials_path' => '/must/not/be/read.json']);
        $reader = \Mockery::mock(SheetSnapshotReader::class); $reader->shouldNotReceive('read');
        $this->app->instance(SheetSnapshotReader::class, $reader);
        $sync = \Mockery::mock(LegacySnapshotSync::class); $sync->shouldNotReceive('syncAll');
        $this->app->instance(LegacySnapshotSync::class, $sync);
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('fetch:sheet-data'));
        $this->assertStringContainsString('Spreadsheet synchronization is disabled.', \Illuminate\Support\Facades\Artisan::output());
        // The manual "sync from Sheets" buttons were removed with the old records tools.
        $this->actingAs($this->createAdmin());
        foreach (['syncTeachers', 'syncStudents', 'syncMasters', 'syncTeacherReportData', 'syncDueReportData'] as $path) {
            $this->postJson('/admin/'.$path)->assertNotFound();
        }
    }

    public function test_missing_configuration_fails_before_network(): void
    {
        config(['integrations.sheets.enabled' => true, 'integrations.sheets.spreadsheet_id' => '']);
        try { app(SheetSnapshotReader::class)->read('TEACHER'); $this->fail('Missing configuration accepted.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(503, $exception->getStatusCode()); }
    }

    public function test_master_snapshot_deduplicates_identical_assignments_and_allows_negative_profit(): void
    {
        $this->createMaster(['student_code' => 'OLD']);
        $row = $this->masterRow([6 => '-20.50']);
        app(LegacySnapshotSync::class)->importMasters($this->values([$row, $row]));
        $this->assertDatabaseCount('masters', 1);
        $this->assertDatabaseHas('masters', ['student_code' => 'S-QA', 'profit_per_hour' => '-20.50', 'hour_payment' => '100.00']);
    }

    public function test_conflicting_master_assignments_credentials_names_and_malformed_rates_preserve_snapshot(): void
    {
        $old = $this->createMaster(['student_code' => 'OLD']);
        foreach ([
            [$this->masterRow(), $this->masterRow([5 => '200'])],
            [$this->masterRow(), $this->masterRow([0 => 'S2', 4 => '02/01/1990'])],
            [$this->masterRow(), $this->masterRow([2 => 'T2'])],
            [$this->masterRow(), $this->masterRow([2 => 'T2', 3 => 'Other', 1 => 'Other student'])],
            [$this->masterRow([5 => 'bad-rate'])], [$this->masterRow([7 => '-5'])],
            [$this->masterRow([4 => ''])], [['partial']], [],
        ] as $rows) {
            $this->rejected(fn () => app(LegacySnapshotSync::class)->importMasters($this->values($rows)));
            $this->assertDatabaseCount('masters', 1);
            $this->assertDatabaseHas('masters', ['id' => $old->id, 'student_code' => 'OLD']);
        }
    }

    public function test_database_failure_rolls_back_snapshot_deletion(): void
    {
        $this->createMaster(['student_code' => 'OLD']);
        // Inject once inside the service transaction, without DDL that implicitly commits on MySQL.
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            $sql = str_replace(['`', '"'], '', strtolower($query->sql));
            if ($armed && strpos($sql, 'insert into masters ') === 0) {
                $armed = false;
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new \RuntimeException('Synthetic insert failure'));
            }
        });
        try { app(LegacySnapshotSync::class)->importMasters($this->values([$this->masterRow()])); $this->fail('Expected insertion failure.'); }
        catch (QueryException $exception) { $this->assertDatabaseHas('masters', ['student_code' => 'OLD']); }
        finally { $armed = false; }
    }

    public function test_google_omitted_trailing_optional_cells_are_preserved_as_empty_fields(): void
    {
        app(LegacySnapshotSync::class)->importMasters($this->values([array_slice($this->masterRow(), 0, 8)]));
        $this->assertDatabaseHas('masters', ['teacher_code' => 'T-QA', 'team' => '']);
    }

    public function test_admin_tables_search_real_columns_without_credentials_or_subject_actions(): void
    {
        $this->actingAs($this->createAdmin()); $this->createMaster();
        $this->createMaster(['student_name' => 'Other Student', 'student_code' => 'S-OTHER', 'teacher_name' => 'Other Teacher', 'teacher_code' => 'T-OTHER']);
        Teacher::create(['teacher_name' => 'QA Teacher', 'teacher_code' => 'T-QA']);
        Teacher::create(['teacher_name' => 'Other Teacher', 'teacher_code' => 'T-OTHER']);
        Students::create(['student_name' => 'QA Student', 'student_code' => 'S-QA']);
        Students::create(['student_name' => 'Other Student', 'student_code' => 'S-OTHER']);
        TeacherReport::create(['teacher_name' => 'QA Teacher', 'student_name' => 'QA Student']);
        TeacherReport::create(['teacher_name' => 'Other Teacher', 'student_name' => 'Other Student']);
        // Classic /admin/master, /teachers, /students and /workreport are these records sets.
        foreach (['assignments', 'teachers', 'students', 'teacher-hours'] as $set) {
            $this->getJson('/app-api/records/'.$set.'?q=QA')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)
                ->assertDontSee('01/01/1990')->assertDontSee('teacher_dob')->assertDontSee('teacherDob')->assertDontSee('subjects/');
        }
    }
}
