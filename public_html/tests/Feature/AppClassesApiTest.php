<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** JSON reads for the Classes screens keep the classic role matrix and field lists. Synthetic data only. */
class AppClassesApiTest extends TestCase
{
    private int $unit;
    private int $other;
    private int $student;
    private int $teacher;
    private int $subject;
    private int $enrollment;
    private int $scheduled;
    private int $submitted;
    private int $approved;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'CLS-A', 'name' => 'Classes Unit A', 'timezone' => 'UTC']);
        $this->other = DB::table('erp_units')->insertGetId(['code' => 'CLS-B', 'name' => 'Private Unit B', 'timezone' => 'UTC']);
        $this->student = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'CLS-S1', 'name' => 'Synthetic Learner', 'registered_on' => '2026-01-01', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE']);
        $this->teacher = DB::table('erp_teachers')->insertGetId(['code' => 'CLS-T1', 'name' => 'Synthetic Tutor', 'timezone' => 'UTC', 'status' => 'active']);
        DB::table('erp_teacher_units')->insert(['teacher_id' => $this->teacher, 'unit_id' => $this->unit]);
        $this->subject = DB::table('erp_subjects')->insertGetId(['name' => 'Synthetic Algebra']);
        DB::table('erp_eligibilities')->insert(['teacher_id' => $this->teacher, 'subject_id' => $this->subject, 'grade' => '8', 'syllabus' => 'CBSE']);
        $this->enrollment = DB::table('erp_enrollments')->insertGetId(['unit_id' => $this->unit, 'student_id' => $this->student, 'teacher_id' => $this->teacher, 'subject_id' => $this->subject, 'starts_on' => '2026-01-01', 'status' => 'active', 'student_rate_paise' => 45000, 'teacher_rate_paise' => 30000, 'currency' => 'INR']);
        $lesson = fn (string $at, string $status, string $kind = 'regular') => DB::table('erp_lessons')->insertGetId(['unit_id' => $this->unit, 'enrollment_id' => $this->enrollment, 'student_id' => $this->student, 'teacher_id' => $this->teacher, 'subject_id' => $this->subject, 'starts_at' => $at, 'ends_at' => gmdate('Y-m-d H:i:s', strtotime($at.' UTC') + 3600), 'status' => $status, 'kind' => $kind, 'student_rate_paise' => 45000, 'teacher_rate_paise' => 30000, 'currency' => 'INR']);
        $this->scheduled = $lesson('2026-01-06 10:00:00', 'scheduled');
        $done = $lesson('2026-01-07 10:00:00', 'completed');
        $doneToo = $lesson('2026-01-08 10:00:00', 'completed');
        $lesson('2026-01-09 10:00:00', 'cancelled');
        $this->submitted = DB::table('erp_attendance')->insertGetId(['unit_id' => $this->unit, 'lesson_id' => $done, 'actual_minutes' => 60, 'status' => 'submitted', 'submitted_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->approved = DB::table('erp_attendance')->insertGetId(['unit_id' => $this->unit, 'lesson_id' => $doneToo, 'actual_minutes' => 55, 'status' => 'approved', 'submitted_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_ledger')->insert(['unit_id' => $this->unit, 'teacher_id' => $this->teacher, 'attendance_id' => $this->approved, 'kind' => 'teacher_accrual', 'amount_paise' => 27500, 'currency' => 'INR', 'reference' => 'CLS-ACC-1', 'event_key' => 'cls-acc-1', 'occurred_at' => now(), 'created_by' => 1]);
        DB::table('erp_marks')->insert(['unit_id' => $this->unit, 'enrollment_id' => $this->enrollment, 'assessment' => 'Synthetic quiz', 'assessed_on' => '2026-01-15', 'score' => 8, 'maximum' => 10, 'notes' => 'Good', 'created_by' => 1]);
        DB::table('erp_marks')->insert(['unit_id' => $this->unit, 'enrollment_id' => $this->enrollment, 'assessment' => 'Synthetic test', 'assessed_on' => '2026-02-15', 'score' => 5, 'maximum' => 20, 'created_by' => 1]);

        // Another unit's records that must never leak.
        $ostudent = DB::table('erp_students')->insertGetId(['unit_id' => $this->other, 'code' => 'CLS-SECRET', 'name' => 'Private Other Learner', 'registered_on' => '2026-01-01', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE']);
        $oteacher = DB::table('erp_teachers')->insertGetId(['code' => 'CLS-OT', 'name' => 'Private Other Tutor', 'timezone' => 'UTC', 'status' => 'active']);
        DB::table('erp_teacher_units')->insert(['teacher_id' => $oteacher, 'unit_id' => $this->other]);
        $oenrollment = DB::table('erp_enrollments')->insertGetId(['unit_id' => $this->other, 'student_id' => $ostudent, 'teacher_id' => $oteacher, 'subject_id' => $this->subject, 'starts_on' => '2026-01-01', 'status' => 'active', 'student_rate_paise' => 1, 'teacher_rate_paise' => 1, 'currency' => 'INR']);
        DB::table('erp_lessons')->insert(['unit_id' => $this->other, 'enrollment_id' => $oenrollment, 'student_id' => $ostudent, 'teacher_id' => $oteacher, 'subject_id' => $this->subject, 'starts_at' => '2026-01-06 12:00:00', 'ends_at' => '2026-01-06 13:00:00', 'status' => 'scheduled', 'kind' => 'regular', 'student_rate_paise' => 1, 'teacher_rate_paise' => 1, 'currency' => 'INR']);
        // A malformed mark in this unit pointing at the other unit's enrollment.
        DB::table('erp_marks')->insert(['unit_id' => $this->unit, 'enrollment_id' => $oenrollment, 'assessment' => 'Private malformed', 'assessed_on' => '2026-01-20', 'score' => 1, 'maximum' => 2, 'created_by' => 1]);
    }

    private function member(string $role, ?int $unit = null)
    {
        $user = $this->createMember(['name' => 'Synthetic '.$role]);
        DB::table('erp_memberships')->insert(['unit_id' => $unit ?? $this->unit, 'user_id' => $user->id, 'role' => $role]);
        return $user;
    }

    private function api(string $path, ?int $unit = null): string
    {
        return '/app-api/units/'.($unit ?? $this->unit).$path;
    }

    private function assertNoMoney(array $row): void
    {
        foreach (array_keys($row) as $key) $this->assertStringNotContainsString('paise', strtolower($key), $key.' leaks a money field');
    }

    public function test_timetable_week_is_scoped_filtered_and_whitelisted(): void
    {
        $json = $this->actingAs($this->member('academics'))->getJson($this->api('/timetable?date=2026-01-07'))->assertOk()->json();
        $this->assertSame(['start' => '2026-01-05', 'end' => '2026-01-11', 'timezone' => 'UTC', 'today' => now('UTC')->toDateString(), 'prevDate' => '2025-12-29', 'nextDate' => '2026-01-12', 'count' => 4, 'truncated' => false], $json['week']);
        $this->assertCount(7, $json['days']);
        $tuesday = $json['days'][1];
        $this->assertSame('2026-01-06', $tuesday['date']);
        $this->assertCount(1, $tuesday['lessons']);
        $lesson = $tuesday['lessons'][0];
        $this->assertSame(['id', 'enrollmentId', 'studentId', 'studentName', 'studentCode', 'teacherId', 'teacherName', 'teacherCode', 'subjectName', 'status', 'kind', 'localDate', 'startTime', 'endTime', 'endDate', 'utcOffset', 'durationMinutes', 'hasEnded', 'cancellationReason'], array_keys($lesson));
        $this->assertSame($this->scheduled, $lesson['id']);
        $this->assertSame('10:00', $lesson['startTime']);
        $this->assertSame(60, $lesson['durationMinutes']);
        $this->assertTrue($lesson['hasEnded']);
        $this->assertNoMoney($lesson);
        $this->assertStringNotContainsString('Private Other', json_encode($json));

        $this->getJson($this->api('/timetable?date=2026-01-07&status=cancelled'))->assertOk()->assertJsonPath('week.count', 1);
        $this->getJson($this->api('/timetable?date=2026-01-07&student=CLS-S1'))->assertOk()->assertJsonPath('week.count', 4);
        $this->getJson($this->api('/timetable?date=2026-01-07&teacher=Nobody'))->assertOk()->assertJsonPath('week.count', 0);
        $this->getJson($this->api('/timetable?status=deleted'))->assertStatus(422);
        $this->getJson($this->api('/timetable', $this->other))->assertForbidden();
    }

    public function test_timetable_plans_list_agreements_and_exceptions(): void
    {
        DB::table('erp_agreements')->insert(['unit_id' => $this->unit, 'enrollment_id' => $this->enrollment, 'weekday' => 2, 'start_time' => '10:00', 'duration_minutes' => 60, 'timezone' => 'UTC', 'starts_on' => '2026-01-06', 'status' => 'confirmed']);
        DB::table('erp_exceptions')->insert(['unit_id' => $this->unit, 'teacher_id' => $this->teacher, 'date' => '2026-01-10', 'reason' => 'Synthetic leave']);
        $json = $this->actingAs($this->member('read_only'))->getJson($this->api('/timetable/plans'))->assertOk()->json();
        $this->assertSame(['id', 'enrollmentId', 'weekday', 'startTime', 'durationMinutes', 'timezone', 'startsOn', 'endsOn', 'status', 'studentName', 'teacherName', 'subjectName'], array_keys($json['agreements']['data'][0]));
        $this->assertSame('Tuesday', $json['agreements']['data'][0]['weekday']);
        $this->assertSame(['id', 'date', 'interval', 'startsAtUtc', 'endsAtUtc', 'snapshotTimezone', 'isLegacy', 'who', 'party', 'reason'], array_keys($json['exceptions']['data'][0]));
        $this->assertSame('teacher', $json['exceptions']['data'][0]['party']);
        // A row without a UTC snapshot is a legacy full day in the participant's timezone.
        $this->assertSame(['Full day · participant timezone (legacy)', null, null, true], [$json['exceptions']['data'][0]['interval'], $json['exceptions']['data'][0]['startsAtUtc'], $json['exceptions']['data'][0]['endsAtUtc'], $json['exceptions']['data'][0]['isLegacy']]);
        // A snapshot row carries its stored UTC interval; an end on the next local date is marked.
        DB::table('erp_exceptions')->insert(['unit_id' => $this->unit, 'teacher_id' => $this->teacher, 'date' => '2026-01-11', 'starts_at' => '2026-01-11 17:30:00', 'ends_at' => '2026-01-11 18:30:00', 'timezone' => 'Asia/Kolkata', 'reason' => 'Synthetic late slot']);
        $row = $this->getJson($this->api('/timetable/plans'))->assertOk()->json('exceptions.data.0');
        $this->assertSame(['23:00–00:00 (next day) Asia/Kolkata', '2026-01-11 17:30:00', '2026-01-11 18:30:00', 'Asia/Kolkata', false], [$row['interval'], $row['startsAtUtc'], $row['endsAtUtc'], $row['snapshotTimezone'], $row['isLegacy']]);
        $this->getJson($this->api('/timetable/plans', $this->other))->assertForbidden();
        $this->assertTrue($json['can']['openPlanning']);
    }

    public function test_every_endpoint_refuses_finance_and_other_units(): void
    {
        $paths = ['/timetable', '/timetable/plans', '/attendance', '/attendance/queues/approval', '/attendance/queues/import', '/attendance/queues/missing', '/attendance/reviews', '/matching', '/matching/teachers', '/matching/prepare/'.$this->teacher, '/academics', '/academics/eligibility'];
        $this->actingAs($this->member('finance'));
        foreach ($paths as $path) $this->getJson($this->api($path))->assertForbidden();
        $this->actingAs($this->member('team_manager'));
        foreach ($paths as $path) $this->getJson($this->api($path, $this->other))->assertForbidden();
        // read_only may open every page but cannot prepare teachers (an allocation-qualify action).
        $this->actingAs($this->member('read_only'));
        foreach (array_diff($paths, ['/matching/teachers', '/matching/prepare/'.$this->teacher]) as $path) $this->getJson($this->api($path))->assertOk();
        $this->getJson($this->api('/matching/prepare/'.$this->teacher))->assertForbidden();
    }

    public function test_timetable_and_attendance_refuse_roles_without_the_page(): void
    {
        foreach (['finance', 'sales_followups'] as $role) {
            $this->actingAs($this->member($role))->getJson($this->api('/timetable'))->assertForbidden();
            $this->getJson($this->api('/attendance'))->assertForbidden();
            $this->getJson($this->api('/attendance/queues/approval'))->assertForbidden();
            $this->getJson($this->api('/academics'))->assertForbidden();
        }
    }

    public function test_attendance_register_shows_finance_states_and_counts(): void
    {
        $json = $this->actingAs($this->member('academics'))->getJson($this->api('/attendance?sort=starts_at&dir=asc'))->assertOk()->json();
        $this->assertSame(2, $json['meta']['total']);
        $first = $json['data'][0];
        $this->assertSame(['id', 'lessonId', 'localDate', 'startTime', 'kind', 'studentName', 'studentCode', 'teacherName', 'subjectName', 'actualMinutes', 'status', 'pendingReviews', 'studentFinanceState', 'teacherFinanceState'], array_keys($first));
        $this->assertSame($this->submitted, $first['id']);
        $this->assertSame('Pending import', $first['studentFinanceState']);
        $this->assertSame('Pending approval', $first['teacherFinanceState']);
        $this->assertSame('Accrued', $json['data'][1]['teacherFinanceState']);
        $this->assertSame(['approval' => 1, 'import' => 2, 'missing' => 1, 'reviews' => 0, 'submitted' => 1], $json['counts']);

        $this->getJson($this->api('/attendance?status=approved'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->approved);
        $this->getJson($this->api('/attendance?q=CLS-SECRET'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->api('/attendance?sort=amount_paise'))->assertStatus(422);
        $this->getJson($this->api('/attendance', $this->other))->assertForbidden();
    }

    public function test_attendance_queues_and_reviews(): void
    {
        $this->actingAs($this->member('team_manager'));
        $approval = $this->getJson($this->api('/attendance/queues/approval'))->assertOk()->json();
        $this->assertSame(1, $approval['meta']['total']);
        $this->assertSame('approval_queue_page', $approval['meta']['pageParam']);
        $this->assertSame($this->submitted, $approval['data'][0]['id']);
        $this->assertSame(['id', 'lessonId', 'localDate', 'startTime', 'kind', 'studentName', 'studentCode', 'teacherName', 'subjectName', 'actualMinutes', 'status', 'pendingReviews', 'studentFinanceState', 'teacherFinanceState'], array_keys($approval['data'][0]));
        $this->assertNoMoney($approval['data'][0]);
        $this->getJson($this->api('/attendance/queues/import'))->assertOk()->assertJsonPath('meta.total', 2);
        $missing = $this->getJson($this->api('/attendance/queues/missing'))->assertOk()->json();
        $this->assertSame(['lessonId', 'localDate', 'startTime', 'kind', 'studentName', 'studentCode', 'teacherName', 'subjectName'], array_keys($missing['data'][0]));
        $this->assertSame($this->scheduled, $missing['data'][0]['lessonId']);
        $this->getJson($this->api('/attendance/queues/everything'))->assertNotFound();
        $this->getJson($this->api('/attendance/queues/approval?approval_queue_page=0'))->assertStatus(422);

        DB::table('erp_duplicate_reviews')->insert(['unit_id' => $this->unit, 'attendance_id' => $this->submitted, 'other_attendance_id' => $this->approved, 'status' => 'pending']);
        $reviews = $this->getJson($this->api('/attendance/reviews'))->assertOk()->json();
        $this->assertSame(['id', 'attendance', 'other', 'status'], array_keys($reviews['data'][0]));
        $this->assertStringContainsString('Synthetic Learner', $reviews['data'][0]['attendance']['label']);
        $this->getJson($this->api('/attendance'))->assertJsonPath('counts.reviews', 1)->assertJsonPath('data.0.pendingReviews', 1);
    }

    public function test_bulk_approval_posts_reuse_the_classic_route(): void
    {
        config(['erp.finance_policy' => 'admin_import_delivered_only']);
        $this->actingAs($this->member('academics'))->postJson('/erp/units/'.$this->unit.'/approve', ['attendance_id' => $this->submitted])->assertOk();
        $this->assertSame('approved', DB::table('erp_attendance')->where('id', $this->submitted)->value('status'));
        $this->actingAs($this->member('read_only'))->postJson('/erp/units/'.$this->unit.'/approve', ['attendance_id' => $this->submitted])->assertForbidden();
    }

    public function test_matching_scopes_leads_and_gates_preparation(): void
    {
        $sales = $this->member('sales_followups');
        $manager = $this->member('team_manager');
        $mine = DB::table('erp_leads')->insertGetId(['unit_id' => $this->unit, 'name' => 'Synthetic Own Lead', 'phone' => '9800000001', 'enquiry_date' => '2026-01-01', 'status' => 'LEAD', 'grade' => '8', 'syllabus' => 'CBSE', 'assigned_to' => $sales->id, 'created_by' => $sales->id]);
        $theirs = DB::table('erp_leads')->insertGetId(['unit_id' => $this->unit, 'name' => 'Synthetic Other Lead', 'phone' => '9800000002', 'enquiry_date' => '2026-01-01', 'status' => 'LEAD', 'grade' => '8', 'syllabus' => 'CBSE', 'assigned_to' => $manager->id, 'created_by' => $manager->id]);
        DB::table('erp_demos')->insert(['unit_id' => $this->unit, 'lead_id' => $theirs, 'teacher_id' => $this->teacher, 'subject_id' => $this->subject, 'scheduled_on' => '2026-01-02', 'scheduled_time' => '10:00', 'status' => 'ATTENDED', 'feedback_outcome' => 'CONTINUE', 'created_by' => $manager->id, 'starts_at' => '2026-01-02 10:00:00', 'ends_at' => '2026-01-02 11:00:00', 'timezone' => 'UTC', 'duration_minutes' => 60]);

        $json = $this->actingAs($sales)->getJson($this->api('/matching'))->assertOk()->json();
        $this->assertSame([['id' => $mine, 'name' => 'Synthetic Own Lead']], $json['leads']);
        $this->assertSame([], $json['handoffs']);
        $this->assertNull($json['context']);
        $this->getJson($this->api('/matching?lead_id='.$theirs))->assertNotFound();
        $this->getJson($this->api('/matching/prepare/'.$this->teacher))->assertForbidden();
        $ctx = $this->getJson($this->api('/matching?lead_id='.$mine))->assertOk()->json();
        $this->assertSame(['studentId', 'leadId', 'name', 'grade', 'syllabus', 'timezone', 'status'], array_keys($ctx['context']));

        $json = $this->actingAs($manager)->getJson($this->api('/matching?lead_id='.$theirs))->assertOk()->json();
        $this->assertCount(2, $json['leads']);
        $this->assertSame(['demoId', 'leadId', 'leadName', 'when', 'teacherName', 'subjectName'], array_keys($json['handoffs'][0]));
        $this->assertSame('allocate', $json['demos'][0]['next']);
        $this->assertSame((string) $this->subject, $json['demos'][0]['allocate']['subject_id']);
        $this->assertSame(['id', 'teacherName', 'scheduledOn', 'scheduledTime', 'status', 'feedbackOutcome', 'next', 'allocate'], array_keys($json['demos'][0]));

        $prep = $this->getJson($this->api('/matching/prepare/'.$this->teacher))->assertOk()->json();
        $this->assertSame(['id', 'code', 'name', 'status', 'timezone'], array_keys($prep['teacher']));
        $this->assertSame([['subject' => 'Synthetic Algebra', 'grade' => '8', 'syllabus' => 'CBSE']], $prep['eligibilities']);
        $otherTeacher = DB::table('erp_teachers')->where('code', 'CLS-OT')->value('id');
        $this->getJson($this->api('/matching/prepare/'.$otherTeacher))->assertNotFound();

        $this->actingAs($this->member('finance'))->getJson($this->api('/matching'))->assertForbidden();
        $this->actingAs($manager)->getJson($this->api('/matching', $this->other))->assertForbidden();
    }

    public function test_matching_teachers_reports_blockers_and_validates_the_slot(): void
    {
        $this->actingAs($this->member('academics'));
        $slot = ['student_id' => $this->student, 'subject_id' => $this->subject, 'date' => '2099-01-05', 'time' => '10:00', 'timezone' => 'UTC', 'duration_minutes' => 60, 'medium' => 'English', 'grade' => '8', 'syllabus' => 'CBSE'];
        $json = $this->getJson($this->api('/matching/teachers?'.http_build_query($slot)))->assertOk()->json();
        $this->assertSame('Monday', $json['weekday']);
        $this->assertSame(1, $json['meta']['total']);
        $row = $json['data'][0];
        $this->assertSame(['id', 'code', 'name', 'timezone', 'levels', 'category', 'verifiedAt', 'blockers', 'localTime', 'hours', 'ttHours', 'demoStats', 'can', 'successfulDemos'], array_keys($row));
        $this->assertNotEmpty($row['blockers']);
        $this->assertTrue($row['can']['prepare']);
        $this->assertFalse($row['can']['regular']);
        $this->getJson($this->api('/matching/teachers?'.http_build_query(['medium' => 'Klingon'] + $slot)))->assertStatus(422);
        $this->getJson($this->api('/matching/teachers?'.http_build_query(['level' => 'PhD'] + $slot)))->assertStatus(422);
    }

    public function test_results_are_scoped_filtered_and_whitelisted(): void
    {
        $json = $this->actingAs($this->member('academics'))->getJson($this->api('/academics'))->assertOk()->json();
        $this->assertSame(2, $json['meta']['total']);
        $this->assertSame(['id', 'assessedOn', 'studentId', 'studentName', 'studentCode', 'teacherName', 'subjectName', 'assessment', 'score', 'maximum', 'percentage', 'notes'], array_keys($json['data'][0]));
        $this->assertSame('Synthetic test', $json['data'][0]['assessment']);
        $this->assertSame(25, $json['data'][0]['percentage'] * 1);
        $this->assertFalse($json['can']['openProfile']);
        $this->assertStringNotContainsString('Private', json_encode($json));

        $this->getJson($this->api('/academics?q=quiz'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.percentage', 80);
        $this->getJson($this->api('/academics?from=2026-02-01&through=2026-02-28'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->api('/academics?from=2026-02-01&through=2026-01-01'))->assertStatus(422);
        $this->getJson($this->api('/academics?sort=score_paise'))->assertStatus(422);
        $this->getJson($this->api('/academics?sort=student&dir=asc'))->assertOk()->assertJsonPath('meta.sort', 'student');

        $elig = $this->getJson($this->api('/academics/eligibility?q=Algebra'))->assertOk()->json();
        $this->assertSame([['id' => $elig['data'][0]['id'], 'subjectName' => 'Synthetic Algebra', 'teacherName' => 'Synthetic Tutor', 'grade' => '8', 'syllabus' => 'CBSE']], $elig['data']);
        $this->getJson($this->api('/academics', $this->other))->assertForbidden();

        $this->actingAs($this->member('read_only'))->getJson($this->api('/academics'))->assertOk()->assertJsonPath('can.openProfile', true);
    }
}
