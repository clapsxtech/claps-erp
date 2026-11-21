<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Payments & dues, Accounts & tax and Tuition JSON reads keep the classic role matrix. Synthetic data only. */
class AppMoneyApiTest extends TestCase
{
    private int $unit;
    private int $other;
    private int $student;
    private int $clear;
    private int $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'MNY-A', 'name' => 'Money Unit A', 'timezone' => 'UTC']);
        $this->other = DB::table('erp_units')->insertGetId(['code' => 'MNY-B', 'name' => 'Private Money Unit B', 'timezone' => 'UTC']);
        $this->student = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'MNY-S1', 'name' => 'Synthetic Owing Student', 'registered_on' => '2026-01-05', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE']);
        $this->clear = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'MNY-S2', 'name' => 'Synthetic Clear Student', 'registered_on' => '2026-01-06', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '9', 'syllabus' => 'ICSE']);
        $secret = DB::table('erp_students')->insertGetId(['unit_id' => $this->other, 'code' => 'MNY-SECRET', 'name' => 'Private Other Student', 'registered_on' => '2026-01-01', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE']);
        $this->teacher = DB::table('erp_teachers')->insertGetId(['code' => 'MNY-T1', 'name' => 'Synthetic Teacher', 'timezone' => 'UTC', 'status' => 'active']);
        DB::table('erp_teacher_units')->insert(['unit_id' => $this->unit, 'teacher_id' => $this->teacher]);
        $this->ledger(['student_id' => $this->student, 'kind' => 'lesson_charge', 'amount_paise' => -45000, 'reference' => 'MNY-CHG-1', 'occurred_at' => '2026-03-01 10:00:00']);
        $this->ledger(['student_id' => $this->clear, 'kind' => 'payment', 'amount_paise' => 20000, 'reference' => 'MNY-PAY-1', 'occurred_at' => '2026-03-02 10:00:00']);
        $this->ledger(['teacher_id' => $this->teacher, 'kind' => 'teacher_accrual', 'amount_paise' => 30000, 'reference' => 'MNY-ACC-1', 'occurred_at' => '2026-03-03 10:00:00']);
        $this->ledger(['unit_id' => $this->other, 'student_id' => $secret, 'kind' => 'lesson_charge', 'amount_paise' => -99900, 'reference' => 'MNY-SECRET-CHG', 'occurred_at' => '2026-03-01 10:00:00']);
        DB::table('erp_payment_requests')->insert(['unit_id' => $this->unit, 'student_id' => $this->student, 'reference' => 'MNY-REQ-1', 'amount_paise' => 45000, 'status' => 'pending', 'requested_on' => '2026-03-01', 'created_by' => 1]);
    }

    private function ledger(array $row): int
    {
        return DB::table('erp_ledger')->insertGetId(array_merge(['unit_id' => $this->unit, 'currency' => 'INR', 'event_key' => $row['reference'], 'created_by' => 1], $row));
    }

    private function member(string $role, ?int $unit = null)
    {
        $user = $this->createMember(['name' => 'Synthetic '.$role]);
        DB::table('erp_memberships')->insert(['unit_id' => $unit ?? $this->unit, 'user_id' => $user->id, 'role' => $role]);
        return $user;
    }

    private function api(string $path): string
    {
        return '/app-api/units/'.$this->unit.$path;
    }

    public function test_money_endpoints_allow_finance_and_refuse_academics_and_other_units(): void
    {
        $finance = $this->member('finance');
        $academics = $this->member('academics');
        foreach (['/money/summary', '/money/dues', '/money/students', '/money/ledger', '/money/requests', '/money/teachers'] as $path) {
            $this->actingAs($finance)->getJson($this->api($path))->assertOk();
            $this->actingAs($academics)->getJson($this->api($path))->assertForbidden();
            $this->actingAs($finance)->getJson('/app-api/units/'.$this->other.$path)->assertForbidden();
        }
        $this->actingAs($this->member('sales_followups'))->getJson($this->api('/money/ledger'))->assertForbidden();
        $this->actingAs($this->member('read_only'))->getJson($this->api('/money/ledger'))->assertOk();
    }

    public function test_summary_and_dues_queue_list_only_owing_students_with_explicit_fields(): void
    {
        $this->actingAs($this->member('finance'));
        $summary = $this->getJson($this->api('/money/summary'))->assertOk()->json();
        $this->assertSame(['dueCount' => 1, 'duePaise' => -45000, 'openRequestCount' => 1, 'outstandingRequestPaise' => 45000, 'teachersOwedCount' => 1, 'teacherPayablePaise' => 30000], $summary['totals']);
        $this->assertSame(['openProfile' => false, 'openTuition' => false], $summary['can']);

        $dues = $this->getJson($this->api('/money/dues'))->assertOk()->json();
        $this->assertSame(1, $dues['meta']['total']);
        $this->assertSame(['id', 'code', 'name', 'grade', 'status', 'registeredOn', 'priority', 'balancePaise'], array_keys($dues['data'][0]));
        $this->assertSame(-45000, $dues['data'][0]['balancePaise']);
        $this->getJson($this->api('/money/dues?q=Clear'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->api('/money/dues?sort=password'))->assertStatus(422);

        $picker = $this->getJson($this->api('/money/students?id='.$this->clear))->assertOk()->json('data');
        $this->assertSame([['id' => $this->clear, 'code' => 'MNY-S2', 'name' => 'Synthetic Clear Student', 'status' => 'active', 'balancePaise' => 20000]], $picker);
        $this->getJson($this->api('/money/students?q=MNY-SECRET'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ledger_filters_totals_running_balance_and_field_whitelist(): void
    {
        $this->actingAs($this->member('finance'));
        $json = $this->getJson($this->api('/money/ledger'))->assertOk()->json();
        $this->assertSame(3, $json['meta']['total']);
        $this->assertSame(['id', 'occurredAt', 'kind', 'reference', 'amountPaise', 'currency', 'studentId', 'studentName', 'studentCode', 'teacherId', 'teacherName', 'reversesId', 'reversedById', 'runningPaise'], array_keys($json['data'][0]));
        $this->assertSame('MNY-ACC-1', $json['data'][0]['reference']);
        $this->assertSame(['entries' => 3, 'creditsPaise' => 50000, 'debitsPaise' => -45000, 'netPaise' => 5000], $json['totals']);
        // Newest first; running net counts every filtered row up to and including each one.
        $this->assertSame([5000, -25000, -45000], array_column($json['data'], 'runningPaise'));
        $this->assertNotContains('MNY-SECRET-CHG', array_column($json['data'], 'reference'));

        $this->getJson($this->api('/money/ledger?kind=payment'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reference', 'MNY-PAY-1');
        $this->getJson($this->api('/money/ledger?student='.$this->student))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('totals.netPaise', -45000);
        $this->getJson($this->api('/money/ledger?teacher='.$this->teacher))->assertOk()->assertJsonPath('data.0.teacherName', 'Synthetic Teacher');
        $this->getJson($this->api('/money/ledger?from=2026-03-02&to=2026-03-02'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.runningPaise', 20000);
        $page2 = $this->getJson($this->api('/money/ledger?per_page=25&page=1&sort=occurred_at&dir=asc'))->assertOk()->json();
        $this->assertSame([-45000, -25000, 5000], array_column($page2['data'], 'runningPaise'));
        $this->getJson($this->api('/money/ledger?q=Owing'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->api('/money/ledger?kind=delete'))->assertStatus(422);
        $this->getJson($this->api('/money/ledger?from=yesterday'))->assertStatus(422);
    }

    public function test_payment_posted_through_classic_json_route_appears_in_ledger_with_exact_paise(): void
    {
        $finance = $this->member('finance');
        $this->actingAs($finance)->postJson('/erp/units/'.$this->unit.'/payment', ['student_id' => $this->student, 'amount_inr' => '100.50', 'reference' => 'MNY-APP-RECEIPT'])->assertOk();
        $json = $this->getJson($this->api('/money/ledger?kind=payment&student='.$this->student))->assertOk()->json();
        $this->assertSame(1, $json['meta']['total']);
        $this->assertSame(10050, $json['data'][0]['amountPaise']);
        $this->assertSame('MNY-APP-RECEIPT', $json['data'][0]['reference']);
        $this->assertNull($json['data'][0]['reversedById']);
        $this->getJson($this->api('/money/dues'))->assertJsonPath('data.0.balancePaise', -45000 + 10050);

        // A reversal through the classic route marks the original as reversed; nothing is deleted.
        $entry = $json['data'][0]['id'];
        $this->postJson('/erp/units/'.$this->unit.'/business-actions/receipt-reverse', ['entry_id' => $entry, 'reason' => 'Synthetic mistake'])->assertOk();
        $after = $this->getJson($this->api('/money/ledger?student='.$this->student))->assertOk()->json();
        $this->assertSame(3, $after['meta']['total']);
        $original = collect($after['data'])->firstWhere('id', $entry);
        $this->assertNotNull($original['reversedById']);
        $this->assertSame(-45000, $after['totals']['netPaise']);
    }

    public function test_requests_and_teacher_pay_use_reporting_figures(): void
    {
        $this->actingAs($this->member('finance'));
        $requests = $this->getJson($this->api('/money/requests'))->assertOk()->json();
        $this->assertSame(['id', 'reference', 'studentId', 'studentName', 'studentCode', 'requestedOn', 'dueOn', 'requestedPaise', 'coveredPaise', 'outstandingPaise', 'status'], array_keys($requests['data'][0]));
        $this->assertSame(['pending', 45000, 0, 45000], [$requests['data'][0]['status'], $requests['data'][0]['requestedPaise'], $requests['data'][0]['coveredPaise'], $requests['data'][0]['outstandingPaise']]);
        $this->getJson($this->api('/money/requests?status=cancelled'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->api('/money/requests?status=fulfilled'))->assertStatus(422);

        $teachers = $this->getJson($this->api('/money/teachers'))->assertOk()->json();
        $this->assertSame(['id', 'code', 'name', 'status', 'earnedPaise', 'paidPaise', 'otherAdjustmentsPaise', 'netPayablePaise'], array_keys($teachers['data'][0]));
        $this->assertSame([30000, 0, 0, 30000], [$teachers['data'][0]['earnedPaise'], $teachers['data'][0]['paidPaise'], $teachers['data'][0]['otherAdjustmentsPaise'], $teachers['data'][0]['netPayablePaise']]);
        $this->getJson($this->api('/money/teachers?q=nobody'))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_accounts_endpoints_role_matrix_and_balanced_books(): void
    {
        $finance = $this->member('finance');
        $academics = $this->member('academics');
        foreach (['/accounts/overview', '/accounts/chart', '/accounts/journals', '/accounts/ledger', '/accounts/reports', '/accounts/invoices', '/accounts/tax'] as $path) {
            $this->actingAs($academics)->getJson($this->api($path))->assertForbidden();
            $this->actingAs($finance)->getJson('/app-api/units/'.$this->other.$path)->assertForbidden();
            $this->actingAs($finance)->getJson($this->api($path))->assertOk();
        }
        $this->actingAs($finance);
        $overview = $this->getJson($this->api('/accounts/overview?from=2026-01-01&to=2026-12-31'))->assertOk()->json();
        $this->assertTrue($overview['trial']['isBalanced']);
        $this->assertSame($overview['trial']['debitPaise'], $overview['trial']['creditPaise']);
        $this->assertGreaterThan(0, $overview['trial']['debitPaise']);
        $this->assertSame(['id', 'code', 'name', 'type', 'debitPaise', 'creditPaise'], array_keys($overview['trial']['rows'][0]));
        $this->assertSame(0, $overview['pendingPosts']);
        $this->assertTrue($overview['can']['books-journal']);

        $chart = $this->getJson($this->api('/accounts/chart'))->assertOk()->json('data');
        $this->assertSame(['id', 'code', 'name', 'type', 'isSystem', 'isActive'], array_keys($chart[0]));

        // A manual journal through the classic action route shows up balanced; reversal is a new entry.
        $bank = collect($chart)->firstWhere('code', '1000')['id'];
        $opening = collect($chart)->firstWhere('code', '3000')['id'];
        $this->postJson('/erp/units/'.$this->unit.'/business-actions/books-journal', ['entry_date' => now()->toDateString(), 'memo' => 'Synthetic capital', 'lines' => [['account_id' => $bank, 'debit_inr' => '250.25'], ['account_id' => $opening, 'credit_inr' => '250.25']]])->assertOk();
        $journals = $this->getJson($this->api('/accounts/journals?source=manual'))->assertOk()->json();
        $this->assertSame(1, $journals['meta']['total']);
        $this->assertSame(['id', 'entryDate', 'reference', 'memo', 'source', 'reversesId', 'isReversed', 'debitPaise', 'creditPaise', 'lines'], array_keys($journals['data'][0]));
        $this->assertSame([25025, 25025, false], [$journals['data'][0]['debitPaise'], $journals['data'][0]['creditPaise'], $journals['data'][0]['isReversed']]);
        $this->postJson('/erp/units/'.$this->unit.'/business-actions/books-journal-reverse', ['entry_id' => $journals['data'][0]['id'], 'reason' => 'Synthetic test'])->assertOk();
        $reversed = $this->getJson($this->api('/accounts/journals?source=manual'))->assertOk()->json();
        $this->assertSame(2, $reversed['meta']['total']);
        $this->assertTrue(collect($reversed['data'])->firstWhere('id', $journals['data'][0]['id'])['isReversed']);

        $ledger = $this->getJson($this->api('/accounts/ledger?account_id='.$bank.'&from=2026-01-01&to='.now()->toDateString()))->assertOk()->json();
        $this->assertSame('1000', $ledger['account']['code']);
        $this->assertSame(['entryId', 'entryDate', 'reference', 'memo', 'studentName', 'teacherName', 'debitPaise', 'creditPaise', 'runningPaise'], array_keys($ledger['lines'][0]));
        $this->getJson($this->api('/accounts/ledger?from=not-a-date'))->assertStatus(422);
        $reports = $this->getJson($this->api('/accounts/reports?from=2026-01-01&to=2026-12-31'))->assertOk()->json();
        $this->assertSame($reports['sheet']['assetsPaise'], $reports['sheet']['liabilitiesEquityPaise']);
        $this->getJson($this->api('/accounts/invoices?status=void'))->assertStatus(422);
        $this->getJson($this->api('/accounts/invoices'))->assertOk()->assertJsonPath('uninvoiced.charges', 1)->assertJsonPath('uninvoiced.totalPaise', 45000);
        $this->getJson($this->api('/accounts/tax?month=2026-03'))->assertOk()->assertJsonPath('month', '2026-03');

        // An invoice run through the classic action lists the invoice and the tax summary by state.
        $this->postJson('/erp/units/'.$this->unit.'/business-actions/books-invoice-run', ['month' => '2026-03'])->assertOk();
        $invoices = $this->getJson($this->api('/accounts/invoices'))->assertOk()->json();
        $this->assertSame(1, $invoices['meta']['total']);
        $this->assertSame(['id', 'number', 'documentType', 'issueDate', 'billingPeriod', 'studentName', 'studentCode', 'taxablePaise', 'taxPaise', 'totalPaise', 'status'], array_keys($invoices['data'][0]));
        $this->assertSame([45000, 'issued', 'Synthetic Owing Student'], [$invoices['data'][0]['totalPaise'], $invoices['data'][0]['status'], $invoices['data'][0]['studentName']]);
        $this->assertSame(['charges' => 0, 'totalPaise' => 0], $invoices['uninvoiced']);
        $this->getJson($this->api('/accounts/invoices?status=cancelled'))->assertOk()->assertJsonPath('meta.total', 0);
        $tax = $this->getJson($this->api('/accounts/tax?month=2026-03'))->assertOk()->json();
        $this->assertSame(['state', 'documents', 'taxablePaise', 'taxPaise'], array_keys($tax['byState'][0]));
        $this->assertSame(45000, $tax['issued']['taxablePaise']);
    }

    public function test_read_only_sees_accounts_but_cannot_act(): void
    {
        $json = $this->actingAs($this->member('read_only'))->getJson($this->api('/accounts/overview'))->assertOk()->json();
        $this->assertSame(['books-account' => false, 'books-journal' => false, 'books-journal-reverse' => false, 'books-invoice-run' => false, 'books-invoice-cancel' => false], $json['can']);
    }

    public function test_tuition_keeps_its_own_explicit_role_check(): void
    {
        foreach (['finance', 'academics', 'read_only', 'sales_followups'] as $role) {
            $this->actingAs($this->member($role))->getJson($this->api('/tuition'))->assertForbidden();
        }
        $manager = $this->member('team_manager');
        $json = $this->actingAs($manager)->getJson($this->api('/tuition'))->assertOk()->json();
        $this->assertSame(['timezone', 'parentRequests', 'students', 'pending', 'pendingMeta', 'lessons', 'shortfalls', 'notes', 'candidates', 'can'], array_keys($json));
        $this->assertSame(['Synthetic Clear Student', 'Synthetic Owing Student'], array_column($json['students'], 'name'));
        $this->assertSame(['id', 'name', 'code'], array_keys($json['students'][0]));
        $this->getJson('/app-api/units/'.$this->other.'/tuition')->assertForbidden();
        $this->assertTrue($this->actingAs($manager)->getJson($this->api('/money/summary'))->json('can.openTuition'));
        $this->actingAs($this->createAdmin())->getJson($this->api('/tuition'))->assertOk();
    }

    public function test_tuition_rows_list_explicit_fields_and_classic_writes_round_trip(): void
    {
        $manager = $this->member('team_manager');
        $lesson = DB::table('erp_lessons')->insertGetId(['unit_id' => $this->unit, 'enrollment_id' => 1, 'student_id' => $this->student, 'teacher_id' => $this->teacher, 'subject_id' => 1, 'starts_at' => '2026-03-01 10:00:00', 'ends_at' => '2026-03-01 11:00:00', 'status' => 'completed', 'student_rate_paise' => 45000, 'teacher_rate_paise' => 30000]);
        $attendance = DB::table('erp_attendance')->insertGetId(['unit_id' => $this->unit, 'lesson_id' => $lesson, 'actual_minutes' => 45, 'status' => 'submitted', 'submitted_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $query = DB::table('erp_parent_requests')->insertGetId(['unit_id' => $this->unit, 'student_id' => $this->student, 'attendance_id' => $attendance, 'note' => 'Synthetic parent question', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($manager);
        $base = '/erp/units/'.$this->unit.'/tuition/';
        $this->postJson($base.'note', ['lesson_id' => $lesson, 'kind' => 'internal', 'note' => 'Synthetic private note'])->assertOk();
        $this->postJson($base.'candidate', ['name' => 'Synthetic Candidate', 'contact' => 'candidate@example.invalid', 'qualification' => 'MSc', 'subjects' => 'Maths', 'levels' => 'High school'])->assertOk();
        $candidate = DB::table('erp_candidates')->where('unit_id', $this->unit)->value('id');
        $this->postJson($base.'interview', ['candidate_id' => $candidate, 'score' => '80', 'decision' => 'hold', 'strengths' => 'Clear'])->assertOk();

        $json = $this->getJson($this->api('/tuition'))->assertOk()->json();
        $this->assertSame(['id', 'receivedAt', 'studentName', 'studentCode', 'note', 'attendanceId', 'lessonStartsAt', 'actualMinutes', 'attendanceStatus'], array_keys($json['parentRequests'][0]));
        $this->assertSame(['id', 'lessonId', 'studentName', 'actualMinutes', 'actualStartsAt', 'submittedLate', 'parentReviewStatus', 'parentDeadlineAt', 'changeReason'], array_keys($json['pending'][0]));
        $this->assertSame(['id', 'startsAt', 'status', 'kind', 'studentName', 'teacherName', 'needsFollowUp'], array_keys($json['lessons'][0]));
        $this->assertSame([['lessonId' => $lesson, 'studentName' => 'Synthetic Owing Student', 'startsAt' => '2026-03-01 10:00:00', 'plannedMinutes' => 60, 'actualMinutes' => 45]], $json['shortfalls']);
        $this->assertSame(['id', 'lessonId', 'kind', 'outcome', 'note', 'createdAt', 'actorName'], array_keys($json['notes'][0]));
        $this->assertSame(['id', 'name', 'contact', 'qualification', 'subjects', 'levels', 'languages', 'availability', 'status', 'interviews'], array_keys($json['candidates'][0]));
        $this->assertSame(['id', 'score', 'decision', 'strengths', 'concerns', 'recordingUrl', 'createdAt'], array_keys($json['candidates'][0]['interviews'][0]));
        $this->assertSame(['approve' => true, 'write' => true], $json['can']);

        // The classic resolve operation answers with a redirect; the query then leaves the open list.
        $this->post($base.'parent-request-resolve', ['request_id' => $query, 'reply' => 'Synthetic reply'], ['Accept' => 'application/json'])->assertRedirect();
        $this->getJson($this->api('/tuition'))->assertOk()->assertJsonCount(0, 'parentRequests');
    }
}
