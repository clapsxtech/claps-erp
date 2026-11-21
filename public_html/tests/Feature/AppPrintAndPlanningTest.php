<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * React print documents, the sales teacher directory, the planning availability
 * read and the From Sheets JSON branch keep the classic access rules and list
 * explicit fields only. Synthetic data only.
 */
class AppPrintAndPlanningTest extends TestCase
{
    private int $unit;
    private int $other;
    private int $student;
    private int $payment;
    private int $invoice;
    private int $request;
    private int $teacher;
    private array $attendance = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'PR-A', 'name' => 'Print Unit A', 'timezone' => 'UTC', 'phone' => '+910000000001', 'legal_name' => 'Print Unit LLP', 'state' => 'Kerala']);
        $this->other = DB::table('erp_units')->insertGetId(['code' => 'PR-B', 'name' => 'Private Unit B', 'timezone' => 'UTC']);
        $this->student = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'PR-S1', 'name' => 'Synthetic Print Student', 'registered_on' => '2026-01-05', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE', 'guardian_contact' => '+910000000077']);
        $this->payment = DB::table('erp_ledger')->insertGetId(['unit_id' => $this->unit, 'student_id' => $this->student, 'kind' => 'payment', 'amount_paise' => 250050, 'currency' => 'INR', 'reference' => 'PR-RCPT-1', 'event_key' => 'pr-rcpt-1', 'occurred_at' => '2026-11-02 10:00:00', 'created_by' => 1]);
        DB::table('erp_ledger')->insert(['unit_id' => $this->unit, 'student_id' => $this->student, 'kind' => 'reversal', 'amount_paise' => -250050, 'currency' => 'INR', 'reference' => 'PR-REV-1', 'event_key' => 'pr-rev-1', 'reverses_id' => $this->payment, 'occurred_at' => '2026-11-03 10:00:00', 'created_by' => 1]);
        $this->invoice = DB::table('erp_invoices')->insertGetId(['unit_id' => $this->unit, 'student_id' => $this->student, 'invoice_number' => 'PR/0001', 'status' => 'issued', 'issue_date' => '2026-11-05', 'currency' => 'INR', 'subtotal_paise' => 50847, 'tax_paise' => 9153, 'total_paise' => 60000, 'billing_name' => 'Synthetic Parent', 'created_by' => 1, 'document_type' => 'tax_invoice', 'billing_period' => '2026-10', 'place_of_supply' => 'Kerala', 'cgst_paise' => 4576, 'sgst_paise' => 4577, 'supplier_tax_id' => '32AAAAA0000A1Z5']);
        DB::table('erp_invoice_lines')->insert(['unit_id' => $this->unit, 'invoice_id' => $this->invoice, 'description' => 'Tuition', 'sac' => '999293', 'taxable_paise' => 50847, 'amount_paise' => 60000]);
        $this->request = DB::table('erp_payment_requests')->insertGetId(['unit_id' => $this->unit, 'student_id' => $this->student, 'amount_paise' => 120000, 'reference' => 'PR-REQ-1', 'requested_on' => '2026-11-01', 'due_on' => '2026-11-30', 'notes' => 'November classes', 'status' => 'open', 'created_by' => 1]);
        $this->teacher = DB::table('erp_teachers')->insertGetId(['code' => 'PR-T1', 'name' => 'Synthetic Directory Teacher', 'timezone' => 'UTC', 'status' => 'active', 'levels' => 'LP,UP', 'category' => 'Advanced']);
        DB::table('erp_teacher_units')->insert(['unit_id' => $this->unit, 'teacher_id' => $this->teacher]);
        DB::table('erp_teacher_candidates')->insert(['business_id' => 1, 'identity_key' => 'pr-t1', 'name' => 'Synthetic Directory Teacher', 'phone' => '9876543210', 'teacher_id' => $this->teacher, 'teacher_code' => 'PR-T1', 'stage' => 'REGISTERED', 'qualification' => 'MSc', 'registered_at' => '2026-01-10 09:00:00']);
        $subject = DB::table('erp_subjects')->insertGetId(['name' => 'Synthetic Physics']);
        $enrollment = DB::table('erp_enrollments')->insertGetId(['unit_id' => $this->unit, 'student_id' => $this->student, 'teacher_id' => $this->teacher, 'subject_id' => $subject, 'starts_on' => '2026-01-01', 'status' => 'active', 'student_rate_paise' => 60000, 'teacher_rate_paise' => 30000, 'currency' => 'INR']);
        foreach ([['2026-11-03 10:00:00', 'regular', 60, 'approved'], ['2026-11-05 10:00:00', 'regular', 55, 'submitted'], ['2026-11-07 10:00:00', 'demo', 45, 'submitted']] as [$start, $kind, $minutes, $status]) {
            $lesson = DB::table('erp_lessons')->insertGetId(['unit_id' => $this->unit, 'enrollment_id' => $enrollment, 'student_id' => $this->student, 'teacher_id' => $this->teacher, 'subject_id' => $subject, 'starts_at' => $start, 'ends_at' => date('Y-m-d H:i:s', strtotime($start) + 3600), 'status' => 'completed', 'kind' => $kind, 'student_rate_paise' => 60000, 'teacher_rate_paise' => 30000, 'currency' => 'INR']);
            $this->attendance[] = DB::table('erp_attendance')->insertGetId(['unit_id' => $this->unit, 'lesson_id' => $lesson, 'actual_minutes' => $minutes, 'status' => $status, 'submitted_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('erp_availability')->insert(['unit_id' => $this->unit, 'student_id' => $this->student, 'weekday' => 1, 'start_time' => '06:00', 'end_time' => '10:00', 'timezone' => 'UTC', 'starts_on' => '2026-12-07']);
        DB::table('erp_availability')->insert(['unit_id' => $this->other, 'teacher_id' => $this->teacher, 'weekday' => 2, 'start_time' => '06:00', 'end_time' => '10:00', 'timezone' => 'UTC', 'starts_on' => '2026-12-07']);
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

    public function test_print_host_page_requires_sign_in_and_embeds_only_the_area(): void
    {
        $this->get('/print/u/'.$this->unit.'/receipt/'.$this->payment)->assertRedirect('/login');
        $response = $this->actingAs($this->member('finance'))->get('/print/u/'.$this->unit.'/receipt/'.$this->payment)->assertOk()->assertSee('id="erp-root"', false);
        $this->assertStringContainsString('id="erp-session">{"kind":"print"}<', $response->getContent());
        $this->assertStringNotContainsString('Synthetic Print Student', $response->getContent());
    }

    public function test_receipt_follows_the_money_page_and_lists_explicit_fields(): void
    {
        $json = $this->actingAs($this->member('finance'))->getJson($this->api('/print/receipt/'.$this->payment))->assertOk()->json();
        $this->assertSame(['id', 'kind', 'title', 'businessName', 'businessPhone', 'reference', 'studentName', 'studentCode', 'amountLabel', 'amount', 'recordedAt', 'installationTimezone', 'statement', 'reversal', 'replacement'], array_keys($json));
        $this->assertSame('INR 2,500.50', $json['amount']);
        $this->assertSame('Payment acknowledgement', $json['title']);
        $this->assertSame(['reference' => 'PR-REV-1'], $json['reversal']);
        $this->assertNull($json['replacement']);
        $this->assertStringNotContainsString('910000000077', json_encode($json));
        // Not a payment or refund, another unit, other roles.
        $charge = DB::table('erp_ledger')->where('reference', 'PR-REV-1')->value('id');
        $this->getJson($this->api('/print/receipt/'.$charge))->assertNotFound();
        $this->getJson('/app-api/units/'.$this->other.'/print/receipt/'.$this->payment)->assertForbidden();
        $this->actingAs($this->member('academics'))->getJson($this->api('/print/receipt/'.$this->payment))->assertForbidden();
        $this->actingAs($this->member('sales_followups'))->getJson($this->api('/print/receipt/'.$this->payment))->assertForbidden();
    }

    public function test_payment_request_follows_the_money_page(): void
    {
        $json = $this->actingAs($this->member('finance'))->getJson($this->api('/print/request/'.$this->request))->assertOk()->json();
        $this->assertSame(['id', 'businessName', 'businessPhone', 'studentName', 'studentCode', 'reference', 'status', 'requested', 'covered', 'outstanding', 'due', 'notes'], array_keys($json));
        $this->assertSame('INR 1,200.00', $json['requested']);
        $this->assertSame('2026-11-30', $json['due']);
        $this->actingAs($this->member('academics'))->getJson($this->api('/print/request/'.$this->request))->assertForbidden();
    }

    public function test_invoice_follows_the_accounts_page_and_shows_gst_lines(): void
    {
        $json = $this->actingAs($this->member('finance'))->getJson($this->api('/print/invoice/'.$this->invoice))->assertOk()->json();
        $this->assertSame(['id', 'title', 'documentType', 'number', 'isCancelled', 'supplier', 'issueDate', 'billingPeriod', 'credited', 'billingName', 'studentCode', 'placeOfSupply', 'hasGst', 'lines', 'taxes', 'totalLabel', 'total', 'words', 'taxNote', 'reason'], array_keys($json));
        $this->assertSame('Tax invoice', $json['title']);
        $this->assertSame('5 Nov 2026', $json['issueDate']);
        $this->assertSame('October 2026', $json['billingPeriod']);
        $this->assertSame(['Taxable value', 'CGST 9%', 'SGST 9%'], array_column($json['taxes'], 'label'));
        $this->assertSame(['number', 'description', 'sac', 'taxable', 'amount'], array_keys($json['lines'][0]));
        $this->assertSame('INR 600.00', $json['total']);
        $this->actingAs($this->member('academics'))->getJson($this->api('/print/invoice/'.$this->invoice))->assertForbidden();
        $this->actingAs($this->member('finance', $this->other))->getJson('/app-api/units/'.$this->other.'/print/invoice/'.$this->invoice)->assertNotFound();
    }

    public function test_statement_is_for_managers_only_and_feeds_the_monthly_review(): void
    {
        $query = '/print/statement?student_id='.$this->student.'&month=2026-11';
        foreach (['finance', 'read_only', 'academics', 'sales_followups'] as $role) {
            $this->actingAs($this->member($role))->getJson($this->api($query))->assertForbidden();
        }
        $manager = $this->member('team_manager');
        $json = $this->actingAs($manager)->getJson($this->api($query))->assertOk()->json();
        $this->assertSame(['businessName', 'businessTimezone', 'month', 'student', 'rows', 'totalMinutes', 'account', 'review'], array_keys($json));
        $this->assertSame(['id', 'name', 'code', 'timezone'], array_keys($json['student']));
        $this->assertSame(['id', 'when', 'teacher', 'subject', 'minutes', 'charge', 'status'], array_keys($json['rows'][0]));
        $this->assertSame(160, $json['totalMinutes']);
        $this->assertSame('Synthetic Physics (free demo)', $json['rows'][2]['subject']);
        $this->assertSame('₹0.00', $json['rows'][2]['charge']);
        $this->assertSame('₹550.00', $json['rows'][1]['charge']);
        $this->assertStringNotContainsString('rate_paise', json_encode($json));
        $this->assertSame([$this->attendance[1], $this->attendance[2]], array_column($json['review']['entries'], 'attendanceId'));
        $this->getJson($this->api('/print/statement?student_id='.$this->student.'&month=November'))->assertStatus(422);
        $this->getJson('/app-api/units/'.$this->other.'/print/statement?student_id='.$this->student.'&month=2026-11')->assertForbidden();

        // The React monthly-review posts exactly these entries and snapshots.
        $entries = $json['review']['entries'];
        $this->postJson('/erp/units/'.$this->unit.'/tuition/monthly-review', [
            'attendance_ids' => array_column($entries, 'attendanceId'),
            'snapshots' => array_combine(array_column($entries, 'attendanceId'), array_column($entries, 'snapshot')),
            'deadline_at' => '2026-11-30T18:00',
            'note' => 'Shared on WhatsApp',
        ])->assertOk()->assertJsonPath('message', 'Statement sharing recorded for the selected attendance entries.');
        $this->assertSame('2026-11-30 18:00:00', DB::table('erp_attendance')->where('id', $this->attendance[1])->value('parent_deadline_at'));
        $this->assertNull(DB::table('erp_attendance')->where('id', $this->attendance[0])->value('parent_deadline_at'));
    }

    public function test_teacher_directory_is_for_the_admissions_page_with_explicit_fields(): void
    {
        $sales = $this->member('sales_followups');
        $json = $this->actingAs($sales)->getJson($this->api('/teacher-directory'))->assertOk()->json();
        $this->assertSame(1, $json['meta']['total']);
        $row = $json['data'][0];
        $this->assertSame(['id', 'code', 'name', 'ttHours', 'registeredOn', 'category', 'levels', 'subjects', 'classes', 'mediums', 'qualification', 'dial', 'whatsapp', 'profileUrl'], array_keys($row));
        $this->assertSame(['LP', 'UP'], $row['levels']);
        $this->assertSame('https://wa.me/919876543210', $row['whatsapp']);
        $this->assertStringStartsWith('https://wa.me/?text=', $row['profileUrl']);
        $this->assertStringContainsString(rawurlencode('Synthetic Directory Teacher'), $row['profileUrl']);
        $this->assertFalse($json['can']['classify']);
        $this->getJson($this->api('/teacher-directory?level=HS'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->api('/teacher-directory?sort=password'))->assertStatus(422);

        // for_lead resolves only an enquiry this user may see.
        $owner = $this->member('sales_followups');
        $lead = DB::table('erp_leads')->insertGetId(['unit_id' => $this->unit, 'name' => 'Synthetic Lead', 'phone' => '9800000001', 'country' => 'IN', 'enquiry_date' => '2026-11-01', 'status' => 'LEAD', 'assigned_to' => $owner->id, 'created_by' => $owner->id]);
        $this->getJson($this->api('/teacher-directory?for_lead='.$lead))->assertOk()->assertJsonPath('profileLead', null);
        $mine = $this->actingAs($owner)->getJson($this->api('/teacher-directory?for_lead='.$lead))->assertOk()->json();
        $this->assertSame(['id' => $lead, 'name' => 'Synthetic Lead', 'hasWhatsapp' => true], $mine['profileLead']);
        $this->assertStringStartsWith('https://wa.me/919800000001?text=', $mine['data'][0]['profileUrl']);

        $this->actingAs($this->member('finance'))->getJson($this->api('/teacher-directory'))->assertForbidden();
        $this->actingAs($this->member('academics'))->getJson($this->api('/teacher-directory'))->assertForbidden();
        $this->actingAs($sales)->getJson('/app-api/units/'.$this->other.'/teacher-directory')->assertForbidden();
    }

    public function test_planning_availability_is_scoped_to_the_timetable_page(): void
    {
        $json = $this->actingAs($this->member('academics'))->getJson($this->api('/timetable/availability'))->assertOk()->json();
        $this->assertSame(1, $json['meta']['total']);
        $this->assertSame(['id', 'party', 'who', 'weekday', 'startTime', 'endTime', 'timezone', 'startsOn', 'endsOn'], array_keys($json['data'][0]));
        $this->assertSame('Monday', $json['data'][0]['weekday']);
        $this->assertSame('Synthetic Print Student', $json['data'][0]['who']);
        $this->actingAs($this->member('finance'))->getJson($this->api('/timetable/availability'))->assertForbidden();
        $this->actingAs($this->member('sales_followups'))->getJson($this->api('/timetable/availability'))->assertForbidden();
    }

    public function test_planning_writes_answer_json_on_the_classic_routes(): void
    {
        $academics = $this->member('academics');
        $this->actingAs($academics)->postJson('/erp/units/'.$this->unit.'/availability', ['teacher_id' => $this->teacher, 'weekday' => 1, 'start_time' => '06:00', 'end_time' => '10:00', 'timezone' => 'UTC', 'starts_on' => '2026-12-07'])->assertOk()->assertJsonPath('result.teacher_id', $this->teacher);
        $this->actingAs($this->member('finance'))->postJson('/erp/units/'.$this->unit.'/availability', ['teacher_id' => $this->teacher, 'weekday' => 1, 'start_time' => '06:00', 'end_time' => '10:00', 'timezone' => 'UTC', 'starts_on' => '2026-12-07'])->assertForbidden();
    }

    public function test_from_sheets_json_lists_configured_fields_and_hides_review_counts_from_members(): void
    {
        config(['sheet_transition.enabled' => true]);
        DB::table('erp_sheet_sources')->insert(['source_key' => 'students', 'lease_tick' => 0, 'last_success_at' => now(), 'last_attempt_at' => now(), 'last_status' => 'ready']);
        DB::table('erp_sheet_runs')->insert(['id' => 'run-pr', 'source_key' => 'students', 'status' => 'ready', 'contract_digest' => 'x', 'row_count' => 2, 'issue_count' => 1, 'created_at' => now(), 'completed_at' => now()]);
        DB::table('erp_sheet_sources')->where('source_key', 'students')->update(['active_run_id' => 'run-pr', 'latest_run_id' => 'run-pr']);
        DB::table('erp_sheet_records')->insert(['run_id' => 'run-pr', 'unit_id' => $this->unit, 'source_row' => 2, 'source_identity' => 'CCA1', 'payload' => json_encode(['band' => 'K', 'name' => 'Sheet Student', 'status' => 'ACTIVE', 'code' => 'CCA1', 'grade' => '8', 'syllabus' => 'CBSE', 'secret' => 'not listed'])]);
        DB::table('erp_sheet_records')->insert(['run_id' => 'run-pr', 'unit_id' => $this->other, 'source_row' => 3, 'source_identity' => 'CCA2', 'payload' => json_encode(['band' => 'G', 'name' => 'Other Team Student', 'status' => 'ACTIVE', 'code' => 'CCA2', 'grade' => '8', 'syllabus' => 'CBSE'])]);

        $json = $this->actingAs($this->member('academics'))->getJson('/erp/from-sheets?unit='.$this->unit.'&source=students')->assertOk()->json();
        $this->assertSame(['sources', 'source', 'units', 'unit', 'isAdministrator', 'review', 'sheetUrl', 'isStale', 'timezone', 'state', 'run', 'columns', 'rows', 'meta'], array_keys($json));
        $this->assertSame(['band', 'name', 'status', 'code', 'grade', 'syllabus'], $json['columns']);
        $this->assertSame(1, $json['meta']['total']);
        $this->assertSame(['sourceRow', 'identity', 'data'], array_keys($json['rows'][0]));
        $this->assertArrayNotHasKey('secret', $json['rows'][0]['data']);
        $this->assertSame(['status', 'reason'], array_keys($json['run']));
        $this->assertNotContains('balances', array_column($json['sources'], 'key'));
        $this->getJson('/erp/from-sheets?unit='.$this->unit.'&source=students&review=1')->assertForbidden();
        $this->getJson('/erp/from-sheets?unit='.$this->other.'&source=students')->assertForbidden();

        $admin = $this->actingAs($this->createAdmin())->getJson('/erp/from-sheets?unit='.$this->unit.'&source=students&review=1')->assertOk()->json();
        $this->assertSame(['status', 'reason', 'rowCount', 'issueCount'], array_keys($admin['run']));
        $this->assertSame(2, $admin['meta']['total']);
        $this->assertSame(['sourceRow', 'identity', 'issue', 'data'], array_keys($admin['rows'][0]));

        config(['sheet_transition.enabled' => false]);
        $this->getJson('/erp/from-sheets')->assertNotFound();
    }
}
