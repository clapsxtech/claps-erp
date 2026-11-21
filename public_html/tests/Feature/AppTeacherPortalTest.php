<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The React teacher portal at /teacher, its JSON read and the JSON branches of the classic writes. Synthetic data only. */
class AppTeacherPortalTest extends TestCase
{
    private $teacherUser;
    private int $unit;
    private int $otherUnit;
    private int $teacher;
    private int $ownLesson;
    private int $futureLesson;
    private int $otherLesson;
    private int $ownEnrollment;
    private int $otherEnrollment;
    private int $ownStudent;
    private int $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacherUser = $this->createMember(['name' => 'Synthetic Portal Teacher Login']);
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'APP-TP-A', 'name' => 'Portal Unit A', 'timezone' => 'Asia/Kolkata']);
        $this->otherUnit = DB::table('erp_units')->insertGetId(['code' => 'APP-TP-B', 'name' => 'Private Unit B']);
        $this->teacher = DB::table('erp_teachers')->insertGetId(['user_id' => $this->teacherUser->id, 'code' => 'APP-TP-T1', 'name' => 'Linked Portal Teacher', 'timezone' => 'Asia/Kolkata', 'status' => 'active']);
        $otherTeacher = DB::table('erp_teachers')->insertGetId(['code' => 'APP-TP-T2', 'name' => 'Other Portal Teacher', 'timezone' => 'UTC', 'status' => 'active']);
        DB::table('erp_teacher_units')->insert([['teacher_id' => $this->teacher, 'unit_id' => $this->unit], ['teacher_id' => $otherTeacher, 'unit_id' => $this->otherUnit]]);
        DB::table('erp_memberships')->insert(['user_id' => $this->teacherUser->id, 'unit_id' => $this->unit, 'role' => 'teacher']);
        $subject = DB::table('erp_subjects')->insertGetId(['name' => 'Synthetic Portal Maths']);
        $date = now('UTC')->subDays(2)->toDateString();
        foreach ([[$this->unit, $this->teacher, 'Owned Portal Student'], [$this->otherUnit, $otherTeacher, 'Hidden Portal Student']] as $i => [$unit, $teacher, $name]) {
            $student = DB::table('erp_students')->insertGetId(['unit_id' => $unit, 'code' => 'APP-TP-S'.$i, 'name' => $name, 'registered_on' => $date, 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'Test', 'guardian_contact' => 'PRIVATE-GUARDIAN-SECRET-'.$i, 'meeting_url' => 'https://example.invalid/private-meeting-'.$i]);
            $enrollment = DB::table('erp_enrollments')->insertGetId(['unit_id' => $unit, 'student_id' => $student, 'teacher_id' => $teacher, 'subject_id' => $subject, 'starts_on' => $date, 'student_rate_paise' => 98765, 'teacher_rate_paise' => 54321, 'currency' => 'INR', 'status' => 'active']);
            $lesson = DB::table('erp_lessons')->insertGetId(['unit_id' => $unit, 'enrollment_id' => $enrollment, 'student_id' => $student, 'teacher_id' => $teacher, 'subject_id' => $subject, 'starts_at' => $date.' 10:00:00', 'ends_at' => $date.' 11:00:00', 'status' => 'scheduled', 'kind' => 'regular', 'student_rate_paise' => 98765, 'teacher_rate_paise' => 54321, 'currency' => 'INR']);
            if ($i === 0) { $this->ownLesson = $lesson; $this->ownEnrollment = $enrollment; $this->ownStudent = $student; }
            else { $this->otherLesson = $lesson; $this->otherEnrollment = $enrollment; $this->otherStudent = $student; }
        }
        $future = now('UTC')->addDays(3)->toDateString();
        $this->futureLesson = DB::table('erp_lessons')->insertGetId(['unit_id' => $this->unit, 'enrollment_id' => $this->ownEnrollment, 'student_id' => $this->ownStudent, 'teacher_id' => $this->teacher, 'subject_id' => $subject, 'starts_at' => $future.' 10:00:00', 'ends_at' => $future.' 11:00:00', 'status' => 'scheduled', 'kind' => 'regular', 'student_rate_paise' => 98765, 'teacher_rate_paise' => 54321, 'currency' => 'INR']);
    }

    private function embedded($response): array
    {
        $html = $response->getContent();
        $start = strpos($html, '<script type="application/json" id="erp-session">') + strlen('<script type="application/json" id="erp-session">');
        return json_decode(html_entity_decode(substr($html, $start, strpos($html, '</script>', $start) - $start)), true);
    }

    private function assertNoPrivateData(string $content): void
    {
        foreach (['98765', '54321', 'PRIVATE-GUARDIAN-SECRET', 'private-meeting', 'Hidden Portal Student', 'Private Unit B', 'rate_paise', 'ratePaise'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    private function resultPayload(array $overrides = []): array
    {
        return array_merge(['enrollment_id' => $this->ownEnrollment, 'assessment' => 'Portal JSON test', 'assessed_on' => now('Asia/Kolkata')->toDateString(),
            'score' => 0, 'maximum' => 20, 'notes' => 'Progress note', 'submission_key' => (string) Str::uuid()], $overrides);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/teacher')->assertRedirect('/login');
        $this->get('/teacher/attendance')->assertRedirect('/login');
        $this->getJson('/teacher-api/workspace')->assertUnauthorized();
    }

    public function test_linked_teacher_gets_portal_host_page_with_teacher_only_session(): void
    {
        foreach (['/teacher', '/teacher/overview', '/teacher/timetable', '/teacher/students', '/teacher/attendance', '/teacher/results', '/teacher/reports'] as $url) {
            $response = $this->actingAs($this->teacherUser)->get($url)->assertOk()->assertSee('id="erp-root"', false);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $session = $this->embedded($response);
            $this->assertSame(['kind', 'mode', 'user', 'teacher', 'unitName'], array_keys($session));
            $this->assertSame('teacher', $session['kind']);
            $this->assertSame('portal', $session['mode']);
            $this->assertSame(['name' => 'Linked Portal Teacher', 'code' => 'APP-TP-T1', 'timezone' => 'Asia/Kolkata'], $session['teacher']);
            $this->assertSame('Portal Unit A', $session['unitName']);
            $this->assertNoPrivateData($response->getContent());
        }
        $this->actingAs($this->teacherUser)->get('/teacher/unknown')->assertNotFound();
    }

    public function test_administrator_and_unlinked_staff_see_setup_steps_for_their_own_teams(): void
    {
        $admin = $this->createAdmin();
        $session = $this->embedded($this->actingAs($admin)->get('/teacher')->assertOk());
        $this->assertSame('setup', $session['mode']);
        $this->assertTrue($session['isAdmin']);
        $this->assertSame(['id', 'name', 'canOpenTeamAccess', 'canOpenPeople'], array_keys($session['units'][0]));

        $staff = $this->createMember(['name' => 'Synthetic Academics']);
        DB::table('erp_memberships')->insert(['user_id' => $staff->id, 'unit_id' => $this->unit, 'role' => 'academics']);
        $response = $this->actingAs($staff)->get('/teacher')->assertOk();
        $session = $this->embedded($response);
        $this->assertSame('setup', $session['mode']);
        $this->assertFalse($session['isAdmin']);
        $this->assertSame([['id' => $this->unit, 'name' => 'Portal Unit A', 'canOpenTeamAccess' => false, 'canOpenPeople' => true]], $session['units']);
        $this->assertStringNotContainsString('Private Unit B', $response->getContent());
        // Setup users have no teacher identity, so the data read refuses them.
        $this->actingAs($staff)->getJson('/teacher-api/workspace')->assertForbidden();
    }

    /** Every refusal of the write guard (TeacherController::identity()) is also a refusal of the read guard and the page. */
    public function test_portal_guard_refuses_exactly_what_the_classic_guard_refuses(): void
    {
        $cases = [
            'unlinked' => fn () => DB::table('erp_teachers')->where('id', $this->teacher)->update(['user_id' => null]),
            'inactive teacher' => fn () => DB::table('erp_teachers')->where('id', $this->teacher)->update(['status' => 'inactive']),
            'no pool' => fn () => DB::table('erp_teacher_units')->where('teacher_id', $this->teacher)->delete(),
            // A second pool row is impossible (unique teacher_id); a pool in another team is the reachable case.
            'pool in another team' => fn () => DB::table('erp_teacher_units')->where('teacher_id', $this->teacher)->update(['unit_id' => $this->otherUnit]),
            'archived unit' => fn () => DB::table('erp_units')->where('id', $this->unit)->update(['archived_at' => now()]),
            'membership missing' => fn () => DB::table('erp_memberships')->where('user_id', $this->teacherUser->id)->delete(),
            'membership in another unit' => fn () => DB::table('erp_memberships')->where('user_id', $this->teacherUser->id)->update(['unit_id' => $this->otherUnit]),
            'membership with another role' => fn () => DB::table('erp_memberships')->where('user_id', $this->teacherUser->id)->update(['role' => 'academics']),
            'deleted user' => fn () => $this->teacherUser->forceFill(['deleted_at' => now()])->save(),
        ];
        foreach ($cases as $name => $break) {
            DB::beginTransaction();
            try {
                $break();
                $this->teacherUser->refresh();
                $this->actingAs($this->teacherUser);
                $this->assertSame(403, $this->postJson('/erp/teacher/results', $this->resultPayload())->status(), "write guard (results): $name");
                $this->assertSame(403, $this->postJson('/erp/teacher/attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 60])->status(), "write guard (attendance): $name");
                $this->assertSame(403, $this->getJson('/teacher-api/workspace')->status(), "read guard: $name");
                $page = $this->get('/teacher');
                $this->assertSame(403, $page->status(), "portal page: $name");
                if ($name !== 'deleted user') $this->assertSame('refused', $this->embedded($page)['mode'], $name);
                $this->assertNoPrivateData($page->getContent());
            } finally {
                DB::rollBack();
                $this->teacherUser->refresh();
            }
        }
        $this->assertSame(0, DB::table('erp_attendance')->count());
        $this->assertSame(0, DB::table('erp_marks')->count());
    }

    public function test_workspace_lists_only_own_records_with_explicit_fields(): void
    {
        DB::table('erp_marks')->insert(['unit_id' => $this->otherUnit, 'enrollment_id' => $this->otherEnrollment, 'assessment' => 'Hidden mark', 'assessed_on' => now()->toDateString(), 'score' => 1, 'maximum' => 2, 'created_by' => 1]);
        $this->actingAs($this->teacherUser)->postJson('/erp/teacher/results', $this->resultPayload())->assertOk();
        $response = $this->getJson('/teacher-api/workspace')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNoPrivateData($response->getContent());
        $this->assertStringNotContainsString('Hidden mark', $response->getContent());
        $json = $response->json();
        $this->assertSame(['range', 'lessons', 'assignments', 'students', 'results'], array_keys($json));
        $this->assertSame(['from', 'through', 'today', 'timezone', 'truncated', 'student'], array_keys($json['range']));
        $this->assertSame('Asia/Kolkata', $json['range']['timezone']);
        $this->assertCount(2, $json['lessons']);
        $this->assertSame(['id', 'date', 'startTime', 'endTime', 'startsAtLocal', 'scheduledMinutes', 'status', 'kind', 'studentName', 'studentCode', 'subjectName', 'hasEnded', 'isUpcoming', 'needsAttendance', 'canMarkNoShow', 'attendance'], array_keys($json['lessons'][0]));
        $past = collect($json['lessons'])->firstWhere('id', $this->ownLesson);
        $this->assertSame(['15:30', '16:30', 60, true, true, null], [$past['startTime'], $past['endTime'], $past['scheduledMinutes'], $past['needsAttendance'], $past['canMarkNoShow'], $past['attendance']]);
        $future = collect($json['lessons'])->firstWhere('id', $this->futureLesson);
        $this->assertFalse($future['needsAttendance']);
        $this->assertTrue($future['isUpcoming']);
        $this->assertSame([['id' => $this->ownEnrollment, 'studentId' => $this->ownStudent, 'studentName' => 'Owned Portal Student', 'studentCode' => 'APP-TP-S0', 'grade' => '8', 'syllabus' => 'Test', 'subjectName' => 'Synthetic Portal Maths', 'learningRequirement' => null, 'concern' => null, 'aboutStudent' => null]], $json['assignments']);
        $this->assertSame([['id' => $this->ownStudent, 'name' => 'Owned Portal Student', 'code' => 'APP-TP-S0']], $json['students']);
        $this->assertSame(['id', 'assessment', 'assessedOn', 'score', 'maximum', 'notes', 'studentName', 'subjectName'], array_keys($json['results']['data'][0]));
        $this->assertSame(['page' => 1, 'perPage' => 25, 'total' => 1, 'lastPage' => 1], $json['results']['meta']);
    }

    public function test_workspace_filters_validate_and_keep_teacher_scope(): void
    {
        $this->actingAs($this->teacherUser);
        $this->getJson('/teacher-api/workspace?student='.$this->otherStudent)->assertForbidden();
        $this->getJson('/teacher-api/workspace?from=2026-01-01&through=2027-06-01')->assertUnprocessable()->assertJsonValidationErrors('through');
        $this->getJson('/teacher-api/workspace?from=bad')->assertUnprocessable()->assertJsonValidationErrors('from');
        // Date boundaries use the teacher's time zone (IST): 20:00 UTC on the 24th is the 25th locally.
        DB::table('erp_lessons')->where('id', $this->ownLesson)->update(['starts_at' => '2026-10-24 20:00:00', 'ends_at' => '2026-10-24 21:00:00']);
        $this->assertSame([$this->ownLesson], array_column($this->getJson('/teacher-api/workspace?from=2026-10-25&through=2026-10-25')->json('lessons'), 'id'));
        $this->assertSame([], $this->getJson('/teacher-api/workspace?from=2026-10-24&through=2026-10-24')->json('lessons'));
        for ($i = 0; $i < 26; $i++) $this->postJson('/erp/teacher/results', $this->resultPayload(['assessment' => 'Assessment '.$i]))->assertOk();
        $page2 = $this->getJson('/teacher-api/workspace?results_page=2&student='.$this->ownStudent)->assertOk()->json('results');
        $this->assertSame(['page' => 2, 'perPage' => 25, 'total' => 26, 'lastPage' => 2], $page2['meta']);
        $this->assertCount(1, $page2['data']);
    }

    public function test_json_attendance_submit_is_duplicate_safe_and_owner_only(): void
    {
        $this->actingAs($this->teacherUser);
        $this->postJson('/erp/teacher/attendance', ['lesson_id' => $this->otherLesson, 'actual_minutes' => 60])->assertForbidden();
        $this->postJson('/erp/teacher/attendance', ['lesson_id' => $this->ownLesson])->assertUnprocessable()->assertJsonValidationErrors('actual_minutes');
        $this->postJson('/erp/teacher/attendance', ['lesson_id' => $this->futureLesson, 'actual_minutes' => 60])->assertUnprocessable();
        $first = $this->postJson('/erp/teacher/attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 60])->assertOk()
            ->assertJsonPath('message', 'Attendance submitted for administrator review.')->assertJsonPath('result.lessonId', $this->ownLesson)->assertJsonPath('result.status', 'submitted')->json();
        $this->assertSame(['id', 'lessonId', 'actualMinutes', 'status'], array_keys($first['result']));
        // A replay (double tap, retry) returns the same record instead of a second row.
        $this->postJson('/erp/teacher/attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 60])->assertOk()->assertJsonPath('result.id', $first['result']['id']);
        $this->postJson('/erp/teacher/attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 45])->assertUnprocessable();
        $this->assertSame(1, DB::table('erp_attendance')->count());
        $this->assertDatabaseHas('erp_attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 60, 'submitted_by' => $this->teacherUser->id, 'status' => 'submitted']);
        $lesson = collect($this->getJson('/teacher-api/workspace')->json('lessons'))->firstWhere('id', $this->ownLesson);
        $this->assertSame(['status' => 'submitted', 'minutes' => 60], $lesson['attendance']);
        $this->assertFalse($lesson['needsAttendance']);
    }

    public function test_json_changed_time_needs_a_reason_and_no_show_answers_json(): void
    {
        $this->actingAs($this->teacherUser);
        $local = \Carbon\CarbonImmutable::parse(DB::table('erp_lessons')->where('id', $this->ownLesson)->value('starts_at'), 'UTC')->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i');
        $this->postJson('/erp/teacher/attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 90, 'actual_starts_at' => $local, 'change_reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $this->postJson('/erp/teacher/attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 90, 'actual_starts_at' => $local, 'change_reason' => 'Extra half hour requested'])->assertOk();
        $this->assertDatabaseHas('erp_attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 90, 'change_reason' => 'Extra half hour requested']);

        DB::table('erp_attendance')->delete();
        DB::table('erp_lessons')->where('id', $this->ownLesson)->update(['status' => 'scheduled']);
        $this->postJson('/erp/teacher/attendance', ['no_show' => true, 'lesson_id' => $this->ownLesson])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/erp/teacher/attendance', ['no_show' => true, 'lesson_id' => $this->otherLesson, 'reason' => 'x'])->assertForbidden();
        $this->postJson('/erp/teacher/attendance', ['no_show' => true, 'lesson_id' => $this->ownLesson, 'reason' => 'Waited 15 minutes'])->assertOk()->assertJsonPath('result', ['lessonId' => $this->ownLesson, 'status' => 'no_show']);
        $this->assertDatabaseHas('erp_lessons', ['id' => $this->ownLesson, 'status' => 'no_show']);
        $this->assertSame(0, DB::table('erp_attendance')->count());
    }

    public function test_json_result_is_replay_safe_validated_and_owner_only(): void
    {
        $this->actingAs($this->teacherUser);
        $payload = $this->resultPayload();
        $id = $this->postJson('/erp/teacher/results', $payload)->assertOk()->assertJsonPath('message', 'Assessment result saved.')->json('result.id');
        $this->postJson('/erp/teacher/results', $payload)->assertOk()->assertJsonPath('result.id', $id);
        $this->assertSame(1, DB::table('erp_marks')->count());
        $this->assertDatabaseHas('erp_marks', ['id' => $id, 'score' => 0, 'maximum' => 20, 'unit_id' => $this->unit, 'created_by' => $this->teacherUser->id]);
        $this->postJson('/erp/teacher/results', array_merge($payload, ['score' => 1]))->assertStatus(409);
        $this->postJson('/erp/teacher/results', $this->resultPayload(['score' => 21]))->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->postJson('/erp/teacher/results', $this->resultPayload(['submission_key' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors('submission_key');
        $this->postJson('/erp/teacher/results', $this->resultPayload(['enrollment_id' => $this->otherEnrollment]))->assertForbidden();
        DB::table('erp_enrollments')->where('id', $this->ownEnrollment)->update(['status' => 'ended']);
        $this->postJson('/erp/teacher/results', $this->resultPayload())->assertUnprocessable();
        $this->assertSame(1, DB::table('erp_marks')->count());
    }

    /** The classic redirects are gone: a plain form post now also answers JSON and saves the same way. */
    public function test_plain_form_posts_answer_json_and_persist(): void
    {
        $this->actingAs($this->teacherUser);
        $this->post('/erp/teacher/attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 60])->assertOk()->assertJsonPath('result.status', 'submitted');
        $this->assertDatabaseHas('erp_attendance', ['lesson_id' => $this->ownLesson, 'actual_minutes' => 60, 'submitted_by' => $this->teacherUser->id]);
        $id = $this->post('/erp/teacher/results', $this->resultPayload())->assertOk()->assertJsonPath('message', 'Assessment result saved.')->json('result.id');
        $this->assertDatabaseHas('erp_marks', ['id' => $id, 'unit_id' => $this->unit, 'created_by' => $this->teacherUser->id]);
    }
}
