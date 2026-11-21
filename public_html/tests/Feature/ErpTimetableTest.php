<?php
namespace Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Timetable JSON read (app-api) with synthetic, in-memory records only. */
class ErpTimetableTest extends TestCase
{
    private $unit;
    private $other;
    private $admin;
    private $student;
    private $teacher;
    private $subject;
    private $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'WEEK-AA', 'name' => 'Synthetic Weekly Unit', 'timezone' => 'UTC']);
        $this->other = DB::table('erp_units')->insertGetId(['code' => 'WEEK-BB', 'name' => 'Private Other Unit', 'timezone' => 'UTC']);
        DB::table('erp_memberships')->insert(['unit_id' => $this->unit, 'user_id' => $this->admin->id, 'role' => 'admin']);
        $this->student = $this->student('WEEK-STUDENT', 'Synthetic Weekly Student');
        $this->teacher = $this->teacher('WEEK-TEACHER', 'Synthetic Weekly Teacher');
        $this->subject = DB::table('erp_subjects')->insertGetId(['name' => 'Synthetic Weekly Mathematics']);
        $this->enrollment = DB::table('erp_enrollments')->insertGetId([
            'unit_id' => $this->unit, 'student_id' => $this->student, 'teacher_id' => $this->teacher,
            'subject_id' => $this->subject, 'starts_on' => '2026-01-01', 'status' => 'active',
            'student_rate_paise' => 12000, 'teacher_rate_paise' => 6000, 'currency' => 'INR',
        ]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function student(string $code, string $name, ?int $unit = null): int
    {
        return DB::table('erp_students')->insertGetId([
            'unit_id' => $unit ?? $this->unit, 'code' => $code, 'name' => $name,
            'registered_on' => '2026-01-01', 'timezone' => 'Pacific/Honolulu',
            'status' => 'active', 'grade' => '8', 'syllabus' => 'Synthetic',
        ]);
    }

    private function teacher(string $code, string $name, ?int $unit = null): int
    {
        $id = DB::table('erp_teachers')->insertGetId([
            'code' => $code, 'name' => $name, 'timezone' => 'Europe/London', 'status' => 'active',
        ]);
        DB::table('erp_teacher_units')->insert(['teacher_id' => $id, 'unit_id' => $unit ?? $this->unit]);
        return $id;
    }

    private function lesson(string $starts, string $ends, array $attributes = []): int
    {
        return DB::table('erp_lessons')->insertGetId(array_merge([
            'unit_id' => $this->unit, 'enrollment_id' => $this->enrollment,
            'student_id' => $this->student, 'teacher_id' => $this->teacher, 'subject_id' => $this->subject,
            'starts_at' => $starts, 'ends_at' => $ends, 'status' => 'scheduled', 'kind' => 'regular',
            'student_rate_paise' => 12000, 'teacher_rate_paise' => 6000, 'currency' => 'INR',
        ], $attributes));
    }

    private function url(array $query = [], ?int $unit = null): string
    {
        return route('app.api.classes.timetable', [$unit ?? $this->unit])
            .'?'.http_build_query(array_merge(['date' => '2026-10-21'], $query));
    }

    /** The JSON read of one week. */
    private function week(array $query = [], ?int $unit = null)
    {
        return $this->getJson($this->url($query, $unit))->assertOk();
    }

    /** The same projection the endpoint is built from, for the exact UTC week instants the JSON reports as dates. */
    private function projection(array $query = []): array
    {
        $filters = array_filter(array_merge(['date' => '2026-10-21'], $query), fn ($value) => $value !== null && $value !== '');
        $tz = DB::table('erp_units')->where('id', $this->unit)->value('timezone');
        return (new \App\Modules\Erp\Services\Timetable())->week($this->unit, $tz, $filters);
    }

    private function ids($response, ?string $date = null): array
    {
        $days = collect($response->json('days'));
        $lessons = $date !== null ? collect($days->firstWhere('date', $date)['lessons'])
            : $days->flatMap(function ($day) { return $day['lessons']; });
        return $lessons->pluck('id')->map(function ($id) { return (int) $id; })->unique()->values()->all();
    }

    private function lessonRow($response, int $id): array
    {
        return collect($response->json('days'))->flatMap(fn ($day) => $day['lessons'])->firstWhere('id', $id);
    }

    private function plain($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function test_week_is_monday_to_monday_and_uses_half_open_overlap_boundaries(): void
    {
        $this->lesson('2026-10-18 22:00:00', '2026-10-19 00:00:00'); // Ends at the excluded boundary.
        $carryIn = $this->lesson('2026-10-18 23:30:00', '2026-10-19 00:30:00');
        $monday = $this->lesson('2026-10-19 00:00:00', '2026-10-19 01:00:00');
        $sunday = $this->lesson('2026-10-25 23:00:00', '2026-10-26 00:00:00');
        $carryOut = $this->lesson('2026-10-25 23:30:00', '2026-10-26 00:30:00');
        $this->lesson('2026-10-26 00:00:00', '2026-10-26 01:00:00');

        $response = $this->week();
        $projection = $this->projection();
        $this->assertInstanceOf(CarbonImmutable::class, $projection['start']);
        $this->assertSame('2026-10-19 00:00:00 +00:00', $projection['start']->format('Y-m-d H:i:s P'));
        $this->assertSame('2026-10-26 00:00:00 +00:00', $projection['end']->format('Y-m-d H:i:s P'));
        $response->assertJsonPath('week.start', '2026-10-19')->assertJsonPath('week.end', '2026-10-25')->assertJsonPath('week.timezone', 'UTC');
        $this->assertSame(['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'], array_column($response->json('days'), 'date'));
        $this->assertSame([$carryIn, $monday, $sunday, $carryOut], $this->ids($response));
        $this->assertSame([$carryIn, $monday], $this->ids($response, '2026-10-19'));
        $this->assertSame([$sunday, $carryOut], $this->ids($response, '2026-10-25'));
        $response->assertJsonPath('week.count', 4)->assertJsonPath('week.truncated', false);
        $row = $this->lessonRow($response, $monday);
        $this->assertSame(['Synthetic Weekly Student', 'WEEK-STUDENT', 'Synthetic Weekly Teacher', 'WEEK-TEACHER', 'Synthetic Weekly Mathematics'],
            [$row['studentName'], $row['studentCode'], $row['teacherName'], $row['teacherCode'], $row['subjectName']]);
        // Lesson rates are money and never leave the server on the calendar read.
        $this->assertStringNotContainsStringIgnoringCase('rate', implode(',', array_keys($row)));
        $this->assertStringNotContainsString('12000', $response->getContent());
        // The registers under the week are a separate read.
        $this->getJson(route('app.api.classes.timetable.plans', $this->unit))->assertOk()->assertJsonStructure(['agreements' => ['data', 'meta'], 'exceptions' => ['data', 'meta']]);
    }

    public function test_cross_midnight_lessons_appear_on_each_overlapping_local_day_but_not_their_exclusive_end_day(): void
    {
        DB::table('erp_units')->where('id', $this->unit)->update(['timezone' => 'Asia/Kolkata']);
        $monday = $this->lesson('2026-10-18 19:00:00', '2026-10-18 20:00:00');
        $crossMidnight = $this->lesson('2026-10-19 18:00:00', '2026-10-19 19:00:00');
        $endsAtMidnight = $this->lesson('2026-10-20 17:30:00', '2026-10-20 18:30:00');
        $this->lesson('2026-10-25 18:30:00', '2026-10-25 19:30:00');

        $response = $this->week()->assertJsonPath('week.timezone', 'Asia/Kolkata');
        $this->assertSame('+05:30', $this->lessonRow($response, $monday)['utcOffset']);
        $cross = $this->lessonRow($response, $crossMidnight);
        $this->assertSame(['2026-10-19', '23:30', '00:30', '2026-10-20'], [$cross['localDate'], $cross['startTime'], $cross['endTime'], $cross['endDate']]);
        $projection = $this->projection();
        $this->assertSame('Asia/Kolkata', $projection['start']->timezoneName);
        $this->assertSame('2026-10-18 18:30:00', $projection['start']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame([$monday, $crossMidnight], $this->ids($response, '2026-10-19'));
        $this->assertSame([$crossMidnight, $endsAtMidnight], $this->ids($response, '2026-10-20'));
        $this->assertSame([], $this->ids($response, '2026-10-21'));
        $response->assertJsonPath('week.count', 3); // Unique lessons, not calendar-card count.
    }

    public function test_default_week_uses_the_business_timezone_not_the_server_or_person_timezone(): void
    {
        DB::table('erp_units')->where('id', $this->unit)->update(['timezone' => 'Asia/Kolkata']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-18 20:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 20:00:00', 'UTC'));
        $response = $this->getJson(route('app.api.classes.timetable', [$this->unit]))->assertOk();
        $response->assertJsonPath('week.start', '2026-10-19')->assertJsonPath('week.timezone', 'Asia/Kolkata')->assertJsonPath('week.today', '2026-10-19')->assertJsonPath('week.count', 0);
        $this->assertCount(7, $response->json('days'));
    }

    public function test_spring_dst_week_has_local_midnight_boundaries_and_displays_both_offsets(): void
    {
        DB::table('erp_units')->where('id', $this->unit)->update(['timezone' => 'America/New_York']);
        $early = $this->lesson('2026-03-03 15:00:00', '2026-03-03 16:00:00');
        $spring = $this->lesson('2026-03-08 06:30:00', '2026-03-08 07:30:00');
        $last = $this->lesson('2026-03-09 03:30:00', '2026-03-09 04:00:00');
        $this->lesson('2026-03-09 04:00:00', '2026-03-09 05:00:00');
        $response = $this->week(['date' => '2026-03-08'])->assertJsonPath('week.timezone', 'America/New_York')->assertJsonPath('week.start', '2026-03-02')->assertJsonPath('week.end', '2026-03-08');
        $this->assertSame('-05:00', $this->lessonRow($response, $early)['utcOffset']);
        $this->assertSame('-05:00', $this->lessonRow($response, $spring)['utcOffset']);
        $this->assertSame('-04:00', $this->lessonRow($response, $last)['utcOffset']);
        $week = $this->projection(['date' => '2026-03-08']);
        $this->assertSame('2026-03-02 05:00:00', $week['start']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 04:00:00', $week['end']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(167 * 3600, $week['end']->timestamp - $week['start']->timestamp);
        $this->assertSame([$spring, $last], $this->ids($response, '2026-03-08'));
    }

    public function test_fall_dst_keeps_repeated_local_hours_distinct_and_in_chronological_order(): void
    {
        DB::table('erp_units')->where('id', $this->unit)->update(['timezone' => 'America/New_York']);
        $second = $this->lesson('2026-11-01 06:30:00', '2026-11-01 06:50:00');
        $first = $this->lesson('2026-11-01 05:30:00', '2026-11-01 05:50:00');
        $response = $this->week(['date' => '2026-11-01']);
        $one = $this->lessonRow($response, $first); $two = $this->lessonRow($response, $second);
        $this->assertSame(['01:30', '-04:00'], [$one['startTime'], $one['utcOffset']]);
        $this->assertSame(['01:30', '-05:00'], [$two['startTime'], $two['utcOffset']]);
        $week = $this->projection(['date' => '2026-11-01']);
        $this->assertSame('2026-10-26 04:00:00', $week['start']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 05:00:00', $week['end']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(169 * 3600, $week['end']->timestamp - $week['start']->timestamp);
        $this->assertSame([$first, $second], $this->ids($response, '2026-11-01'));
        $response->assertJsonPath('week.count', 2);
    }

    public function test_student_and_teacher_name_or_code_filters_combine_with_status_and_week(): void
    {
        $student = $this->student('FILTER-OTHER-STUDENT', 'Other Pupil');
        $teacher = $this->teacher('FILTER-OTHER-TEACHER', 'Other Tutor');
        $match = $this->lesson('2026-10-20 09:00:00', '2026-10-20 10:00:00', ['status' => 'completed']);
        $scheduled = $this->lesson('2026-10-20 11:00:00', '2026-10-20 12:00:00');
        $cancelled = $this->lesson('2026-10-20 12:00:00', '2026-10-20 13:00:00', ['status' => 'cancelled']);
        $otherStudent = $this->lesson('2026-10-20 13:00:00', '2026-10-20 14:00:00', ['student_id' => $student, 'status' => 'completed']);
        $otherTeacher = $this->lesson('2026-10-20 14:00:00', '2026-10-20 15:00:00', ['teacher_id' => $teacher, 'status' => 'completed']);
        $this->lesson('2026-10-27 09:00:00', '2026-10-27 10:00:00', ['status' => 'completed']);

        foreach ([['student' => 'Weekly Student', 'teacher' => 'WEEK-TEACHER'], ['student' => 'WEEK-STUDENT', 'teacher' => 'Weekly Teacher']] as $filters) {
            $response = $this->week($filters + ['status' => 'completed']);
            $this->assertSame([$match], $this->ids($response));
            $response->assertDontSee('Other Pupil')->assertDontSee('Other Tutor');
            $response->assertJsonPath('filters.student', $filters['student'])->assertJsonPath('filters.teacher', $filters['teacher'])->assertJsonPath('filters.status', 'completed');
        }
        $cases = [
            ['status' => 'scheduled', 'ids' => [$scheduled]],
            ['status' => 'cancelled', 'ids' => [$cancelled]],
            ['status' => '', 'ids' => [$match, $scheduled, $cancelled, $otherStudent, $otherTeacher]],
        ];
        foreach ($cases as $case) {
            $this->assertSame($case['ids'], $this->ids($this->week(['status' => $case['status']])));
        }
        $this->week(['student' => 'not-present'])->assertJsonPath('week.count', 0)->assertJsonPath('week.truncated', false);
    }

    public function test_names_codes_subjects_and_reflected_filter_values_are_returned_verbatim_as_json_data(): void
    {
        $studentName = '<script>alert("student")</script>';
        $teacherName = '<img src=x onerror=alert("teacher")>';
        $subjectName = '<svg onload=alert("subject")>';
        DB::table('erp_students')->where('id', $this->student)->update(['name' => $studentName, 'code' => 'S<code>']);
        DB::table('erp_teachers')->where('id', $this->teacher)->update(['name' => $teacherName, 'code' => 'T<code>']);
        DB::table('erp_subjects')->where('id', $this->subject)->update(['name' => $subjectName]);
        $id = $this->lesson('2026-10-20 10:00:00', '2026-10-20 11:00:00');
        $response = $this->week(['student' => $studentName, 'teacher' => $teacherName]);
        $this->assertSame([$id], $this->ids($response));
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $row = $this->lessonRow($response, $id);
        // Values are data, not markup: the client renders them as text.
        $this->assertSame([$studentName, $teacherName, $subjectName, 'S<code>', 'T<code>'], [$row['studentName'], $row['teacherName'], $row['subjectName'], $row['studentCode'], $row['teacherCode']]);
        $response->assertJsonPath('filters.student', $studentName)->assertJsonPath('filters.teacher', $teacherName);
        $attack = "' OR 1=1 --";
        $this->assertSame([], $this->ids($this->week(['student' => $attack])));
    }

    public function test_rescheduled_and_no_show_history_remain_filterable_without_live_scheduling_actions(): void
    {
        $rescheduled = $this->lesson('2026-10-20 10:00:00', '2026-10-20 11:00:00', ['status' => 'rescheduled']);
        $replacement = $this->lesson('2026-10-21 10:00:00', '2026-10-21 11:00:00', ['replaces_lesson_id' => $rescheduled]);
        $noShow = $this->lesson('2026-10-22 10:00:00', '2026-10-22 11:00:00', ['status' => 'no_show', 'cancellation_reason' => "Student didn't attend"]);
        $response = $this->week(['status' => 'rescheduled']);
        $this->assertSame([$rescheduled], $this->ids($response));
        $this->assertSame(['2026-10-20', '10:00'], [$this->lessonRow($response, $rescheduled)['localDate'], $this->lessonRow($response, $rescheduled)['startTime']]); // Original time retained for history.
        $response = $this->week(['status' => 'no_show']);
        $this->assertSame([$noShow], $this->ids($response));
        $this->assertSame(['no_show', "Student didn't attend"], [$this->lessonRow($response, $noShow)['status'], $this->lessonRow($response, $noShow)['cancellationReason']]);
        $this->assertSame([$replacement], $this->ids($this->week(['status' => 'scheduled'])));
        $this->assertSame([$rescheduled, $replacement, $noShow], $this->ids($this->week()));
        // History rows accept no live scheduling action; the scheduled replacement does.
        $before = DB::table('erp_lessons')->orderBy('id')->get()->toJson();
        foreach ([$rescheduled, $noShow] as $lesson) {
            $this->postJson(route('erp.mutate', [$this->unit, 'cancel']), ['lesson_id' => $lesson, 'reason' => 'Synthetic cancel of history'])->assertUnprocessable();
        }
        $this->assertSame($before, DB::table('erp_lessons')->orderBy('id')->get()->toJson());
        $this->postJson(route('erp.mutate', [$this->unit, 'cancel']), ['lesson_id' => $replacement, 'reason' => 'Synthetic cancel'])->assertOk();
        $this->assertDatabaseHas('erp_lessons', ['id' => $replacement, 'status' => 'cancelled']);
    }

    public function test_week_navigation_preserves_filters_and_does_not_link_beyond_supported_date_bounds(): void
    {
        $filters = ['student' => 'WEEK-STUDENT', 'teacher' => 'WEEK-TEACHER', 'status' => 'scheduled'];
        $response = $this->week($filters)->assertJsonPath('week.prevDate', '2026-10-12')->assertJsonPath('week.nextDate', '2026-10-26')
            ->assertJsonPath('filters', ['date' => '2026-10-21'] + $filters);
        foreach (['2026-10-12', '2026-10-26'] as $date) {
            $this->week(['date' => $date] + $filters)->assertJsonPath('filters.student', 'WEEK-STUDENT');
        }
        $this->week(['date' => '2000-01-03'])->assertJsonPath('week.prevDate', null)->assertJsonPath('week.nextDate', '2000-01-10');
        $this->week(['date' => '2099-12-27'])->assertJsonPath('week.nextDate', null)->assertJsonPath('week.prevDate', '2099-12-14');
    }

    public function test_foreign_lessons_and_inconsistent_student_or_teacher_joins_never_leak_into_the_calendar(): void
    {
        $foreignStudent = $this->student('WEEK-SECRET-STUDENT', 'Private Foreign Student', $this->other);
        $foreignTeacher = $this->teacher('WEEK-SECRET-TEACHER', 'Private Foreign Teacher', $this->other);
        $own = $this->lesson('2026-10-20 10:00:00', '2026-10-20 11:00:00');
        $this->lesson('2026-10-20 10:00:00', '2026-10-20 11:00:00', ['unit_id' => $this->other, 'student_id' => $foreignStudent, 'teacher_id' => $foreignTeacher]);
        $this->lesson('2026-10-20 11:00:00', '2026-10-20 12:00:00', ['student_id' => $foreignStudent]);
        $this->lesson('2026-10-20 12:00:00', '2026-10-20 13:00:00', ['teacher_id' => $foreignTeacher]);
        DB::table('erp_students')->where('id', $this->student)->update(['guardian_contact' => 'PRIVATE-GUARDIAN-CONTACT', 'meeting_url' => 'https://example.invalid/private-meeting']);
        $response = $this->week();
        foreach (['Private Foreign', 'WEEK-SECRET', 'PRIVATE-GUARDIAN-CONTACT', 'https://example.invalid/private-meeting'] as $secret) {
            $this->assertStringNotContainsString($secret, $this->plain($response));
        }
        $this->assertSame([$own], $this->ids($response));
        $this->week([], $this->other);

        DB::table('erp_memberships')->insert(['unit_id' => $this->other, 'user_id' => $this->admin->id, 'role' => 'admin']);
        $response = $this->week(['student' => 'WEEK-SECRET']);
        $this->assertStringNotContainsString('Private Foreign', $this->plain($response));
        $this->assertSame([], $this->ids($response));
    }

    public function test_timetable_requires_explicit_active_site_and_admin_unit_membership(): void
    {
        foreach (['teacher', 'sales'] as $role) {
            $member = $this->createMember();
            DB::table('erp_memberships')->insert(['unit_id' => $this->unit, 'user_id' => $member->id, 'role' => $role]);
            $this->actingAs($member)->getJson($this->url())->assertForbidden();
            $this->getJson(route('app.api.classes.timetable.plans', $this->unit))->assertForbidden();
        }
        $this->actingAs($this->createMember())->getJson($this->url())->assertForbidden();
        foreach ([['business_id' => 999], ['status' => 0]] as $attributes) {
            $member = $this->createMember($attributes);
            DB::table('erp_memberships')->insert(['unit_id' => $this->unit, 'user_id' => $member->id, 'role' => 'admin']);
            $this->actingAs($member)->getJson($this->url())->assertForbidden();
        }
        $this->app['auth']->guard()->logout();
        $this->get($this->url())->assertRedirect(route('login'));
        $this->getJson($this->url())->assertUnauthorized();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFilters')]
    public function test_malformed_and_unbounded_filters_are_validation_errors(array $query, string $field): void
    {
        $this->getJson($this->url($query))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            'invalid calendar day' => [['date' => '2026-02-30'], 'date'],
            'non ISO date' => [['date' => '10/21/2026'], 'date'],
            'timestamp instead of date' => [['date' => '2026-10-21T00:00:00Z'], 'date'],
            'date array' => [['date' => ['2026-10-21']], 'date'],
            'before lower bound' => [['date' => '2000-01-02'], 'date'],
            'after upper bound' => [['date' => '2099-12-28'], 'date'],
            'unknown status' => [['status' => 'approved'], 'status'],
            'status array' => [['status' => ['scheduled']], 'status'],
            'student array' => [['student' => ['WEEK-STUDENT']], 'student'],
            'teacher array' => [['teacher' => ['WEEK-TEACHER']], 'teacher'],
            'student length' => [['student' => str_repeat('s', 101)], 'student'],
            'teacher length' => [['teacher' => str_repeat('t', 101)], 'teacher'],
        ];
    }

    public function test_supported_date_bounds_and_empty_filters_render_without_errors(): void
    {
        foreach (['2000-01-03', '2099-12-27', ''] as $date) {
            $response = $this->week(['date' => $date, 'student' => '', 'teacher' => '', 'status' => '']);
            $this->assertCount(7, $response->json('days'));
        }
        $this->week(['student' => str_repeat('s', 100), 'teacher' => str_repeat('t', 100)]);
    }

    public function test_calendar_caps_unique_lessons_at_250_in_stable_order_and_warns_only_when_truncated(): void
    {
        $ids = [];
        for ($i = 0; $i < 250; $i++) {
            $ids[] = $this->lesson('2026-10-20 10:00:00', '2026-10-20 11:00:00');
        }
        $response = $this->week()->assertJsonPath('week.count', 250)->assertJsonPath('week.truncated', false);
        $this->assertSame($ids, $this->ids($response));
        $this->lesson('2026-10-20 10:00:00', '2026-10-20 11:00:00');
        // The truncated flag drives the "Showing only the first 250 matching lessons" warning.
        $response = $this->week()->assertJsonPath('week.count', 250)->assertJsonPath('week.truncated', true);
        $this->assertSame($ids, $this->ids($response));
    }

    public function test_calendar_reads_preserve_all_erp_records_finance_audit_and_outbox_state(): void
    {
        $lesson = $this->lesson('2026-10-20 10:00:00', '2026-10-20 11:00:00', ['status' => 'completed']);
        DB::table('erp_attendance')->insert(['unit_id' => $this->unit, 'lesson_id' => $lesson, 'actual_minutes' => 60, 'status' => 'submitted', 'submitted_by' => $this->admin->id]);
        DB::table('erp_ledger')->insert(['unit_id' => $this->unit, 'student_id' => $this->student, 'kind' => 'payment', 'amount_paise' => 12000, 'currency' => 'INR', 'reference' => 'WEEK-RECORDED-PAYMENT', 'event_key' => 'week-payment', 'occurred_at' => '2026-10-19 08:00:00', 'created_by' => $this->admin->id]);
        $tables = array_values(array_filter(DB::connection()->getSchemaBuilder()->getTableListing(null, false), function ($table) {
            return strpos($table, 'erp_') === 0 || $table === 'integration_outbox';
        }));
        $snapshot = function () use ($tables) {
            $state = [];
            foreach ($tables as $table) $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            return $state;
        };
        $before = $snapshot();
        foreach ([[], ['status' => 'completed'], ['student' => 'WEEK-STUDENT'], ['date' => '2026-10-28']] as $query) {
            $this->week($query);
        }
        $this->getJson(route('app.api.classes.timetable.plans', $this->unit))->assertOk();
        $this->getJson($this->url(['status' => 'invalid']))->assertUnprocessable();
        $this->assertSame($before, $snapshot());
    }
}
