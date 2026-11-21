<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpWorkspaceTest extends TestCase
{
    private $unit;
    private $otherUnit;
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.finance_policy' => 'admin_import_delivered_only', 'erp.payroll_policy' => 'approved_attended_only']);
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'HTTP-AA', 'name' => 'Synthetic AA', 'timezone' => 'UTC']);
        $this->otherUnit = DB::table('erp_units')->insertGetId(['code' => 'HTTP-BB', 'name' => 'Synthetic BB', 'timezone' => 'UTC']);
        $this->admin = $this->createAdmin();
        DB::table('erp_memberships')->insert(['user_id' => $this->admin->id, 'unit_id' => $this->unit, 'role' => 'admin']);
    }

    private function action(string $action, array $payload): array
    {
        return $this->postJson('/erp/units/'.$this->unit.'/'.$action, $payload)->assertOk()->json('result');
    }

    private function api(string $path, array $query = [], ?int $unit = null)
    {
        return $this->getJson('/app-api/units/'.($unit ?? $this->unit).'/'.$path.($query ? '?'.http_build_query($query) : ''));
    }

    private function body($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function hostSession(string $html): array
    {
        $marker = '<script type="application/json" id="erp-session">';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start);
        $start += strlen($marker);
        return json_decode(html_entity_decode(substr($html, $start, strpos($html, '</script>', $start) - $start)), true);
    }

    private function teacherRow(string $code): array
    {
        return collect($this->api('teachers', ['q' => $code])->assertOk()->json('data'))->firstWhere('code', $code);
    }

    private function scheduledLesson(): array
    {
        $date = now('UTC')->subDays(3)->toDateString();
        $weekday = (int) now('UTC')->subDays(3)->format('N');
        $student = $this->action('student', ['code' => 'HTTP-S', 'name' => 'Synthetic Student', 'registered_on' => $date, 'timezone' => 'UTC', 'grade' => '8', 'syllabus' => 'Synthetic']);
        $teacher = $this->action('teacher', ['code' => 'HTTP-T', 'name' => 'Synthetic Teacher', 'timezone' => 'UTC']);
        $eligibility = $this->action('eligibility', ['teacher_id' => $teacher['id'], 'subject' => 'Synthetic Maths', 'grade' => '8', 'syllabus' => 'Synthetic']);
        $assignment = $this->action('assign', ['student_id' => $student['id'], 'teacher_id' => $teacher['id'], 'subject_id' => $eligibility['subject_id'], 'starts_on' => $date, 'student_rate_paise' => 12000, 'teacher_rate_paise' => 6000]);
        foreach (['student_id' => $student['id'], 'teacher_id' => $teacher['id']] as $field => $id) {
            $this->action('availability', [$field => $id, 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '12:00', 'timezone' => 'UTC', 'starts_on' => $date, 'ends_on' => $date]);
        }
        $agreement = $this->action('propose', ['enrollment_id' => $assignment['id'], 'weekday' => $weekday, 'start_time' => '10:00', 'duration_minutes' => 60, 'timezone' => 'UTC', 'starts_on' => $date, 'ends_on' => $date]);
        $this->action('confirm', ['agreement_id' => $agreement['id'], 'teacher_confirmed' => true, 'student_confirmed' => true, 'confirmation_channel' => 'call', 'confirmation_note' => 'Synthetic bilateral confirmation']);
        $lessons = $this->action('materialize', ['agreement_id' => $agreement['id'], 'from' => $date, 'through' => $date]);
        $this->assertCount(1, $lessons);
        $this->assertSame([], $this->action('materialize', ['agreement_id' => $agreement['id'], 'from' => $date, 'through' => $date]));
        return [$student, $teacher, $lessons[0]];
    }

    public function test_admin_http_journey_from_registration_through_import_receipt_and_correction(): void
    {
        $this->actingAs($this->admin);
        $this->get('/')->assertRedirect('/app');
        $units = collect($this->hostSession($this->get('/app')->assertOk()->getContent())['units']);
        $this->assertSame(['Synthetic AA', 'Synthetic BB'], $units->pluck('name')->sort()->values()->all());
        $this->assertContains('student', $units->firstWhere('id', $this->unit)['actions']);
        [$student, $teacher, $lesson] = $this->scheduledLesson();
        $attendance = $this->action('attendance', ['lesson_id' => $lesson['id'], 'actual_minutes' => 45]);
        $this->assertSame(0, DB::table('erp_ledger')->count());
        $reviewed = $this->action('approve', ['attendance_id' => $attendance['id']]);
        $this->assertFalse($reviewed['student_financial_posted']);
        $this->assertTrue($reviewed['teacher_financial_posted']);
        $this->assertSame(1, DB::table('erp_ledger')->count());
        $this->assertSame(4500, (int) DB::table('erp_ledger')->where('teacher_id', $teacher['id'])->sum('amount_paise'));
        $this->assertSame(0, (int) DB::table('erp_ledger')->where('student_id', $student['id'])->sum('amount_paise'));
        // The browser form submits comma-separated IDs; JSON callers may use arrays.
        $batch = $this->action('import-batch', ['attendance_ids' => (string) $attendance['id'], 'reference' => 'HTTP-IMPORT-1']);
        $this->assertSame($batch['id'], $this->action('import-batch', ['attendance_ids' => [$attendance['id']], 'reference' => 'HTTP-IMPORT-1'])['id']);
        $this->assertSame(-9000, (int) DB::table('erp_ledger')->where('student_id', $student['id'])->sum('amount_paise'));
        $receipt = $this->action('payment', ['student_id' => $student['id'], 'amount_paise' => 20000, 'reference' => 'HTTP-RECEIPT-1']);
        $this->api('print/receipt/'.$receipt['id'])->assertOk()->assertJsonPath('reference', 'HTTP-RECEIPT-1')->assertJsonPath('studentName', 'Synthetic Student');
        $this->api('students')->assertOk()->assertJsonPath('data.0.name', 'Synthetic Student');
        $this->api('teachers')->assertOk()->assertJsonPath('data.0.name', 'Synthetic Teacher');
        $this->action('correct', ['attendance_id' => $attendance['id'], 'actual_minutes' => 30, 'reason' => 'Synthetic teacher correction']);
        $this->assertSame(20000, (int) DB::table('erp_ledger')->where('student_id', $student['id'])->sum('amount_paise'));
        // Corrected submitted attendance can be imported without a separate approval.
        $this->action('import-batch', ['attendance_ids' => [$attendance['id']], 'reference' => 'HTTP-IMPORT-2']);
        $this->assertSame(14000, (int) DB::table('erp_ledger')->where('student_id', $student['id'])->sum('amount_paise'));
        $this->assertSame(1, DB::table('erp_ledger')->where('kind', 'teacher_accrual')->count());
        $this->assertSame(0, (int) DB::table('erp_ledger')->where('teacher_id', $teacher['id'])->sum('amount_paise'));
        $this->assertDatabaseHas('erp_attendance', ['id' => $attendance['id'], 'status' => 'submitted', 'reviewed_by' => null, 'reviewed_at' => null]);
        $this->assertSame(2, DB::table('erp_ledger')->where('kind', 'reversal')->count());
        $reapproved = $this->action('approve', ['attendance_id' => $attendance['id']]);
        $this->assertTrue($reapproved['teacher_financial_posted']);
        $this->assertTrue($reapproved['student_financial_posted']);
        $this->assertSame(3000, (int) DB::table('erp_ledger')->where('teacher_id', $teacher['id'])->sum('amount_paise'));
        $this->assertSame(14000, (int) DB::table('erp_ledger')->where('student_id', $student['id'])->sum('amount_paise'));
        $this->assertSame(3000, $this->teacherRow('HTTP-T')['earnedPaise']);
        $this->assertSame(2, DB::table('erp_attendance_batches')->count());
        $this->assertDatabaseHas('erp_audit', ['action' => 'attendance.corrected', 'actor_id' => $this->admin->id]);
    }

    public function test_missing_membership_cross_unit_and_teacher_cannot_access_admin_workspace(): void
    {
        $this->actingAs($this->admin);
        foreach (['students', 'teachers', 'attendance'] as $read) $this->api($read, [], $this->otherUnit)->assertOk();
        $this->postJson('/erp/units/'.$this->otherUnit.'/student', [])->assertUnprocessable();
        $unassigned = $this->createMember();
        foreach (['students', 'teachers', 'attendance'] as $read) $this->actingAs($unassigned)->api($read)->assertForbidden();
        $this->get('/app')->assertOk();
        $this->assertSame([], $this->hostSession($this->get('/app')->getContent())['units']);
        $teacher = $this->createMember();
        DB::table('erp_memberships')->insert(['user_id' => $teacher->id, 'unit_id' => $this->unit, 'role' => 'teacher']);
        foreach (['students', 'teachers', 'attendance'] as $read) $this->actingAs($teacher)->api($read)->assertForbidden();
        $this->postJson('/erp/units/'.$this->unit.'/import-batch', ['attendance_ids' => [1], 'reference' => 'DENIED'])->assertForbidden();
        $this->postJson('/erp/units/'.$this->unit.'/payment', [])->assertForbidden();
        $this->assertSame(0, DB::table('erp_ledger')->count());
        $outsider = $this->createMember(['business_id' => 999]);
        DB::table('erp_memberships')->insert(['user_id' => $outsider->id, 'unit_id' => $this->unit, 'role' => 'admin']);
        foreach (['students', 'teachers', 'attendance'] as $read) $this->actingAs($outsider)->api($read)->assertForbidden();
        // Positive control: an assigned admin of this site reads the same screens.
        foreach (['students', 'teachers', 'attendance'] as $read) $this->actingAs($this->admin)->api($read)->assertOk();
    }

    public function test_cancellation_http_never_creates_charge_and_bad_unit_payload_is_rejected(): void
    {
        $this->actingAs($this->admin);
        [$student, $teacher, $lesson] = $this->scheduledLesson();
        $this->action('cancel', ['lesson_id' => $lesson['id'], 'reason' => 'Synthetic cancellation']);
        $this->postJson('/erp/units/'.$this->unit.'/attendance', ['lesson_id' => $lesson['id'], 'actual_minutes' => 60])->assertUnprocessable();
        DB::table('erp_memberships')->insert(['user_id' => $this->admin->id, 'unit_id' => $this->otherUnit, 'role' => 'admin']);
        $this->postJson('/erp/units/'.$this->otherUnit.'/payment', ['student_id' => $student['id'], 'amount_paise' => 10000, 'reference' => 'BAD-UNIT'])->assertUnprocessable();
        $this->postJson('/erp/units/'.$this->otherUnit.'/attendance', ['lesson_id' => $lesson['id'], 'actual_minutes' => 60])->assertUnprocessable();
        $this->assertSame(0, DB::table('erp_ledger')->count());
        $this->assertSame(0, DB::table('erp_attendance')->count());
    }
    public function test_workspace_search_and_independent_pagination_keep_unit_boundary(): void
    {
        $this->actingAs($this->admin);
        for ($i = 1; $i <= 55; $i++) {
            DB::table('erp_students')->insert(['unit_id' => $this->unit, 'code' => sprintf('PAGE-CODE-%03d', $i), 'name' => sprintf('Pagination Student %03d', $i), 'registered_on' => now('UTC')->subDay()->toDateString(), 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'Synthetic']);
        }
        DB::table('erp_students')->insert(['unit_id' => $this->otherUnit, 'code' => 'PAGE-CODE-FOREIGN', 'name' => 'Pagination Student Hidden BB', 'registered_on' => now('UTC')->subDay()->toDateString(), 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'Synthetic']);
        $this->action('teacher', ['code' => 'PAGE-TEACHER', 'name' => 'Pagination Teacher', 'timezone' => 'UTC']);
        // Newest-first page of 50, as the classic workspace listed them.
        $list = fn (array $query = []) => $this->api('students', $query + ['per_page' => 50, 'sort' => 'name', 'dir' => 'desc'])->assertOk();
        $first = $list();
        $this->assertStringContainsString('Pagination Student 055', $this->body($first));
        $this->assertStringNotContainsString('Pagination Student 001', $this->body($first));
        $this->assertStringNotContainsString('Pagination Student Hidden BB', $this->body($first));
        $this->assertCount(50, $first->json('data'));
        $this->assertSame(55, $first->json('meta.total'));
        $second = $list(['page' => 2]);
        $this->assertStringContainsString('Pagination Student 001', $this->body($second));
        $this->assertStringNotContainsString('Pagination Student 055', $this->body($second));
        $this->assertStringNotContainsString('Pagination Student Hidden BB', $this->body($second));
        $this->assertCount(5, $second->json('data'));
        $this->assertSame(2, $second->json('meta.page'));
        // Teachers page independently of students.
        $this->api('teachers', ['per_page' => 50])->assertOk()->assertJsonPath('meta.page', 1)->assertJsonPath('data.0.name', 'Pagination Teacher');
        $nameSearch = $list(['q' => 'Pagination Student 003']);
        $this->assertSame(['Pagination Student 003'], array_column($nameSearch->json('data'), 'name'));
        $this->assertSame(1, $nameSearch->json('meta.total'));
        $codeSearch = $list(['q' => 'PAGE-CODE-002']);
        $this->assertSame(['Pagination Student 002'], array_column($codeSearch->json('data'), 'name'));
        $this->assertSame(1, $codeSearch->json('meta.total'));
        $list(['q' => 'Hidden'])->assertJsonPath('meta.total', 0);
        $filteredPage = $list(['q' => 'PAGE-CODE', 'page' => 2]);
        $this->assertStringContainsString('Pagination Student 001', $this->body($filteredPage));
        $this->assertStringNotContainsString('Pagination Student Hidden BB', $this->body($filteredPage));
        $this->assertSame(55, $filteredPage->json('meta.total'));
    }

    public function test_tasks_and_marks_http_creation_completion_and_validation(): void
    {
        $this->actingAs($this->admin);
        [$student, $teacher, $lesson] = $this->scheduledLesson();
        $task = $this->action('task', ['title' => 'Synthetic guardian follow-up', 'notes' => 'Discuss next lesson', 'due_on' => now('UTC')->addDay()->toDateString(), 'student_id' => $student['id'], 'assigned_to' => $this->admin->id]);
        $this->assertDatabaseHas('erp_tasks', ['id' => $task['id'], 'unit_id' => $this->unit, 'status' => 'open', 'created_by' => $this->admin->id]);
        $this->action('complete-task', ['task_id' => $task['id']]);
        $this->action('complete-task', ['task_id' => $task['id']]);
        $this->assertDatabaseHas('erp_tasks', ['id' => $task['id'], 'status' => 'completed']);
        $this->assertSame(1, DB::table('erp_audit')->where('action', 'task.completed')->where('entity_id', $task['id'])->count());
        $mark = $this->action('mark', ['enrollment_id' => $lesson['enrollment_id'], 'assessment' => 'Synthetic fractions quiz', 'assessed_on' => now('UTC')->subDay()->toDateString(), 'score' => 8, 'maximum' => 10, 'notes' => 'Synthetic result']);
        $this->assertDatabaseHas('erp_marks', ['id' => $mark['id'], 'unit_id' => $this->unit, 'score' => 8, 'maximum' => 10]);
        $this->api('overview/tasks', ['scope' => 'all', 'status' => 'all'])->assertOk()->assertJsonPath('data.0.title', 'Synthetic guardian follow-up')->assertJsonPath('data.0.status', 'completed');
        $this->api('academics')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.assessment', 'Synthetic fractions quiz');
        $this->postJson('/erp/units/'.$this->unit.'/mark', ['enrollment_id' => $lesson['enrollment_id'], 'assessment' => 'Invalid score', 'assessed_on' => now('UTC')->toDateString(), 'score' => 11, 'maximum' => 10])->assertUnprocessable();
        DB::table('erp_memberships')->insert(['user_id' => $this->admin->id, 'unit_id' => $this->otherUnit, 'role' => 'admin']);
        $this->postJson('/erp/units/'.$this->otherUnit.'/complete-task', ['task_id' => $task['id']])->assertNotFound();
        $this->postJson('/erp/units/'.$this->otherUnit.'/task', ['title' => 'Cross-unit task', 'student_id' => $student['id']])->assertUnprocessable();
        $this->postJson('/erp/units/'.$this->otherUnit.'/mark', ['enrollment_id' => $lesson['enrollment_id'], 'assessment' => 'Cross-unit assessment', 'assessed_on' => now('UTC')->toDateString(), 'score' => 8, 'maximum' => 10])->assertUnprocessable();
        $this->assertSame(1, DB::table('erp_marks')->count());
        $this->assertSame(1, DB::table('erp_tasks')->count());
    }

    public function test_exception_and_agreement_replacement_forms_integrate_with_unit_scoping(): void
    {
        $this->actingAs($this->admin);
        [$student, $teacher, $lesson] = $this->scheduledLesson();
        $today = now('UTC')->toDateString();
        $tomorrow = now('UTC')->addDay()->toDateString();
        $exceptionPayload = ['student_id' => (string) $student['id'], 'teacher_id' => '', 'date' => $tomorrow, 'reason' => 'Synthetic student unavailable'];
        $this->postJson('/erp/units/'.$this->unit.'/exception', $exceptionPayload)->assertOk();
        $this->assertDatabaseHas('erp_exceptions', ['unit_id' => $this->unit, 'student_id' => $student['id'], 'teacher_id' => null, 'date' => $tomorrow, 'reason' => 'Synthetic student unavailable']);
        $this->postJson('/erp/units/'.$this->unit.'/exception', ['student_id' => '', 'teacher_id' => (string) $teacher['id'], 'date' => $tomorrow, 'reason' => 'Synthetic teacher holiday'])->assertOk();
        $plans = $this->api('timetable/plans')->assertOk();
        $this->assertSame(2, $plans->json('exceptions.meta.total'));
        $this->assertSame(['Synthetic teacher holiday', 'Synthetic student unavailable'], array_column($plans->json('exceptions.data'), 'reason'));
        $original = $this->action('propose', ['enrollment_id' => $lesson['enrollment_id'], 'weekday' => (int) now('UTC')->format('N'), 'start_time' => '10:00', 'duration_minutes' => 60, 'timezone' => 'UTC', 'starts_on' => $today, 'ends_on' => now('UTC')->addDays(14)->toDateString()]);
        $this->postJson('/erp/units/'.$this->unit.'/end-agreement', ['agreement_id' => (string) $original['id'], 'ends_on' => $today])->assertOk();
        $replacementPayload = ['enrollment_id' => (string) $lesson['enrollment_id'], 'replaces_agreement_id' => (string) $original['id'], 'weekday' => (string) now('UTC')->addDays(2)->format('N'), 'start_time' => '11:00', 'duration_minutes' => '45', 'timezone' => 'UTC', 'starts_on' => $tomorrow, 'ends_on' => now('UTC')->addDays(14)->toDateString()];
        $this->postJson('/erp/units/'.$this->unit.'/propose', $replacementPayload)->assertOk();
        $this->assertDatabaseHas('erp_agreements', ['id' => $original['id'], 'ends_on' => $today]);
        $this->assertDatabaseHas('erp_agreements', ['unit_id' => $this->unit, 'replaces_agreement_id' => $original['id'], 'start_time' => '11:00', 'duration_minutes' => 45, 'status' => 'proposed']);
        $this->assertDatabaseHas('erp_lessons', ['id' => $lesson['id'], 'student_rate_paise' => 12000, 'teacher_rate_paise' => 6000]);
        DB::table('erp_memberships')->insert(['user_id' => $this->admin->id, 'unit_id' => $this->otherUnit, 'role' => 'admin']);
        $this->postJson('/erp/units/'.$this->otherUnit.'/exception', $exceptionPayload)->assertUnprocessable();
        $this->postJson('/erp/units/'.$this->otherUnit.'/exception', ['teacher_id' => $teacher['id'], 'date' => $tomorrow, 'reason' => 'Wrong unit'])->assertUnprocessable();
        $this->postJson('/erp/units/'.$this->otherUnit.'/end-agreement', ['agreement_id' => $original['id'], 'ends_on' => $today])->assertUnprocessable();
        $this->postJson('/erp/units/'.$this->otherUnit.'/propose', $replacementPayload)->assertUnprocessable();
        $this->assertSame(2, DB::table('erp_exceptions')->count());
        $this->assertSame(1, DB::table('erp_agreements')->where('replaces_agreement_id', $original['id'])->count());
        $this->assertSame(0, DB::table('erp_ledger')->count());
    }

    public function test_student_import_before_payroll_approval_preserves_frozen_rates_and_separate_status(): void
    {
        $this->actingAs($this->admin);
        [$student, $teacher, $lesson] = $this->scheduledLesson();
        DB::table('erp_enrollments')->where('id', $lesson['enrollment_id'])->update(['student_rate_paise' => 24000, 'teacher_rate_paise' => 18000]);
        $attendance = $this->action('attendance', ['lesson_id' => $lesson['id'], 'actual_minutes' => 45]);
        $this->action('import-batch', ['attendance_ids' => [$attendance['id']], 'reference' => 'HTTP-STUDENT-FIRST']);
        $this->assertSame(-9000, (int) DB::table('erp_ledger')->where('student_id', $student['id'])->sum('amount_paise'));
        $this->assertSame(0, DB::table('erp_ledger')->where('kind', 'teacher_accrual')->count());
        $this->assertDatabaseHas('erp_attendance', ['id' => $attendance['id'], 'status' => 'submitted']);
        $this->postJson('/erp/units/'.$this->unit.'/approve', ['attendance_id' => $attendance['id']])->assertOk()
            ->assertJsonPath('message', 'Attendance approved. Teacher accrual: posted. Student charge: already imported. No money was transferred.');
        $approved = $this->action('approve', ['attendance_id' => $attendance['id']]);
        $this->assertTrue($approved['teacher_financial_posted']);
        $this->assertTrue($approved['student_financial_posted']);
        $this->assertSame(4500, (int) DB::table('erp_ledger')->where('teacher_id', $teacher['id'])->sum('amount_paise'));
        $this->assertSame(1, DB::table('erp_ledger')->where('kind', 'teacher_accrual')->count());
        $this->assertSame(-9000, (int) DB::table('erp_ledger')->where('student_id', $student['id'])->sum('amount_paise'));
        $this->assertSame(4500, $this->teacherRow('HTTP-T')['earnedPaise']);
    }

    public function test_one_off_demo_http_is_free_unpaid_and_reference_idempotent(): void
    {
        $this->actingAs($this->admin);
        [$student, $teacher, $lesson] = $this->scheduledLesson();
        $payload = ['enrollment_id' => $lesson['enrollment_id'], 'date' => substr($lesson['starts_at'], 0, 10), 'start_time' => '11:00', 'timezone' => 'UTC', 'duration_minutes' => 45, 'kind' => 'demo', 'teacher_confirmed' => true, 'student_confirmed' => true, 'confirmation_channel' => 'call', 'confirmation_note' => 'Synthetic free demo agreement', 'reference' => 'HTTP-DEMO-1'];
        $demo = $this->action('extra', $payload);
        $this->assertSame($demo['id'], $this->action('extra', $payload)['id']);
        $this->assertSame('demo', $demo['kind']);
        $this->postJson('/erp/units/'.$this->unit.'/extra', array_merge($payload, ['duration_minutes' => 30]))->assertUnprocessable();
        $attendance = $this->action('attendance', ['lesson_id' => $demo['id'], 'actual_minutes' => 45]);
        $approved = $this->action('approve', ['attendance_id' => $attendance['id']]);
        $this->assertFalse($approved['teacher_financial_posted']);
        $this->assertFalse($approved['student_financial_posted']);
        $this->action('import-batch', ['attendance_ids' => [$attendance['id']], 'reference' => 'HTTP-DEMO-IMPORT']);
        $this->assertSame(0, DB::table('erp_ledger')->count());
        $this->assertSame(0, $this->teacherRow('HTTP-T')['earnedPaise']);
        $this->api('students')->assertOk()->assertJsonPath('data.0.code', 'HTTP-S')->assertJsonPath('data.0.balancePaise', 0);
    }

}
