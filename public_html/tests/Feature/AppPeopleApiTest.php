<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** JSON reads for Teachers, Admissions, Follow-ups and Teacher hiring keep the classic role matrix. Synthetic data only. */
class AppPeopleApiTest extends TestCase
{
    private int $unit;
    private int $other;
    private int $teacher;
    private int $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'PPL-A', 'name' => 'People Unit A', 'timezone' => 'UTC']);
        $this->other = DB::table('erp_units')->insertGetId(['code' => 'PPL-B', 'name' => 'People Unit B', 'timezone' => 'UTC']);
        $this->teacher = DB::table('erp_teachers')->insertGetId(['code' => 'CT2', 'name' => 'Synthetic Paid Teacher', 'timezone' => 'UTC', 'status' => 'active', 'levels' => 'LP,HS', 'category' => 'Advanced']);
        $second = DB::table('erp_teachers')->insertGetId(['code' => 'CT10', 'name' => 'Synthetic Stopped Teacher', 'timezone' => 'UTC', 'status' => 'stopped']);
        $hidden = DB::table('erp_teachers')->insertGetId(['code' => 'CT3', 'name' => 'Private Other Teacher', 'timezone' => 'UTC', 'status' => 'active']);
        foreach ([[$this->teacher, $this->unit], [$second, $this->unit], [$hidden, $this->other]] as [$teacher, $unit]) DB::table('erp_teacher_units')->insert(['teacher_id' => $teacher, 'unit_id' => $unit]);
        $subject = DB::table('erp_subjects')->insertGetId(['name' => 'Synthetic Maths']);
        DB::table('erp_eligibilities')->insert(['teacher_id' => $this->teacher, 'subject_id' => $subject, 'grade' => '8', 'syllabus' => 'CBSE']);
        DB::table('erp_ledger')->insert(['unit_id' => $this->unit, 'teacher_id' => $this->teacher, 'kind' => 'teacher_accrual', 'amount_paise' => 60000, 'currency' => 'INR', 'reference' => 'PPL-ACC-1', 'event_key' => 'ppl-acc-1', 'occurred_at' => now(), 'created_by' => 1]);
        $this->student = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'PPL-S1', 'name' => 'Synthetic Due Student', 'registered_on' => '2026-01-05', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE']);
        $later = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'PPL-S2', 'name' => 'Synthetic Later Student', 'registered_on' => '2026-01-06', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '9', 'syllabus' => 'ICSE']);
        $now = now();
        DB::table('erp_followups')->insert([
            ['unit_id' => $this->unit, 'student_id' => $this->student, 'contact_channel' => 'call', 'note' => 'Old note', 'outcome' => 'Call back', 'next_due_on' => '2026-01-01', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['unit_id' => $this->unit, 'student_id' => $this->student, 'contact_channel' => 'whatsapp', 'note' => 'Latest note', 'outcome' => 'Interested', 'next_due_on' => '2026-02-01', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['unit_id' => $this->unit, 'student_id' => $later, 'contact_channel' => 'call', 'note' => 'Future', 'outcome' => 'Busy', 'next_due_on' => '2099-01-01', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    private function member(string $role, ?int $unit = null)
    {
        $user = $this->createMember(['name' => 'Synthetic '.$role]);
        DB::table('erp_memberships')->insert(['unit_id' => $unit ?? $this->unit, 'user_id' => $user->id, 'role' => $role]);
        return $user;
    }

    private function lead(int $owner, string $name, array $extra = []): int
    {
        return DB::table('erp_leads')->insertGetId(array_merge(['unit_id' => $this->unit, 'name' => $name, 'phone' => '9800000001', 'country' => 'IN', 'enquiry_date' => '2026-03-01', 'status' => 'LEAD', 'assigned_to' => $owner, 'created_by' => $owner], $extra));
    }

    public function test_teacher_list_shows_earnings_only_to_money_roles(): void
    {
        $json = $this->actingAs($this->member('team_manager'))->getJson('/app-api/units/'.$this->unit.'/teachers')->assertOk()->json();
        $this->assertSame(2, $json['meta']['total']);
        $this->assertSame(['CT2', 'CT10'], array_column($json['data'], 'code'), 'Codes sort naturally.');
        $this->assertSame(['id', 'code', 'name', 'status', 'levels', 'category', 'subjects', 'ttHours', 'activeClasses', 'earnedPaise', 'paidPaise', 'netPayablePaise'], array_keys($json['data'][0]));
        $this->assertSame(60000, $json['data'][0]['netPayablePaise']);
        $this->assertSame(['LP', 'HS'], $json['data'][0]['levels']);
        $this->assertSame(['Synthetic Maths'], $json['data'][0]['subjects']);
        $this->assertTrue($json['can']['seeMoney']);

        $this->getJson('/app-api/units/'.$this->unit.'/teachers?status=stopped')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'CT10');
        $this->getJson('/app-api/units/'.$this->unit.'/teachers?level=HS&category=Advanced')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/app-api/units/'.$this->unit.'/teachers?q=CT3')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/app-api/units/'.$this->unit.'/teachers?sort=earnedPaise')->assertStatus(422);
        $this->getJson('/app-api/units/'.$this->other.'/teachers')->assertForbidden();

        $academic = $this->actingAs($this->member('academics'))->getJson('/app-api/units/'.$this->unit.'/teachers')->assertOk()->json();
        $this->assertFalse($academic['can']['seeMoney']);
        $this->assertNull($academic['data'][0]['earnedPaise']);
        $this->assertNull($academic['data'][0]['netPayablePaise']);
        $detail = $this->getJson('/app-api/units/'.$this->unit.'/teachers/'.$this->teacher)->assertOk()->json();
        $this->assertNull($detail['statement']);
        // Class hourly rates are money: null for roles without the money page.
        $subject = DB::table('erp_subjects')->value('id');
        DB::table('erp_enrollments')->insert(['unit_id' => $this->unit, 'student_id' => $this->student, 'teacher_id' => $this->teacher, 'subject_id' => $subject, 'starts_on' => '2026-01-01', 'status' => 'active', 'student_rate_paise' => 45123, 'teacher_rate_paise' => 30123, 'currency' => 'INR']);
        $detail = $this->getJson('/app-api/units/'.$this->unit.'/teachers/'.$this->teacher)->assertOk()->json();
        $this->assertSame(['id', 'studentId', 'studentName', 'subjectName', 'startsOn', 'endsOn', 'status', 'studentRatePaise', 'teacherRatePaise'], array_keys($detail['classes'][0]));
        $this->assertSame([null, null], [$detail['classes'][0]['studentRatePaise'], $detail['classes'][0]['teacherRatePaise']]);
        $this->assertStringNotContainsString('30123', json_encode($detail));
        $managed = $this->actingAs($this->member('team_manager'))->getJson('/app-api/units/'.$this->unit.'/teachers/'.$this->teacher)->assertOk()->json();
        $this->assertSame([45123, 30123], [$managed['classes'][0]['studentRatePaise'], $managed['classes'][0]['teacherRatePaise']]);
        $this->assertSame([['subject' => 'Synthetic Maths', 'grade' => '8', 'syllabus' => 'CBSE']], $detail['eligibilities']);
        $hidden = DB::table('erp_teachers')->where('code', 'CT3')->value('id');
        $this->getJson('/app-api/units/'.$this->unit.'/teachers/'.$hidden)->assertNotFound();
    }

    public function test_teacher_detail_statement_for_read_only_and_refused_roles(): void
    {
        $json = $this->actingAs($this->member('read_only'))->getJson('/app-api/units/'.$this->unit.'/teachers/'.$this->teacher)->assertOk()->json();
        $this->assertSame(['earnedPaise' => 60000, 'paidPaise' => 0, 'otherAdjustmentsPaise' => 0, 'netPayablePaise' => 60000], $json['statement']);
        $this->assertSame(['id', 'code', 'name', 'status', 'timezone', 'levels', 'category', 'ttHours'], array_keys($json['teacher']));
        $this->actingAs($this->member('finance'))->getJson('/app-api/units/'.$this->unit.'/teachers')->assertForbidden();
        $this->actingAs($this->member('sales_followups'))->getJson('/app-api/units/'.$this->unit.'/teachers/'.$this->teacher)->assertForbidden();
    }

    public function test_admissions_list_is_owner_scoped_staged_and_whitelisted(): void
    {
        $salesA = $this->member('sales_followups');
        $salesB = $this->member('sales_followups');
        $mine = $this->lead($salesA->id, 'Synthetic Own Lead', ['is_hot' => true, 'next_follow_up_on' => '2026-01-01']);
        $theirs = $this->lead($salesB->id, 'Synthetic Other Lead');
        DB::table('erp_demos')->insert(['unit_id' => $this->unit, 'lead_id' => $mine, 'scheduled_on' => '2099-01-01', 'scheduled_time' => '10:00', 'status' => 'SCHEDULED', 'created_by' => $salesA->id]);

        $json = $this->actingAs($salesA)->getJson('/app-api/units/'.$this->unit.'/admissions')->assertOk()->json();
        $this->assertSame(1, $json['meta']['total']);
        $this->assertSame(['id', 'name', 'grade', 'subject', 'syllabus', 'source', 'status', 'stage', 'stageLabel', 'isHot', 'enquiryDate', 'nextFollowUpOn', 'followUpState', 'interestStatus', 'assignedName', 'phone', 'dial', 'whatsapp'], array_keys($json['data'][0]));
        $this->assertSame('demo_scheduled', $json['data'][0]['stage']);
        $this->assertSame('overdue', $json['data'][0]['followUpState']);
        $this->assertSame('https://wa.me/919800000001', $json['data'][0]['whatsapp']);
        $this->assertSame(1, $json['totals']['overdue']);
        $this->assertTrue($json['can']['followup']);
        $this->assertFalse($json['can']['manage']);

        $this->getJson('/app-api/units/'.$this->unit.'/admissions?stage=new')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/app-api/units/'.$this->unit.'/admissions?followup=overdue&hot=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/app-api/units/'.$this->unit.'/admissions?sort=phone')->assertStatus(422);
        $this->getJson('/app-api/units/'.$this->unit.'/admissions/'.$theirs)->assertNotFound();
        $this->getJson('/app-api/units/'.$this->unit.'/admissions/demos')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/app-api/units/'.$this->other.'/admissions')->assertForbidden();

        $manager = $this->actingAs($this->member('team_manager'))->getJson('/app-api/units/'.$this->unit.'/admissions?sort=name&dir=asc')->assertOk()->json();
        $this->assertSame(['Synthetic Other Lead', 'Synthetic Own Lead'], array_column($manager['data'], 'name'));
        $this->actingAs($this->member('academics'))->getJson('/app-api/units/'.$this->unit.'/admissions')->assertForbidden();
    }

    public function test_lead_detail_lists_history_reminders_and_transfer_rights(): void
    {
        $sales = $this->member('sales_followups');
        $colleague = $this->member('sales_followups');
        $lead = $this->lead($sales->id, 'Synthetic Detail Lead');
        $this->actingAs($sales)->postJson('/erp/units/'.$this->unit.'/business-actions/admission-followup', ['lead_id' => $lead, 'channel' => 'call', 'note' => 'Spoke to parent', 'outcome' => 'Interested', 'next_due_on' => '2099-01-02'])->assertOk();
        $this->postJson('/erp/units/'.$this->unit.'/business-actions/admission-reminder', ['lead_id' => $lead, 'due_on' => '2026-01-01', 'text' => 'Check fees'])->assertOk();
        $this->postJson('/erp/units/'.$this->unit.'/business-actions/admission-transfer', ['lead_id' => $lead, 'to_user_id' => $colleague->id])->assertOk();

        $json = $this->getJson('/app-api/units/'.$this->unit.'/admissions/'.$lead)->assertOk()->json();
        $this->assertSame('contacted', $json['lead']['stage']);
        $this->assertSame('2099-01-02', $json['lead']['nextFollowUpOn']);
        $this->assertSame(['id', 'kind', 'channel', 'outcome', 'summary', 'details', 'occurredAt', 'actorName'], array_keys($json['activities'][0]));
        $this->assertTrue($json['reminders'][0]['isOverdue']);
        $this->assertSame([['value' => (string) $colleague->id, 'label' => 'Synthetic sales_followups']], $json['members']);
        $this->assertTrue($json['transfers'][0]['canCancel']);
        $this->assertFalse($json['transfers'][0]['canAccept']);
        $this->getJson('/app-api/units/'.$this->unit.'/admissions/reminders')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.text', 'Check fees');

        $incoming = $this->actingAs($colleague)->getJson('/app-api/units/'.$this->unit.'/admissions/transfers')->assertOk()->json();
        $this->assertSame(1, $incoming['meta']['total']);
        $this->assertTrue($incoming['data'][0]['canAccept']);
        $this->assertFalse($incoming['data'][0]['canCancel']);

        $readOnly = $this->actingAs($this->member('read_only'))->getJson('/app-api/units/'.$this->unit.'/admissions/'.$lead)->assertOk()->json();
        $this->assertFalse($readOnly['can']['followup']);
        $this->assertSame([], $readOnly['members']);
    }

    public function test_followup_queue_uses_latest_instruction_and_balance_rule(): void
    {
        $sales = $this->member('sales_followups');
        $json = $this->actingAs($sales)->getJson('/app-api/units/'.$this->unit.'/followups?student='.$this->student)->assertOk()->json();
        $this->assertSame(1, $json['meta']['total']);
        $this->assertSame('Latest note', $json['data'][0]['lastNote']);
        $this->assertSame(['id', 'studentId', 'code', 'name', 'status', 'priority', 'lastChannel', 'lastNote', 'lastOutcome', 'lastContactAt', 'nextDueOn', 'isOverdue', 'createdByName', 'balancePaise'], array_keys($json['data'][0]));
        $this->assertSame(['id' => $this->student, 'label' => 'Synthetic Due Student · PPL-S1'], $json['student']);
        $this->assertSame(1, $json['counts']['overdue']);

        $students = $this->getJson('/app-api/units/'.$this->unit.'/followups?view=students&sort=name')->assertOk()->json();
        $this->assertSame(2, $students['meta']['total']);
        $this->assertSame(0, $students['data'][0]['balancePaise']);
        $this->getJson('/app-api/units/'.$this->unit.'/followups?view=history')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/app-api/units/'.$this->unit.'/followups?view=students&priority=super')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/app-api/units/'.$this->unit.'/followups?view=history&sort=balance')->assertStatus(422);
        $other = DB::table('erp_students')->insertGetId(['unit_id' => $this->other, 'code' => 'PPL-X', 'name' => 'Private', 'registered_on' => '2026-01-01', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '1', 'syllabus' => 'CBSE']);
        $this->getJson('/app-api/units/'.$this->unit.'/followups?student='.$other)->assertOk()->assertJsonPath('student', null);
        $this->getJson('/app-api/units/'.$this->other.'/followups')->assertForbidden();

        $readOnly = $this->actingAs($this->member('read_only'))->getJson('/app-api/units/'.$this->unit.'/followups?view=students')->assertOk()->json();
        $this->assertSame(0, $readOnly['data'][0]['balancePaise'], 'Read-only has the Money page.');
        $this->actingAs($this->member('academics'))->getJson('/app-api/units/'.$this->unit.'/followups')->assertForbidden();
    }

    public function test_recruitment_directory_hides_applications_from_non_managers(): void
    {
        DB::table('erp_teacher_candidates')->insert([
            ['business_id' => 1, 'identity_key' => 'k1', 'name' => 'Synthetic Applicant', 'phone' => '919800000010', 'stage' => 'APPLIED', 'teacher_code' => null],
            ['business_id' => 1, 'identity_key' => 'k2', 'name' => 'Synthetic Registered', 'phone' => '919800000011', 'stage' => 'REGISTERED', 'teacher_code' => 'CT77'],
        ]);
        $applicant = DB::table('erp_teacher_candidates')->where('identity_key', 'k1')->value('id');
        $manager = $this->actingAs($this->member('team_manager'))->getJson('/app-api/units/'.$this->unit.'/recruitment')->assertOk()->json();
        $this->assertSame(2, $manager['meta']['total']);
        $this->assertTrue($manager['can']['manage']);
        $this->assertSame(['id', 'name', 'teacherCode', 'phone', 'email', 'qualification', 'subjects', 'grades', 'syllabuses', 'medium', 'availability', 'stage', 'sourceStatus', 'operationalStatus'], array_keys($manager['data'][0]));
        $this->getJson('/app-api/units/'.$this->unit.'/recruitment?stage=APPLIED')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/app-api/units/'.$this->unit.'/recruitment/'.$applicant)->assertOk()->assertJsonPath('candidate.stage', 'APPLIED');
        $this->getJson('/app-api/units/'.$this->unit.'/recruitment?sort=phone')->assertStatus(422);
        $this->getJson('/app-api/units/'.$this->other.'/recruitment')->assertForbidden();

        $sales = $this->actingAs($this->member('sales_followups'))->getJson('/app-api/units/'.$this->unit.'/recruitment?stage=APPLIED')->assertOk()->json();
        $this->assertSame(0, $sales['meta']['total']);
        $this->assertFalse($sales['can']['manage']);
        $this->getJson('/app-api/units/'.$this->unit.'/recruitment')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Synthetic Registered');
        $this->getJson('/app-api/units/'.$this->unit.'/recruitment/'.$applicant)->assertForbidden();
        $this->actingAs($this->member('read_only'))->getJson('/app-api/units/'.$this->unit.'/recruitment/'.$applicant)->assertForbidden();
        $this->actingAs($this->member('finance'))->getJson('/app-api/units/'.$this->unit.'/recruitment')->assertForbidden();
    }
    public function test_drawer_payloads_are_accepted_by_classic_actions(): void
    {
        $academic = $this->member('academics');
        $this->actingAs($academic)->postJson('/erp/units/'.$this->unit.'/business-actions/teacher-classify', ['teacher_id' => $this->teacher, 'levels' => ['UP'], 'category' => 'Standard'])->assertOk();
        $this->postJson('/erp/units/'.$this->unit.'/eligibility', ['teacher_id' => $this->teacher, 'subject' => 'Synthetic Physics', 'grade' => '9', 'syllabus' => 'ICSE'])->assertOk();
        $json = $this->getJson('/app-api/units/'.$this->unit.'/teachers/'.$this->teacher)->assertOk()->json();
        $this->assertSame(['UP'], $json['teacher']['levels']);
        $this->assertCount(2, $json['eligibilities']);

        $sales = $this->member('sales_followups');
        $this->actingAs($sales)->postJson('/erp/units/'.$this->unit.'/business-actions/followup', ['student_id' => $this->student, 'contact_channel' => 'call', 'outcome' => 'Interested', 'note' => 'Synthetic call', 'next_due_on' => '2026-01-02'])->assertOk();
        $this->getJson('/app-api/units/'.$this->unit.'/followups')->assertOk()->assertJsonPath('data.0.lastNote', 'Synthetic call');
        $this->postJson('/erp/units/'.$this->unit.'/business-actions/teacher-classify', ['teacher_id' => $this->teacher, 'levels' => []])->assertForbidden();
    }
    public function test_every_endpoint_refuses_other_units_and_roles_without_the_page(): void
    {
        $lead = $this->lead(1, 'Synthetic Gate Lead');
        $candidate = DB::table('erp_teacher_candidates')->insertGetId(['business_id' => 1, 'identity_key' => 'gate', 'name' => 'Synthetic Gate', 'stage' => 'APPLIED']);
        $base = '/app-api/units/'.$this->unit;
        $routes = [
            ['/teachers', 'sales_followups'], ['/teachers/'.$this->teacher, 'sales_followups'],
            ['/admissions', 'academics'], ['/admissions/'.$lead, 'academics'], ['/admissions/demos', 'academics'], ['/admissions/reminders', 'finance'], ['/admissions/transfers', 'finance'],
            ['/followups', 'academics'],
            ['/recruitment', 'finance'], ['/recruitment/'.$candidate, 'finance'],
        ];
        $outsider = $this->member('team_manager', $this->other);
        foreach ($routes as [$path, $refused]) {
            $this->actingAs($outsider)->getJson($base.$path)->assertForbidden();
            $this->actingAs($this->member($refused))->getJson($base.$path)->assertForbidden();
        }
    }

    public function test_admissions_rows_and_candidate_detail_list_explicit_fields(): void
    {
        $manager = $this->member('team_manager');
        $lead = $this->lead($manager->id, 'Synthetic Field Lead', ['notes' => 'Old notes']);
        DB::table('erp_demos')->insert(['unit_id' => $this->unit, 'lead_id' => $lead, 'scheduled_on' => '2099-01-01', 'scheduled_time' => '10:00', 'status' => 'SCHEDULED', 'meet_link' => 'javascript:alert(1)', 'created_by' => $manager->id]);
        DB::table('erp_lead_reminders')->insert(['unit_id' => $this->unit, 'lead_id' => $lead, 'due_on' => '2026-01-01', 'text' => 'Call', 'created_by' => $manager->id]);
        $colleague = $this->member('sales_followups');
        DB::table('erp_lead_transfers')->insert(['unit_id' => $this->unit, 'lead_id' => $lead, 'from_user_id' => $manager->id, 'to_user_id' => $colleague->id, 'status' => 'pending']);
        $this->actingAs($manager);

        $detail = $this->getJson('/app-api/units/'.$this->unit.'/admissions/'.$lead)->assertOk()->json();
        $this->assertSame(['id', 'name', 'grade', 'subject', 'syllabus', 'source', 'status', 'stage', 'stageLabel', 'isHot', 'enquiryDate', 'nextFollowUpOn', 'followUpState', 'interestStatus', 'assignedName', 'phone', 'dial', 'whatsapp',
            'rawName', 'email', 'country', 'studentPhone', 'notes', 'lostReason', 'joinedOn', 'admissionCode', 'studentId', 'studentCode', 'learningRequirement', 'concern', 'aboutStudent', 'aboutParent', 'awaitingAllocation', 'joinedMessage'], array_keys($detail['lead']));
        $this->assertNull($detail['demos'][0]['meetLink'], 'Only http(s) meeting links are returned.');
        $demo = $this->getJson('/app-api/units/'.$this->unit.'/admissions/demos')->assertOk()->json('data.0');
        $this->assertSame(['id', 'scheduledOn', 'scheduledTime', 'status', 'meetLink', 'isMatched', 'feedbackOutcome', 'feedbackNote', 'teacherName', 'subjectName', 'leadId', 'leadName', 'parentDial', 'parentWhatsapp', 'hasTeacher', 'teacherDial', 'teacherWhatsapp', 'isUpcoming'], array_keys($demo));
        $reminder = $this->getJson('/app-api/units/'.$this->unit.'/admissions/reminders')->assertOk()->json('data.0');
        $this->assertSame(['id', 'leadId', 'leadName', 'dueOn', 'dueTime', 'text', 'isOverdue'], array_keys($reminder));
        $transfer = $this->getJson('/app-api/units/'.$this->unit.'/admissions/transfers')->assertOk()->json('data.0');
        $this->assertSame(['id', 'leadId', 'leadName', 'fromName', 'toName', 'createdAt', 'canAccept', 'canDecline', 'canCancel'], array_keys($transfer));
        $this->assertTrue($transfer['canAccept'], 'Managers may resolve any transfer.');

        // The edit drawer sends emptied fields so the classic action clears them.
        $this->postJson('/erp/units/'.$this->unit.'/business-actions/admission-update-lead', ['lead_id' => $lead, 'notes' => ''])->assertOk();
        $this->assertNull(DB::table('erp_leads')->where('id', $lead)->value('notes'));

        $candidate = DB::table('erp_teacher_candidates')->insertGetId(['business_id' => 1, 'identity_key' => 'fields', 'name' => 'Synthetic Fields', 'phone' => '919800000012', 'stage' => 'APPLIED']);
        DB::table('erp_teacher_candidate_sources')->insert(['candidate_id' => $candidate, 'source_key' => 'sheet:1', 'kind' => 'sheet', 'payload' => json_encode(['name' => 'Synthetic Fields', 'phone' => '9800000012', 'bank_account' => 'SECRET-123'])]);
        $json = $this->getJson('/app-api/units/'.$this->unit.'/recruitment/'.$candidate)->assertOk()->json();
        $this->assertSame(['id', 'name', 'phone', 'email', 'teacherCode', 'teacherId', 'stage', 'mergedIntoId', 'qualification', 'subjects', 'grades', 'syllabuses', 'medium', 'availability', 'sourceStatus', 'cvUrl', 'registeredAt'], array_keys($json['candidate']));
        $this->assertSame(['name' => 'Synthetic Fields', 'phone' => '9800000012'], $json['sources'][0]['fields']);
        $this->assertStringNotContainsString('SECRET-123', json_encode($json));
    }
    public function test_lead_activity_details_are_whitelisted_display_fields(): void
    {
        $manager = $this->member('team_manager');
        $lead = $this->lead($manager->id, 'Synthetic Details Lead');
        $activity = fn (string $kind, array $metadata) => DB::table('erp_lead_activities')->insert(['unit_id' => $this->unit, 'lead_id' => $lead, 'actor_id' => $manager->id, 'kind' => $kind, 'summary' => $kind, 'metadata' => json_encode($metadata), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $activity('re_enquiry', ['external_id' => 'crm-9', 'status' => 'LEAD', 'enquiry_date' => '2026-04-02', 'source' => 'Later campaign', 'secret_key' => 'PRIVATE-META', 'student_id' => 4242]);
        $activity('edited', ['changes' => ['source' => ['from' => 'Walk-in', 'to' => 'Referral'], 'assigned_to' => ['from' => 7, 'to' => 8], 'bank' => ['from' => 'x', 'to' => 'PRIVATE-CHANGE']]]);
        DB::table('erp_lead_activities')->insert(['unit_id' => $this->unit, 'lead_id' => $lead, 'actor_id' => $manager->id, 'kind' => 'created', 'summary' => 'Bad json', 'metadata' => '{not json', 'occurred_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);

        $activities = collect($this->actingAs($manager)->getJson('/app-api/units/'.$this->unit.'/admissions/'.$lead)->assertOk()->json('activities'))->keyBy('kind');
        $this->assertSame(['id', 'kind', 'channel', 'outcome', 'summary', 'details', 'occurredAt', 'actorName'], array_keys($activities['re_enquiry']));
        $this->assertSame([['label' => 'Source', 'value' => 'Later campaign'], ['label' => 'Enquiry date', 'value' => '2026-04-02'], ['label' => 'CRM reference', 'value' => 'crm-9'], ['label' => 'Lead status', 'value' => 'LEAD']], $activities['re_enquiry']['details']);
        $this->assertSame([['label' => 'Source', 'value' => 'Walk-in → Referral']], $activities['edited']['details']);
        $this->assertSame([], $activities['created']['details']);
        $body = json_encode($activities);
        foreach (['PRIVATE-META', '4242', 'PRIVATE-CHANGE', 'secret_key'] as $hidden) $this->assertStringNotContainsString($hidden, $body);
    }

    public function test_demo_contacts_follow_the_classic_card_rules(): void
    {
        $sales = $this->member('sales_followups');
        $local = $this->lead($sales->id, 'Synthetic Local Lead', ['phone' => '04712345678', 'country' => null]);
        $indian = $this->lead($sales->id, 'Synthetic Indian Lead', ['phone' => '9800000002']);
        $noPhone = DB::table('erp_teachers')->insertGetId(['code' => 'CT40', 'name' => 'Synthetic Phoneless Teacher', 'timezone' => 'UTC', 'status' => 'active']);
        DB::table('erp_teacher_candidates')->insert(['business_id' => 1, 'identity_key' => 'demo-contact', 'name' => 'Synthetic Paid Teacher', 'phone' => '9876500009', 'teacher_id' => $this->teacher, 'stage' => 'APPROVED', 'created_at' => now(), 'updated_at' => now()]);
        $demo = fn (int $lead, ?int $teacher, string $on, string $status = 'SCHEDULED') => DB::table('erp_demos')->insertGetId(['unit_id' => $this->unit, 'lead_id' => $lead, 'teacher_id' => $teacher, 'scheduled_on' => $on, 'scheduled_time' => '10:00', 'status' => $status, 'duration_minutes' => 45, 'created_by' => $sales->id]);
        $withTeacher = $demo($indian, $this->teacher, '2099-01-01');
        $without = $demo($local, null, '2099-01-02');
        $phoneless = $demo($indian, $noPhone, '2099-01-03');
        $past = $demo($indian, $this->teacher, '2020-01-01', 'ATTENDED');
        // Another counsellor's demo never appears, contact links included.
        $demo($this->lead($this->member('sales_followups')->id, 'Synthetic Hidden Lead', ['phone' => '9800000099']), $this->teacher, '2099-01-04');

        $rows = collect($this->actingAs($sales)->getJson('/app-api/units/'.$this->unit.'/admissions/demos?scope=all')->assertOk()->assertJsonPath('meta.total', 4)->json('data'))->keyBy('id');
        $this->assertSame('+919800000002', $rows[$withTeacher]['parentDial']);
        $this->assertStringStartsWith('https://wa.me/919800000002?text='.rawurlencode('Dear Parent,'), $rows[$withTeacher]['parentWhatsapp']);
        $this->assertStringContainsString(rawurlencode('Duration: 45 minutes'), $rows[$withTeacher]['parentWhatsapp']);
        $this->assertSame('+919876500009', $rows[$withTeacher]['teacherDial']);
        $this->assertStringStartsWith('https://wa.me/919876500009?text='.rawurlencode('Hi Synthetic Paid Teacher,'), $rows[$withTeacher]['teacherWhatsapp']);
        $this->assertTrue($rows[$withTeacher]['isUpcoming']);
        // A local number without a country: dial as written, WhatsApp lets the sender pick the chat.
        $this->assertSame('04712345678', $rows[$without]['parentDial']);
        $this->assertStringStartsWith('https://wa.me/?text=', $rows[$without]['parentWhatsapp']);
        $this->assertFalse($rows[$without]['hasTeacher']);
        $this->assertNull($rows[$without]['teacherDial']);
        $this->assertNull($rows[$without]['teacherWhatsapp']);
        $this->assertTrue($rows[$phoneless]['hasTeacher']);
        $this->assertNull($rows[$phoneless]['teacherDial']);
        $this->assertNull($rows[$phoneless]['teacherWhatsapp']);
        $this->assertFalse($rows[$past]['isUpcoming']);
        $this->assertStringNotContainsString('9800000099', json_encode($rows));
        $this->actingAs($this->member('academics'))->getJson('/app-api/units/'.$this->unit.'/admissions/demos')->assertForbidden();
    }
}
