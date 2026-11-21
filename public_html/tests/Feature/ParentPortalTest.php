<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    private $admin;
    private $unit;
    private $student;
    private $other;
    private $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin=$this->createAdmin();
        $this->unit=DB::table('erp_units')->insertGetId(['code'=>'PP','name'=>'Portal team','timezone'=>'Asia/Kolkata','phone'=>'+91 98470 10000']);
        $teacher=DB::table('erp_teachers')->insertGetId(['code'=>'PP-T','name'=>'Portal Teacher','timezone'=>'Asia/Kolkata','status'=>'active']);
        DB::table('erp_teacher_units')->insert(['unit_id'=>$this->unit,'teacher_id'=>$teacher]);
        $subject=DB::table('erp_subjects')->insertGetId(['name'=>'Portal Maths']);
        $this->student=DB::table('erp_students')->insertGetId(['unit_id'=>$this->unit,'code'=>'PP-1001','name'=>'Anya Joseph','registered_on'=>'2026-01-01','timezone'=>'Asia/Kolkata','status'=>'active','grade'=>'8','syllabus'=>'CBSE','guardian_contact'=>'9811122233']);
        $this->other=DB::table('erp_students')->insertGetId(['unit_id'=>$this->unit,'code'=>'PP-1002','name'=>'Other Child','registered_on'=>'2026-01-01','timezone'=>'Asia/Kolkata','status'=>'active','grade'=>'8','syllabus'=>'CBSE']);
        DB::table('erp_student_profiles')->insert([['unit_id'=>$this->unit,'student_id'=>$this->student,'date_of_birth'=>'2013-04-21'],['unit_id'=>$this->unit,'student_id'=>$this->other,'date_of_birth'=>'2012-01-01']]);
        $this->enrollment=DB::table('erp_enrollments')->insertGetId(['unit_id'=>$this->unit,'student_id'=>$this->student,'teacher_id'=>$teacher,'subject_id'=>$subject,'starts_on'=>'2026-01-01','status'=>'active','student_rate_paise'=>45000,'teacher_rate_paise'=>30000,'currency'=>'INR']);
        $lesson=fn($at,$status)=>DB::table('erp_lessons')->insertGetId(['unit_id'=>$this->unit,'enrollment_id'=>$this->enrollment,'student_id'=>$this->student,'teacher_id'=>$teacher,'subject_id'=>$subject,'starts_at'=>$at,'ends_at'=>date('Y-m-d H:i:s',strtotime($at.' UTC')+3600),'status'=>$status,'kind'=>'regular','student_rate_paise'=>45000,'teacher_rate_paise'=>30000,'currency'=>'INR']);
        $past=$lesson(now('UTC')->subDays(3)->format('Y-m-d 10:00:00'),'completed'); $lesson(now('UTC')->addDays(4)->format('Y-m-d 10:00:00'),'scheduled');
        DB::table('erp_attendance')->insert(['unit_id'=>$this->unit,'lesson_id'=>$past,'actual_minutes'=>60,'status'=>'submitted','submitted_by'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('erp_internal_notes')->insert(['unit_id'=>$this->unit,'lesson_id'=>$past,'created_by'=>$this->admin->id,'kind'=>'internal','note'=>'Private staff observation','created_at'=>now(),'updated_at'=>now()]);
        DB::table('erp_ledger')->insert([['unit_id'=>$this->unit,'student_id'=>$this->student,'kind'=>'payment','amount_paise'=>360000,'currency'=>'INR','reference'=>'PP-UPI-1','event_key'=>'pp-1','occurred_at'=>now(),'created_by'=>$this->admin->id],
            ['unit_id'=>$this->unit,'student_id'=>$this->other,'kind'=>'payment','amount_paise'=>99900,'currency'=>'INR','reference'=>'PP-OTHER-PAY','event_key'=>'pp-2','occurred_at'=>now(),'created_by'=>$this->admin->id]]);
        DB::table('erp_marks')->insert(['unit_id'=>$this->unit,'enrollment_id'=>$this->enrollment,'assessment'=>'Algebra quiz','assessed_on'=>'2026-10-20','score'=>17,'maximum'=>20,'notes'=>'Good work','created_by'=>$this->admin->id]);
    }

    private function signIn(string $code='PP-1001', string $dob='2013-04-21')
    {
        return $this->post(route('parent.login.submit'),['student_code'=>$code,'date_of_birth'=>$dob]);
    }

    /** The portal pages are React host pages; their data comes from /parent-api. */
    private function section(string $section)
    {
        return $this->getJson(route('parent.api.section',['section'=>$section]));
    }

    private function body($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function hostSession($response): array
    {
        $html=$response->getContent(); $marker='<script type="application/json" id="erp-session">';
        $start=strpos($html,$marker); $this->assertNotFalse($start); $start+=strlen($marker);
        return json_decode(html_entity_decode(substr($html,$start,strpos($html,'</script>',$start)-$start)),true);
    }

    public function test_family_signs_in_and_sees_only_their_child(): void
    {
        $this->get(route('parent.home'))->assertRedirect(route('parent.login'));
        $this->section('overview')->assertStatus(401);
        $this->signIn(' pp-1001 ')->assertRedirect(route('parent.home'));
        $home=$this->hostSession($this->get(route('parent.home'))->assertOk());
        $this->assertSame('Anya Joseph',$home['student']['name']);
        $this->assertSame('+91 98470 10000',$home['centre']['phone']);
        // A positive balance is shown as "Paid in advance".
        $this->section('overview')->assertOk()->assertJsonPath('balancePaise',360000);
        $classes=$this->section('classes')->assertOk()->assertJsonPath('past.0.minutes',60)->assertJsonPath('past.0.status','completed');
        $this->assertStringNotContainsString('Private staff observation',$this->body($classes));
        $this->section('results')->assertOk()->assertJsonPath('data.0.assessment','Algebra quiz')->assertJsonPath('data.0.note','Good work');
        $fees=$this->section('fees')->assertOk()->assertJsonPath('receipts.0.reference','PP-UPI-1')->assertJsonCount(1,'receipts');
        $this->assertStringNotContainsString('PP-OTHER-PAY',$this->body($fees));
        // Neither the teacher's rate (INR 300.00), the class rate nor the guardian's phone reaches the family.
        foreach ([$fees,$classes] as $page) foreach (['30000','45000','300.00','9811122233','rate'] as $hidden) $this->assertStringNotContainsString($hidden,$this->body($page));
        $mine=DB::table('erp_ledger')->where('reference','PP-UPI-1')->value('id'); $theirs=DB::table('erp_ledger')->where('reference','PP-OTHER-PAY')->value('id');
        $this->getJson(route('parent.api.document',['type'=>'receipt','id'=>$mine]))->assertOk()->assertJsonPath('receipt.reference','PP-UPI-1')->assertJsonPath('receipt.isRefund',false);
        $this->get(route('parent.document',['type'=>'receipt','id'=>$mine]))->assertOk();
        $this->getJson(route('parent.api.document',['type'=>'receipt','id'=>$theirs]))->assertNotFound();
        $this->get(route('parent.document',['type'=>'receipt','id'=>$theirs]))->assertNotFound();
        $statement=$this->getJson(route('parent.api.statement',['month'=>now('Asia/Kolkata')->format('Y-m')]))->assertOk()->assertJsonPath('account.closingPaise',360000);
        // The family copy has no staff "statement sharing" record form.
        $this->assertSame(['month','monthLabel','rows','totalMinutes','account'],array_keys($statement->json()));
        $this->assertStringNotContainsString('monthly-review',$this->body($statement));
        $this->post(route('parent.logout'))->assertRedirect(route('parent.login'));
        $this->get(route('parent.home'))->assertRedirect(route('parent.login'));
        $this->section('overview')->assertStatus(401);
    }

    public function test_wrong_details_lock_the_student_id(): void
    {
        $this->signIn('PP-1001','2013-04-22')->assertSessionHasErrors(['student_code'=>'The student ID and date of birth do not match our records.']);
        $this->signIn('PP-9999','2013-04-21')->assertSessionHasErrors(['student_code'=>'The student ID and date of birth do not match our records.']);
        for ($i=0; $i<4; $i++) $this->signIn('PP-1001','2000-01-01');
        $this->signIn()->assertSessionHasErrors(['student_code'=>'Too many attempts. Please wait 15 minutes, or contact the centre.']);
        $this->signIn('PP-1002','2012-01-01')->assertRedirect(route('parent.home'));
        // Slow guessing: after the 15-minute window passes, after 10 wrong guesses in a day the ID is closed until tomorrow, even with the right date.
        \Illuminate\Support\Facades\RateLimiter::clear('parent-login:'.hash('sha256','PP-1001'));
        for ($i=0; $i<5; $i++) $this->signIn('PP-1001','2000-02-0'.($i+1));
        \Illuminate\Support\Facades\RateLimiter::clear('parent-login:'.hash('sha256','PP-1001'));
        $this->signIn()->assertSessionHasErrors(['student_code'=>'Too many attempts for this student ID today. Please try again tomorrow, or contact the centre.']);
        $this->assertSame(1,DB::table('erp_audit')->where('action','parent.signed_in')->count());
    }

    public function test_one_source_cannot_spray_ids_and_an_attack_does_not_lock_out_everyone(): void
    {
        $ids=fn($n)=>'PP-'.(5000+$n);
        config(['proxy.trusted_proxies'=>'192.0.2.10']);
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.7']);
        for ($i=0; $i<20; $i++) $this->signIn($ids($i),'2013-04-21');
        $this->signIn()->assertSessionHasErrors(['student_code'=>'Too many attempts. Please wait 15 minutes, or contact the centre.']);

        // A distributed attack trips the site breaker: IDs already guessed at are frozen, untouched ones still work.
        \Illuminate\Support\Facades\RateLimiter::clear('parent-login-ip:'.hash('sha256','203.0.113.7'));
        for ($i=0; $i<200; $i++) \Illuminate\Support\Facades\RateLimiter::hit('parent-login:site',60);
        $this->withServerVariables(['REMOTE_ADDR'=>'198.51.100.9']);
        $this->signIn('PP-1002','2000-01-01')->assertSessionHasErrors('student_code');
        $this->signIn('PP-1002','2012-01-01')->assertSessionHasErrors(['student_code'=>'Too many attempts. Please wait 15 minutes, or contact the centre.']);
        $this->signIn()->assertRedirect(route('parent.home'));
    }

    public function test_a_shared_internal_address_is_not_rate_limited_as_one_client(): void
    {
        $this->assertNull(\App\Modules\Erp\Services\ParentPortal::clientIp('10.0.0.5'));
        $this->assertNull(\App\Modules\Erp\Services\ParentPortal::clientIp('127.0.0.1'));
        $this->assertNull(\App\Modules\Erp\Services\ParentPortal::clientIp('203.0.113.7'),'No trusted proxy configured: the address is not the family\'s.');
        config(['proxy.trusted_proxies'=>'192.0.2.10']);
        $this->assertNull(\App\Modules\Erp\Services\ParentPortal::clientIp('100.64.0.1'));
        $this->assertSame('203.0.113.7',\App\Modules\Erp\Services\ParentPortal::clientIp('203.0.113.7'));
        config(['proxy.trusted_proxies'=>'']);
        $this->withServerVariables(['REMOTE_ADDR'=>'10.0.0.5']);
        for ($i=0; $i<25; $i++) $this->signIn('PP-'.(6000+$i),'2013-04-21');
        $this->signIn()->assertRedirect(route('parent.home'));
    }

    public function test_the_worker_supplied_visitor_address_is_used_only_behind_the_worker(): void
    {
        \Illuminate\Support\Facades\Route::get('/__ip-probe',fn(\Illuminate\Http\Request $r)=>response($r->ip().'|'.var_export($r->headers->has('x-erp-client-ip'),true)));
        $this->withServerVariables(['REMOTE_ADDR'=>'10.0.0.5']);
        $this->get('/__ip-probe',['X-ERP-Client-IP'=>'198.51.100.23'])->assertSee('10.0.0.5|false',false);
        config(['proxy.client_ip_from_worker'=>true]);
        $this->get('/__ip-probe',['X-ERP-Client-IP'=>'198.51.100.23'])->assertSee('198.51.100.23|false',false);
        $this->get('/__ip-probe',['X-ERP-Client-IP'=>'not-an-ip'])->assertSee('10.0.0.5|false',false);
        $this->assertSame('198.51.100.23',\App\Modules\Erp\Services\ParentPortal::clientIp('198.51.100.23'));
    }

    public function test_children_without_a_date_of_birth_or_in_archived_teams_cannot_sign_in(): void
    {
        DB::table('erp_student_profiles')->where('student_id',$this->student)->update(['date_of_birth'=>null]);
        $this->signIn()->assertSessionHasErrors('student_code');
        DB::table('erp_student_profiles')->where('student_id',$this->student)->update(['date_of_birth'=>'2013-04-21']);
        DB::table('erp_units')->where('id',$this->unit)->update(['archived_at'=>now()]);
        $this->signIn()->assertSessionHasErrors('student_code');
    }

    public function test_changing_the_date_of_birth_or_idling_signs_the_family_out(): void
    {
        $this->signIn()->assertRedirect(route('parent.home'));
        $this->section('overview')->assertOk();
        $this->actingAs($this->admin)->postJson(route('erp.business.mutate',[$this->unit,'student-update']),['student_id'=>$this->student,'date_of_birth'=>'2013-05-21'])->assertOk();
        $this->assertSame(['date_of_birth'],json_decode(DB::table('erp_audit')->where('action','student.profile_updated')->value('payload'),true)['fields']);
        \Illuminate\Support\Facades\Auth::logout();
        $this->get(route('parent.home'))->assertRedirect(route('parent.login'));
        $this->section('overview')->assertStatus(401);
        $this->signIn('PP-1001','2013-05-21')->assertRedirect(route('parent.home'));
        $this->section('overview')->assertOk();
        $this->travel(31)->minutes();
        $this->section('overview')->assertStatus(401);
        $this->get(route('parent.home'))->assertRedirect(route('parent.login'));
    }

    public function test_a_dispute_holds_the_class_until_staff_answer(): void
    {
        config(['erp.parent_review_required'=>true]);
        $attendance=DB::table('erp_attendance')->value('id');
        $this->signIn();
        $this->section('classes')->assertOk()->assertJsonPath('past.0.beingChecked',false)->assertJsonPath('past.0.querySent',false);
        $this->postJson(route('parent.dispute'),['attendance_id'=>$attendance,'note'=>'Too short'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson(route('parent.dispute'),['attendance_id'=>$attendance,'note'=>'The class was only 40 minutes long'])->assertOk()->assertJsonPath('held',true)->assertJsonPath('message',fn($s)=>str_contains($s,'will not be billed until it is resolved'));
        $this->assertSame('disputed',DB::table('erp_attendance')->find($attendance)->parent_review_status);
        $this->section('classes')->assertOk()->assertJsonPath('past.0.attendanceId',$attendance)->assertJsonPath('past.0.beingChecked',true)->assertJsonPath('past.0.querySent',true);
        $this->postJson(route('parent.dispute'),['attendance_id'=>$attendance,'note'=>'Sending the same thing again'])->assertStatus(422)->assertJsonValidationErrors('attendance_id');
        $this->postJson(route('parent.dispute'),['attendance_id'=>$attendance+999,'note'=>'Not my class at all, surely'])->assertStatus(422)->assertJsonValidationErrors('attendance_id');

        $this->actingAs($this->admin)->postJson(route('erp.mutate',[$this->unit,'approve']),['attendance_id'=>$attendance])->assertStatus(422)->assertJsonValidationErrors('parent_review');
        $this->assertSame('submitted',DB::table('erp_attendance')->find($attendance)->status);
        $request=DB::table('erp_parent_requests')->value('id');
        $this->getJson('/app-api/units/'.$this->unit.'/tuition')->assertOk()->assertJsonPath('parentRequests.0.id',$request)->assertJsonPath('parentRequests.0.note','The class was only 40 minutes long')->assertJsonPath('parentRequests.0.attendanceId',$attendance);
        $this->postJson(route('erp.tuition.mutate',[$this->unit,'parent-request-resolve']),['request_id'=>$request,'reply'=>'Checked with the teacher; corrected to 40 minutes'])->assertOk();
        $this->getJson('/app-api/units/'.$this->unit.'/tuition')->assertOk()->assertJsonCount(0,'parentRequests');
        $this->assertSame('resolved',DB::table('erp_parent_requests')->find($request)->status);

        // An approved class is already charged, so the family is not promised it will be held.
        DB::table('erp_attendance')->where('id',$attendance)->update(['status'=>'approved','parent_review_status'=>'unshared']);
        \Illuminate\Support\Facades\Auth::logout(); $this->signIn();
        $this->postJson(route('parent.dispute'),['attendance_id'=>$attendance,'note'=>'This class was cancelled by the teacher'])->assertOk()->assertJsonPath('held',false)->assertJsonPath('message',fn($s)=>str_contains($s,'already been billed') && !str_contains($s,'will not be billed'));
        $this->assertSame('unshared',DB::table('erp_attendance')->find($attendance)->parent_review_status);
    }

    public function test_staff_record_date_of_birth_and_billing_state(): void
    {
        $this->actingAs($this->admin)->postJson(route('erp.mutate',[$this->unit,'student']),['code'=>'PP-1003','name'=>'New Child','registered_on'=>'2026-01-01','timezone'=>'Asia/Kolkata','grade'=>'5','syllabus'=>'State','date_of_birth'=>'2016-02-29','billing_state'=>'Tamil Nadu'])->assertOk();
        $id=DB::table('erp_students')->where('code','PP-1003')->value('id');
        $this->assertSame(['2016-02-29','Tamil Nadu'],array_values((array)DB::table('erp_student_profiles')->where('student_id',$id)->first(['date_of_birth','billing_state'])));
        $this->postJson(route('erp.mutate',[$this->unit,'student']),['code'=>'PP-1004','name'=>'Future Child','registered_on'=>'2026-01-01','timezone'=>'Asia/Kolkata','grade'=>'5','syllabus'=>'State','date_of_birth'=>now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
        $this->assertDatabaseMissing('erp_students',['code'=>'PP-1004']);
    }
}
