<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** HTTP route, middleware, JSON read and persistence tests with synthetic fixtures only. The teacher dashboard is /teacher (React host page) plus GET /teacher-api/workspace. */
class ErpTeacherHttpTest extends TestCase
{
    private $teacherUser;
    private $ownLesson;
    private $otherLesson;
    private $teacher;
    private $unit;
    protected function setUp(): void {
        parent::setUp();
        $this->teacherUser=$this->createMember(['name'=>'Synthetic Teacher Login']);
        $this->unit=DB::table('erp_units')->insertGetId(['code'=>'HTTP-TEACH-AA','name'=>'Synthetic AA']);
        $otherUnit=DB::table('erp_units')->insertGetId(['code'=>'HTTP-TEACH-BB','name'=>'Synthetic BB']);
        $this->teacher=DB::table('erp_teachers')->insertGetId(['user_id'=>$this->teacherUser->id,'code'=>'HTTP-TEACH-T1','name'=>'Linked Synthetic Teacher','timezone'=>'Asia/Kolkata','status'=>'active']);
        $otherTeacher=DB::table('erp_teachers')->insertGetId(['code'=>'HTTP-TEACH-T2','name'=>'Other Synthetic Teacher','timezone'=>'UTC','status'=>'active']);
        DB::table('erp_teacher_units')->insert([['teacher_id'=>$this->teacher,'unit_id'=>$this->unit],['teacher_id'=>$otherTeacher,'unit_id'=>$otherUnit]]);
        DB::table('erp_memberships')->insert(['user_id'=>$this->teacherUser->id,'unit_id'=>$this->unit,'role'=>'teacher']);
        $subject=DB::table('erp_subjects')->insertGetId(['name'=>'Synthetic HTTP Maths']);
        $date=now('UTC')->subDays(2)->toDateString();
        foreach ([[$this->unit,$this->teacher,'Owned Synthetic Student'],[$otherUnit,$otherTeacher,'Hidden Other Student']] as $i=>$values) {
            [$unit,$teacher,$name]=$values;
            $student=DB::table('erp_students')->insertGetId(['unit_id'=>$unit,'code'=>'HTTP-TEACH-S'.$i,'name'=>$name,'registered_on'=>$date,'timezone'=>'UTC','status'=>'active','grade'=>'8','syllabus'=>'Test','guardian_contact'=>'PRIVATE-GUARDIAN-SECRET-'.$i,'meeting_url'=>'https://example.invalid/private-meeting-'.$i]);
            $e=DB::table('erp_enrollments')->insertGetId(['unit_id'=>$unit,'student_id'=>$student,'teacher_id'=>$teacher,'subject_id'=>$subject,'starts_on'=>$date,'student_rate_paise'=>98765,'teacher_rate_paise'=>54321,'currency'=>'INR','status'=>'active']);
            $lesson=DB::table('erp_lessons')->insertGetId(['unit_id'=>$unit,'enrollment_id'=>$e,'student_id'=>$student,'teacher_id'=>$teacher,'subject_id'=>$subject,'starts_at'=>$date.' 10:00:00','ends_at'=>$date.' 11:00:00','status'=>'scheduled','kind'=>'regular','student_rate_paise'=>98765,'teacher_rate_paise'=>54321,'currency'=>'INR']);
            if ($i===0) $this->ownLesson=$lesson; else $this->otherLesson=$lesson;
        }
    }
    private function embedded($response): array {
        $html=$response->getContent();$tag='<script type="application/json" id="erp-session">';$start=strpos($html,$tag)+strlen($tag);
        return json_decode(html_entity_decode(substr($html,$start,strpos($html,'</script>',$start)-$start)),true);
    }
    private function assertPrivate($response): void {
        $response->assertDontSee('Hidden Other Student')->assertDontSee('PRIVATE-GUARDIAN-SECRET')->assertDontSee('private-meeting')->assertDontSee('98765')->assertDontSee('54321');
    }
    private function workspace(string $query=''): \Illuminate\Testing\TestResponse {
        $response=$this->getJson('/teacher-api/workspace'.$query)->assertOk();$this->assertPrivate($response);return $response;
    }
    public function test_guest_is_redirected_to_login_for_listing_and_submission(): void {
        $this->get('/teacher')->assertRedirect(route('login'));
        $this->getJson('/teacher-api/workspace')->assertUnauthorized();
        $this->post('/erp/teacher/attendance',['lesson_id'=>$this->ownLesson,'actual_minutes'=>60])->assertRedirect(route('login'));
        $this->postJson('/erp/teacher/attendance',['lesson_id'=>$this->ownLesson,'actual_minutes'=>60])->assertUnauthorized();
        $this->assertSame(0,DB::table('erp_attendance')->count());
    }
    public function test_teacher_view_renders_only_own_lessons_and_posts_attendance(): void {
        $this->actingAs($this->teacherUser);
        $this->assertSame('Asia/Kolkata',$this->embedded($this->get('/teacher/attendance')->assertOk())['teacher']['timezone']);
        $json=$this->workspace()->assertSee('Owned Synthetic Student')->json();
        $this->assertSame('Asia/Kolkata',$json['range']['timezone']);
        $this->assertSame([$this->ownLesson],array_column($json['lessons'],'id'));
        $this->assertTrue($json['lessons'][0]['needsAttendance']);
        $this->postJson('/erp/teacher/attendance',['lesson_id'=>$this->ownLesson,'actual_minutes'=>45,'unit_id'=>999,'teacher_id'=>999])->assertOk()->assertJsonPath('message','Attendance submitted for administrator review.');
        $this->assertDatabaseHas('erp_attendance',['lesson_id'=>$this->ownLesson,'unit_id'=>$this->unit,'actual_minutes'=>45,'submitted_by'=>$this->teacherUser->id,'status'=>'submitted']);
        $this->assertSame(0,DB::table('erp_ledger')->count());
        $lesson=$this->workspace()->json('lessons.0');
        $this->assertSame(['status'=>'submitted','minutes'=>45],$lesson['attendance']);
        $this->assertFalse($lesson['needsAttendance']);
    }
    public function test_forged_crossunit_and_other_teacher_ids_are_forbidden(): void {
        $this->actingAs($this->teacherUser);
        $this->postJson('/erp/teacher/attendance',['lesson_id'=>$this->otherLesson,'actual_minutes'=>60])->assertForbidden();
        DB::table('erp_lessons')->where('id',$this->otherLesson)->update(['teacher_id'=>$this->teacher]);
        $this->postJson('/erp/teacher/attendance',['lesson_id'=>$this->otherLesson,'actual_minutes'=>60])->assertForbidden();
        $this->assertSame(0,DB::table('erp_attendance')->count());
    }
    public function test_deleted_user_and_unlinked_site_user_cannot_enter_teacher_portal(): void {
        $this->teacherUser->forceFill(['deleted_at'=>now()])->save();
        $this->actingAs($this->teacherUser);
        $this->get('/teacher')->assertForbidden();
        $this->getJson('/teacher-api/workspace')->assertForbidden();
        $this->postJson('/erp/teacher/attendance',['lesson_id'=>$this->ownLesson,'actual_minutes'=>60])->assertForbidden();
        // Same name as the teacher record, but no explicit link: identity is never inferred from a name.
        $unlinked=$this->createMember(['name'=>'Linked Synthetic Teacher']);
        $page=$this->actingAs($unlinked)->get('/teacher')->assertForbidden();
        $this->assertSame('refused',$this->embedded($page)['mode']);
        $this->assertPrivate($page->assertDontSee('Owned Synthetic Student'));
        $this->getJson('/teacher-api/workspace')->assertForbidden();
        $this->assertSame(0,DB::table('erp_attendance')->count());
    }

