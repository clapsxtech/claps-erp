<?php
namespace Tests\Feature;

use App\Models\WorkHour;
use App\Modules\People\Services\TeacherIdentityVerifier as Verifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherCredentialLockoutTest extends TestCase
{
    private const VISITOR = '8.8.8.8';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaster(['teacher_name' => 'Synthetic Hidden Teacher', 'teacher_code' => 'T-HIDDEN']);
        $this->createSubject();
    }

    private function verify(string $name, string $dob, bool $fromVisitor = false)
    {
        // Server variables persist between requests in a test, so set the address every time.
        return $this->withServerVariables(['REMOTE_ADDR' => $fromVisitor ? self::VISITOR : '127.0.0.1'])->postJson('/studentinfom', ['teacher_name' => $name, 'password' => $dob]);
    }

    private function key(string $key): string
    {
        return hash('sha256', $key);
    }

    private function teacherKey(): string
    {
        return 'teacher-dob:'.Verifier::normalizeName('Synthetic Hidden Teacher');
    }

    public function test_public_forms_do_not_list_teacher_names(): void
    {
        foreach (['/attendance', '/resultform', '/performance'] as $page) {
            $this->get($page)->assertOk()->assertDontSee('Synthetic Hidden Teacher')
                ->assertSee('id="teacher_name"', false);
        }
    }

    public function test_typed_name_ignores_case_and_spacing_and_writes_the_recorded_spelling(): void
    {
        $this->verify('  synthetic   HIDDEN teacher ', '01/01/1990')->assertOk()->assertJson(['teacher_code' => 'T-HIDDEN']);
        $this->postJson('/upload-workhour', [
            'submission_key' => (string) Str::uuid(), 'teacher_name' => 'synthetic hidden teacher', 'teacher_code' => 'T-HIDDEN',
            'student_name' => 'QA Student', 'student_code' => 'S-QA', 'password' => '01/01/1990', 'subject' => 1,
            'date' => '2026-10-20', 'chapter' => 'Fractions', 'topic' => 'Addition', 'hour_taken' => '1', 'homework_status' => 'yes',
        ])->assertOk();
        $this->assertSame('Synthetic Hidden Teacher', WorkHour::first()->teacher_name);
    }

    public function test_repeated_wrong_dates_lock_the_teacher_even_with_the_right_date(): void
    {
        for ($i = 0; $i < Verifier::TEACHER_ATTEMPTS; $i++) $this->verify('Synthetic Hidden Teacher', '02/02/1990')->assertForbidden();
        // Spelling variants share the same lockout.
        $this->verify('synthetic  hidden teacher', '01/01/1990')->assertStatus(429)->assertJsonFragment(['message' => 'Too many attempts. Please wait 15 minutes, or contact the centre.']);
        // The counter is in the database, so it survives a cache reset or container restart.
        $this->assertDatabaseHas('erp_credential_attempts', ['key' => $this->key($this->teacherKey()), 'attempts' => Verifier::TEACHER_ATTEMPTS]);
        // Locked requests write nothing more.
        $this->assertDatabaseHas('erp_credential_attempts', ['key' => $this->key($this->teacherKey().':day'), 'attempts' => Verifier::TEACHER_ATTEMPTS]);
    }

    public function test_daily_limit_applies_after_the_short_window_expires(): void
    {
        DB::table('erp_credential_attempts')->insert(['key' => $this->key($this->teacherKey().':day'), 'attempts' => Verifier::TEACHER_DAILY_ATTEMPTS, 'expires_at' => time() + 3600]);
        $this->verify('Synthetic Hidden Teacher', '01/01/1990')->assertStatus(429)->assertJsonFragment(['message' => 'Too many attempts for this teacher today. Please try again tomorrow, or contact the centre.']);
    }

    public function test_success_clears_only_the_short_window(): void
    {
        for ($i = 0; $i < 3; $i++) $this->verify('Synthetic Hidden Teacher', '02/02/1990')->assertForbidden();
        $this->verify('Synthetic Hidden Teacher', '01/01/1990')->assertOk();
        $this->assertDatabaseMissing('erp_credential_attempts', ['key' => $this->key($this->teacherKey())]);
        $this->assertDatabaseHas('erp_credential_attempts', ['key' => $this->key($this->teacherKey().':day'), 'attempts' => 3]);
    }

    public function test_expired_counters_restart_from_one(): void
    {
        DB::table('erp_credential_attempts')->insert(['key' => $this->key($this->teacherKey()), 'attempts' => Verifier::TEACHER_ATTEMPTS, 'expires_at' => time() - 1]);
        $this->verify('Synthetic Hidden Teacher', '02/02/1990')->assertForbidden();
        $this->assertDatabaseHas('erp_credential_attempts', ['key' => $this->key($this->teacherKey()), 'attempts' => 1]);
    }

    public function test_unknown_names_count_only_against_the_visitor_address(): void
    {
        $this->verify('Nobody Real', '01/01/1990')->assertForbidden();
        $this->assertDatabaseCount('erp_credential_attempts', 0);

        config(['proxy.client_ip_from_worker' => true]);
        for ($i = 0; $i < Verifier::IP_ATTEMPTS; $i++) $this->verify('Nobody Real '.$i, '01/01/1990', true)->assertForbidden();
        $this->assertDatabaseCount('erp_credential_attempts', 1);
        $this->verify('Synthetic Hidden Teacher', '01/01/1990', true)->assertStatus(429);
        // Other visitors and the teacher's own counters are unaffected.
        $this->verify('Synthetic Hidden Teacher', '01/01/1990')->assertOk();
    }

    public function test_every_public_credential_endpoint_records_failures(): void
    {
        // Complete, valid forms, so each endpoint reaches the credential check.
        $topic = \App\Models\Topic::create(['name' => 'Engagement']);
        $common = ['teacher_name' => 'Synthetic Hidden Teacher', 'teacher_code' => 'T-HIDDEN', 'student_name' => 'QA Student', 'student_code' => 'S-QA',
            'password' => '02/02/1990', 'dob' => '02/02/1990', 'subject' => 1, 'chapter' => 'Fractions', 'topic' => $topic->id,
            'total_mark' => '20', 'mark_scored' => '18', 'mode_of_exam' => 'Written (live)', 'examination_date' => '2026-10-20', 'remark' => 'Good progress',
            'date' => '2026-10-20', 'hour_taken' => '1', 'homework_status' => 'yes'];
        foreach (['/studentinfom', '/studentinforesult', '/get-teacher-dob', '/upload-workhour', '/upload-result', '/performnce-upload'] as $i => $endpoint) {
            if ($endpoint === '/upload-workhour') $this->postJson($endpoint, ['topic' => 'Addition'] + $common + ['submission_key' => (string) Str::uuid()])->assertForbidden();
            else $this->postJson($endpoint, $common + ['submission_key' => (string) Str::uuid()])->assertForbidden();
            $this->assertDatabaseHas('erp_credential_attempts', ['key' => $this->key($this->teacherKey().':day'), 'attempts' => $i + 1]);
            DB::table('erp_credential_attempts')->where('key', $this->key($this->teacherKey()))->delete();
        }
    }
}
