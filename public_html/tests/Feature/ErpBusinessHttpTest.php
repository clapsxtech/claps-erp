<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real write routes (JSON) and the app-api JSON reads that replaced the business pages. Synthetic in-memory data only. */
class ErpBusinessHttpTest extends TestCase
{
    private $unit; private $other; private $admin; private $student; private $teacher; private $staff;
    protected function setUp(): void {
        parent::setUp(); config(['erp.finance_policy'=>'admin_import_delivered_only','erp.payroll_policy'=>'approved_attended_only']);
        $this->admin=$this->createAdmin(); $this->staff=$this->createMember(['name'=>'Synthetic Staff Account']);
        $this->unit=DB::table('erp_units')->insertGetId(['code'=>'BHTTP-AA','name'=>'Business HTTP AA','timezone'=>'UTC']);
        $this->other=DB::table('erp_units')->insertGetId(['code'=>'BHTTP-BB','name'=>'Private Business BB','timezone'=>'UTC']);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$this->admin->id,'role'=>'admin']);
        $this->student=DB::table('erp_students')->insertGetId(['unit_id'=>$this->unit,'code'=>'BHTTP-STUDENT','name'=>'Synthetic Own Student','registered_on'=>now()->startOfMonth()->toDateString(),'timezone'=>'UTC','status'=>'active','grade'=>'8','syllabus'=>'Synthetic']);
        DB::table('erp_students')->insert(['unit_id'=>$this->other,'code'=>'BHTTP-SECRET','name'=>'Private Other Student','registered_on'=>'2026-01-01','timezone'=>'UTC','status'=>'active','grade'=>'8','syllabus'=>'Synthetic']);
        $this->teacher=DB::table('erp_teachers')->insertGetId(['code'=>'BHTTP-TEACHER','name'=>'Synthetic Own Teacher','timezone'=>'UTC','status'=>'active']);
        DB::table('erp_teacher_units')->insert(['teacher_id'=>$this->teacher,'unit_id'=>$this->unit]);
        $this->actingAs($this->admin);
    }
    private function form(string $action,array $payload,string $page='dashboard') {
        return $this->postJson(route('erp.business.mutate',[$this->unit,$action]),$payload+['return_page'=>$page]);
    }
    private function saved(string $action,array $payload,string $page='dashboard'): void {
        $this->form($action,$payload,$page)->assertOk()->assertJsonPath('message',fn($m)=>is_string($m)&&$m!=='');
    }
    private function receipt(string $reference,int $amount=10000) {
        $this->postJson(route('erp.mutate',[$this->unit,'payment']),['student_id'=>$this->student,'amount_paise'=>$amount,'reference'=>$reference])->assertOk();
        return DB::table('erp_ledger')->where('reference',$reference)->first();
    }
    /** JSON reads that replaced each classic business page. */
    private const READS=['dashboard'=>['overview/home'],'people'=>['students','teachers'],'timetable'=>['timetable','timetable/plans'],'attendance'=>['attendance'],
        'money'=>['money/summary','money/dues','money/ledger','money/requests','money/teachers'],'followups'=>['followups'],'staff'=>['admin/team'],'migration'=>['overview/exports']];
    private function read(int $unit,string $path,array $query=[]) {
        return $this->getJson('/app-api/units/'.$unit.'/'.$path.($query?'?'.http_build_query($query):''));
    }
    private function plain($response): string {
        return json_encode($response->json(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }
    private function appSession(): array {
        $html=$this->get('/app')->assertOk()->getContent();
        $this->assertSame(1,preg_match('#<script type="application/json" id="erp-session">(.*?)</script>#s',$html,$m));
        return json_decode($m[1],true);
    }
    public function test_all_eight_business_reads_answer_with_unit_scoped_data(): void {
        $units=collect($this->appSession()['units'])->pluck('name')->all();
        $this->assertContains('Business HTTP AA',$units); $this->assertContains('Private Business BB',$units);
        foreach (self::READS as $page=>$paths) foreach ($paths as $path) {
            $response=$this->read($this->unit,$path)->assertOk();
            $this->assertStringNotContainsString('Private Other Student',$this->plain($response),$path);
            $this->assertStringNotContainsString('BHTTP-SECRET',$this->plain($response),$path);
        }
        $this->read($this->unit,'admin/team')->assertJsonPath('team.name','Business HTTP AA');
        $this->read($this->unit,'students')->assertJsonFragment(['name'=>'Synthetic Own Student']);
        $this->read($this->unit,'teachers')->assertJsonFragment(['name'=>'Synthetic Own Teacher']);
        $this->read($this->unit,'not-a-page')->assertNotFound();
        $this->read($this->other,'students')->assertOk()->assertJsonFragment(['name'=>'Private Other Student']);
    }
    public function test_searchable_lookups_are_scoped_and_names_codes_are_searchable(): void {
        $rows=$this->getJson(route('erp.lookup',[$this->unit,'type'=>'students','q'=>'BHTTP-STUDENT']))->assertOk()->json();
        $this->assertCount(1,$rows); $this->assertSame($this->student,$rows[0]['id']); $this->assertStringContainsString('Synthetic Own Student',$rows[0]['label']);
        $this->getJson(route('erp.lookup',[$this->unit,'type'=>'students','q'=>'Private']))->assertOk()->assertExactJson([]);
        $this->getJson(route('erp.lookup',[$this->other,'type'=>'students']))->assertOk();
        $this->getJson(route('erp.lookup',[$this->unit,'type'=>'teachers','q'=>'BHTTP-TEACHER']))->assertOk()->assertJsonFragment(['id'=>$this->teacher]);
        $this->read($this->unit,'students',['q'=>'not-present'])->assertOk()->assertJsonPath('data',[])->assertDontSee('Synthetic Own Student');
        $this->read($this->unit,'students',['q'=>'BHTTP-STUDENT'])->assertOk()->assertJsonPath('data.0.id',$this->student);
        $this->getJson(route('erp.lookup',[$this->unit,'type'=>'unsupported']))->assertUnprocessable();
    }
    public function test_salary_forms_record_only_already_paid_money_and_audited_reversal(): void {
        DB::table('erp_ledger')->insert(['unit_id'=>$this->unit,'teacher_id'=>$this->teacher,'kind'=>'teacher_accrual','amount_paise'=>20000,'currency'=>'INR','reference'=>'BHTTP-EARNED','event_key'=>'bhttp-earned','occurred_at'=>now(),'created_by'=>$this->admin->id]);
        $payload=['teacher_id'=>$this->teacher,'amount_paise'=>12000,'reference'=>'BHTTP-PAID','paid_on'=>now()->toDateString(),'reason'=>'Synthetic externally completed salary payment'];
        $this->form('salary-paid',$payload,'money')->assertUnprocessable()->assertJsonValidationErrors('already_paid');
        $this->assertSame(0,DB::table('erp_ledger')->where('kind','teacher_payment')->count());
        $this->saved('salary-paid',$payload+['already_paid'=>'1'],'money');
        $paid=DB::table('erp_ledger')->where('reference','BHTTP-PAID')->first(); $this->assertSame(-12000,(int)$paid->amount_paise);
        $this->read($this->unit,'money/ledger')->assertOk()->assertJsonFragment(['reference'=>'BHTTP-PAID','amountPaise'=>-12000]);
        $this->read($this->unit,'money/teachers')->assertOk()->assertJsonPath('data.0.netPayablePaise',8000);
        $audit=json_decode(DB::table('erp_audit')->where('action','teacher_payment.recorded')->value('payload'),true); $this->assertFalse($audit['money_transferred_by_app']);
        $this->saved('salary-reverse',['entry_id'=>$paid->id,'reason'=>'Correct incorrectly recorded external salary payment'],'money');
        $this->assertSame(20000,(int)DB::table('erp_ledger')->where('teacher_id',$this->teacher)->sum('amount_paise'));
        $this->assertDatabaseHas('erp_ledger',['reverses_id'=>$paid->id,'amount_paise'=>12000]);
    }
    public function test_request_receipt_allocation_print_and_correction_preserve_history(): void {
        $this->saved('request-create',['student_id'=>$this->student,'reference'=>'BHTTP-REQUEST','amount_paise'=>10000,'requested_on'=>now()->toDateString(),'due_on'=>now()->addDays(5)->toDateString(),'notes'=>'Synthetic request, not a receipt'],'money');
        $request=DB::table('erp_payment_requests')->where('reference','BHTTP-REQUEST')->first();
        $this->assertSame(0,DB::table('erp_ledger')->count());
        $this->read($this->unit,'print/request/'.$request->id)->assertOk()->assertJsonPath('reference','BHTTP-REQUEST')->assertJsonPath('requested','INR 100.00')->assertJsonPath('status','pending')->assertJsonPath('notes','Synthetic request, not a receipt');
        $payment=$this->receipt('BHTTP-RECEIPT');
        $this->saved('request-allocate',['request_id'=>$request->id,'payment_id'=>$payment->id,'amount_paise'=>10000],'money');
        $this->read($this->unit,'print/request/'.$request->id)->assertOk()->assertJsonPath('status','fulfilled')->assertJsonPath('outstanding','INR 0.00');
        $this->read($this->unit,'print/receipt/'.$payment->id)->assertOk()->assertJsonPath('title','Payment acknowledgement')->assertJsonPath('reversal',null)->assertJsonPath('replacement',null);
        $this->saved('receipt-correct',['entry_id'=>$payment->id,'amount_paise'=>8000,'new_reference'=>'BHTTP-REPLACEMENT','reason'=>'Synthetic amount correction'],'money');
        $replacement=DB::table('erp_ledger')->where('reference','BHTTP-REPLACEMENT')->first();
        $this->read($this->unit,'print/receipt/'.$payment->id)->assertOk()->assertJsonPath('reversal.reference',fn($r)=>is_string($r)&&$r!=='')
            ->assertJsonPath('replacement.id',(int)$replacement->id)->assertJsonPath('replacement.reference','BHTTP-REPLACEMENT');
        $this->read($this->unit,'print/receipt/'.$replacement->id)->assertOk()->assertJsonPath('amount','INR 80.00')->assertJsonPath('reversal',null);
        $this->read($this->unit,'print/request/'.$request->id)->assertOk()->assertJsonPath('outstanding','INR 100.00');
        $this->saved('request-allocate',['request_id'=>$request->id,'payment_id'=>$replacement->id,'amount_paise'=>8000],'money');
        $this->read($this->unit,'print/request/'.$request->id)->assertOk()->assertJsonPath('outstanding','INR 20.00');
        $this->assertSame(8000,(int)DB::table('erp_ledger')->where('student_id',$this->student)->sum('amount_paise'));
    }
    public function test_followup_task_checklist_and_status_forms_are_connected(): void {
        $this->postJson(route('erp.mutate',[$this->unit,'task']),['title'=>'Business checklist task','student_id'=>$this->student,'due_on'=>now()->toDateString()])->assertOk();
        $task=DB::table('erp_tasks')->where('title','Business checklist task')->first();
        $this->saved('task-update',['task_id'=>$task->id,'status'=>'in_progress','assigned_to'=>$this->admin->id,'notes'=>'Synthetic progress'],'followups');
        $this->saved('task-item',['task_id'=>$task->id,'title'=>'Record guardian response'],'followups');
        $item=DB::table('erp_task_items')->where('task_id',$task->id)->first();
        $this->form('task-update',['task_id'=>$task->id,'status'=>'completed'],'followups')->assertUnprocessable();
        $this->saved('task-item-complete',['item_id'=>$item->id],'followups');
        $this->saved('task-update',['task_id'=>$task->id,'status'=>'completed'],'followups');
        $this->saved('followup',['student_id'=>$this->student,'contact_channel'=>'call','note'=>'Synthetic logged call only','outcome'=>'Call again tomorrow','next_due_on'=>now()->toDateString()],'followups');
        $this->read($this->unit,'overview/tasks',['scope'=>'all','status'=>'all'])->assertOk()->assertJsonPath('data.0.title','Business checklist task')->assertJsonPath('data.0.status','completed')
            ->assertJsonPath('data.0.items.0.title','Record guardian response')->assertJsonPath('data.0.items.0.completedAt',fn($v)=>$v!==null)->assertJsonPath('data.0.studentName','Synthetic Own Student');
        $this->read($this->unit,'followups',['view'=>'history'])->assertOk()->assertJsonPath('data.0.lastNote','Synthetic logged call only')->assertJsonPath('data.0.lastOutcome','Call again tomorrow');
        $this->read($this->unit,'followups')->assertOk()->assertJsonPath('data.0.studentId',$this->student)->assertJsonPath('counts.dueToday',1);
        $this->assertDatabaseHas('erp_tasks',['id'=>$task->id,'status'=>'completed']);
        $audit=json_decode(DB::table('erp_audit')->where('action','followup.recorded')->value('payload'),true); $this->assertFalse($audit['outbound_sent']);
    }
    public function test_staff_forms_grant_link_unlink_revoke_with_safety_guards(): void {
        $this->form('membership-revoke',['user_id'=>$this->admin->id],'staff')->assertUnprocessable();
        $this->saved('membership-grant',['user_id'=>$this->staff->id,'role'=>'teacher'],'staff');
        $this->saved('teacher-link',['teacher_id'=>$this->teacher,'user_id'=>$this->staff->id],'staff');
        $this->form('membership-revoke',['user_id'=>$this->staff->id],'staff')->assertUnprocessable();
        $this->read($this->unit,'admin/team')->assertOk()->assertJsonFragment(['id'=>$this->teacher,'linkedUserId'=>$this->staff->id]);
        $this->assertDatabaseHas('erp_teachers',['id'=>$this->teacher,'user_id'=>$this->staff->id]);
        $this->saved('teacher-unlink',['teacher_id'=>$this->teacher,'reason'=>'Synthetic explicit account separation'],'staff');
        $this->saved('membership-revoke',['user_id'=>$this->staff->id],'staff');
        $this->assertNull(DB::table('erp_teachers')->where('id',$this->teacher)->value('user_id'));
        $this->assertSame(0,DB::table('erp_memberships')->where('user_id',$this->staff->id)->count());
        $this->assertSame(1,DB::table('erp_memberships')->where('unit_id',$this->unit)->where('role','admin')->count());
        $team=$this->read($this->unit,'admin/team')->assertOk()->assertJsonFragment(['id'=>$this->teacher,'linkedUserId'=>null]);
        $this->assertNotContains($this->staff->id,array_column($team->json('members'),'userId'));
    }
    public function test_csv_exports_and_reconciliation_are_scoped_read_only_and_formula_safe(): void {
        DB::table('erp_students')->where('id',$this->student)->update(['name'=>'=2+2','code'=>'@danger']);
        $response=$this->get(route('erp.export',[$this->unit,'students']))->assertOk();
        $csv=$response->streamedContent(); $stream=fopen('php://memory','r+'); fwrite($stream,$csv); rewind($stream); $header=fgetcsv($stream); $record=fgetcsv($stream); fclose($stream);
        $row=array_combine($header,$record); $this->assertSame("'=2+2",$row['name']); $this->assertSame("'@danger",$row['code']); $this->assertStringNotContainsString('Private Other Student',$csv);
        foreach (['enrollments','lessons','attendance','ledger','tasks','marks','payment_requests','migration_history'] as $dataset) $this->get(route('erp.export',[$this->unit,$dataset]))->assertOk();
        $this->get(route('erp.export',[$this->unit,'users']))->assertNotFound();
        $this->get(route('erp.export',[$this->other,'students']))->assertOk();
        $before=DB::table('erp_audit')->count();
        $this->getJson(route('erp.reconciliation',$this->unit))->assertOk()->assertJsonPath('unit_id',$this->unit)->assertJsonPath('counts.students',1)->assertJsonPath('writes',0)->assertJsonPath('source_reconciled',false);
        $this->getJson(route('erp.reconciliation',$this->other))->assertOk(); $this->assertSame($before,DB::table('erp_audit')->count());
        DB::table('erp_memberships')->insert(['unit_id'=>$this->other,'user_id'=>$this->admin->id,'role'=>'admin']);
        $this->getJson(route('erp.reconciliation',$this->other))->assertOk()->assertJsonPath('counts.students',1);
    }
    public function test_record_references_remain_unit_scoped_even_for_admin_of_both_units(): void {
        $this->saved('request-create',['student_id'=>$this->student,'reference'=>'BHTTP-SCOPE-REQUEST','amount_paise'=>10000,'requested_on'=>now()->toDateString()],'money');
        $request=DB::table('erp_payment_requests')->where('reference','BHTTP-SCOPE-REQUEST')->first(); $payment=$this->receipt('BHTTP-SCOPE-RECEIPT');
        DB::table('erp_memberships')->insert(['unit_id'=>$this->other,'user_id'=>$this->admin->id,'role'=>'admin']);
        $this->read($this->other,'print/request/'.$request->id)->assertNotFound();
        $this->read($this->other,'print/receipt/'.$payment->id)->assertNotFound();
        $this->postJson(route('erp.business.mutate',[$this->other,'request-allocate']),['request_id'=>$request->id,'payment_id'=>$payment->id,'amount_paise'=>10000])->assertUnprocessable();
        $this->postJson(route('erp.business.mutate',[$this->other,'receipt-correct']),['entry_id'=>$payment->id,'new_reference'=>'BHTTP-CROSS-DENIED','amount_paise'=>9000,'reason'=>'Cross-unit attempt'])->assertUnprocessable();
        $this->assertSame(0,DB::table('erp_request_allocations')->count());
        $this->assertSame(1,DB::table('erp_ledger')->count());
    }
    public function test_sales_and_guests_cannot_access_administrative_business_operations(): void {
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$this->staff->id,'role'=>'sales']);
        $this->actingAs($this->staff);
        foreach (self::READS as $paths) foreach ($paths as $path) $this->read($this->unit,$path)->assertForbidden();
        $this->read($this->unit,'overview/tasks')->assertForbidden();
        $this->postJson(route('erp.business.mutate',[$this->unit,'membership-grant']),['user_id'=>$this->staff->id,'role'=>'admin'])->assertForbidden();
        $this->getJson(route('erp.lookup',[$this->unit,'type'=>'users']))->assertForbidden();
        $this->app['auth']->guard()->logout();
        $this->get('/app-api/units/'.$this->unit.'/overview/home')->assertRedirect(route('login'));
        $this->getJson('/app-api/units/'.$this->unit.'/overview/home')->assertUnauthorized();
        $this->get('/app')->assertRedirect(route('login'));
        $this->post(route('erp.business.mutate',[$this->unit,'membership-revoke']),['user_id'=>$this->staff->id])->assertRedirect(route('login'));
    }

    public function test_explicit_clear_fields_remove_optional_values_while_blank_inputs_leave_unchanged(): void {
        DB::table('erp_students')->where('id',$this->student)->update(['guardian_contact'=>'Synthetic contact','meeting_url'=>'https://example.invalid/meeting']);
        $this->saved('student-update',['student_id'=>$this->student,'name'=>'Retained Student','guardian_contact'=>'','meeting_url'=>''],'people');
        $this->assertSame('Synthetic contact',DB::table('erp_students')->where('id',$this->student)->value('guardian_contact'));
        $this->saved('student-update',['student_id'=>$this->student,'clear_fields'=>['guardian_contact','meeting_url']],'people');
        $this->assertNull(DB::table('erp_students')->where('id',$this->student)->value('guardian_contact'));
        $this->assertNull(DB::table('erp_students')->where('id',$this->student)->value('meeting_url'));
        $task=DB::table('erp_tasks')->insertGetId(['unit_id'=>$this->unit,'title'=>'Clear optional task values','notes'=>'Synthetic note','student_id'=>$this->student,'assigned_to'=>$this->admin->id,'due_on'=>now()->toDateString(),'status'=>'open','created_by'=>$this->admin->id]);
        $this->saved('task-update',['task_id'=>$task,'clear_fields'=>['notes','student_id','assigned_to','due_on']],'followups');
        $row=DB::table('erp_tasks')->where('id',$task)->first(); foreach (['notes','student_id','assigned_to','due_on'] as $field) $this->assertNull($row->$field);
        $this->postJson(route('erp.business.mutate',[$this->unit,'student-update']),['student_id'=>$this->student,'clear_fields'=>['unit_id']])->assertUnprocessable();
        $this->assertSame($this->unit,(int)DB::table('erp_students')->where('id',$this->student)->value('unit_id'));
        $this->read($this->unit,'students/'.$this->student)->assertOk()->assertJsonPath('student.name','Retained Student')->assertJsonPath('student.guardianContact',null)->assertJsonPath('student.meetingUrl',null);
    }
    public function test_timetable_and_attendance_lookup_types_resolve_scoped_human_readable_records(): void {
        $subject=DB::table('erp_subjects')->insertGetId(['name'=>'Synthetic lookup subject']);
        DB::table('erp_eligibilities')->insert(['teacher_id'=>$this->teacher,'subject_id'=>$subject,'grade'=>'8','syllabus'=>'Synthetic']);
        $enrollment=DB::table('erp_enrollments')->insertGetId(['unit_id'=>$this->unit,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$subject,'starts_on'=>'2026-01-01','status'=>'active','student_rate_paise'=>10000,'teacher_rate_paise'=>6000,'currency'=>'INR']);
        $agreement=DB::table('erp_agreements')->insertGetId(['unit_id'=>$this->unit,'enrollment_id'=>$enrollment,'weekday'=>1,'start_time'=>'10:00','duration_minutes'=>60,'timezone'=>'UTC','starts_on'=>'2026-01-01','status'=>'proposed']);
        $attendance=[]; $lesson=null;
        foreach ([1,2] as $day) {
            $lesson=DB::table('erp_lessons')->insertGetId(['unit_id'=>$this->unit,'enrollment_id'=>$enrollment,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$subject,'starts_at'=>'2026-01-0'.$day.' 10:00:00','ends_at'=>'2026-01-0'.$day.' 11:00:00','status'=>'completed','kind'=>'regular','student_rate_paise'=>10000,'teacher_rate_paise'=>6000,'currency'=>'INR']);
            $attendance[]=DB::table('erp_attendance')->insertGetId(['unit_id'=>$this->unit,'lesson_id'=>$lesson,'actual_minutes'=>60,'status'=>'submitted','submitted_by'=>$this->admin->id]);
        }
        $review=DB::table('erp_duplicate_reviews')->insertGetId(['unit_id'=>$this->unit,'attendance_id'=>$attendance[0],'other_attendance_id'=>$attendance[1],'status'=>'pending','channel'=>'gallabox']);
        foreach (['enrollments'=>$enrollment,'agreements'=>$agreement,'lessons'=>$lesson,'attendance'=>$attendance[0],'reviews'=>$review] as $type=>$id) {
            $this->getJson(route('erp.lookup',[$this->unit,'type'=>$type,'q'=>'Synthetic Own Student']))->assertOk()->assertJsonFragment(['id'=>$id]);
            $this->getJson(route('erp.lookup',[$this->unit,'type'=>$type,'q'=>'Private Other Student']))->assertOk()->assertExactJson([]);
        }
        $this->getJson(route('erp.lookup',[$this->unit,'type'=>'subjects','q'=>'Synthetic lookup']))->assertOk()->assertJsonFragment(['id'=>$subject]);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->other,'user_id'=>$this->admin->id,'role'=>'admin']);
        foreach (['enrollments','agreements','lessons','attendance','reviews','subjects'] as $type) $this->getJson(route('erp.lookup',[$this->other,'type'=>$type]))->assertOk()->assertExactJson([]);
    }
    public function test_export_escapes_whitespace_and_newline_formulas_but_preserves_negative_paise(): void {
        foreach (["\n=2+2",'  =2+2',"\t@SUM(A1)"] as $name) {
            DB::table('erp_students')->where('id',$this->student)->update(['name'=>$name]);
            $csv=$this->get(route('erp.export',[$this->unit,'students']))->assertOk()->streamedContent();
            $f=fopen('php://memory','r+'); fwrite($f,$csv); rewind($f); $header=fgetcsv($f); $values=fgetcsv($f); fclose($f);
            $row=array_combine($header,$values); $this->assertSame("'".$name,$row['name']);
        }
        DB::table('erp_ledger')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'kind'=>'opening_balance','amount_paise'=>-10000,'currency'=>'INR','reference'=>'BHTTP-NEGATIVE','event_key'=>'bhttp-negative','occurred_at'=>now(),'created_by'=>$this->admin->id]);
        $csv=$this->get(route('erp.export',[$this->unit,'ledger']))->assertOk()->streamedContent();
        $f=fopen('php://memory','r+'); fwrite($f,$csv); rewind($f); $header=fgetcsv($f); $values=fgetcsv($f); fclose($f);
        $this->assertSame('-10000',array_combine($header,$values)['amount_paise']);
    }

    public function test_revocation_lookup_and_form_include_inactive_deleted_accounts_only_in_current_unit(): void {
        $deleted=$this->createMember(['name'=>'Deleted Membership Account']);
        $outside=$this->createMember(['name'=>'Other Unit Membership Account']);
        DB::table('erp_memberships')->insert([['unit_id'=>$this->unit,'user_id'=>$this->staff->id,'role'=>'sales'],['unit_id'=>$this->unit,'user_id'=>$deleted->id,'role'=>'sales'],['unit_id'=>$this->other,'user_id'=>$outside->id,'role'=>'sales']]);
        $this->staff->forceFill(['status'=>0])->save(); $deleted->forceFill(['deleted_at'=>now()])->save();
        $rows=$this->getJson(route('erp.lookup',[$this->unit,'type'=>'revocable_members']))->assertOk()->json();
        $ids=array_column($rows,'id'); $this->assertContains($this->staff->id,$ids); $this->assertContains($deleted->id,$ids); $this->assertNotContains($outside->id,$ids);
        $inactive=array_values(array_filter($rows,fn($row)=>$row['id']===$this->staff->id))[0]; $this->assertStringContainsString('(inactive)',$inactive['label']);
        $this->getJson(route('erp.lookup',[$this->unit,'type'=>'revocable_members','q'=>'Deleted Membership']))->assertOk()->assertJsonFragment(['id'=>$deleted->id]);
        $this->getJson(route('erp.lookup',[$this->unit,'type'=>'revocable_members','q'=>'Other Unit Membership']))->assertOk()->assertExactJson([]);
        $members=$this->read($this->unit,'admin/team')->assertOk()->json('members');
        $this->assertSame([false,false],[collect($members)->firstWhere('userId',$this->staff->id)['isActive'],collect($members)->firstWhere('userId',$deleted->id)['isActive']]);
        $this->assertNotContains($outside->id,array_column($members,'userId'));
        $this->saved('membership-revoke',['user_id'=>$this->staff->id],'staff');
        $this->saved('membership-revoke',['user_id'=>$deleted->id],'staff');
        $this->assertSame(0,DB::table('erp_memberships')->where('unit_id',$this->unit)->whereIn('user_id',[$this->staff->id,$deleted->id])->count());
        $this->assertDatabaseHas('erp_memberships',['unit_id'=>$this->other,'user_id'=>$outside->id,'role'=>'sales']);
        $this->assertSame(2,DB::table('erp_audit')->where('action','membership.revoked')->count());
    }

    public function test_receipt_labels_installation_timezone_without_reinterpreting_saved_time(): void {
        config(['app.timezone'=>'Asia/Kolkata']);
        $entry=$this->receipt('BHTTP-TIMEZONE');
        $this->read($this->unit,'print/receipt/'.$entry->id)->assertOk()->assertJsonPath('installationTimezone','Asia/Kolkata')->assertJsonPath('recordedAt',$entry->occurred_at)->assertDontSee($entry->occurred_at.' UTC');
    }
    public function test_logout_ends_the_session_and_reads_then_require_sign_in(): void {
        $this->post(route('logout'))->assertRedirect('/');$this->assertGuest();
        $this->get('/app-api/units/'.$this->unit.'/students')->assertRedirect(route('login'));
    }
}
