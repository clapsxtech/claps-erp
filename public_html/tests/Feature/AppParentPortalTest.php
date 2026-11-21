<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** The React family portal: host pages, JSON reads and JSON writes keep the classic sign-in rules. Synthetic data only. */
class AppParentPortalTest extends TestCase
{
    private $admin;
    private int $unit;
    private int $student;
    private int $other;
    private int $attendance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'APP-PP', 'name' => 'Portal team', 'timezone' => 'Asia/Kolkata', 'phone' => '+91 98470 10000', 'legal_name' => 'Portal Learning LLP', 'address' => '1 Synthetic Road', 'state' => 'Kerala']);
        $teacher = DB::table('erp_teachers')->insertGetId(['code' => 'APP-PP-T', 'name' => 'Portal Teacher', 'timezone' => 'Asia/Kolkata', 'status' => 'active']);
        DB::table('erp_teacher_units')->insert(['unit_id' => $this->unit, 'teacher_id' => $teacher]);
        $subject = DB::table('erp_subjects')->insertGetId(['name' => 'Portal Maths']);
        $this->student = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'APP-PP-1', 'name' => 'Anya Joseph', 'registered_on' => '2026-01-01', 'timezone' => 'Asia/Kolkata', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE', 'guardian_contact' => '9811122233', 'meeting_url' => 'https://meet.invalid/secret-room']);
        $this->other = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'APP-PP-2', 'name' => 'Other Child', 'registered_on' => '2026-01-01', 'timezone' => 'Asia/Kolkata', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE']);
        DB::table('erp_student_profiles')->insert([['unit_id' => $this->unit, 'student_id' => $this->student, 'date_of_birth' => '2013-04-21', 'father_name' => 'Secret Father'], ['unit_id' => $this->unit, 'student_id' => $this->other, 'date_of_birth' => '2012-01-01', 'father_name' => null]]);
        $enrollment = DB::table('erp_enrollments')->insertGetId(['unit_id' => $this->unit, 'student_id' => $this->student, 'teacher_id' => $teacher, 'subject_id' => $subject, 'starts_on' => '2026-01-01', 'status' => 'active', 'student_rate_paise' => 45000, 'teacher_rate_paise' => 30000, 'currency' => 'INR']);
        $lesson = fn ($at, $status) => DB::table('erp_lessons')->insertGetId(['unit_id' => $this->unit, 'enrollment_id' => $enrollment, 'student_id' => $this->student, 'teacher_id' => $teacher, 'subject_id' => $subject, 'starts_at' => $at, 'ends_at' => date('Y-m-d H:i:s', strtotime($at.' UTC') + 3600), 'status' => $status, 'kind' => 'regular', 'student_rate_paise' => 45000, 'teacher_rate_paise' => 30000, 'currency' => 'INR']);
        $past = $lesson(now('UTC')->subDays(3)->format('Y-m-d 10:00:00'), 'completed');
        $lesson(now('UTC')->addDays(4)->format('Y-m-d 10:00:00'), 'scheduled');
        $this->attendance = DB::table('erp_attendance')->insertGetId(['unit_id' => $this->unit, 'lesson_id' => $past, 'actual_minutes' => 60, 'status' => 'submitted', 'submitted_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_internal_notes')->insert(['unit_id' => $this->unit, 'lesson_id' => $past, 'created_by' => $this->admin->id, 'kind' => 'internal', 'note' => 'Private staff observation', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_ledger')->insert([
            ['unit_id' => $this->unit, 'student_id' => $this->student, 'kind' => 'payment', 'amount_paise' => 360000, 'currency' => 'INR', 'reference' => 'PP-UPI-1', 'event_key' => 'app-pp-1', 'occurred_at' => now(), 'created_by' => $this->admin->id],
            ['unit_id' => $this->unit, 'student_id' => $this->other, 'kind' => 'payment', 'amount_paise' => 99900, 'currency' => 'INR', 'reference' => 'PP-OTHER-PAY', 'event_key' => 'app-pp-2', 'occurred_at' => now(), 'created_by' => $this->admin->id],
        ]);
        DB::table('erp_marks')->insert(['unit_id' => $this->unit, 'enrollment_id' => $enrollment, 'assessment' => 'Algebra quiz', 'assessed_on' => '2026-10-20', 'score' => 17, 'maximum' => 20, 'notes' => 'Good work', 'created_by' => $this->admin->id]);
        DB::table('erp_payment_requests')->insert([
            ['unit_id' => $this->unit, 'student_id' => $this->student, 'reference' => 'PP-REQ-1', 'amount_paise' => 500000, 'status' => 'open', 'requested_on' => '2026-11-01', 'due_on' => '2026-11-30', 'notes' => 'November classes', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
            ['unit_id' => $this->unit, 'student_id' => $this->other, 'reference' => 'PP-REQ-OTHER', 'amount_paise' => 100000, 'status' => 'open', 'requested_on' => '2026-11-01', 'due_on' => null, 'notes' => null, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $invoice = DB::table('erp_invoices')->insertGetId(['unit_id' => $this->unit, 'student_id' => $this->student, 'invoice_number' => 'PP-INV-1', 'status' => 'issued', 'issue_date' => '2026-11-01', 'currency' => 'INR', 'subtotal_paise' => 38136, 'discount_paise' => 0, 'tax_paise' => 6864, 'total_paise' => 45000, 'billing_name' => 'Joseph family', 'billing_contact' => '9811122233', 'notes' => 'Internal: chase payment', 'created_by' => $this->admin->id, 'document_type' => 'tax_invoice', 'billing_period' => '2026-10', 'place_of_supply' => 'Kerala', 'cgst_paise' => 3432, 'sgst_paise' => 3432, 'igst_paise' => 0, 'supplier_tax_id' => '32ABCDE1234F1Z5']);
        DB::table('erp_invoice_lines')->insert(['unit_id' => $this->unit, 'invoice_id' => $invoice, 'description' => 'Maths classes', 'quantity' => 1, 'unit_rate_paise' => 45000, 'amount_paise' => 45000, 'taxable_paise' => 38136, 'tax_paise' => 6864, 'sac' => '999293']);
    }

    private function signIn(string $code = 'APP-PP-1', string $dob = '2013-04-21')
    {
        return $this->postJson('/parent/login', ['student_code' => $code, 'date_of_birth' => $dob]);
    }

    private function embedded(string $html): array
    {
        $start = strpos($html, '<script type="application/json" id="erp-session">') + strlen('<script type="application/json" id="erp-session">');
        return json_decode(html_entity_decode(substr($html, $start, strpos($html, '</script>', $start) - $start)), true);
    }

    public function test_host_pages_need_the_portal_session_and_embed_only_the_family_header(): void
    {
        foreach (['/parent', '/parent/classes', '/parent/fees', '/parent/statement?month=2026-11', '/parent/documents/receipt/1'] as $url) $this->get($url)->assertRedirect('/parent/login');
        $login = $this->get('/parent/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(['kind' => 'parent', 'signedIn' => false, 'status' => null], $this->embedded($login->getContent()));

        $this->signIn(' app-pp-1 ')->assertOk()->assertExactJson(['redirect' => '/parent']);
        $this->get('/parent/login')->assertRedirect(route('parent.home'));
        $page = $this->get('/parent/fees')->assertOk()->assertSee('id="erp-root"', false);
        $session = $this->embedded($page->getContent());
        $this->assertSame(['kind', 'signedIn', 'status', 'student', 'centre'], array_keys($session));
        $this->assertSame(['name' => 'Anya Joseph', 'code' => 'APP-PP-1', 'grade' => 'Grade 8', 'syllabus' => 'CBSE', 'timezone' => 'Asia/Kolkata'], $session['student']);
        $this->assertSame(['name' => 'Portal team', 'phone' => '+91 98470 10000'], $session['centre']);
        foreach (['9811122233', '2013-04-21', 'secret-room', 'Secret Father', 'Other Child'] as $secret) $page->assertDontSee($secret, false);
        $this->get('/parent/nonsense')->assertNotFound();
    }

    public function test_json_reads_are_whitelisted_and_scoped_to_the_signed_in_child(): void
    {
        foreach (['/parent-api/sections/overview', '/parent-api/sections/fees', '/parent-api/statement?month=2026-11', '/parent-api/documents/receipt/1'] as $url) $this->getJson($url)->assertStatus(401);
        $this->signIn()->assertOk();

        $overview = $this->getJson('/parent-api/sections/overview')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame(['balancePaise', 'openRequests', 'upcoming', 'results'], array_keys($overview));
        $this->assertSame(360000, $overview['balancePaise']);
        $this->assertSame(1, $overview['openRequests']);
        $this->assertSame(['id', 'startsAt', 'endsAt', 'subject', 'teacher', 'kind'], array_keys($overview['upcoming'][0]));
        $this->assertStringEndsWith('Z', $overview['upcoming'][0]['startsAt']);
        $this->assertSame(['id', 'assessedOn', 'subject', 'assessment', 'score', 'maximum', 'note'], array_keys($overview['results'][0]));

        $classes = $this->getJson('/parent-api/sections/classes')->assertOk();
        $this->assertSame(['id', 'startsAt', 'endsAt', 'subject', 'teacher', 'kind', 'status', 'attendanceId', 'minutes', 'beingChecked', 'querySent'], array_keys($classes->json('past.0')));
        $this->assertSame(60, $classes->json('past.0.minutes'));
        $classes->assertDontSee('Private staff observation')->assertDontSee('30000')->assertDontSee('45000');

        $results = $this->getJson('/parent-api/sections/results')->assertOk()->assertJsonPath('data.0.assessment', 'Algebra quiz')->assertJsonPath('data.0.note', 'Good work')->assertJsonPath('meta.total', 1);
        $this->getJson('/parent-api/sections/results?page=0')->assertStatus(422);

        $fees = $this->getJson('/parent-api/sections/fees')->assertOk();
        $this->assertSame(['balancePaise', 'requests', 'invoices', 'receipts', 'months'], array_keys($fees->json()));
        $this->assertSame(['id', 'reference', 'requestedPaise', 'paidPaise', 'outstandingPaise', 'dueOn', 'status'], array_keys($fees->json('requests.0')));
        $fees->assertJsonPath('requests.0.status', 'pending');
        $this->assertSame(['id', 'number', 'issueDate', 'period', 'totalPaise', 'isCreditNote', 'isCancelled'], array_keys($fees->json('invoices.0')));
        $this->assertSame(['id', 'date', 'reference', 'isRefund', 'amountPaise', 'isWithdrawn'], array_keys($fees->json('receipts.0')));
        $fees->assertJsonPath('receipts.0.reference', 'PP-UPI-1')->assertDontSee('PP-OTHER-PAY')->assertDontSee('PP-REQ-OTHER')->assertDontSee('Internal: chase payment')->assertDontSee('9811122233');
        $this->getJson('/parent-api/sections/secret')->assertNotFound();
    }

    public function test_documents_and_statement_match_the_classic_data_and_refuse_other_families(): void
    {
        $this->signIn()->assertOk();
        $mine = DB::table('erp_ledger')->where('reference', 'PP-UPI-1')->value('id');
        $theirs = DB::table('erp_ledger')->where('reference', 'PP-OTHER-PAY')->value('id');
        $this->get('/parent/documents/receipt/'.$mine)->assertOk()->assertSee('id="erp-root"', false);
        $this->get('/parent/documents/receipt/'.$theirs)->assertNotFound();
        $this->getJson('/parent-api/documents/receipt/'.$theirs)->assertNotFound();
        $receipt = $this->getJson('/parent-api/documents/receipt/'.$mine)->assertOk()->json();
        $this->assertSame(['type' => 'receipt', 'centre' => ['name' => 'Portal team', 'phone' => '+91 98470 10000'], 'receipt' => ['reference' => 'PP-UPI-1', 'isRefund' => false, 'amountPaise' => 360000, 'date' => now()->format('Y-m-d'), 'isWithdrawn' => false]], $receipt);

        $request = DB::table('erp_payment_requests')->where('reference', 'PP-REQ-1')->value('id');
        $other = DB::table('erp_payment_requests')->where('reference', 'PP-REQ-OTHER')->value('id');
        $this->getJson('/parent-api/documents/request/'.$other)->assertNotFound();
        $json = $this->getJson('/parent-api/documents/request/'.$request)->assertOk()->json('request');
        $this->assertSame(['reference' => 'PP-REQ-1', 'requestedPaise' => 500000, 'paidPaise' => 0, 'outstandingPaise' => 500000, 'dueOn' => '2026-11-30', 'status' => 'pending', 'notes' => 'November classes'], $json);

        $invoice = DB::table('erp_invoices')->value('id');
        $json = $this->getJson('/parent-api/documents/invoice/'.$invoice)->assertOk()->json('invoice');
        $this->assertTrue($json['hasGst']);
        $this->assertSame('Tax invoice', $json['title']);
        $this->assertSame('Portal Learning LLP', $json['supplier']['name']);
        $this->assertSame([['id' => DB::table('erp_invoice_lines')->value('id'), 'description' => 'Maths classes', 'sac' => '999293', 'taxablePaise' => 38136, 'amountPaise' => 45000]], $json['lines']);
        $this->assertNull($json['creditReason']);
        $this->assertStringNotContainsString('chase payment', json_encode($json));
        $this->assertStringNotContainsString('9811122233', json_encode($json));
        $this->get('/parent/documents/invoice/999999')->assertNotFound();

        $month = now('Asia/Kolkata')->subDays(3)->format('Y-m');
        $this->get('/parent/statement?month='.$month)->assertOk();
        $statement = $this->getJson('/parent-api/statement?month='.$month)->assertOk()->json();
        $this->assertSame(['id', 'startsAt', 'teacher', 'subject', 'isDemo', 'minutes', 'chargePaise'], array_keys($statement['rows'][0]));
        $this->assertSame(45000, $statement['rows'][0]['chargePaise']);
        $this->assertSame(60, $statement['totalMinutes']);
        $this->assertSame(['openingPaise', 'lines', 'closingPaise'], array_keys($statement['account']));
        $this->assertStringNotContainsString('student_rate', json_encode($statement));
        $this->getJson('/parent-api/statement?month=nope')->assertStatus(422);
    }

    public function test_json_sign_in_keeps_the_lockouts_and_sign_out_ends_the_session(): void
    {
        $this->signIn('APP-PP-1', '2013-04-22')->assertStatus(422)->assertJsonPath('errors.student_code.0', 'The student ID and date of birth do not match our records.');
        for ($i = 0; $i < 4; $i++) $this->signIn('APP-PP-1', '2000-01-01')->assertStatus(422);
        $this->signIn()->assertStatus(422)->assertJsonPath('errors.student_code.0', 'Too many attempts. Please wait 15 minutes, or contact the centre.');
        $this->getJson('/parent-api/sections/overview')->assertStatus(401);
        $this->signIn('APP-PP-2', '2012-01-01')->assertOk();
        $this->getJson('/parent-api/sections/fees')->assertOk()->assertJsonPath('receipts.0.reference', 'PP-OTHER-PAY')->assertDontSee('PP-UPI-1');

        $this->postJson('/parent/logout')->assertOk()->assertExactJson(['redirect' => '/parent/login']);
        $this->assertSame('You have signed out.', $this->embedded($this->get('/parent/login')->getContent())['status']);
        $this->getJson('/parent-api/sections/overview')->assertStatus(401);
        $this->postJson('/parent/login', ['student_code' => 'APP-PP-1'])->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
    }

    public function test_idling_or_a_changed_date_of_birth_returns_401(): void
    {
        $this->signIn()->assertOk();
        $this->getJson('/parent-api/sections/overview')->assertOk();
        $this->travel(31)->minutes();
        $this->getJson('/parent-api/sections/overview')->assertStatus(401);
        $this->get('/parent')->assertRedirect('/parent/login');
        $this->travelBack();
        $this->signIn()->assertOk();
        DB::table('erp_student_profiles')->where('student_id', $this->student)->update(['date_of_birth' => '2013-05-21']);
        $this->getJson('/parent-api/sections/fees')->assertStatus(401);
    }

    public function test_document_variants_withdrawn_receipt_cancelled_invoice_and_credit_note(): void
    {
        $this->signIn()->assertOk();
        $payment = DB::table('erp_ledger')->where('reference', 'PP-UPI-1')->value('id');
        DB::table('erp_ledger')->insert(['unit_id' => $this->unit, 'student_id' => $this->student, 'kind' => 'reversal', 'amount_paise' => -360000, 'currency' => 'INR', 'reference' => 'PP-REV-1', 'event_key' => 'app-pp-rev', 'reverses_id' => $payment, 'occurred_at' => now(), 'created_by' => $this->admin->id]);
        $this->getJson('/parent-api/documents/receipt/'.$payment)->assertOk()->assertJsonPath('receipt.isWithdrawn', true);
        $this->getJson('/parent-api/sections/fees')->assertJsonPath('receipts.0.isWithdrawn', true);

        $original = DB::table('erp_invoices')->where('invoice_number', 'PP-INV-1')->value('id');
        DB::table('erp_invoices')->where('id', $original)->update(['status' => 'cancelled', 'igst_paise' => 6864, 'cgst_paise' => 0, 'sgst_paise' => 0]);
        $credit = DB::table('erp_invoices')->insertGetId(['unit_id' => $this->unit, 'student_id' => $this->student, 'invoice_number' => 'PP-CN-1', 'status' => 'issued', 'issue_date' => '2026-11-05', 'currency' => 'INR', 'subtotal_paise' => 38136, 'discount_paise' => 0, 'tax_paise' => 6864, 'total_paise' => 45000, 'billing_name' => 'Joseph family', 'notes' => 'Class was cancelled by the teacher', 'created_by' => $this->admin->id, 'document_type' => 'credit_note', 'credits_invoice_id' => $original, 'supplier_tax_id' => null]);
        $cancelled = $this->getJson('/parent-api/documents/invoice/'.$original)->assertOk()->json('invoice');
        $this->assertSame(['title', 'number', 'isCreditNote', 'isCancelled', 'issueDate', 'periodLabel', 'credited', 'supplier', 'billingName', 'placeOfSupply', 'hasGst', 'gstRatePercent', 'lines', 'subtotalPaise', 'igstPaise', 'cgstPaise', 'sgstPaise', 'totalPaise', 'amountInWords', 'creditReason'], array_keys($cancelled));
        $this->assertTrue($cancelled['isCancelled']);
        $this->assertSame(6864, $cancelled['igstPaise']);
        $note = $this->getJson('/parent-api/documents/invoice/'.$credit)->assertOk()->json('invoice');
        $this->assertSame('Credit note', $note['title']);
        $this->assertFalse($note['hasGst'], 'A credit note without a supplier GSTIN shows no tax lines, as on the classic page.');
        $this->assertSame(['number' => 'PP-INV-1', 'issueDate' => '2026-11-01'], $note['credited']);
        $this->assertSame('Class was cancelled by the teacher', $note['creditReason']);
        $this->getJson('/parent-api/sections/fees')->assertJsonPath('invoices.0.number', 'PP-CN-1')->assertJsonPath('invoices.0.isCreditNote', true)->assertJsonPath('invoices.1.isCancelled', true);
    }

    public function test_json_dispute_holds_the_class_and_refuses_bad_requests(): void
    {
        $this->postJson('/parent/disputes', ['attendance_id' => $this->attendance, 'note' => 'The class was only 40 minutes long'])->assertStatus(401);
        $this->signIn()->assertOk();
        $this->postJson('/parent/disputes', ['attendance_id' => $this->attendance, 'note' => 'Too short'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson('/parent/disputes', ['attendance_id' => $this->attendance + 999, 'note' => 'Not my class at all, surely'])->assertStatus(422)->assertJsonValidationErrors('attendance_id');
        $res = $this->postJson('/parent/disputes', ['attendance_id' => $this->attendance, 'note' => 'The class was only 40 minutes long'])->assertOk();
        $this->assertSame(['message', 'held', 'attendanceId'], array_keys($res->json()));
        $this->assertTrue($res->json('held'));
        $this->assertStringContainsString('will not be billed until it is resolved', $res->json('message'));
        $this->assertSame('disputed', DB::table('erp_attendance')->find($this->attendance)->parent_review_status);
        $this->assertSame(1, DB::table('erp_parent_requests')->where('attendance_id', $this->attendance)->where('status', 'open')->count());
        $this->getJson('/parent-api/sections/classes')->assertJsonPath('past.0.beingChecked', true)->assertJsonPath('past.0.querySent', true);
        $this->postJson('/parent/disputes', ['attendance_id' => $this->attendance, 'note' => 'Sending the same thing again'])->assertStatus(422)->assertJsonValidationErrors('attendance_id');
        RateLimiter::clear('parent-dispute:'.$this->student);
    }
}
