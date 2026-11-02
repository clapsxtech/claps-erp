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

    public function test_family_signs_in_and_sees_only_their_child(): void
    {
        $this->get(route('parent.home'))->assertRedirect(route('parent.login'));
        $this->signIn(' pp-1001 ')->assertRedirect(route('parent.home'));
        $this->get(route('parent.home'))->assertOk()->assertSee('Anya Joseph')->assertSee('INR 3,600.00 paid in advance')->assertSee('+91 98470 10000');
        $this->get(route('parent.home',['section'=>'classes']))->assertOk()->assertSee('60 minutes taught')->assertDontSee('Private staff observation');
        $this->get(route('parent.home',['section'=>'results']))->assertOk()->assertSee('Algebra quiz')->assertSee('Good work');
        $fees=$this->get(route('parent.home',['section'=>'fees']))->assertOk()->assertSee('PP-UPI-1')->assertDontSee('PP-OTHER-PAY');
        foreach ([$fees,$this->get(route('parent.home',['section'=>'classes']))] as $page) $page->assertDontSee('300.00')->assertDontSee('9811122233');
        $mine=DB::table('erp_ledger')->where('reference','PP-UPI-1')->value('id'); $theirs=DB::table('erp_ledger')->where('reference','PP-OTHER-PAY')->value('id');
        $this->get(route('parent.document',['type'=>'receipt','id'=>$mine]))->assertOk()->assertSee('Payment acknowledgement');
        $this->get(route('parent.document',['type'=>'receipt','id'=>$theirs]))->assertNotFound();
        $statement=$this->get(route('parent.statement',['month'=>now('Asia/Kolkata')->format('Y-m')]))->assertOk()->assertSee('Account summary')->assertSee('Back to the parent portal');
        $statement->assertDontSee('Record statement sharing')->assertDontSee('monthly-review');
        $this->post(route('parent.logout'))->assertRedirect(route('parent.login'));
        $this->get(route('parent.home'))->assertRedirect(route('parent.login'));
    }

    public function test_wrong_details_lock_the_student_id(): void
    {
        $this->signIn('PP-1001','2013-04-22')->assertSessionHasErrors(['student_code'=>'The student ID and date of birth do not match our records.']);
        $this->signIn('PP-9999','2013-04-21')->assertSessionHasErrors(['student_code'=>'The student ID and date of birth do not match our records.']);
        for ($i=0; $i<4; $i++) $this->signIn('PP-1001','2000-01-01');
        $this->signIn()->assertSessionHasErrors(['student_code'=>'Too many attempts. Please wait 15 minutes, or contact the centre.']);
        $this->signIn('PP-1002','2012-01-01')->assertRedirect(route('parent.home'));
        $this->assertSame(1,DB::table('erp_audit')->where('action','parent.signed_in')->count());
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
        $this->actingAs($this->admin)->post(route('erp.business.mutate',[$this->unit,'student-update']),['student_id'=>$this->student,'date_of_birth'=>'2013-05-21'])->assertRedirect();
        $this->assertSame(['date_of_birth'],json_decode(DB::table('erp_audit')->where('action','student.profile_updated')->value('payload'),true)['fields']);
        \Illuminate\Support\Facades\Auth::logout();
        $this->get(route('parent.home'))->assertRedirect(route('parent.login'));
        $this->signIn('PP-1001','2013-05-21')->assertRedirect(route('parent.home'));
        $this->travel(31)->minutes();
        $this->get(route('parent.home'))->assertRedirect(route('parent.login'));
    }

    public function test_a_dispute_holds_the_class_until_staff_answer(): void
    {
        config(['erp.parent_review_required'=>true]);
        $attendance=DB::table('erp_attendance')->value('id');
        $this->signIn();
        $this->post(route('parent.dispute'),['attendance_id'=>$attendance,'note'=>'Too short'])->assertSessionHasErrors('note');
        $this->post(route('parent.dispute'),['attendance_id'=>$attendance,'note'=>'The class was only 40 minutes long'])->assertRedirect(route('parent.home',['section'=>'classes']));
        $this->assertSame('disputed',DB::table('erp_attendance')->find($attendance)->parent_review_status);
        $this->get(route('parent.home',['section'=>'classes']))->assertSee('Being checked by the centre')->assertSee('Query sent');
        $this->post(route('parent.dispute'),['attendance_id'=>$attendance,'note'=>'Sending the same thing again'])->assertSessionHasErrors('attendance_id');
        $this->post(route('parent.dispute'),['attendance_id'=>$attendance+999,'note'=>'Not my class at all, surely'])->assertSessionHasErrors('attendance_id');

        $this->actingAs($this->admin)->post(route('erp.mutate',[$this->unit,'approve']),['attendance_id'=>$attendance])->assertSessionHasErrors('parent_review');
        $this->assertSame('submitted',DB::table('erp_attendance')->find($attendance)->status);
        $request=DB::table('erp_parent_requests')->value('id');
        $this->get(route('erp.tuition',$this->unit))->assertOk()->assertSee('The class was only 40 minutes long');
        $this->post(route('erp.tuition.mutate',[$this->unit,'parent-request-resolve']),['request_id'=>$request,'reply'=>'Checked with the teacher; corrected to 40 minutes'])->assertRedirect(route('erp.tuition',$this->unit));
        $this->assertSame('resolved',DB::table('erp_parent_requests')->find($request)->status);
    }

    public function test_staff_record_date_of_birth_and_billing_state(): void
    {
        $this->actingAs($this->admin)->post(route('erp.mutate',[$this->unit,'student']),['code'=>'PP-1003','name'=>'New Child','registered_on'=>'2026-01-01','timezone'=>'Asia/Kolkata','grade'=>'5','syllabus'=>'State','date_of_birth'=>'2016-02-29','billing_state'=>'Tamil Nadu'])->assertRedirect();
        $id=DB::table('erp_students')->where('code','PP-1003')->value('id');
        $this->assertSame(['2016-02-29','Tamil Nadu'],array_values((array)DB::table('erp_student_profiles')->where('student_id',$id)->first(['date_of_birth','billing_state'])));
        $this->post(route('erp.mutate',[$this->unit,'student']),['code'=>'PP-1004','name'=>'Future Child','registered_on'=>'2026-01-01','timezone'=>'Asia/Kolkata','grade'=>'5','syllabus'=>'State','date_of_birth'=>now()->addDay()->toDateString()])->assertSessionHasErrors('date_of_birth');
        $this->get(route('erp.business',[$this->unit,'people']))->assertSee('Date of birth (for the parent portal)');
    }
}
