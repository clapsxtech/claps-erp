<?php
namespace Tests\Feature;

use App\Modules\Erp\Services\StudentProfile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Synthetic fixtures only; no source import, integration or live ledger writes. */
class ErpStudentProfileTest extends TestCase
{
    private $unit; private $other; private $admin; private $student; private $peer; private $teacher; private $enrollment; private $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
        $this->unit = DB::table('erp_units')->insertGetId(['code'=>'PROFILE-A','name'=>'Profile unit','timezone'=>'Asia/Kolkata']);
        $this->other = DB::table('erp_units')->insertGetId(['code'=>'PROFILE-B','name'=>'Other private unit','timezone'=>'UTC']);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$this->admin->id,'role'=>'admin']);
        $this->student = $this->student('PROFILE-STUDENT', 'Synthetic Profile Student');
        $this->peer = $this->student('PROFILE-PEER', 'Private Peer Student');
        $this->teacher = DB::table('erp_teachers')->insertGetId(['code'=>'PROFILE-TEACHER','name'=>'Synthetic Profile Teacher','timezone'=>'UTC','status'=>'active']);
        DB::table('erp_teacher_units')->insert(['teacher_id'=>$this->teacher,'unit_id'=>$this->unit]);
        $subject = DB::table('erp_subjects')->insertGetId(['name'=>'Profile Mathematics']);
        $this->enrollment = DB::table('erp_enrollments')->insertGetId(['unit_id'=>$this->unit,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$subject,'starts_on'=>'2026-01-01','status'=>'active','student_rate_paise'=>12029,'teacher_rate_paise'=>6025,'currency'=>'INR']);
        DB::table('erp_agreements')->insert(['unit_id'=>$this->unit,'enrollment_id'=>$this->enrollment,'weekday'=>1,'start_time'=>'10:00','duration_minutes'=>60,'timezone'=>'UTC','starts_on'=>'2026-01-01','status'=>'confirmed']);
        $this->lesson = DB::table('erp_lessons')->insertGetId(['unit_id'=>$this->unit,'enrollment_id'=>$this->enrollment,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$subject,'starts_at'=>'2026-10-20 10:00:00','ends_at'=>'2026-10-20 11:00:00','status'=>'completed','kind'=>'regular','student_rate_paise'=>12029,'teacher_rate_paise'=>6025,'currency'=>'INR']);
        DB::table('erp_attendance')->insert(['unit_id'=>$this->unit,'lesson_id'=>$this->lesson,'actual_minutes'=>60,'status'=>'submitted','submitted_by'=>$this->admin->id]);
        $this->actingAs($this->admin);
    }

    private function student(string $code, string $name, ?int $unit = null): int
    {
        return DB::table('erp_students')->insertGetId(['unit_id'=>$unit ?? $this->unit,'code'=>$code,'name'=>$name,'registered_on'=>'2026-01-01','timezone'=>'UTC','status'=>'active','grade'=>'8','syllabus'=>'Synthetic']);
    }
    /** The profile now loads from the Students JSON read. */
    private function url(array $query = [], ?int $unit = null, ?int $student = null): string
    {
        return '/app-api/units/'.($unit ?? $this->unit).'/students/'.($student ?? $this->student).($query ? '?'.http_build_query($query) : '');
    }
    private function profile(array $query = [])
    {
        return $this->getJson($this->url($query));
    }
    private function body($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    private function entry(string $reference, int $amount, array $extra = []): int
    {
        return DB::table('erp_ledger')->insertGetId(array_merge(['unit_id'=>$this->unit,'student_id'=>$this->student,'kind'=>'payment','amount_paise'=>$amount,'currency'=>'INR','reference'=>$reference,'event_key'=>$reference,'occurred_at'=>'2026-10-20 10:00:00','created_by'=>$this->admin->id],$extra));
    }

    public function test_unified_profile_is_named_read_only_and_keeps_financial_boundaries(): void
    {
        $payment = $this->entry('PROFILE-RECEIPT', 20000);
        $this->entry('PROFILE-CHARGE', -12029, ['kind'=>'lesson_charge']);
        $this->entry('PRIVATE-TEACHER-PAY', 6025, ['kind'=>'teacher_accrual','student_id'=>null,'teacher_id'=>$this->teacher]);
        DB::table('erp_followups')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'contact_channel'=>'call','note'=>'Synthetic logged conversation','outcome'=>'Call next week','next_due_on'=>'2026-10-28','created_by'=>$this->admin->id]);
        DB::table('erp_tasks')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'assigned_to'=>$this->admin->id,'title'=>'Synthetic student task','status'=>'open','created_by'=>$this->admin->id]);
        $before = [];
        foreach (['erp_ledger','erp_audit','erp_attendance','erp_tasks','erp_followups'] as $table) $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        $response = $this->profile()->assertOk()
            ->assertJsonPath('student.name', 'Synthetic Profile Student')
            ->assertJsonPath('assignments.0.teacher_name', 'Synthetic Profile Teacher')->assertJsonPath('assignments.0.subject_name', 'Profile Mathematics')
            // Stored in UTC; the client shows it in the team's zone (Asia/Kolkata: 2026-10-20 15:30).
            ->assertJsonPath('lessons.0.starts_at', '2026-10-20 10:00:00')
            ->assertJsonPath('balances', [['currency'=>'INR','balancePaise'=>7971]])
            ->assertJsonPath('attendance.0.status', 'submitted')
            ->assertJsonPath('followups.0.note', 'Synthetic logged conversation')
            ->assertJsonPath('tasks.0.title', 'Synthetic student task');
        // The receipt link is built from a payment row's id; that receipt opens for this unit.
        $this->assertSame([['id'=>$payment,'kind'=>'payment']], collect($response->json('ledger'))->where('kind','payment')->map(fn ($r) => ['id'=>$r['id'],'kind'=>$r['kind']])->values()->all());
        $this->getJson('/app-api/units/'.$this->unit.'/print/receipt/'.$payment)->assertOk()->assertJsonPath('reference', 'PROFILE-RECEIPT');
        $body = $this->body($response);
        foreach (['PRIVATE-TEACHER-PAY','teacher_rate_paise','teacherRatePaise','student_rate_paise','6025','Private Peer Student'] as $hidden) $this->assertStringNotContainsString($hidden, $body);
        $this->profile()->assertOk();
        foreach ($before as $table=>$snapshot) $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table.' was changed by a read');
    }

    public function test_access_requires_active_site_user_and_explicit_unit_admin_membership(): void
    {
        $this->profile()->assertOk();
        $member = $this->createMember();
        foreach (['sales','teacher'] as $role) {
            DB::table('erp_memberships')->updateOrInsert(['unit_id'=>$this->unit,'user_id'=>$member->id],['role'=>$role]);
            $this->actingAs($member)->getJson($this->url())->assertForbidden();
        }
        DB::table('erp_memberships')->updateOrInsert(['unit_id'=>$this->unit,'user_id'=>$member->id],['role'=>'team_manager']);
        $this->actingAs($member)->getJson($this->url())->assertOk();
        $this->actingAs($this->admin)->getJson($this->url([], $this->other))->assertNotFound();
        DB::table('erp_memberships')->insert(['unit_id'=>$this->other,'user_id'=>$this->admin->id,'role'=>'admin']);
        $this->getJson($this->url([], $this->other))->assertNotFound();
        $this->getJson($this->url([], $this->unit, 999999))->assertNotFound();
        $this->admin->forceFill(['status'=>0])->save();
        $this->getJson($this->url())->assertForbidden();
        $this->app['auth']->guard()->logout();
        $this->get($this->url())->assertRedirect(route('login'));
    }

    public function test_cross_unit_and_other_student_rows_are_excluded_even_with_corrupt_references(): void
    {
        $this->entry('PRIVATE-OTHER-UNIT', 88888, ['unit_id'=>$this->other]);
        $this->entry('PRIVATE-OTHER-STUDENT', 77777, ['student_id'=>$this->peer]);
        $this->entry('PRIVATE-MIXED-OWNER', 66666, ['teacher_id'=>$this->teacher]);
        foreach ([[$this->other,$this->student],[$this->unit,$this->peer]] as [$unit,$student]) {
            DB::table('erp_followups')->insert(['unit_id'=>$unit,'student_id'=>$student,'contact_channel'=>'call','note'=>'PRIVATE FOLLOWUP','outcome'=>'Private outcome','created_by'=>$this->admin->id]);
            DB::table('erp_tasks')->insert(['unit_id'=>$unit,'student_id'=>$student,'title'=>'PRIVATE TASK','status'=>'open','created_by'=>$this->admin->id]);
            DB::table('erp_payment_requests')->insert(['unit_id'=>$unit,'student_id'=>$student,'reference'=>'PRIVATE-REQUEST-'.$unit.'-'.$student,'amount_paise'=>88888,'requested_on'=>'2026-10-20','created_by'=>$this->admin->id]);
        }
        DB::table('erp_agreements')->where('enrollment_id',$this->enrollment)->update(['unit_id'=>$this->other]);
        DB::table('erp_attendance')->where('lesson_id',$this->lesson)->update(['unit_id'=>$this->other]);
        $profile = (new StudentProfile())->read($this->unit,$this->student);
        foreach (['ledger','balances','followups','tasks','requests','agreements','attendance'] as $key) $this->assertCount(0,$profile[$key]);
        $response = $this->profile()->assertOk();
        foreach (['ledger','balances','followups','tasks','agreements','attendance'] as $key) $response->assertJsonCount(0, $key);
        $this->assertStringNotContainsString('PRIVATE', $this->body($response));
        DB::table('erp_teacher_units')->where('teacher_id',$this->teacher)->update(['unit_id'=>$this->other]);
        $profile = (new StudentProfile())->read($this->unit,$this->student);
        $this->assertCount(0,$profile['assignments']); $this->assertCount(0,$profile['lessons']);
        $this->profile()->assertOk()->assertJsonCount(0, 'assignments')->assertJsonCount(0, 'lessons');
    }

    public function test_reversals_requests_and_multiple_currencies_do_not_create_false_credit(): void
    {
        $payment = $this->entry('PROFILE-ORIGINAL', 10000);
        $reversal = $this->entry('PROFILE-REVERSAL', -10000, ['kind'=>'reversal','reverses_id'=>$payment]);
        $this->entry('PROFILE-DUE', -12029, ['kind'=>'opening_balance']);
        $this->entry('PROFILE-OTHER-CURRENCY', 999, ['currency'=>'USD']);
        $request = DB::table('erp_payment_requests')->insertGetId(['unit_id'=>$this->unit,'student_id'=>$this->student,'reference'=>'PROFILE-REQUEST','amount_paise'=>10000,'requested_on'=>'2026-10-20','created_by'=>$this->admin->id]);
        DB::table('erp_request_allocations')->insert(['unit_id'=>$this->unit,'request_id'=>$request,'payment_id'=>$payment,'amount_paise'=>10000]);
        $profile = (new StudentProfile())->read($this->unit,$this->student);
        $this->assertSame(-12029,(int)$profile['balances']->firstWhere('currency','INR')->balance_paise);
        $this->assertSame(999,(int)$profile['balances']->firstWhere('currency','USD')->balance_paise);
        $this->assertSame(10000,$profile['requestSummaries'][$request]['outstanding_paise']);
        $response = $this->profile()->assertOk()->assertJsonPath('balances', [['currency'=>'INR','balancePaise'=>-12029],['currency'=>'USD','balancePaise'=>999]]);
        $this->assertSame($payment, collect($response->json('ledger'))->firstWhere('id', $reversal)['reverses_id']);
        $this->getJson('/app-api/units/'.$this->unit.'/print/request/'.$request)->assertOk()->assertJsonPath('reference', 'PROFILE-REQUEST')->assertJsonPath('outstanding', 'INR 100.00');
    }

    public function test_profile_lists_payment_requests_with_outstanding_amounts(): void
    {
        $payment = $this->entry('PROFILE-PAYMENT', 4000);
        $request = DB::table('erp_payment_requests')->insertGetId(['unit_id'=>$this->unit,'student_id'=>$this->student,'reference'=>'PROFILE-REQUEST','amount_paise'=>12029,'requested_on'=>'2026-10-20','due_on'=>'2026-10-30','created_by'=>$this->admin->id]);
        DB::table('erp_request_allocations')->insert(['unit_id'=>$this->unit,'request_id'=>$request,'payment_id'=>$payment,'amount_paise'=>4000]);
        $this->profile()->assertOk()->assertJsonPath('requests', [['id'=>$request,'reference'=>'PROFILE-REQUEST','requestedOn'=>'2026-10-20','dueOn'=>'2026-10-30','requestedPaise'=>12029,'coveredPaise'=>4000,'outstandingPaise'=>8029,'status'=>'pending']])
            ->assertJsonPath('pages.requests.total', 1);
        // The print link the profile offers opens for this unit.
        $this->getJson('/app-api/units/'.$this->unit.'/print/request/'.$request)->assertOk()->assertJsonPath('reference', 'PROFILE-REQUEST');
    }

    public function test_pagination_is_independent_and_balance_covers_all_history(): void
    {
        for ($i=1;$i<=17;$i++) $this->entry('PROFILE-PAGINATION-'.$i,100);
        for ($i=1;$i<=12;$i++) DB::table('erp_followups')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'contact_channel'=>'call','note'=>'Follow-up '.$i,'outcome'=>'Synthetic','created_by'=>$this->admin->id]);
        $balance = [['currency'=>'INR','balancePaise'=>1700]];
        $this->profile()->assertOk()->assertJsonPath('balances', $balance)->assertJsonCount(15, 'ledger')->assertJsonCount(10, 'followups');
        $this->profile(['ledger_page'=>2,'followups_page'=>2])->assertOk()->assertJsonPath('balances', $balance)->assertJsonCount(2, 'ledger')->assertJsonCount(2, 'followups');
        $this->profile(['ledger_page'=>2])->assertOk()->assertJsonCount(2, 'ledger')->assertJsonCount(10, 'followups');
        $this->profile(['ledger_page'=>100])->assertOk()->assertJsonCount(0, 'ledger')->assertJsonPath('balances', $balance);
    }

    public function test_profile_pages_report_totals_and_reject_invalid_page_numbers(): void
    {
        for ($i=1;$i<=17;$i++) $this->entry('PROFILE-PAGES-'.$i,100);
        $this->profile()->assertOk()->assertJsonCount(15, 'ledger')->assertJsonPath('pages.ledger', ['page'=>1,'perPage'=>15,'total'=>17,'param'=>'ledger_page'])->assertJsonPath('pages.followups.total', 0);
        $this->profile(['ledger_page'=>2])->assertOk()->assertJsonCount(2, 'ledger')->assertJsonPath('pages.ledger.page', 2);
        foreach (['0','-1','nope','1000001'] as $page) $this->profile(['ledger_page'=>$page])->assertUnprocessable()->assertJsonValidationErrors('ledger_page');
        $this->profile(['tasks_page'=>'nope'])->assertUnprocessable();
        $this->profile(['ledger_page'=>100])->assertOk()->assertJsonCount(0, 'ledger')->assertJsonPath('pages.ledger.total', 17);
    }

    public function test_empty_sections_and_untrusted_text_are_returned_as_data(): void
    {
        DB::table('erp_students')->where('id',$this->student)->update(['name'=>'<script>alert(1)</script>','meeting_url'=>'javascript:alert(2)']);
        $response = $this->profile()->assertOk()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('student.name', '<script>alert(1)</script>')
            ->assertJsonCount(0, 'followups')->assertJsonCount(0, 'tasks')->assertJsonCount(0, 'ledger')->assertJsonCount(0, 'balances');
    }

    public function test_meeting_address_is_not_offered_as_a_script_link(): void
    {
        DB::table('erp_students')->where('id',$this->student)->update(['meeting_url'=>'javascript:alert(2)']);
        $response = $this->profile()->assertOk()->assertJsonPath('student.meetingUrl', null);
        $this->assertStringNotContainsString('javascript:', $this->body($response));
        DB::table('erp_students')->where('id',$this->student)->update(['meeting_url'=>'meet.example.test/abc']);
        $this->profile()->assertOk()->assertJsonPath('student.meetingUrl', 'https://meet.example.test/abc');
        DB::table('erp_students')->where('id',$this->student)->update(['meeting_url'=>'http://meet.example.test/x']);
        $this->profile()->assertOk()->assertJsonPath('student.meetingUrl', 'http://meet.example.test/x');
    }

    public function test_task_assignee_names_require_membership_in_the_profile_unit(): void
    {
        $outside = $this->createMember(['name'=>'PRIVATE OUTSIDE ASSIGNEE']);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->other,'user_id'=>$outside->id,'role'=>'sales']);
        DB::table('erp_tasks')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'assigned_to'=>$outside->id,'title'=>'Synthetic malformed task','status'=>'open','created_by'=>$this->admin->id]);
        $response = $this->profile()->assertOk()->assertJsonPath('tasks.0.title', 'Synthetic malformed task');
        $this->assertStringNotContainsString('PRIVATE OUTSIDE ASSIGNEE', $this->body($response));
    }

    public function test_task_assignee_names_are_shown_for_unit_members(): void
    {
        $outside = $this->createMember(['name'=>'PRIVATE OUTSIDE ASSIGNEE']);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->other,'user_id'=>$outside->id,'role'=>'sales']);
        DB::table('erp_tasks')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'assigned_to'=>$outside->id,'title'=>'Synthetic malformed task','status'=>'open','created_by'=>$this->admin->id]);
        DB::table('erp_tasks')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'assigned_to'=>$this->admin->id,'title'=>'Synthetic member task','notes'=>'Call after school','status'=>'open','created_by'=>$this->admin->id]);
        $response = $this->profile()->assertOk()
            ->assertJsonPath('tasks.0.title', 'Synthetic member task')->assertJsonPath('tasks.0.assignedTo', $this->admin->name)->assertJsonPath('tasks.0.notes', 'Call after school')
            ->assertJsonPath('tasks.1.title', 'Synthetic malformed task')->assertJsonPath('tasks.1.assignedTo', 'Unassigned or unavailable account');
        $this->assertStringNotContainsString('PRIVATE OUTSIDE ASSIGNEE', $this->body($response));
    }
}
