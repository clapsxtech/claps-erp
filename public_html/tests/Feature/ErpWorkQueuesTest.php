<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpWorkQueuesTest extends TestCase
{
    private $admin;
    private $unit;
    private $other;
    private $student;
    private $teacher;
    private $subject;
    private $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.finance_policy'=>'admin_import_delivered_only','erp.payroll_policy'=>'approved_attended_only']);
        $this->admin=$this->createAdmin();
        $this->unit=DB::table('erp_units')->insertGetId(['code'=>'QUEUE-AA','name'=>'Queue AA','timezone'=>'Asia/Kolkata']);
        $this->other=DB::table('erp_units')->insertGetId(['code'=>'QUEUE-BB','name'=>'Queue BB','timezone'=>'UTC']);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$this->admin->id,'role'=>'admin']);
        $this->student=DB::table('erp_students')->insertGetId(['unit_id'=>$this->unit,'code'=>'QUEUE-S','name'=>'Named Queue Student','registered_on'=>'2026-01-01','timezone'=>'UTC','status'=>'active','grade'=>'8','syllabus'=>'Synthetic']);
        $this->teacher=DB::table('erp_teachers')->insertGetId(['code'=>'QUEUE-T','name'=>'Named Queue Teacher','timezone'=>'UTC','status'=>'active']);
        DB::table('erp_teacher_units')->insert(['unit_id'=>$this->unit,'teacher_id'=>$this->teacher]);
        $this->subject=DB::table('erp_subjects')->insertGetId(['name'=>'Queue Mathematics']);
        $this->enrollment=DB::table('erp_enrollments')->insertGetId(['unit_id'=>$this->unit,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$this->subject,'starts_on'=>'2026-01-01','status'=>'active','student_rate_paise'=>10000,'teacher_rate_paise'=>6000,'currency'=>'INR']);
        $this->actingAs($this->admin);
    }
    private function attendance(array $lesson=[],array $attendance=[]): int
    {
        $id=DB::table('erp_lessons')->insertGetId(array_merge(['unit_id'=>$this->unit,'enrollment_id'=>$this->enrollment,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$this->subject,'starts_at'=>'2026-01-05 10:00:00','ends_at'=>'2026-01-05 11:00:00','kind'=>'regular','status'=>'completed','student_rate_paise'=>10000,'teacher_rate_paise'=>6000,'currency'=>'INR'],$lesson));
        return DB::table('erp_attendance')->insertGetId(array_merge(['unit_id'=>$this->unit,'lesson_id'=>$id,'actual_minutes'=>60,'status'=>'submitted','submitted_by'=>$this->admin->id],$attendance));
    }
    private function queue(string $queue,array $query=[]) {return $this->getJson(route('app.api.classes.attendance.queue',[$this->unit,$queue]+$query));}
    private function ids($response): array {return collect($response->json('data'))->pluck('id')->map(fn($id)=>(int)$id)->all();}
    private function queueIds(string $queue): array {return $this->ids($this->queue($queue)->assertOk());}
    private function action(string $action,array $payload) {return $this->postJson(route('erp.mutate',[$this->unit,$action]),$payload+['return_page'=>'attendance']);}
    private function plain($response): string {return json_encode($response->json(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}

    public function test_named_action_queues_keep_student_import_separate_from_teacher_approval(): void
    {
        $id=$this->attendance();$demo=$this->attendance(['kind'=>'demo']);
        $import=$this->queue('import')->assertOk();$approval=$this->queue('approval')->assertOk();
        $this->assertSame([$id],$this->ids($import));
        $this->assertSame([$id,$demo],$this->ids($approval));
        foreach ([$import,$approval] as $response) {
            $response->assertJsonPath('timezone','Asia/Kolkata')->assertJsonPath('data.0.studentName','Named Queue Student')->assertJsonPath('data.0.teacherName','Named Queue Teacher')
                ->assertJsonPath('data.0.subjectName','Queue Mathematics')->assertJsonPath('data.0.startTime','15:30')->assertJsonPath('data.0.localDate','2026-01-05');
        }
        // Regular rows await import and approval; the demo row is free and unpaid.
        $import->assertJsonPath('data.0.studentFinanceState','Pending import');
        $approval->assertJsonPath('data.0.teacherFinanceState','Pending approval')->assertJsonPath('data.1.kind','demo')->assertJsonPath('data.1.teacherFinanceState','Unpaid demo')->assertJsonPath('data.1.studentFinanceState','Free demo');
        $this->getJson(route('app.api.overview.home',$this->unit))->assertOk()->assertJsonPath('queues.studentImport',1)->assertJsonPath('queues.teacherApproval',2);
        $this->getJson(route('app.api.classes.attendance',$this->unit))->assertOk()->assertJsonPath('counts.import',1)->assertJsonPath('counts.approval',2);
        $this->assertSame(0,DB::table('erp_ledger')->count());$this->assertSame(0,DB::table('erp_audit')->count());
    }
    public function test_queue_posts_revalidate_and_replays_do_not_double_post_either_stream(): void
    {
        $id=$this->attendance();
        $payload=['attendance_ids'=>[$id],'reference'=>'SYN-QUEUE-IMPORT'];
        $this->action('import-batch',$payload)->assertOk();
        $this->assertSame([],$this->queueIds('import'));$this->assertSame([$id],$this->queueIds('approval'));
        $this->assertSame(1,DB::table('erp_ledger')->where('kind','lesson_charge')->count());$this->assertSame(0,DB::table('erp_ledger')->where('kind','teacher_accrual')->count());
        $this->action('import-batch',$payload)->assertOk();
        $this->action('approve',['attendance_id'=>$id])->assertOk();
        $this->action('approve',['attendance_id'=>$id])->assertOk();
        $this->assertSame([],$this->queueIds('import'));$this->assertSame([],$this->queueIds('approval'));
        $this->assertSame(2,DB::table('erp_ledger')->count());
        $this->action('correct',['attendance_id'=>$id,'actual_minutes'=>30,'reason'=>'Synthetic corrected minutes'])->assertOk();
        $this->assertSame([$id],$this->queueIds('import'));$this->assertSame([$id],$this->queueIds('approval'));
        $this->assertSame(0,(int)DB::table('erp_ledger')->sum('amount_paise'));
    }
    public function test_pending_duplicates_still_import_but_cannot_be_approved_and_post_is_rejected(): void
    {
        $one=$this->attendance();$two=$this->attendance();
        DB::table('erp_duplicate_reviews')->insert(['unit_id'=>$this->unit,'attendance_id'=>$one,'other_attendance_id'=>$two,'status'=>'pending','channel'=>'gallabox']);
        $approval=$this->queue('approval')->assertOk()->assertJsonPath('data.0.pendingReviews',1)->assertJsonPath('data.1.pendingReviews',1);
        $this->assertSame([$one,$two],$this->ids($approval));
        $this->getJson(route('app.api.classes.attendance.reviews',$this->unit))->assertOk()->assertJsonPath('data.0.attendance.id',$one)->assertJsonPath('data.0.other.id',$two);
        $this->assertSame([$one,$two],$this->queueIds('import'));
        $this->action('approve',['attendance_id'=>$one])->assertUnprocessable();
        $this->action('import-batch',['attendance_ids'=>[$one,$two],'reference'=>'SYN-QUEUE-DUPLICATES'])->assertOk();
        $this->assertSame(2,DB::table('erp_ledger')->where('kind','lesson_charge')->count());$this->assertSame(0,DB::table('erp_ledger')->where('kind','teacher_accrual')->count());
    }
    public function test_rejected_cancelled_and_foreign_rows_do_not_leak_and_access_requires_unit_admin(): void
    {
        $own=$this->attendance();$this->attendance([],['status'=>'rejected']);$this->attendance(['status'=>'cancelled']);
        $this->attendance(['unit_id'=>$this->other],['unit_id'=>$this->other]);
        $this->assertSame([$own],$this->queueIds('import'));$this->assertSame([$own],$this->queueIds('approval'));
        $this->getJson(route('app.api.classes.attendance.queue',[$this->other,'import']))->assertOk();
        $teacherUser=$this->createMember();DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$teacherUser->id,'role'=>'teacher']);
        $this->actingAs($teacherUser);
        foreach (['import','approval','missing'] as $queue) $this->queue($queue)->assertForbidden();
        $this->getJson(route('app.api.classes.attendance',$this->unit))->assertForbidden();
        $this->action('approve',['attendance_id'=>$own])->assertForbidden();
        $this->assertSame(0,DB::table('erp_ledger')->count());
    }
    public function test_queues_are_independently_paginated_and_return_names_verbatim(): void
    {
        DB::table('erp_students')->where('id',$this->student)->update(['name'=>'<script>unsafe()</script>']);
        for($i=0;$i<27;$i++)$this->attendance();
        $response=$this->queue('import')->assertOk()->assertHeader('Content-Type','application/json')->assertJsonPath('data.0.studentName','<script>unsafe()</script>');
        // The value is data in a JSON document (never HTML); the React client renders it as text.
        $this->assertStringNotContainsString('text/html',(string)$response->headers->get('Content-Type'));
        $this->assertCount(25,$this->ids($response));$this->assertSame(27,$response->json('meta.total'));$response->assertJsonPath('meta.pageParam','import_queue_page');
        $this->assertCount(2,$this->ids($this->queue('import',['import_queue_page'=>2])->assertOk()));
        $this->assertCount(25,$this->ids($this->queue('approval',['import_queue_page'=>2])->assertOk()));
    }
    public function test_approved_but_unaccrued_classes_remain_visible_for_explicit_review(): void
    {
        $id=$this->attendance([],['status'=>'approved']);
        $this->attendance(['kind'=>'demo'],['status'=>'approved']);
        $this->queue('approval')->assertOk()->assertJsonPath('data.0.status','approved')->assertJsonPath('data.0.teacherFinanceState','Approved, not accrued');
        $this->assertSame([$id],$this->queueIds('approval'));
        $this->action('approve',['attendance_id'=>$id])->assertOk();
        $this->assertSame(1,DB::table('erp_ledger')->where('kind','teacher_accrual')->count());
        $this->assertSame(0,DB::table('erp_ledger')->where('kind','lesson_charge')->count());
        $this->assertSame([],$this->queueIds('approval'));
    }
    public function test_generic_registers_resolve_party_names_without_cross_unit_name_leaks(): void
    {
        $id=$this->attendance();
        $foreignStudent=DB::table('erp_students')->insertGetId(['unit_id'=>$this->other,'code'=>'QUEUE-SECRET','name'=>'Private Foreign Queue Student','registered_on'=>'2026-01-01','timezone'=>'UTC','status'=>'active','grade'=>'8','syllabus'=>'Synthetic']);
        $lesson=DB::table('erp_attendance')->where('id',$id)->value('lesson_id');
        $this->getJson(route('app.api.classes.attendance',$this->unit))->assertOk()->assertJsonPath('data.0.lessonId',(int)$lesson)
            ->assertJsonPath('data.0.studentName','Named Queue Student')->assertJsonPath('data.0.teacherName','Named Queue Teacher')->assertJsonPath('data.0.subjectName','Queue Mathematics');
        DB::table('erp_ledger')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'teacher_id'=>null,'kind'=>'opening_balance','amount_paise'=>-10000,'currency'=>'INR','event_key'=>'syn-queue-opening','reference'=>'SYN-QUEUE-OPEN','occurred_at'=>'2026-01-01 00:00:00','created_by'=>$this->admin->id]);
        // A row that points at another unit's student must not resolve that student's name.
        DB::table('erp_ledger')->insert(['unit_id'=>$this->unit,'student_id'=>$foreignStudent,'teacher_id'=>null,'kind'=>'opening_balance','amount_paise'=>-100,'currency'=>'INR','event_key'=>'syn-queue-foreign','reference'=>'SYN-QUEUE-FOREIGN','occurred_at'=>'2025-12-01 00:00:00','created_by'=>$this->admin->id]);
        $ledger=$this->getJson(route('app.api.money.ledger',$this->unit))->assertOk()->assertJsonPath('data.0.studentName','Named Queue Student')->assertJsonPath('data.0.studentCode','QUEUE-S');
        $this->assertStringNotContainsString('Private Foreign Queue Student',$this->plain($ledger));
        $this->assertStringNotContainsString('QUEUE-SECRET',$this->plain($ledger));
    }

    public function test_named_tasks_offer_completion_only_after_checklist_and_revalidate_on_post(): void
    {
        $task=DB::table('erp_tasks')->insertGetId(['unit_id'=>$this->unit,'student_id'=>$this->student,'assigned_to'=>$this->admin->id,'title'=>'Synthetic actionable task','status'=>'open','created_by'=>$this->admin->id]);
        $item=DB::table('erp_task_items')->insertGetId(['unit_id'=>$this->unit,'task_id'=>$task,'title'=>'Synthetic checklist item']);
        $tasks=fn()=>$this->getJson(route('app.api.overview.tasks',[$this->unit,'scope'=>'all']))->assertOk();
        $tasks()->assertJsonPath('data.0.id',$task)->assertJsonPath('data.0.remainingItems',1)->assertJsonPath('data.0.assigneeName',$this->admin->name)
            ->assertJsonPath('data.0.items.0.title','Synthetic checklist item')->assertJsonPath('data.0.items.0.completedAt',null);
        $this->postJson(route('erp.business.mutate',[$this->unit,'task-update']),['task_id'=>$task,'status'=>'completed','return_page'=>'dashboard'])->assertUnprocessable();
        $this->assertDatabaseHas('erp_tasks',['id'=>$task,'status'=>'open']);
        $this->postJson(route('erp.business.mutate',[$this->unit,'task-item-complete']),['item_id'=>$item,'return_page'=>'followups'])->assertOk();
        $tasks()->assertJsonPath('data.0.remainingItems',0);
        $this->postJson(route('erp.business.mutate',[$this->unit,'task-update']),['task_id'=>$task,'status'=>'completed','return_page'=>'dashboard'])->assertOk();
        $this->assertDatabaseHas('erp_tasks',['id'=>$task,'status'=>'completed']);
        $this->assertSame([],$tasks()->json('data'));
        $this->assertSame([$task],collect($this->getJson(route('app.api.overview.tasks',[$this->unit,'scope'=>'all','status'=>'completed']))->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_finance_imports_student_charges_from_money_without_the_teacher_approval_queue(): void
    {
        $finance=$this->createMember(['name'=>'Queue Finance']);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$finance->id,'role'=>'finance']);
        $attendance=$this->attendance();
        $this->actingAs($finance);
        $this->getJson(route('app.api.overview.home',$this->unit))->assertOk()->assertJsonPath('queues.studentImport',1)->assertJsonPath('queues.teacherApproval',null);
        $this->queue('approval')->assertForbidden();
        // No link to the private workspace/profile or tuition screens from Money for finance.
        $this->getJson(route('app.api.money.summary',$this->unit))->assertOk()->assertJsonPath('can.openProfile',false)->assertJsonPath('can.openTuition',false);
        $this->getJson(route('app.api.student',[$this->unit,$this->student]))->assertForbidden();
        $this->postJson(route('erp.mutate',[$this->unit,'import-batch']),['attendance_ids'=>[$attendance],'reference'=>'SYN-FINANCE-IMPORT'])->assertOk();
        $this->assertSame(1,DB::table('erp_ledger')->where('kind','lesson_charge')->count());
        $this->assertSame(0,DB::table('erp_ledger')->where('kind','teacher_accrual')->count());
    }

    public function test_finance_can_list_the_student_import_queue_rows(): void
    {
        $finance=$this->createMember(['name'=>'Queue Finance']);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$finance->id,'role'=>'finance']);
        $attendance=$this->attendance();
        $this->actingAs($finance);
        // Finance reads the same import queue from Payments & dues (page "money"); the Attendance page stays closed.
        $this->queue('import')->assertForbidden();
        $rows=$this->getJson(route('app.api.money.charge-queue',$this->unit))->assertOk()->assertJsonPath('data.0.studentFinanceState','Pending import');
        $this->assertSame([$attendance],$this->ids($rows));
    }

    public function test_academics_manage_scheduled_lessons_from_the_timetable_page(): void
    {
        $academics=$this->createMember(['name'=>'Queue Academics']);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$academics->id,'role'=>'academics']);
        $lesson=DB::table('erp_lessons')->insertGetId(['unit_id'=>$this->unit,'enrollment_id'=>$this->enrollment,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$this->subject,'starts_at'=>now('UTC')->addDays(2)->format('Y-m-d H:i:s'),'ends_at'=>now('UTC')->addDays(2)->addHour()->format('Y-m-d H:i:s'),'status'=>'scheduled','kind'=>'regular','student_rate_paise'=>10000,'teacher_rate_paise'=>8000]);
        $this->actingAs($academics);
        $week=$this->getJson(route('app.api.classes.timetable',[$this->unit,'date'=>now('UTC')->addDays(2)->toDateString()]))->assertOk();
        $this->assertContains($lesson,collect($week->json('days'))->flatMap(fn($day)=>$day['lessons'])->pluck('id')->all());
        $this->assertStringNotContainsString('RatePaise',$week->getContent());
        $this->getJson(route('app.api.money.summary',$this->unit))->assertForbidden();
        // The classic workspace (now the student profile and planning detail) stays closed; no link to it from the timetable.
        $this->getJson(route('app.api.student',[$this->unit,$this->student]))->assertForbidden();
        $this->getJson(route('app.api.classes.timetable.plans',$this->unit))->assertOk()->assertJsonPath('can.openPlanning',false);
        // Cancel, reschedule and extra-class actions are offered on the timetable.
        $html=$this->get('/app')->assertOk()->getContent();
        preg_match('#<script type="application/json" id="erp-session">(.*?)</script>#s',$html,$m);
        $actions=collect(json_decode($m[1],true)['units'])->firstWhere('id',$this->unit)['actions'];
        foreach (['cancel','reschedule','extra'] as $action) $this->assertContains($action,$actions);
        $this->postJson(route('erp.mutate',[$this->unit,'cancel']),['lesson_id'=>$lesson,'reason'=>'Parent asked to skip','return_page'=>'timetable'])->assertOk();
        $this->assertDatabaseHas('erp_lessons',['id'=>$lesson,'status'=>'cancelled']);
    }
}
