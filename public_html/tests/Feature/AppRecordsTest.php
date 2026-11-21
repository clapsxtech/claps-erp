<?php
namespace Tests\Feature;

use App\Http\Controllers\App\RecordsDatasets;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Records & administration (/app-api/records): superadmin only, read-only, explicit fields, no credentials. Synthetic data only. */
class AppRecordsTest extends TestCase
{
    private const DOB = '09/09/1979';
    private const HASH = 'SYNTHETIC-SUBMISSION-HASH';

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
        $this->member = $this->createMember(['name' => 'Synthetic Sales']);
        $unit = DB::table('erp_units')->insertGetId(['code' => 'REC-A', 'name' => 'Records Unit', 'timezone' => 'UTC']);
        DB::table('erp_memberships')->insert(['unit_id' => $unit, 'user_id' => $this->member->id, 'role' => 'team_manager']);
        $now = now();

        DB::table('topics')->insert([['name' => 'Fractions', 'created_at' => $now], ['name' => 'Algebra', 'created_at' => $now]]);
        DB::table('subjects')->insert(['id' => 1, 'name' => 'Mathematics', 'description' => 'Maths']);
        $this->createMaster(['teacher_name' => 'Rec Teacher', 'teacher_code' => 'RT-1', 'student_name' => 'Rec Student', 'student_code' => 'RS-1', 'teacher_dob' => self::DOB, 'team' => 'North']);
        DB::table('students')->insert(['student_name' => '=HYPERLINK("evil")', 'student_code' => 'RS-9', 'status' => 'Active', 'hour_rate' => '450', 'category' => 'Regular', 'source' => 'Web', 'hours' => '3']);
        DB::table('teachers')->insert(['teacher_name' => 'Rec Teacher', 'teacher_code' => 'RT-1', 'status' => 'Active', 'type' => 'Full time', 'subject' => 'Maths', 'medium' => 'English']);
        DB::table('get_students')->insert(['student_name' => 'Rec Student', 'student_code' => 'RS-1', 'team' => 'North', 'total' => '0']);
        foreach (range(1, 30) as $i) {
            DB::table('work_hours')->insert([
                'teacher_name' => $i % 2 ? 'Rec Teacher' : 'Other Teacher', 'teacher_code' => $i % 2 ? 'RT-1' : 'RT-2',
                'student_name' => 'Rec Student', 'student_code' => 'RS-1', 'subject' => 'Mathematics',
                'date' => sprintf('2026-10-%02d', $i), 'date_submitted' => $i === 1 ? '2026-11-02 10:00:00' : sprintf('2026-10-%02d 18:00:00', $i),
                'chapter' => 'Ch', 'topic' => 'Topic '.$i, 'hour_taken' => $i === 30 ? '10' : '2', 'homework_status' => 'yes',
                'dob' => self::DOB, 'submission_key' => 'key-'.$i, 'submission_hash' => self::HASH,
                'hour_payment' => '450', 'payment_per_hour' => '300', 'profit_per_hour' => '150', 'total_amount' => '900',
            ]);
        }
        DB::table('teacher_reports')->insert(['teacher_name' => 'Rec Teacher', 'teacher_code' => 'RT-1', 'student_name' => 'Rec Student', 'student_code' => 'RS-1', 'subject' => 'Maths', 'date' => '2026-10-01', 'date_submitted' => '2026-10-01 10:00:00', 'hour_taken' => '1', 'homework_status' => 'no', 'dob' => self::DOB]);
        DB::table('marklist_reports')->insert([
            ['teacher_name' => 'Rec Teacher', 'teacher_code' => 'RT-1', 'student_name' => 'Rec Student', 'student_code' => 'RS-1', 'subject' => 'Maths', 'chapter' => 'U1', 'mark_scored' => '40', 'total_mark' => '50', 'mode_of_exam' => 'Online', 'date_submitted' => '2026-10-02 10:00:00', 'examination_date' => '2026-10-01', 'remark' => 'Good', 'dob' => self::DOB],
            ['teacher_name' => 'Rec Teacher', 'teacher_code' => 'RT-1', 'student_name' => 'Second Student', 'student_code' => 'RS-2', 'subject' => 'Maths', 'chapter' => 'U2', 'mark_scored' => '10', 'total_mark' => '50', 'mode_of_exam' => 'Online', 'date_submitted' => '2026-10-03 10:00:00', 'examination_date' => '2026-10-02', 'remark' => 'Retry', 'dob' => self::DOB],
        ]);
        DB::table('marklists')->insert(['teacher_name' => 'Rec Teacher', 'teacher_code' => 'RT-1', 'student_name' => 'Rec Student', 'student_code' => 'RS-1', 'subject' => 1, 'chapter' => 'Saved', 'mark_scored' => '45', 'total_mark' => '50', 'examination_date' => '2026-10-05', 'mode_of_exam' => 'Offline', 'remark' => 'Great', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('due_reports')->insert(['student_name' => 'Rec Student', 'student_code' => 'RS-1', 'phone' => '+910000000001', 'admin_staff' => 'Priya', 'section' => 'A', 'balance' => '-900', 'current_status' => 'Due']);
        DB::table('time_tables')->insert([
            ['student_name' => 'Rec Student', 'monday_time' => '16:00', 'monday_teacher' => 'Rec Teacher', 'monday_subject' => 'Maths', 'monday_hour' => '1', 'link' => 'javascript:alert(1)', 'student_number' => '+91900'],
            ['student_name' => 'Other Student', 'monday_time' => '17:00', 'monday_teacher' => 'Rec Teacher', 'monday_subject' => 'Maths', 'monday_hour' => '1', 'link' => 'meet.google.com/abc', 'student_number' => '+91901'],
        ]);
        DB::table('settings')->insert(['name' => 'Synthetic Org', 'phone' => '+91 0', 'support_mail' => 's@example.invalid', 'admin_mail' => 'a@example.invalid', 'career_mail' => 'c@example.invalid']);
        $role = DB::table('roles')->insertGetId(['name' => 'Office', 'guard_name' => 'web']);
        $perm = DB::table('permissions')->insertGetId(['name' => 'view reports', 'guard_name' => 'web']);
        DB::table('role_has_permissions')->insert(['role_id' => $role, 'permission_id' => $perm]);
        DB::table('model_has_roles')->insert(['role_id' => $role, 'model_type' => User::class, 'model_id' => $this->member->id]);
        User::where('id', $this->member->id)->update(['two_factor_secret' => 'SYNTHETIC-TOTP', 'remember_token' => 'SYNTHETIC-REMEMBER', 'dob' => '1980-01-01']);
    }

    private function assertClean(string $body): void
    {
        foreach ([self::DOB, self::HASH, 'key-1', 'teacher_dob', 'teacherDob', 'submission', 'SYNTHETIC-TOTP', 'SYNTHETIC-REMEMBER', '1980-01-01', '$2y$', 'password', 'javascript:'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "leaked $needle");
        }
    }

    public function test_every_route_refuses_guests_and_non_administrators(): void
    {
        $urls = ['/app-api/records', '/app-api/records/settings', '/app-api/records/work-hours', '/app-api/records/work-hours/export', '/app-api/records/users', '/app-api/records/roles/export'];
        foreach ($urls as $url) $this->getJson($url)->assertUnauthorized();
        $this->actingAs($this->member);
        foreach ($urls as $url) $this->getJson($url)->assertForbidden();
        $this->actingAs($this->admin)->getJson('/app-api/records/password')->assertNotFound();
        $this->getJson('/app-api/records/settings/export')->assertNotFound();
    }

    public function test_every_set_lists_only_its_declared_columns_in_json_and_csv(): void
    {
        $this->actingAs($this->admin);
        $registry = array_keys(array_filter(RecordsDatasets::all(), fn ($set) => isset($set['columns'])));
        $this->assertSame($registry, RecordsDatasets::TABLE_SLUGS);
        foreach (RecordsDatasets::TABLE_SLUGS as $slug) {
            $response = $this->getJson('/app-api/records/'.$slug)->assertOk();
            $columns = array_column($response->json('set.columns'), 'key');
            $this->assertSame(array_keys(RecordsDatasets::get($slug)['columns']), $columns, $slug);
            $this->assertNotEmpty($response->json('data'), "$slug has synthetic rows");
            foreach ($response->json('data') as $row) $this->assertSame(array_merge(['rowKey'], $columns), array_keys($row), $slug);
            $this->assertClean($response->getContent());
            $csv = $this->get('/app-api/records/'.$slug.'/export')->assertOk()->streamedContent();
            $this->assertSame($response->json("meta.total") + 1, count(array_filter(explode("\n", trim($csv)))), "$slug export rows");
            $this->assertClean($csv);
        }
    }

    public function test_overview_lists_every_set_with_counts(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/app-api/records')->assertOk()->json();
        $this->assertSame(['academic', 'operations', 'site'], array_column($json['groups'], 'key'));
        $sets = collect($json['sets'])->keyBy('slug');
        $this->assertSame(['topics', 'subjects', 'assessments', 'assignments', 'students', 'teachers', 'assessment-reports', 'monthly-reports', 'teacher-hours', 'work-hours',
            'attendance', 'student-attendance', 'dues', 'timetable', 'student-timetable', 'users', 'roles', 'settings'], $sets->keys()->all());
        $this->assertSame(['slug', 'group', 'title', 'description', 'count', 'classicUrl'], array_keys($json['sets'][0]));
        $this->assertSame(30, $sets['work-hours']['count']);
        $this->assertSame(3, $sets['assessment-reports']['count']);
        $this->assertSame(2, $sets['timetable']['count']);
        $this->assertSame(1, $sets['settings']['count']);
    }

    public function test_work_hours_are_paged_filtered_sorted_totalled_and_whitelisted(): void
    {
        $this->actingAs($this->admin);
        $response = $this->getJson('/app-api/records/work-hours')->assertOk();
        $json = $response->json();
        $this->assertClean($response->getContent());
        $this->assertSame(['page' => 1, 'perPage' => 25, 'total' => 30, 'sort' => 'date', 'dir' => 'desc'], $json['meta']);
        $this->assertCount(25, $json['data']);
        $this->assertSame(['rowKey', 'onTime', 'team', 'teacherName', 'teacherCode', 'studentName', 'studentCode', 'subject', 'date', 'dateSubmitted', 'chapter', 'topic',
            'hourTaken', 'homeworkStatus', 'hourPayment', 'paymentPerHour', 'profitPerHour', 'totalAmount'], array_keys($json['data'][0]));
        $this->assertSame('North', $json['data'][0]['team']);
        $this->assertSame(['hours' => '68', 'payable' => '27000'], array_column($json['totals'], 'value', 'key'));

        $this->getJson('/app-api/records/work-hours?page=2')->assertJsonCount(5, 'data');
        $this->getJson('/app-api/records/work-hours?status=0')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.onTime', false)->assertJsonPath('data.0.date', '2026-10-01');
        $this->getJson('/app-api/records/work-hours?teacher=Rec%20Teacher&from=2026-10-10&to=2026-10-20')->assertJsonPath('meta.total', 5);
        $this->getJson('/app-api/records/work-hours?q=RT-2')->assertJsonPath('meta.total', 15);
        // Spreadsheet text sorts numerically: "10" hours comes first descending, not "2".
        $this->getJson('/app-api/records/work-hours?sort=hourTaken&dir=desc')->assertJsonPath('data.0.hourTaken', '10');
        $this->getJson('/app-api/records/work-hours?sort=dob')->assertStatus(422);
        $this->getJson('/app-api/records/work-hours?status=maybe')->assertStatus(422);
        $this->getJson('/app-api/records/work-hours?from=01/10/2026')->assertStatus(422);
        $this->getJson('/app-api/records/work-hours?from=2026-10-20&to=2026-10-01')->assertStatus(422);
        $this->getJson('/app-api/records/work-hours?per_page=5000')->assertStatus(422);
    }

    public function test_export_streams_the_filtered_whitelisted_csv_with_formula_guard(): void
    {
        $this->actingAs($this->admin);
        $csv = $this->get('/app-api/records/work-hours/export?teacher=Rec%20Teacher')->assertOk()->streamedContent();
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(16, $lines);
        $this->assertStringStartsWith('Submission,Team,Teacher,"Teacher code"', $lines[0]);
        $this->assertClean($csv);
        $this->assertStringNotContainsString('Other Teacher', $csv);

        $students = $this->get('/app-api/records/students/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"evil\"\")\"", $students);
        $this->assertStringNotContainsString(',=HYPERLINK', $students);

        $roles = $this->get('/app-api/records/roles/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Office,web,1,"view reports"', $roles);
    }

    public function test_assignments_never_return_teacher_dates_of_birth(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/app-api/records/assignments?team=North')->assertOk();
        $this->assertSame(['rowKey', 'studentName', 'studentCode', 'teacherName', 'teacherCode', 'team', 'hourPayment', 'paymentPerHour', 'profitPerHour'], array_keys($response->json('data.0')));
        $this->assertClean($response->getContent());
        $this->assertClean($this->get('/app-api/records/assignments/export')->streamedContent());
    }

    public function test_assessment_reports_merge_saved_and_historical_results_per_student(): void
    {
        $this->actingAs($this->admin);
        $response = $this->getJson('/app-api/records/assessment-reports?student=Rec%20Student')->assertOk();
        $this->assertClean($response->getContent());
        $json = $response->json();
        $this->assertSame(2, $json['meta']['total']);
        $this->assertSame(['Saved', 'U1'], array_column($json['data'], 'chapter'));
        $this->assertSame(['1', '2'], array_column($json['data'], 'rowKey'));
        $totals = array_column($json['totals'], 'value', 'key');
        $this->assertSame('2', $totals['assessments']);
        $this->assertEquals(85.0, (float) $totals['averagePercent']);
        $this->getJson('/app-api/records/assessment-reports?from=2026-10-02&to=2026-10-02')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.studentName', 'Second Student');
        $this->getJson('/app-api/records/assessments')->assertJsonPath('data.0.subject', 'Mathematics')->assertJsonPath('meta.total', 1);
    }

    public function test_attendance_reports_scope_by_teacher_and_student_with_totals(): void
    {
        $this->actingAs($this->admin);
        $json = $this->getJson('/app-api/records/attendance?teacher=Rec%20Teacher&from=2026-10-01&to=2026-10-09')->assertOk()->json();
        $this->assertSame(5, $json['meta']['total']);
        $this->assertSame(['rowKey', 'teacherName', 'studentName', 'date', 'hourTaken', 'subject', 'topic'], array_keys($json['data'][0]));
        $this->assertSame(['hours' => '10', 'classes' => '5'], array_column($json['totals'], 'value', 'key'));
        $this->getJson('/app-api/records/student-attendance?student=Nobody')->assertJsonPath('meta.total', 0);
        $this->getJson('/app-api/records/monthly-reports?from=2026-10-29')->assertJsonPath('data.0.hourTaken', '12')->assertJsonPath('data.0.totalAmount', '1800');
        $this->getJson('/app-api/records/teacher-hours')->assertJsonPath('meta.total', 1);
    }

    public function test_timetables_flatten_the_week_and_drop_unsafe_links(): void
    {
        $this->actingAs($this->admin);
        $response = $this->getJson('/app-api/records/timetable?teacher=Rec%20Teacher')->assertOk();
        $this->assertClean($response->getContent());
        $json = $response->json();
        $this->assertSame(['rowKey', 'day', 'time', 'teacherName', 'studentName', 'subject', 'hour', 'link', 'studentNumber'], array_keys($json['data'][0]));
        $this->assertSame([null, 'https://meet.google.com/abc'], array_column($json['data'], 'link'));
        $this->assertSame(['Rec Teacher'], array_column($json['set']['filters'][0]['options'], 'value'));
        $this->getJson('/app-api/records/student-timetable?student=Other%20Student')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.teacherName', 'Rec Teacher');
        $this->getJson('/app-api/records/timetable?day=funday')->assertStatus(422);
    }

    public function test_site_users_roles_and_settings_are_read_only_and_whitelisted(): void
    {
        $this->actingAs($this->admin);
        $users = $this->getJson('/app-api/records/users?q=Synthetic%20Sales')->assertOk();
        $this->assertClean($users->getContent());
        $this->assertSame(['rowKey', 'name', 'email', 'phone', 'roles'], array_keys($users->json('data.0')));
        $this->assertSame(['Office'], $users->json('data.0.roles'));
        $this->assertClean($this->get('/app-api/records/users/export')->streamedContent());

        $this->getJson('/app-api/records/roles')->assertOk()->assertJsonPath('data.0.name', 'Office')->assertJsonPath('data.0.permissions', ['view reports'])->assertJsonPath('data.0.members', '1');

        $settings = $this->getJson('/app-api/records/settings')->assertOk()->json('settings');
        $this->assertSame(['name', 'address', 'phone', 'supportMail', 'adminMail', 'careerMail', 'facebook', 'instagram', 'youtube', 'linkedin', 'twitter', 'map', 'updatedAt'], array_keys($settings));
        DB::table('settings')->delete();
        $this->getJson('/app-api/records/settings')->assertOk()->assertJsonPath('settings', null);

        // Read-only: no write verbs exist on these routes.
        $this->postJson('/app-api/records/topics', ['name' => 'x'])->assertStatus(405);
        $this->assertSame(2, DB::table('topics')->count());
    }
}
