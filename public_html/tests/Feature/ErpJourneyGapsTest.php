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

    private function api(string $path, array $query = [])
    {
        return $this->getJson('/app-api/units/'.$this->unit.'/'.$path.($query ? '?'.http_build_query($query) : ''));
    }

    private function body($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function unitSession(): array
    {
        $html = $this->get('/app')->assertOk()->getContent(); $marker = '<script type="application/json" id="erp-session">';
        $start = strpos($html, $marker); $this->assertNotFalse($start); $start += strlen($marker);
        $session = json_decode(html_entity_decode(substr($html, $start, strpos($html, '</script>', $start) - $start)), true);
        return collect($session['units'])->firstWhere('id', $this->unit);
    }

    public function test_pause_cancels_only_the_break_and_stop_ends_teaching(): void
    {
        $before=$this->lesson($this->day(2).' 10:00:00'); $inBreak=$this->lesson($this->day(5).' 10:00:00'); $alsoInBreak=$this->lesson($this->day(6).' 10:00:00'); $after=$this->lesson($this->day(12).' 10:00:00');
        $this->actingAs($this->member('academics'));
        $this->postJson(route('erp.mutate',[$this->unit,'pause-classes']),['student_id'=>$this->student,'from'=>$this->day(4),'through'=>$this->day(8),'reason'=>'Exams'])
            ->assertOk()->assertJsonPath('message',fn($s)=>str_contains($s,'2 lesson(s) cancelled'));
        $this->assertSame(['scheduled','cancelled','cancelled','scheduled'],DB::table('erp_lessons')->whereIn('id',[$before,$inBreak,$alsoInBreak,$after])->orderBy('starts_at')->pluck('status')->all());
        $this->assertSame('active',DB::table('erp_students')->find($this->student)->status);

        $this->postJson(route('erp.mutate',[$this->unit,'stop-classes']),['student_id'=>$this->student,'last_day'=>$this->day(3),'reason'=>'Family moving'])->assertOk()->assertJsonPath('message',fn($s)=>str_contains($s,'The student stays active until their last class.'));
        $this->assertSame('cancelled',DB::table('erp_lessons')->find($after)->status);
        $this->assertSame('scheduled',DB::table('erp_lessons')->find($before)->status,'Lessons up to the last day stay booked.');
        $this->assertSame($this->day(3),DB::table('erp_enrollments')->find($this->enrollment)->ends_on);
        $this->assertSame('active',DB::table('erp_students')->find($this->student)->status,'Still active until the last class.');

        $this->actingAs($this->member('finance'))->postJson(route('erp.mutate',[$this->unit,'pause-classes']),['student_id'=>$this->student,'from'=>$this->day(1),'through'=>$this->day(2),'reason'=>'x'])->assertForbidden();
        $this->assertSame('scheduled',DB::table('erp_lessons')->find($before)->status);
    }

    public function test_stop_today_marks_the_student_stopped(): void
    {
        $this->lesson($this->day(3).' 10:00:00');
        $this->actingAs($this->member('academics'))->postJson(route('erp.mutate',[$this->unit,'stop-classes']),['student_id'=>$this->student,'last_day'=>$this->day(0),'reason'=>'Left'])->assertOk()->assertJsonPath('message',fn($s)=>str_contains($s,'Student marked stopped.'));
        $this->assertSame('stopped',DB::table('erp_students')->find($this->student)->status);
    }

    public function test_no_show_from_teacher_and_staff_charges_and_pays_nothing(): void
    {
        $user=$this->createMember();
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$user->id,'role'=>'teacher']);
        DB::table('erp_teachers')->where('id',$this->teacher)->update(['user_id'=>$user->id]);
        $ended=$this->lesson(now('UTC')->subDays(1)->format('Y-m-d H:i:s')); $future=$this->lesson($this->day(2).' 10:00:00'); $staff=$this->lesson(now('UTC')->subDays(2)->format('Y-m-d H:i:s'));
        $missing=$this->actingAs($this->admin)->api('attendance/queues/missing')->assertOk();
        $this->assertEqualsCanonicalizing([$ended,$staff],array_column($missing->json('data'),'lessonId'));

        $lessons=collect($this->actingAs($user)->getJson(route('teacher.api.workspace'))->assertOk()->json('lessons'))->keyBy('id');
        $this->assertTrue($lessons[$ended]['canMarkNoShow']);
        $this->assertFalse($lessons[$future]['canMarkNoShow']);
        $this->postJson(route('erp.teacher.submit'),['no_show'=>1,'lesson_id'=>$ended,'reason'=>'Waited 15 minutes'])->assertOk();
        $this->assertSame('no_show',DB::table('erp_lessons')->find($ended)->status);
        $this->postJson(route('erp.teacher.submit'),['no_show'=>1,'lesson_id'=>$future,'reason'=>'Too early'])->assertStatus(422)->assertJsonValidationErrors('lesson_id');
        $this->assertSame('scheduled',DB::table('erp_lessons')->find($future)->status);

        $this->actingAs($this->member('academics'))->postJson(route('erp.mutate',[$this->unit,'no-show']),['lesson_id'=>$staff,'reason'=>'Parent confirmed absence'])->assertOk()->assertJsonPath('message','Recorded as a no-show. The student is not charged and the class is not paid.');
        $this->assertSame('no_show',DB::table('erp_lessons')->find($staff)->status);
        $this->assertSame(0,DB::table('erp_attendance')->whereIn('lesson_id',[$ended,$staff])->count());
        $this->assertSame(0,DB::table('erp_ledger')->where('unit_id',$this->unit)->count());
        $this->actingAs($this->admin)->api('attendance/queues/missing')->assertOk()->assertJsonCount(0,'data')->assertJsonPath('meta.total',0);
        $this->api('attendance')->assertOk()->assertJsonPath('counts.missing',0);
    }

    public function test_refund_is_limited_to_credit_and_can_be_reversed(): void
    {
        $finance=$this->member('finance');
        $this->actingAs($finance)->postJson(route('erp.mutate',[$this->unit,'payment']),['student_id'=>$this->student,'amount_inr'=>'1000','reference'=>'GAP-PAY'])->assertOk();
        $refund=['student_id'=>$this->student,'amount_inr'=>'1200','reference'=>'GAP-REFUND','paid_on'=>$this->day(0),'reason'=>'Family stopped','already_paid'=>'1'];
        $this->postJson(route('erp.business.mutate',[$this->unit,'refund-paid']),$refund)->assertStatus(422)->assertJsonValidationErrors('amount_paise');
        $this->assertSame(0,DB::table('erp_ledger')->where('kind','refund')->count());
        $this->postJson(route('erp.business.mutate',[$this->unit,'refund-paid']),['amount_inr'=>'400']+$refund)->assertOk();
        $entry=DB::table('erp_ledger')->where('reference','GAP-REFUND')->first();
        $this->assertSame(['refund',-40000],[$entry->kind,(int)$entry->amount_paise]);
        $this->assertSame(60000,(int)DB::table('erp_ledger')->where('student_id',$this->student)->sum('amount_paise'));
        $this->postJson(route('erp.business.mutate',[$this->unit,'refund-reverse']),['entry_id'=>$entry->id,'reason'=>'Recorded twice'])->assertOk();
        $this->assertSame(100000,(int)DB::table('erp_ledger')->where('student_id',$this->student)->sum('amount_paise'));
        $this->assertContains('refund-paid',$this->unitSession()['actions']);
        DB::table('erp_units')->where('id',$this->unit)->update(['phone'=>'+91 98470 10000']);
        $this->api('print/receipt/'.$entry->id)->assertOk()->assertJsonPath('title','Refund acknowledgement')->assertJsonPath('amountLabel','Paid back to the family')
            ->assertJsonPath('amount','INR 400.00')->assertJsonPath('businessPhone','+91 98470 10000');
        $academics=$this->member('academics');
        $this->actingAs($academics)->postJson(route('erp.business.mutate',[$this->unit,'refund-paid']),['amount_inr'=>'1','reference'=>'GAP-AC']+$refund)->assertForbidden();
        $this->assertNotContains('refund-paid',$this->unitSession()['actions']);
        $this->assertSame(0,DB::table('erp_ledger')->where('reference','GAP-AC')->count());
    }

    public function test_academics_pick_up_only_enquiries_waiting_for_allocation(): void
    {
        $sales=$this->member('sales_followups'); $academics=$this->member('academics');
        $lead=fn($name)=>DB::table('erp_leads')->insertGetId(['unit_id'=>$this->unit,'name'=>$name,'phone'=>'98'.random_int(10000000,99999999),'enquiry_date'=>'2026-10-01','status'=>'LEAD','grade'=>'8','syllabus'=>'CBSE','created_by'=>$sales->id,'assigned_to'=>$sales->id,'created_at'=>now(),'updated_at'=>now()]);
        $ready=$lead('Ready Learner'); $other=$lead('Private Learner');
        DB::table('erp_demos')->insert(['unit_id'=>$this->unit,'lead_id'=>$ready,'teacher_id'=>$this->teacher,'subject_id'=>$this->subject,'scheduled_on'=>'2026-10-20','scheduled_time'=>'10:00','status'=>'ATTENDED','feedback_outcome'=>'CONTINUE','created_by'=>$sales->id,'starts_at'=>'2026-10-20 10:00:00','ends_at'=>'2026-10-20 11:00:00','timezone'=>'UTC','duration_minutes'=>60]);
        $matching=$this->actingAs($academics)->api('matching')->assertOk();
        $this->assertSame([$ready],array_column($matching->json('handoffs'),'leadId'));
        $this->assertSame('Ready Learner',$matching->json('handoffs.0.leadName'));
        $this->assertSame('Gap Teacher',$matching->json('handoffs.0.teacherName'));
        $this->assertStringNotContainsString('Private Learner',$this->body($matching));
        $this->api('matching',['lead_id'=>$ready])->assertOk()->assertJsonPath('demos.0.next','allocate')->assertJsonPath('demos.0.allocate.teacher_q','Gap Teacher');
        $this->api('matching',['lead_id'=>$other])->assertNotFound();
        $this->actingAs($sales)->api('matching')->assertOk()->assertJsonPath('handoffs',[]);
    }

    public function test_academics_can_add_a_weekly_slot_from_the_timetable(): void
    {
        // The timetable offers "Add a weekly slot" from the unit's capabilities.
        $this->actingAs($this->member('academics'))->api('timetable')->assertOk();
        $academics=$this->unitSession();
        $this->assertContains('timetable',$academics['pages']);
        foreach (['propose','confirm','availability'] as $action) $this->assertContains($action,$academics['actions']);
        $this->actingAs($this->member('read_only'))->api('timetable')->assertOk();
        $readOnly=$this->unitSession();
        foreach (['propose','confirm','availability'] as $action) $this->assertNotContains($action,$readOnly['actions']);
        $this->postJson(route('erp.mutate',[$this->unit,'propose']),[])->assertForbidden();
    }

    public function test_teacher_sees_learning_needs_but_not_parent_notes(): void
    {
        $user=$this->createMember();
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$user->id,'role'=>'teacher']);
        DB::table('erp_teachers')->where('id',$this->teacher)->update(['user_id'=>$user->id]);
        DB::table('erp_leads')->insert(['unit_id'=>$this->unit,'name'=>'Gap Student','phone'=>'9800000001','enquiry_date'=>'2026-01-01','status'=>'JOINED','student_id'=>$this->student,'created_by'=>$this->admin->id,
            'learning_requirement'=>'Needs algebra support','concern'=>'Board exam in March','about_student'=>'Shy, prefers examples','about_parent'=>'Father calls only after 8pm']);
        $workspace=$this->actingAs($user)->getJson(route('teacher.api.workspace'))->assertOk()
            ->assertJsonPath('assignments.0.learningRequirement','Needs algebra support')->assertJsonPath('assignments.0.concern','Board exam in March')->assertJsonPath('assignments.0.aboutStudent','Shy, prefers examples');
        $this->assertStringNotContainsString('Father calls only after 8pm',$this->body($workspace));
        $this->assertStringNotContainsString('9800000001',$this->body($workspace));
    }

    public function test_parent_statement_shows_payments_and_balance(): void
    {
        $at=fn($d)=>$d.' 12:00:00';
        DB::table('erp_ledger')->insert([
            ['unit_id'=>$this->unit,'student_id'=>$this->student,'kind'=>'payment','amount_paise'=>200000,'currency'=>'INR','reference'=>'GAP-SEPT','event_key'=>'gap-sept','occurred_at'=>$at('2026-09-20'),'created_by'=>$this->admin->id],
            ['unit_id'=>$this->unit,'student_id'=>$this->student,'kind'=>'lesson_charge','amount_paise'=>-45000,'currency'=>'INR','reference'=>'GAP-CHG','event_key'=>'gap-chg','occurred_at'=>$at('2026-10-10'),'created_by'=>$this->admin->id],
            ['unit_id'=>$this->unit,'student_id'=>$this->student,'kind'=>'payment','amount_paise'=>50000,'currency'=>'INR','reference'=>'GAP-OCT','event_key'=>'gap-oct','occurred_at'=>$at('2026-10-15'),'created_by'=>$this->admin->id],
        ]);
        $this->actingAs($this->admin)->api('print/statement',['student_id'=>$this->student,'month'=>'2026-10'])->assertOk()->assertJsonPath('month','2026-10')
            ->assertJsonPath('account',['opening'=>'INR 2,000.00 credit','lines'=>[['label'=>'Payments received','amount'=>'INR 500.00'],['label'=>'Class charges posted','amount'=>'INR -450.00']],'closing'=>'INR 2,050.00 credit']);
    }

    public function test_sales_see_student_balances_but_not_teacher_pay(): void
    {
        DB::table('erp_ledger')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'kind'=>'opening_balance','amount_paise'=>-123400,'currency'=>'INR','reference'=>'GAP-DUE','event_key'=>'gap-due','occurred_at'=>now(),'created_by'=>$this->admin->id]);
        $this->actingAs($this->member('sales_followups'))->api('followups',['view'=>'students'])->assertOk()->assertJsonPath('can.seeBalance',true)->assertJsonPath('data.0.balancePaise',-123400);
        $this->api('students')->assertForbidden();
        $students=$this->actingAs($this->member('academics'))->api('students')->assertOk()->assertJsonPath('can.seeBalance',false)->assertJsonPath('data.0.code','GAP-S')->assertJsonPath('data.0.balancePaise',null)->assertJsonPath('totals',null);
        $this->assertStringNotContainsString('123400',$this->body($students));
        // Teacher pay ("net payable") stays with money roles.
        $teacher=$this->api('teachers')->assertOk()->assertJsonPath('data.0.code','GAP-T')->json('data.0');
        $money=array_filter(array_keys($teacher),fn($key)=>str_ends_with($key,'Paise'));
        $this->assertNotEmpty($money);
        foreach ($money as $key) $this->assertNull($teacher[$key],$key.' must stay with money roles');
        foreach (array_keys($teacher) as $key) $this->assertStringNotContainsStringIgnoringCase('rate',$key);
    }

    public function test_switching_teacher_moves_one_subjects_weekly_classes_atomically(): void
    {
        $other=DB::table('erp_teachers')->insertGetId(['code'=>'GAP-T2','name'=>'Second Teacher','timezone'=>'UTC','status'=>'active']);
        DB::table('erp_teacher_units')->insert(['unit_id'=>$this->unit,'teacher_id'=>$other]);
        $first=now('UTC')->toImmutable()->addDays(2)->startOfDay(); $weekday=(int)$first->isoWeekday();
        foreach ([['student_id'=>$this->student],['teacher_id'=>$this->teacher],['teacher_id'=>$other]] as $who) DB::table('erp_availability')->insert($who+['unit_id'=>$this->unit,'weekday'=>$weekday,'start_time'=>'09:00','end_time'=>'12:00','timezone'=>'UTC','starts_on'=>'2026-01-01']);
        DB::table('erp_eligibilities')->insert(['teacher_id'=>$this->teacher,'subject_id'=>$this->subject,'grade'=>'8','syllabus'=>'CBSE']);
        $scheduling=new \App\Modules\Erp\Services\Scheduling();
        $agreement=$scheduling->proposeAgreement($this->unit,$this->admin->id,['enrollment_id'=>$this->enrollment,'weekday'=>$weekday,'start_time'=>'10:00','duration_minutes'=>60,'timezone'=>'UTC','starts_on'=>$first->toDateString()]);
        $confirm=['confirmation_channel'=>'whatsapp','teacher_confirmed'=>'1','student_confirmed'=>'1','confirmation_note'=>'Agreed'];
        $scheduling->confirmAgreement($this->unit,$this->admin->id,['agreement_id'=>$agreement->id]+$confirm);
        $scheduling->materialize($this->unit,$this->admin->id,['agreement_id'=>$agreement->id,'from'=>$first->toDateString(),'through'=>$first->addWeeks(3)->toDateString()]);
        $this->assertSame(4,DB::table('erp_lessons')->where('enrollment_id',$this->enrollment)->where('status','scheduled')->count());
        $switch=['enrollment_id'=>$this->enrollment,'teacher_id'=>$other,'from'=>$first->addWeek()->toDateString(),'teacher_rate_inr'=>'70','student_rate_inr'=>'','reason'=>'Parent asked for a change']+$confirm;
        $this->actingAs($this->member('academics'));
        $this->postJson(route('erp.mutate',[$this->unit,'switch-teacher']),$switch)->assertStatus(422);
        $this->assertSame(4,DB::table('erp_lessons')->where('enrollment_id',$this->enrollment)->where('status','scheduled')->count(),'Nothing changes when the new teacher is not eligible.');
        DB::table('erp_eligibilities')->insert(['teacher_id'=>$other,'subject_id'=>$this->subject,'grade'=>'8','syllabus'=>'CBSE']);
        $this->postJson(route('erp.mutate',[$this->unit,'switch-teacher']),$switch)->assertOk()
            ->assertJsonPath('message',fn($s)=>str_contains($s,'3 lesson(s) moved and 3 new lesson(s) booked'));
        $this->assertSame(1,DB::table('erp_lessons')->where('enrollment_id',$this->enrollment)->where('status','scheduled')->count());
        $new=DB::table('erp_enrollments')->where('student_id',$this->student)->where('teacher_id',$other)->first();
        $this->assertSame([$first->addWeek()->toDateString(),7000],[$new->starts_on,(int)$new->teacher_rate_paise]);
        $this->assertSame($first->addWeeks(1)->format('Y-m-d').' 10:00:00',DB::table('erp_lessons')->where('enrollment_id',$new->id)->orderBy('starts_at')->value('starts_at'));
        $this->assertSame(3,DB::table('erp_lessons')->where('enrollment_id',$new->id)->where('teacher_id',$other)->count());
    }

    public function test_sales_report_compares_counsellors(): void
    {
        $asha=$this->member('sales_followups'); $asha->forceFill(['name'=>'Asha Counsellor'])->save();
        foreach ([['Lead One','JOINED'],['Lead Two','LOST'],['Lead Three','LEAD']] as $i=>[$name,$status]) DB::table('erp_leads')->insert(['unit_id'=>$this->unit,'name'=>$name,'phone'=>'98000000'.($i+10),'enquiry_date'=>now('UTC')->toDateString(),'status'=>$status,'created_by'=>$asha->id,'assigned_to'=>$asha->id]);
        // Conversion (1 of 3 = 33.3%) is computed by the client from these counts.
        $this->actingAs($this->admin)->api('overview/sales-report')->assertOk()
            ->assertJsonPath('counsellors',[['counsellor'=>'Asha Counsellor','enquiries'=>3,'demoLeads'=>0,'joined'=>1,'lost'=>1]]);
    }
}