    private function resultPayload(array $overrides=[]): array {
        return array_merge(['enrollment_id'=>DB::table('erp_lessons')->where('id',$this->ownLesson)->value('enrollment_id'),
            'assessment'=>'Single-login test','assessed_on'=>now()->toDateString(),'score'=>'0','maximum'=>'20','notes'=>'Progress note',
            'submission_key'=>(string)\Illuminate\Support\Str::uuid()],$overrides);
    }
    public function test_teacher_login_lands_on_personal_dashboard_and_old_links_redirect(): void {
        $this->postJson('/login',['email'=>$this->teacherUser->email,'password'=>'Local-Testing-Only-2026'])->assertOk()->assertJson(['redirect'=>url('/teacher')]);
        $this->assertAuthenticatedAs($this->teacherUser);
        $this->get('/')->assertRedirect('/teacher');
        foreach(['/timetableReport'=>'timetable','/attendance'=>'attendance','/resultform'=>'results'] as $path=>$section)
            $this->get($path)->assertRedirect('/teacher/'.$section);
        $this->get('/timetable')->assertRedirect('/teacher/timetable');
        // The old admin report URLs were removed (old URLs 404 by product decision); reports live in the portal's reports section.
        foreach(['/attendanceReport','/markreport','/student-attendance-report'] as $path) $this->get($path)->assertNotFound();
    }
    public function test_all_dashboard_sections_are_private_and_do_not_request_teacher_credentials(): void {
        $this->actingAs($this->teacherUser);
        foreach(['overview','timetable','students','attendance','results','reports'] as $section) {
            $response=$this->get('/teacher/'.$section)->assertOk()->assertDontSee('name="password"',false)->assertDontSee('name="teacher_name"',false);
            $this->assertPrivate($response);
            $this->assertSame(['kind','mode','user','teacher','unitName'],array_keys($this->embedded($response)));
            $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        }
        $this->assertStringContainsString('no-store',$this->workspace()->headers->get('Cache-Control'));
    }
    public function test_teacher_can_record_zero_score_and_replay_without_duplicate_and_see_result_in_reports(): void {
        $this->actingAs($this->teacherUser);$payload=$this->resultPayload();
        $id=$this->postJson('/erp/teacher/results',$payload)->assertOk()->json('result.id');
        $this->postJson('/erp/teacher/results',$payload)->assertOk()->assertJsonPath('result.id',$id);
        $this->assertDatabaseCount('erp_marks',1);
        $this->assertDatabaseHas('erp_marks',['score'=>0,'maximum'=>20,'created_by'=>$this->teacherUser->id,'unit_id'=>$this->unit]);
        $result=$this->workspace()->json('results.data.0');
        $this->assertSame(['Single-login test','Progress note',0],[$result['assessment'],$result['notes'],$result['score']]);
        $this->postJson('/erp/teacher/results',array_merge($payload,['score'=>'1']))->assertStatus(409);
    }
    public function test_teacher_cannot_write_another_assignment_or_forge_identity(): void {
        $this->actingAs($this->teacherUser);
        $payload=$this->resultPayload(['enrollment_id'=>DB::table('erp_lessons')->where('id',$this->otherLesson)->value('enrollment_id'),'teacher_id'=>$this->teacher,'unit_id'=>$this->unit]);
        $this->postJson('/erp/teacher/results',$payload)->assertForbidden();$this->assertDatabaseCount('erp_marks',0);
    }
    public function test_invalid_scores_preserve_input_and_inactive_assignments_reject_results(): void {
        $this->actingAs($this->teacherUser);$payload=$this->resultPayload(['score'=>'21']);
        $this->postJson('/erp/teacher/results',$payload)->assertUnprocessable()->assertJsonValidationErrors('score');
        // A plain form post still gets Laravel's redirect with errors and the old input.
        $this->from('/teacher/results')->post('/erp/teacher/results',$payload)->assertRedirect('/teacher/results')->assertSessionHasErrors('score')->assertSessionHasInput('assessment','Single-login test');
        DB::table('erp_enrollments')->where('id',$payload['enrollment_id'])->update(['status'=>'ended']);
        $this->postJson('/erp/teacher/results',$this->resultPayload())->assertStatus(422);$this->assertDatabaseCount('erp_marks',0);
    }
    public function test_account_unlink_revokes_results_and_dashboard_immediately(): void {
        $this->actingAs($this->teacherUser);$this->workspace();
        DB::table('erp_teachers')->where('id',$this->teacher)->update(['user_id'=>null]);
        $this->get('/teacher')->assertForbidden();$this->getJson('/teacher-api/workspace')->assertForbidden();
        $this->postJson('/erp/teacher/results',$this->resultPayload())->assertForbidden();
        $this->assertDatabaseCount('erp_marks',0);
    }
    public function test_report_date_filters_and_pagination_keep_teacher_scope(): void {
        $this->actingAs($this->teacherUser);
        for($i=0;$i<26;$i++) $this->postJson('/erp/teacher/results',$this->resultPayload(['assessment'=>'Assessment '.$i]))->assertOk();
        $first=array_column($this->workspace()->json('results.data'),'assessment');
        $this->assertContains('Assessment 25',$first);$this->assertNotContains('Assessment 0',$first);
        $this->assertSame(['Assessment 0'],array_column($this->workspace('?results_page=2')->json('results.data'),'assessment'));
        $empty=$this->workspace('?from=2020-01-01&through=2020-01-31')->assertDontSee('Single-login test')->json('results');
        $this->assertSame([],$empty['data']);$this->assertSame(0,$empty['meta']['total']);
    }

