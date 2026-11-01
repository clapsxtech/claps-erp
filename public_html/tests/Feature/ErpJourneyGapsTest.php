<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Gaps found by the customer and staff journey simulation (docs/JOURNEY_SIMULATION.md). */
class ErpJourneyGapsTest extends TestCase
{
    private $admin;
    private $unit;
    private $student;
    private $teacher;
    private $subject;
    private $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.finance_policy'=>'admin_import_delivered_only','erp.payroll_policy'=>'approved_attended_only']);
        $this->admin=$this->createAdmin();
        $this->unit=DB::table('erp_units')->insertGetId(['code'=>'GAPS','name'=>'Gaps team','timezone'=>'UTC']);
        $this->student=DB::table('erp_students')->insertGetId(['unit_id'=>$this->unit,'code'=>'GAP-S','name'=>'Gap Student','registered_on'=>'2026-01-01','timezone'=>'UTC','status'=>'active','grade'=>'8','syllabus'=>'CBSE']);
        $this->teacher=DB::table('erp_teachers')->insertGetId(['code'=>'GAP-T','name'=>'Gap Teacher','timezone'=>'UTC','status'=>'active']);
        DB::table('erp_teacher_units')->insert(['unit_id'=>$this->unit,'teacher_id'=>$this->teacher]);
        $this->subject=DB::table('erp_subjects')->insertGetId(['name'=>'Gap Maths']);
        $this->enrollment=DB::table('erp_enrollments')->insertGetId(['unit_id'=>$this->unit,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$this->subject,'starts_on'=>'2026-01-01','status'=>'active','student_rate_paise'=>10000,'teacher_rate_paise'=>6000,'currency'=>'INR']);
    }

    private function member(string $role)
    {
        $user=$this->createMember();
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$user->id,'role'=>$role]);
        return $user;
    }

    private function lesson(string $startsAt, string $status='scheduled'): int
    {
        return DB::table('erp_lessons')->insertGetId(['unit_id'=>$this->unit,'enrollment_id'=>$this->enrollment,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$this->subject,'starts_at'=>$startsAt,'ends_at'=>date('Y-m-d H:i:s',strtotime($startsAt.' UTC')+3600),'status'=>$status,'kind'=>'regular','student_rate_paise'=>10000,'teacher_rate_paise'=>6000,'currency'=>'INR']);
    }

    private function day(int $offset): string { return now('UTC')->addDays($offset)->toDateString(); }

    public function test_pause_cancels_only_the_break_and_stop_ends_teaching(): void
    {
        $before=$this->lesson($this->day(2).' 10:00:00'); $inBreak=$this->lesson($this->day(5).' 10:00:00'); $alsoInBreak=$this->lesson($this->day(6).' 10:00:00'); $after=$this->lesson($this->day(12).' 10:00:00');
        $this->actingAs($this->member('academics'));
        $this->post(route('erp.mutate',[$this->unit,'pause-classes']),['student_id'=>$this->student,'from'=>$this->day(4),'through'=>$this->day(8),'reason'=>'Exams','return_page'=>'timetable'])
            ->assertRedirect(route('erp.business',[$this->unit,'timetable']))->assertSessionHas('status',fn($s)=>str_contains($s,'2 lesson(s) cancelled'));
        $this->assertSame(['scheduled','cancelled','cancelled','scheduled'],DB::table('erp_lessons')->whereIn('id',[$before,$inBreak,$alsoInBreak,$after])->orderBy('starts_at')->pluck('status')->all());
        $this->assertSame('active',DB::table('erp_students')->find($this->student)->status);

        $this->post(route('erp.mutate',[$this->unit,'stop-classes']),['student_id'=>$this->student,'last_day'=>$this->day(3),'reason'=>'Family moving'])->assertRedirect();
        $this->assertSame('cancelled',DB::table('erp_lessons')->find($after)->status);
        $this->assertSame('scheduled',DB::table('erp_lessons')->find($before)->status,'Lessons up to the last day stay booked.');
        $this->assertSame($this->day(3),DB::table('erp_enrollments')->find($this->enrollment)->ends_on);
        $this->assertSame('active',DB::table('erp_students')->find($this->student)->status,'Still active until the last class.');

        $this->actingAs($this->member('finance'))->post(route('erp.mutate',[$this->unit,'pause-classes']),['student_id'=>$this->student,'from'=>$this->day(1),'through'=>$this->day(2),'reason'=>'x'])->assertForbidden();
    }

    public function test_stop_today_marks_the_student_stopped(): void
    {
        $this->lesson($this->day(3).' 10:00:00');
        $this->actingAs($this->member('academics'))->post(route('erp.mutate',[$this->unit,'stop-classes']),['student_id'=>$this->student,'last_day'=>$this->day(0),'reason'=>'Left'])->assertRedirect();
        $this->assertSame('stopped',DB::table('erp_students')->find($this->student)->status);
    }

    public function test_no_show_from_teacher_and_staff_charges_and_pays_nothing(): void
    {
        $user=$this->createMember();
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$user->id,'role'=>'teacher']);
        DB::table('erp_teachers')->where('id',$this->teacher)->update(['user_id'=>$user->id]);
        $ended=$this->lesson(now('UTC')->subDays(1)->format('Y-m-d H:i:s')); $future=$this->lesson($this->day(2).' 10:00:00'); $staff=$this->lesson(now('UTC')->subDays(2)->format('Y-m-d H:i:s'));
        $this->actingAs($this->admin)->get(route('erp.business',[$this->unit,'attendance']))->assertOk()->assertSee('Ended classes with no attendance')->assertSee('Lesson #'.$ended);

        $this->actingAs($user)->get(route('erp.teacher.index',['section'=>'attendance']))->assertOk()->assertSee('Record no-show');
        $this->post(route('erp.teacher.submit'),['no_show'=>1,'lesson_id'=>$ended,'reason'=>'Waited 15 minutes'])->assertRedirect();
        $this->assertSame('no_show',DB::table('erp_lessons')->find($ended)->status);
        $this->post(route('erp.teacher.submit'),['no_show'=>1,'lesson_id'=>$future,'reason'=>'Too early'])->assertSessionHasErrors('lesson_id');
        $this->assertSame('scheduled',DB::table('erp_lessons')->find($future)->status);

        $this->actingAs($this->member('academics'))->post(route('erp.mutate',[$this->unit,'no-show']),['lesson_id'=>$staff,'reason'=>'Parent confirmed absence'])->assertRedirect();
        $this->assertSame('no_show',DB::table('erp_lessons')->find($staff)->status);
        $this->assertSame(0,DB::table('erp_attendance')->whereIn('lesson_id',[$ended,$staff])->count());
        $this->assertSame(0,DB::table('erp_ledger')->where('unit_id',$this->unit)->count());
        $this->actingAs($this->admin)->get(route('erp.business',[$this->unit,'attendance']))->assertOk()->assertDontSee('Lesson #'.$ended)->assertSee('Every ended class has attendance or a no-show.');
    }

    public function test_refund_is_limited_to_credit_and_can_be_reversed(): void
    {
        $finance=$this->member('finance');
        $this->actingAs($finance)->post(route('erp.mutate',[$this->unit,'payment']),['student_id'=>$this->student,'amount_inr'=>'1000','reference'=>'GAP-PAY'])->assertRedirect();
        $refund=['student_id'=>$this->student,'amount_inr'=>'1200','reference'=>'GAP-REFUND','paid_on'=>$this->day(0),'reason'=>'Family stopped','already_paid'=>'1','return_page'=>'money'];
        $this->post(route('erp.business.mutate',[$this->unit,'refund-paid']),$refund)->assertSessionHasErrors('amount_paise');
        $this->post(route('erp.business.mutate',[$this->unit,'refund-paid']),['amount_inr'=>'400']+$refund)->assertRedirect();
        $entry=DB::table('erp_ledger')->where('reference','GAP-REFUND')->first();
        $this->assertSame(['refund',-40000],[$entry->kind,(int)$entry->amount_paise]);
        $this->assertSame(60000,(int)DB::table('erp_ledger')->where('student_id',$this->student)->sum('amount_paise'));
        $this->post(route('erp.business.mutate',[$this->unit,'refund-reverse']),['entry_id'=>$entry->id,'reason'=>'Recorded twice'])->assertRedirect();
        $this->assertSame(100000,(int)DB::table('erp_ledger')->where('student_id',$this->student)->sum('amount_paise'));
        $this->get(route('erp.business',[$this->unit,'money']))->assertOk()->assertSee('Record refund already paid to the family');
        $this->actingAs($this->member('academics'))->post(route('erp.business.mutate',[$this->unit,'refund-paid']),['amount_inr'=>'1','reference'=>'GAP-AC']+$refund)->assertForbidden();
    }

    public function test_academics_pick_up_only_enquiries_waiting_for_allocation(): void
    {
        $sales=$this->member('sales_followups'); $academics=$this->member('academics');
        $lead=fn($name)=>DB::table('erp_leads')->insertGetId(['unit_id'=>$this->unit,'name'=>$name,'phone'=>'98'.random_int(10000000,99999999),'enquiry_date'=>'2026-10-01','status'=>'LEAD','grade'=>'8','syllabus'=>'CBSE','created_by'=>$sales->id,'assigned_to'=>$sales->id,'created_at'=>now(),'updated_at'=>now()]);
        $ready=$lead('Ready Learner'); $other=$lead('Private Learner');
        DB::table('erp_demos')->insert(['unit_id'=>$this->unit,'lead_id'=>$ready,'teacher_id'=>$this->teacher,'subject_id'=>$this->subject,'scheduled_on'=>'2026-10-20','scheduled_time'=>'10:00','status'=>'ATTENDED','feedback_outcome'=>'CONTINUE','created_by'=>$sales->id,'starts_at'=>'2026-10-20 10:00:00','ends_at'=>'2026-10-20 11:00:00','timezone'=>'UTC','duration_minutes'=>60]);
        $this->actingAs($academics)->get(route('erp.business',[$this->unit,'allocation']))->assertOk()->assertSee('Ready to allocate')->assertSee('Ready Learner')->assertDontSee('Private Learner');
        $this->get(route('erp.business',[$this->unit,'allocation','lead_id'=>$ready]))->assertOk()->assertSee('Allocate weekly class with Gap Teacher');
        $this->get(route('erp.business',[$this->unit,'allocation','lead_id'=>$other]))->assertNotFound();
        $this->actingAs($sales)->get(route('erp.business',[$this->unit,'allocation']))->assertOk()->assertDontSee('Ready to allocate');
    }

    public function test_academics_can_add_a_weekly_slot_from_the_timetable(): void
    {
        $this->actingAs($this->member('academics'))->get(route('erp.business',[$this->unit,'timetable']))->assertOk()
            ->assertSee('href="#action-propose">Add a weekly slot',false)->assertSee('id="action-propose"',false)->assertSee('id="action-confirm"',false)->assertSee('id="action-availability"',false);
        $this->actingAs($this->member('read_only'))->get(route('erp.workspace',$this->unit))->assertOk()->assertDontSee('id="action-propose"',false);
    }
}
