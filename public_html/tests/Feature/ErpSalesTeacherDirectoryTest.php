<?php

namespace Tests\Feature;

use App\Modules\Erp\Services\{Admissions,TeacherDirectory};
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpSalesTeacherDirectoryTest extends TestCase
{
    private $admin;
    private $sales;
    private int $unit;
    private int $subject;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 08:00:00');CarbonImmutable::setTestNow('2026-11-19 08:00:00');
        $this->admin = $this->createAdmin();
        $this->sales = $this->createMember(['id' => 31]);
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'DIR-AA', 'name' => 'Claps Learn', 'timezone' => 'Asia/Kolkata']);
        DB::table('erp_memberships')->insert(['unit_id' => $this->unit, 'user_id' => $this->sales->id, 'role' => 'sales_followups']);
        $this->subject = DB::table('erp_subjects')->insertGetId(['name' => 'Directory Maths']);
    }

    protected function tearDown(): void { Carbon::setTestNow();CarbonImmutable::setTestNow();parent::tearDown(); }

    private function teacher(string $code, string $name, string $created, array $extra = []): int
    {
        $id = DB::table('erp_teachers')->insertGetId(['code' => $code, 'name' => $name, 'timezone' => 'Asia/Kolkata', 'status' => 'active', 'created_at' => $created, 'updated_at' => $created] + $extra);
        DB::table('erp_teacher_units')->insert(['unit_id' => $this->unit, 'teacher_id' => $id]);
        return $id;
    }

    private function api(string $path, array $query = [])
    {
        return $this->getJson('/app-api/units/'.$this->unit.'/'.$path.($query ? '?'.http_build_query($query) : ''));
    }

    public function test_enquiry_can_be_created_without_student_name_but_registration_needs_one(): void
    {
        $this->actingAs($this->sales)->postJson(route('erp.business.mutate', [$this->unit, 'admission-create-lead']), ['phone' => '9000077001', 'country' => 'IN'])->assertOk();
        $lead = DB::table('erp_leads')->where('unit_id', $this->unit)->first();
        $this->assertSame('', $lead->name);
        $this->api('admissions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Student name pending');
        $this->api('admissions/'.$lead->id)->assertOk()->assertJsonPath('lead.name', 'Student name pending')->assertJsonPath('lead.rawName', '')->assertJsonPath('lead.joinedMessage', null);

        $this->postJson(route('erp.business.mutate', [$this->unit, 'admission-convert']), ['lead_id' => $lead->id])->assertStatus(422)->assertJsonValidationErrors('student_name');
        $this->postJson(route('erp.business.mutate', [$this->unit, 'admission-convert']), ['lead_id' => $lead->id, 'student_name' => 'Named Later'])->assertOk();
        $this->assertSame('Named Later', DB::table('erp_students')->where('unit_id', $this->unit)->value('name'));
        $this->assertSame('Named Later', DB::table('erp_leads')->where('id', $lead->id)->value('name'));

        $joined = $this->api('admissions/'.$lead->id)->assertOk()->assertJsonPath('lead.status', 'JOINED')->json('lead.joinedMessage');
        $this->assertStringStartsWith('https://wa.me/?text='.rawurlencode('NEW ADMISSION'), $joined);
    }

    public function test_demo_cards_list_the_teacher_and_meeting_link(): void
    {
        $teacher = $this->teacher('CT7', 'Demo Teacher', '2026-01-01 00:00:00');
        $lead = (new Admissions())->createLead($this->unit, $this->sales->id, ['name' => 'Demo Learner', 'phone' => '9000077002', 'country' => 'IN']);
        DB::table('erp_demos')->insert(['unit_id' => $this->unit, 'lead_id' => $lead->id, 'teacher_id' => $teacher, 'subject_id' => $this->subject, 'scheduled_on' => '2026-11-20', 'scheduled_time' => '17:30', 'status' => 'SCHEDULED', 'meet_link' => 'https://meet.example/abc', 'created_by' => $this->sales->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->sales)->api('admissions/demos')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.leadName', 'Demo Learner')->assertJsonPath('data.0.teacherName', 'Demo Teacher')
            ->assertJsonPath('data.0.subjectName', 'Directory Maths')->assertJsonPath('data.0.meetLink', 'https://meet.example/abc');
    }

    public function test_demo_cards_offer_parent_and_teacher_contact_with_demo_details(): void
    {
        $teacher = $this->teacher('CT7', 'Demo Teacher', '2026-01-01 00:00:00');
        DB::table('erp_teacher_candidates')->insert(['business_id' => 1, 'identity_key' => 'demo-teacher', 'name' => 'Demo Teacher', 'phone' => '9876500001', 'teacher_id' => $teacher, 'stage' => 'APPROVED', 'created_at' => now(), 'updated_at' => now()]);
        $lead = (new Admissions())->createLead($this->unit, $this->sales->id, ['name' => 'Demo Learner', 'phone' => '9000077002', 'country' => 'IN']);
        DB::table('erp_demos')->insert(['unit_id' => $this->unit, 'lead_id' => $lead->id, 'teacher_id' => $teacher, 'subject_id' => $this->subject, 'scheduled_on' => '2026-11-20', 'scheduled_time' => '17:30', 'status' => 'SCHEDULED', 'meet_link' => 'https://meet.example/abc', 'created_by' => $this->sales->id, 'created_at' => now(), 'updated_at' => now()]);

        $demo = $this->actingAs($this->sales)->api('admissions/demos')->assertOk()->assertJsonPath('data.0.teacherName', 'Demo Teacher')->json('data.0');
        $this->assertSame('+919000077002', $demo['parentDial']);
        $this->assertSame('+919876500001', $demo['teacherDial']);
        $this->assertStringStartsWith('https://wa.me/919000077002?text='.rawurlencode('Dear Parent,'), $demo['parentWhatsapp']);
        $this->assertStringContainsString(rawurlencode('Meeting link: https://meet.example/abc'), $demo['parentWhatsapp']);
        $this->assertStringStartsWith('https://wa.me/919876500001?text='.rawurlencode('Hi Demo Teacher,'), $demo['teacherWhatsapp']);
    }

    public function test_sales_sees_teacher_directory_filtered_by_level_and_category_in_code_order(): void
    {
        $a = $this->teacher('CT10', 'Aaron', '2026-03-01 00:00:00', ['levels' => 'LP,UP', 'category' => 'Standard']);
        $z = $this->teacher('CT9', 'Zara', '2026-05-01 00:00:00', ['levels' => 'HS', 'category' => 'Advanced']);
        $m = $this->teacher('CT100', 'Mini', '2026-04-01 00:00:00', ['levels' => 'UP', 'category' => 'Advanced']);
        $student = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'DIR-S1', 'name' => 'Directory Student', 'timezone' => 'Asia/Kolkata', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE', 'registered_on' => '2026-11-01']);
        $enrollment = DB::table('erp_enrollments')->insertGetId(['unit_id' => $this->unit, 'student_id' => $student, 'teacher_id' => $z, 'subject_id' => $this->subject, 'starts_on' => '2026-11-01', 'student_rate_paise' => 0, 'teacher_rate_paise' => 0]);
        foreach ([[1, 90], [3, 60]] as [$day, $minutes]) DB::table('erp_agreements')->insert(['unit_id' => $this->unit, 'enrollment_id' => $enrollment, 'weekday' => $day, 'start_time' => '10:00', 'duration_minutes' => $minutes, 'timezone' => 'Asia/Kolkata', 'starts_on' => '2026-11-01', 'status' => 'confirmed']);

        $this->actingAs($this->sales);
        $list = fn (array $q) => collect($this->api('teacher-directory', $q)->assertOk()->json('data'));
        $this->assertSame(['CT9', 'CT10', 'CT100'], $list([])->pluck('code')->all());
        $this->assertSame(['CT9', 'CT100', 'CT10'], $list(['sort' => 'registered_desc'])->pluck('code')->all());
        $this->assertSame(['CT10', 'CT100'], $list(['level' => 'UP'])->pluck('code')->all());
        $this->assertSame(['CT9', 'CT100'], $list(['category' => 'Advanced'])->pluck('code')->all());
        $this->assertSame(['CT100'], $list(['category' => 'Advanced', 'level' => 'UP'])->pluck('code')->all());
        $this->assertSame('2.5', $list(['teacher_q' => 'CT9'])->first()['ttHours']);
        // Sales may send a profile but may not set level and category.
        $this->api('teacher-directory')->assertJsonPath('can.classify', false)->assertJsonPath('data.0.profileUrl', fn ($url) => str_starts_with($url, 'https://wa.me/?text='));

        // Sales cannot classify; academics and managers can.
        $this->postJson(route('erp.business.mutate', [$this->unit, 'teacher-classify']), ['teacher_id' => $a, 'levels' => ['HS'], 'category' => 'Advanced'])->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('erp.business.mutate', [$this->unit, 'teacher-classify']), ['teacher_id' => $a, 'levels' => ['HS', 'LP'], 'category' => 'Advanced'])->assertOk();
        $this->assertSame(['LP,HS', 'Advanced'], array_values((array) DB::table('erp_teachers')->where('id', $a)->first(['levels', 'category'])));
        $this->postJson(route('erp.business.mutate', [$this->unit, 'teacher-classify']), ['teacher_id' => $a, 'levels' => ['KG']])->assertStatus(422);
        $this->api('teacher-directory')->assertJsonPath('can.classify', true);
    }

    public function test_teacher_profile_from_an_enquiry_goes_to_that_parent(): void
    {
        $this->teacher('CT1', 'Profile Teacher', '2026-01-01 00:00:00', ['levels' => 'HS', 'category' => 'Advanced']);
        $lead = (new Admissions())->createLead($this->unit, $this->sales->id, ['name' => 'Profile Learner', 'phone' => '9000077003', 'country' => 'IN']);
        $json = $this->actingAs($this->sales)->api('teacher-directory', ['for_lead' => $lead->id])->assertOk()
            ->assertJsonPath('profileLead.name', 'Profile Learner')->assertJsonPath('profileLead.hasWhatsapp', true);
        $this->assertStringStartsWith('https://wa.me/919000077003?text='.rawurlencode("Teacher profile — Claps Learn\n\nTeacher: Profile Teacher\nCode: CT1\nCategory: Advanced\nLevels: HS"), $json->json('data.0.profileUrl'));
        // Another counsellor's enquiry is not offered as a recipient.
        $other = (new Admissions())->createLead($this->unit, $this->admin->id, ['name' => 'Other Learner', 'phone' => '9000077004', 'country' => 'IN']);
        $this->api('teacher-directory', ['for_lead' => $other->id])->assertOk()->assertJsonPath('profileLead', null)->assertJsonPath('data.0.profileUrl', fn ($url) => str_starts_with($url, 'https://wa.me/?text='));
    }
}