    public function test_filters_use_teacher_timezone_and_reject_other_students(): void {
        $this->actingAs($this->teacherUser);
        DB::table('erp_lessons')->where('id',$this->ownLesson)->update(['starts_at'=>'2026-10-24 20:00:00','ends_at'=>'2026-10-24 21:00:00']);
        $this->workspace('?from=2026-10-25&through=2026-10-25')->assertJsonPath('lessons.0.studentName','Owned Synthetic Student');
        $this->assertSame([],$this->workspace('?from=2026-10-24&through=2026-10-24')->json('lessons'));
        $otherStudent=DB::table('erp_lessons')->where('id',$this->otherLesson)->value('student_id');
        $this->getJson('/teacher-api/workspace?student='.$otherStudent)->assertForbidden();
        $ownStudent=DB::table('erp_lessons')->where('id',$this->ownLesson)->value('student_id');
        $this->workspace('?student='.$ownStudent)->assertJsonPath('range.student',$ownStudent);
    }

    public function test_assessment_today_uses_the_teacher_local_date(): void {
        \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-10-25 20:30:00','UTC'));
        try {
            $this->actingAs($this->teacherUser);
            $this->postJson('/erp/teacher/results',$this->resultPayload(['assessed_on'=>'2026-10-26']))->assertOk();
            $this->postJson('/erp/teacher/results',$this->resultPayload(['assessed_on'=>'2026-10-27']))->assertUnprocessable()->assertJsonValidationErrors('assessed_on');
        } finally { \Carbon\CarbonImmutable::setTestNow(); }
    }
}
