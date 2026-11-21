<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** INR browser fields are presentation only: synthetic requests still persist integer paise. */
class ErpMoneyInputHttpTest extends TestCase
{
    private $unit;
    private $admin;
    private $student;
    private $teacher;
    private $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'INR-UNIT', 'name' => 'Synthetic Rupee Unit', 'timezone' => 'UTC']);
        DB::table('erp_memberships')->insert(['unit_id' => $this->unit, 'user_id' => $this->admin->id, 'role' => 'admin']);
        $this->student = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'INR-STUDENT', 'name' => 'Synthetic Rupee Student', 'registered_on' => '2026-01-01', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'Synthetic']);
        $this->teacher = DB::table('erp_teachers')->insertGetId(['code' => 'INR-TEACHER', 'name' => 'Synthetic Rupee Teacher', 'timezone' => 'UTC', 'status' => 'active']);
        DB::table('erp_teacher_units')->insert(['unit_id' => $this->unit, 'teacher_id' => $this->teacher]);
        $this->subject = DB::table('erp_subjects')->insertGetId(['name' => 'Synthetic Rupee Subject']);
        DB::table('erp_eligibilities')->insert(['teacher_id' => $this->teacher, 'subject_id' => $this->subject, 'grade' => '8', 'syllabus' => 'Synthetic']);
        $this->actingAs($this->admin);
    }

    public function test_rupee_receipt_posts_exactly_and_retries_match_legacy_paise_payloads(): void
    {
        $url = route('erp.mutate', [$this->unit, 'payment']);
        $payload = ['student_id' => $this->student, 'amount_inr' => '1234.29', 'reference' => 'INR-RECEIPT'];
        $this->postJson($url, $payload + ['return_page' => 'money'])->assertOk();
        $entry = DB::table('erp_ledger')->where('reference', 'INR-RECEIPT')->first();
        $this->assertSame(123429, (int) $entry->amount_paise);
        unset($payload['amount_inr']);
        $this->postJson($url, $payload + ['amount_paise' => 123429])->assertOk()->assertJsonPath('result.id', $entry->id);
        $this->postJson($url, $payload + ['amount_inr' => '1234.29'])->assertOk()->assertJsonPath('result.id', $entry->id);
        $this->postJson($url, $payload + ['amount_inr' => '1234.30'])->assertUnprocessable();
        $this->assertSame(1, DB::table('erp_ledger')->count());
        $this->assertSame(1, DB::table('erp_audit')->where('action', 'payment.recorded')->count());
        $this->getJson(route('app.api.print.receipt', [$this->unit, $entry->id]))->assertOk()->assertJsonPath('amountLabel', 'Received')->assertJsonPath('amount', 'INR 1,234.29');
        $this->getJson(route('app.api.money.ledger', [$this->unit]))->assertOk()->assertJsonPath('data.0.id', (int) $entry->id)->assertJsonPath('data.0.amountPaise', 123429)->assertJsonPath('totals.netPaise', 123429);
    }

    public function test_assignment_forms_accept_decimal_and_zero_rates_without_changing_storage(): void
    {
        $payload = $this->assignment() + ['student_rate_inr' => '120.29', 'teacher_rate_inr' => '0.00'];
        $this->postJson(route('erp.mutate', [$this->unit, 'assign']), $payload)->assertOk();
        $this->assertDatabaseHas('erp_enrollments', ['unit_id' => $this->unit, 'student_rate_paise' => 12029, 'teacher_rate_paise' => 0, 'currency' => 'INR']);
        // The classic workspace listed enrollment rates as INR; no JSON read shows them now (see report). The paise export still does.
        $csv = $this->get(route('erp.export', [$this->unit, 'enrollments']))->assertOk()->streamedContent();
        $this->assertStringContainsString('12029', $csv);
        $this->assertSame(0, DB::table('erp_ledger')->count());
    }

    public function test_rupee_requests_allocations_corrections_and_settlements_preserve_bookkeeping_contract(): void
    {
        $requestPayload = ['student_id' => $this->student, 'amount_inr' => '200.25', 'reference' => 'INR-REQUEST', 'requested_on' => now()->toDateString()];
        $this->business('request-create', $requestPayload)->assertOk();
        $request = DB::table('erp_payment_requests')->where('reference', 'INR-REQUEST')->first();
        $this->assertSame(20025, (int) $request->amount_paise);
        $this->assertSame(0, DB::table('erp_ledger')->count());
        $this->postJson(route('erp.mutate', [$this->unit, 'payment']), ['student_id' => $this->student, 'amount_inr' => '200.25', 'reference' => 'INR-ALLOCATABLE'])->assertOk();
        $receipt = DB::table('erp_ledger')->where('reference', 'INR-ALLOCATABLE')->first();
        $this->business('request-allocate', ['request_id' => $request->id, 'payment_id' => $receipt->id, 'amount_inr' => '100.25'])->assertOk();
        $this->assertDatabaseHas('erp_request_allocations', ['request_id' => $request->id, 'amount_paise' => 10025]);
        $this->getJson(route('app.api.print.request', [$this->unit, $request->id]))->assertOk()->assertJsonPath('requested', 'INR 200.25')->assertJsonPath('covered', 'INR 100.25')->assertJsonPath('outstanding', 'INR 100.00');
        $this->business('receipt-correct', ['entry_id' => $receipt->id, 'amount_inr' => '150.29', 'new_reference' => 'INR-CORRECTED', 'reason' => 'Synthetic amount correction'])->assertOk();
        $this->assertDatabaseHas('erp_ledger', ['reference' => 'INR-CORRECTED', 'amount_paise' => 15029]);
        $this->getJson(route('app.api.print.request', [$this->unit, $request->id]))->assertOk()->assertJsonPath('outstanding', 'INR 200.25');
        DB::table('erp_ledger')->insert(['unit_id' => $this->unit, 'teacher_id' => $this->teacher, 'kind' => 'teacher_accrual', 'amount_paise' => 10025, 'currency' => 'INR', 'reference' => 'INR-EARNED', 'event_key' => 'inr-earned', 'occurred_at' => now(), 'created_by' => $this->admin->id]);
        $settlement = ['teacher_id' => $this->teacher, 'amount_inr' => '40.25', 'reference' => 'INR-PAID', 'paid_on' => now()->toDateString(), 'reason' => 'Synthetic recorded external settlement'];
        $this->business('salary-paid', $settlement)->assertUnprocessable()->assertJsonValidationErrors('already_paid');
        $this->assertSame(0, DB::table('erp_ledger')->where('kind', 'teacher_payment')->count());
        $this->business('salary-paid', $settlement + ['already_paid' => '1'])->assertOk();
        $this->assertDatabaseHas('erp_ledger', ['reference' => 'INR-PAID', 'amount_paise' => -4025]);
        $settlement['reference'] = 'INR-OVERPAYMENT';
        $settlement['amount_inr'] = '60.01';
        $this->business('salary-paid', $settlement + ['already_paid' => '1'])->assertUnprocessable()->assertJsonValidationErrors('amount_paise');
        $this->assertSame(1, DB::table('erp_ledger')->where('kind', 'teacher_payment')->count());
        $this->getJson(route('app.api.money.teachers', [$this->unit]))->assertOk()->assertJsonPath('data.0.id', $this->teacher)
            ->assertJsonPath('data.0.earnedPaise', 10025)->assertJsonPath('data.0.paidPaise', 4025)->assertJsonPath('data.0.netPayablePaise', 6000);
        $this->getJson(route('app.api.money.summary', [$this->unit]))->assertOk()->assertJsonPath('totals.teacherPayablePaise', 6000)->assertJsonPath('totals.teachersOwedCount', 1);
    }

    public function test_invalid_rupees_and_mixed_units_cannot_write_ledger_audit_or_assignments(): void
    {
        foreach (['0', '-1', '+1', '1e2', '1,000.00', '1.001', '01.00', '10000000.01', str_repeat('9', 100), true, false, [], ['10'], 0.25, null] as $invalid) {
            $this->postJson(route('erp.mutate', [$this->unit, 'payment']), ['student_id' => $this->student, 'amount_inr' => $invalid, 'reference' => 'INR-INVALID'])->assertUnprocessable()->assertJsonValidationErrors('amount_inr');
            $this->postJson(route('erp.business.mutate', [$this->unit, 'request-create']), ['student_id' => $this->student, 'amount_inr' => $invalid, 'reference' => 'INR-INVALID-REQUEST', 'requested_on' => now()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('amount_inr');
        }
        foreach ([100, null] as $paise) {
            $this->postJson(route('erp.mutate', [$this->unit, 'payment']), ['student_id' => $this->student, 'amount_inr' => '1.00', 'amount_paise' => $paise, 'reference' => 'INR-MIXED'])->assertUnprocessable()->assertJsonValidationErrors('amount_inr');
        }
        foreach ([['student_rate_inr' => '0.00', 'teacher_rate_paise' => 0], ['student_rate_inr' => '1000000.01', 'teacher_rate_inr' => '0.00'], ['student_rate_inr' => '-0.01', 'teacher_rate_inr' => '0.00']] as $rates) {
            $this->postJson(route('erp.mutate', [$this->unit, 'assign']), $this->assignment() + $rates)->assertUnprocessable()->assertJsonValidationErrors('student_rate_inr');
        }
        $this->assertSame(0, DB::table('erp_ledger')->count());
        $this->assertSame(0, DB::table('erp_audit')->count());
        $this->assertSame(0, DB::table('erp_payment_requests')->count());
        $this->assertSame(0, DB::table('erp_enrollments')->count());
    }

    public function test_a_missing_amount_is_reported_against_the_rupee_box_in_plain_words(): void
    {
        // Forms leave an empty box out of the request; the error must still land on that box and say how to fix it.
        $this->postJson(route('erp.mutate', [$this->unit, 'payment']), ['student_id' => $this->student, 'reference' => 'INR-BLANK'])
            ->assertUnprocessable()->assertJsonPath('errors.amount_inr.0', 'Enter the amount in rupees, like 1500 or 100.50.');
        $this->postJson(route('erp.mutate', [$this->unit, 'payment']), ['student_id' => $this->student, 'amount_inr' => '1,500', 'reference' => 'INR-COMMA'])
            ->assertUnprocessable()->assertJsonPath('errors.amount_inr.0', 'Enter the amount in rupees, like 1500 or 100.50, without commas or a minus sign.');
        $this->assertSame(0, DB::table('erp_ledger')->count());
    }

    public function test_signed_balances_reach_the_app_as_paise_and_exports_stay_paise(): void
    {
        DB::table('erp_ledger')->insert(['unit_id' => $this->unit, 'student_id' => $this->student, 'kind' => 'opening_balance', 'amount_paise' => -123429, 'currency' => 'INR', 'reference' => 'INR-DUE', 'event_key' => 'inr-due', 'occurred_at' => now(), 'created_by' => $this->admin->id]);
        // Signed balances reach the app as integer paise; the client formats them as INR.
        $this->getJson(route('app.api.money.dues', [$this->unit]))->assertOk()->assertJsonPath('data.0.id', $this->student)->assertJsonPath('data.0.balancePaise', -123429);
        $this->getJson(route('app.api.money.summary', [$this->unit]))->assertOk()->assertJsonPath('totals.duePaise', -123429)->assertJsonPath('totals.dueCount', 1);
        $this->getJson(route('app.api.student', [$this->unit, $this->student]))->assertOk()->assertJsonPath('balances.0.balancePaise', -123429);
        $csv = $this->get(route('erp.export', [$this->unit, 'ledger']))->assertOk()->streamedContent();
        $this->assertStringContainsString('amount_paise', $csv);
        $this->assertStringContainsString('-123429', $csv);
        $this->assertStringNotContainsString('INR -1,234.29', $csv);
    }

    public function test_enrollment_hourly_rates_are_readable_in_the_app(): void
    {
        $this->postJson(route('erp.mutate', [$this->unit, 'assign']), $this->assignment() + ['student_rate_inr' => '120.29', 'teacher_rate_inr' => '0.00'])->assertOk();
        // Money roles read the student's hourly rate on the profile and both rates on the teacher's classes.
        $this->getJson('/app-api/units/'.$this->unit.'/students/'.$this->student)->assertOk()->assertJsonPath('assignments.0.studentRatePaise', 12029);
        $this->getJson('/app-api/units/'.$this->unit.'/teachers/'.$this->teacher)->assertOk()
            ->assertJsonPath('classes.0.studentRatePaise', 12029)->assertJsonPath('classes.0.teacherRatePaise', 0);
    }

    private function assignment(): array
    {
        return ['student_id' => $this->student, 'teacher_id' => $this->teacher, 'subject_id' => $this->subject, 'starts_on' => '2026-01-01'];
    }

    private function business(string $action, array $payload)
    {
        return $this->postJson(route('erp.business.mutate', [$this->unit, $action]), $payload + ['return_page' => 'money']);
    }
}
