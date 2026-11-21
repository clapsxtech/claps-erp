<?php
namespace Tests\Feature;

use App\Models\Marklist;
use App\Models\Performance;
use App\Models\TimeTable;
use App\Models\Topic;
use App\Models\WorkHour;
use App\Modules\Integrations\Models\IntegrationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The public, no-login teacher forms served by the React app. Synthetic data only. */
class AppPublicFormsTest extends TestCase
{
    private const DOB = '01/01/1990';

    private int $subject;
    private int $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaster();
        $this->createMaster(['student_name' => 'QA Second Student', 'student_code' => 'S-QA2']);
        $this->subject = $this->createSubject()->id;
        $this->topic = Topic::create(['name' => 'QA Algebra'])->id;
        TimeTable::create([
            'student_name' => 'QA Student', 'monday_teacher' => 'QA Teacher', 'monday_time' => '09:00',
            'monday_hour' => '1', 'monday_subject' => 'Mathematics', 'monday_number' => 'secret-phone',
            'link' => 'https://meet.example.invalid/private-lesson',
        ]);
    }

    private function hostSession(string $path): array
    {
        $html = $this->get($path)->assertOk()->assertSee('id="erp-root"', false)->getContent();
        $start = strpos($html, '<script type="application/json" id="erp-session">') + strlen('<script type="application/json" id="erp-session">');
        return json_decode(html_entity_decode(substr($html, $start, strpos($html, '</script>', $start) - $start)), true);
    }

    private function assertNoPrivateData(string $path): void
    {
        $this->get($path)->assertOk()->assertDontSee(self::DOB)->assertDontSee('QA Student')->assertDontSee('S-QA')
            ->assertDontSee('T-QA')->assertDontSee('100.00')->assertDontSee('private-lesson')->assertDontSee('secret-phone');
    }

    private function pair(array $overrides = []): array
    {
        return array_merge([
            'submission_key' => (string) Str::uuid(), 'teacher_name' => 'QA Teacher', 'teacher_code' => 'T-QA',
            'student_name' => 'QA Student', 'student_code' => 'S-QA', 'password' => self::DOB,
        ], $overrides);
    }

    public function test_upload_pages_embed_only_teacher_names_and_reference_lists(): void
    {
        $attendance = $this->hostSession('/attendance');
        $this->assertSame(['kind', 'form', 'teachers', 'subjects', 'maxDate', 'readOnly'], array_keys($attendance));
        $this->assertSame('attendance', $attendance['form']);
        $this->assertSame(['QA Teacher'], $attendance['teachers']);
        $this->assertSame([['id' => $this->subject, 'name' => 'QA Mathematics']], $attendance['subjects']);
        $this->assertFalse($attendance['readOnly']);

        $result = $this->hostSession('/resultform');
        $this->assertSame(['kind', 'form', 'teachers', 'subjects', 'readOnly'], array_keys($result));
        $performance = $this->hostSession('/performance');
        $this->assertSame(['kind', 'form', 'teachers', 'topics', 'readOnly'], array_keys($performance));
        $this->assertSame([['id' => $this->topic, 'name' => 'QA Algebra']], $performance['topics']);

        foreach (['/attendance', '/resultform', '/performance'] as $path) {
            $this->assertNoPrivateData($path);
            $this->assertStringContainsString('no-store', $this->get($path)->headers->get('Cache-Control'));
        }
    }

    public function test_timetable_pages_embed_no_directory(): void
    {
        foreach (['/timetable', '/timetableReport'] as $path) {
            $this->assertSame(['kind' => 'public', 'form' => 'timetable', 'readOnly' => false], $this->hostSession($path));
            $this->assertNoPrivateData($path);
            $this->get($path)->assertDontSee('QA Teacher');
        }
    }

    public function test_linked_teachers_are_still_sent_to_their_workspace(): void
    {
        $user = $this->createMember(['name' => 'Linked Synthetic Teacher']);
        $unit = DB::table('erp_units')->insertGetId(['code' => 'PUB-A', 'name' => 'Public A']);
        DB::table('erp_memberships')->insert(['user_id' => $user->id, 'unit_id' => $unit, 'role' => 'teacher']);
        $this->actingAs($user);
        foreach (['/timetableReport' => 'timetable', '/timetable' => 'timetable', '/attendance' => 'attendance', '/resultform' => 'results'] as $path => $section) {
            $this->get($path)->assertRedirect(route('teacher.portal.section', ['section' => $section]));
            $this->assertSame(url('/teacher/'.$section), route('teacher.portal.section', ['section' => $section]));
        }
    }

    public function test_classic_route_names_and_throttles_are_kept(): void
    {
        foreach (['attendance' => '/attendance', 'resultform' => '/resultform', 'performenceform' => '/performance', 'timetable' => '/timetable', 'timetableReport' => '/timetableReport'] as $name => $path) {
            $this->assertSame(url($path), route($name));
        }
        foreach (['studentinfom', 'upload-workhour', 'studentinforesult', 'upload-result', 'submit-application', 'performnce-upload', 'get-teacher-dob'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame(['POST'], $route->methods(), $name);
            $this->assertContains('throttle:teacher-credentials', $route->gatherMiddleware(), $name);
        }
    }

    public function test_student_lookups_verify_the_dob_and_list_only_names_and_codes(): void
    {
        foreach (['/studentinfom' => ['student_name', 'student_code'], '/studentinforesult' => ['id', 'student_name', 'student_code']] as $url => $keys) {
            $this->postJson($url, ['teacher_name' => 'QA Teacher'])->assertStatus(422);
            $this->postJson($url, ['teacher_name' => 'QA Teacher', 'password' => '02/02/1992'])->assertForbidden()->assertDontSee('QA Student');
            $response = $this->postJson($url, ['teacher_name' => 'QA Teacher', 'password' => self::DOB])->assertOk()->assertJsonPath('teacher_code', 'T-QA');
            $this->assertSame(['students', 'teacher_code'], array_keys($response->json()));
            $this->assertCount(2, $response->json('students'));
            $this->assertSame($keys, array_keys($response->json('students.0')));
            $this->assertStringNotContainsString(self::DOB, $response->getContent());
            $this->assertStringNotContainsString('hour_payment', $response->getContent());
        }
    }

    public function test_attendance_saves_once_and_refuses_a_wrong_dob(): void
    {
        $payload = $this->pair(['subject' => $this->subject, 'date' => '2026-10-20', 'chapter' => 'Fractions', 'topic' => 'Adding', 'hour_taken' => '1.25', 'homework_status' => 'yes']);
        $this->postJson('/upload-workhour', array_merge($payload, ['password' => '02/02/1992']))->assertForbidden();
        $this->assertDatabaseCount('work_hours', 0);
        $first = $this->postJson('/upload-workhour', $payload)->assertOk()->assertJson(['success' => true]);
        $this->assertSame(['success', 'msg', 'id'], array_keys($first->json()));
        $this->postJson('/upload-workhour', $payload)->assertOk()->assertJson(['id' => $first->json('id')]);
        $this->postJson('/upload-workhour', array_merge($payload, ['hour_taken' => '2']))->assertStatus(409);
        $this->postJson('/upload-workhour', array_merge($payload, ['submission_key' => (string) Str::uuid(), 'hour_taken' => '30']))->assertStatus(422)->assertJsonValidationErrors('hour_taken');
        $this->assertSame(1, WorkHour::count());
        $this->assertDatabaseHas('work_hours', ['teacher_code' => 'T-QA', 'student_code' => 'S-QA', 'total_amount' => '125.00', 'dob' => null]);
    }

    public function test_result_saves_once_with_an_outbox_event_and_refuses_a_wrong_dob(): void
    {
        $payload = $this->pair(['subject' => $this->subject, 'chapter' => 'Fractions', 'total_mark' => '20', 'mark_scored' => '18', 'mode_of_exam' => 'written (offline)', 'examination_date' => '2026-10-20', 'remark' => null]);
        $this->postJson('/upload-result', array_merge($payload, ['password' => '02/02/1992']))->assertForbidden()->assertJson(['success' => false, 'msg' => 'Teacher verification failed.']);
        $first = $this->postJson('/upload-result', $payload)->assertOk()->assertJson(['success' => true, 'replayed' => false]);
        $this->assertSame(['success', 'msg', 'next_submission_key', 'id', 'replayed'], array_keys($first->json()));
        $this->assertTrue(Str::isUuid($first->json('next_submission_key')));
        $this->postJson('/submit-application', $payload)->assertOk()->assertJson(['id' => $first->json('id'), 'replayed' => true]);
        $this->postJson('/upload-result', array_merge($payload, ['mark_scored' => '19']))->assertStatus(409)->assertJson(['success' => false]);
        $this->postJson('/upload-result', array_merge($payload, ['submission_key' => (string) Str::uuid(), 'mark_scored' => '25']))->assertStatus(422)->assertJsonValidationErrors('mark_scored');
        $this->assertSame(1, Marklist::count());
        $this->assertSame(['marklist.public.created'], IntegrationOutbox::pluck('event_type')->all());
    }

    public function test_performance_saves_once_with_an_outbox_event_and_refuses_a_wrong_dob(): void
    {
        $payload = $this->pair(['topic' => $this->topic, 'remark' => 'Steady']);
        $this->postJson('/performnce-upload', array_merge($payload, ['student_code' => 'S-QA2']))->assertForbidden();
        $first = $this->postJson('/performnce-upload', $payload)->assertOk()->assertJson(['success' => true, 'replayed' => false]);
        $this->postJson('/performnce-upload', $payload)->assertOk()->assertJson(['id' => $first->json('id'), 'replayed' => true]);
        $this->postJson('/performnce-upload', array_merge($payload, ['remark' => 'Changed']))->assertStatus(409);
        $this->assertSame(1, Performance::count());
        $this->assertDatabaseHas('performances', ['topic' => 'QA Algebra', 'student_code' => 'S-QA']);
        $this->assertSame(['performance.public.created'], IntegrationOutbox::pluck('event_type')->all());
    }

    public function test_timetable_lookup_returns_only_the_verified_teachers_lessons(): void
    {
        $this->postJson('/get-teacher-dob', ['teacher_name' => 'QA Teacher', 'password' => '02/02/1992'])->assertForbidden()->assertDontSee('private-lesson');
        $response = $this->postJson('/get-teacher-dob', ['teacher_name' => 'QA Teacher', 'password' => self::DOB])->assertOk()
            ->assertJsonPath('days.monday.0.student_name', 'QA Student')->assertJsonPath('days.tuesday', []);
        $this->assertSame(['time', 'subject', 'hour', 'student_name', 'link'], array_keys($response->json('days.monday.0')));
        $response->assertDontSee('secret-phone')->assertDontSee(self::DOB);
    }

    public function test_credential_posts_are_throttled_per_teacher(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/studentinfom', ['teacher_name' => 'QA Teacher', 'password' => 'wrong-'.$i])->assertForbidden();
        }
        $this->postJson('/studentinfom', ['teacher_name' => 'QA Teacher', 'password' => self::DOB])->assertStatus(429);
        $this->postJson('/upload-result', $this->pair())->assertStatus(429);
        $this->postJson('/get-teacher-dob', ['teacher_name' => 'QA Teacher', 'password' => self::DOB])->assertStatus(429);
    }

    public function test_sheets_authority_marks_upload_pages_read_only_and_refuses_saves(): void
    {
        config(['sheet_transition.authority' => 'sheets']);
        $this->assertTrue($this->hostSession('/attendance')['readOnly']);
        $this->assertFalse($this->hostSession('/timetable')['readOnly']);
        $this->postJson('/upload-workhour', $this->pair(['subject' => $this->subject, 'date' => '2026-10-20', 'chapter' => 'A', 'topic' => 'B', 'hour_taken' => '1', 'homework_status' => 'no']))->assertStatus(409);
        $this->postJson('/studentinfom', ['teacher_name' => 'QA Teacher', 'password' => self::DOB])->assertOk();
        $this->assertDatabaseCount('work_hours', 0);
    }
}
