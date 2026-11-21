<?php

namespace Tests\Feature;

use App\Modules\Erp\Services\{TeacherRecruitment,TeacherSheetImporter};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpTeacherRecruitmentTest extends TestCase
{
    private $admin;
    private int $unit;
    protected function setUp(): void
    {
        parent::setUp(); $this->admin=$this->createAdmin();
        $this->unit=DB::table('erp_units')->insertGetId(['code'=>'RECRUIT','name'=>'Recruitment unit','timezone'=>'Asia/Kolkata']);
    }
    private function api(string $path = '', array $query = [])
    {
        return $this->getJson('/app-api/units/'.$this->unit.'/recruitment'.($path !== '' ? '/'.$path : '').($query ? '?'.http_build_query($query) : ''));
    }
    private function body($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    public function test_application_registration_and_approval_create_only_one_canonical_teacher(): void
    {
        $this->actingAs($this->admin);
        $url=fn($action)=>route('erp.business.mutate',[$this->unit,'teacher-candidate-'.$action]);
        $input=['name'=>'Test Teacher','phone'=>'9000012345','subjects'=>'Maths','cv_url'=>'https://example.com/cv.pdf'];
        $this->postJson($url('create'),$input)->assertOk();
        $this->postJson($url('create'),$input)->assertOk();
        $this->assertDatabaseCount('erp_teacher_candidates',1);
        $id=DB::table('erp_teacher_candidates')->value('id');
        $this->postJson($url('approve'),['candidate_id'=>$id,'teacher_code'=>'CT98765'])->assertStatus(422);
        $this->postJson($url('update'),['candidate_id'=>$id,'stage'=>'SELECTED'])->assertOk();
        $this->postJson($url('register'),['candidate_id'=>$id,'availability'=>'6pm to 9pm'])->assertOk();
        $this->assertDatabaseCount('erp_teachers',0);
        $this->postJson($url('approve'),['candidate_id'=>$id,'teacher_code'=>'CT98765'])->assertOk();
        $this->postJson($url('approve'),['candidate_id'=>$id,'teacher_code'=>'CT98765'])->assertOk();
        $this->assertDatabaseCount('erp_teachers',1);
        $this->assertDatabaseHas('erp_teacher_candidates',['id'=>$id,'stage'=>'APPROVED']);
        $this->api((string)$id)->assertOk()->assertJsonPath('candidate.name','Test Teacher')->assertJsonPath('candidate.stage','APPROVED')
            ->assertJsonPath('candidate.teacherCode','CT98765')->assertJsonPath('candidate.cvUrl','https://example.com/cv.pdf');
    }
    public function test_demo_staff_can_search_registered_pool_but_cannot_review_applicants_or_mutate(): void
    {
        $s=new TeacherRecruitment();
        $a=$s->mutate($this->unit,$this->admin,'teacher-candidate-create',['name'=>'Private Applicant','phone'=>'9000000001']);
        $b=$s->mutate($this->unit,$this->admin,'teacher-candidate-create',['name'=>'Registered Teacher','phone'=>'9000000002']);
        DB::table('erp_teacher_candidates')->where('id',$b->id)->update(['stage'=>'REGISTERED','subjects'=>'Maths']);
        $demo=$this->createMember(['id'=>31]);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$demo->id,'role'=>'sales_followups']);
        $this->actingAs($this->admin)->api((string)$a->id)->assertOk()->assertJsonPath('candidate.name','Private Applicant');
        $pool=$this->actingAs($demo)->api('',['subject'=>'Maths'])->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.name','Registered Teacher')->assertJsonPath('can.manage',false);
        $this->assertStringNotContainsString('Private Applicant',$this->body($pool));
        $this->assertStringNotContainsString('Private Applicant',$this->body($this->api()->assertOk()));
        // Applicant profiles and their review history are for managers only.
        $this->api((string)$a->id)->assertForbidden();
        $this->api((string)$b->id)->assertForbidden();
        $this->postJson(route('erp.business.mutate',[$this->unit,'teacher-candidate-update']),['candidate_id'=>$b->id,'stage'=>'SELECTED'])->assertForbidden();
    }
    public function test_open_profile_shows_the_selected_teacher_instead_of_repeating_the_directory(): void
    {
        $service=new TeacherRecruitment();
        $selected=$service->mutate($this->unit,$this->admin,'teacher-candidate-create',['name'=>'Selected Applicant','phone'=>'9000000011']);
        $service->mutate($this->unit,$this->admin,'teacher-candidate-create',['name'=>'Other Applicant','phone'=>'9000000012']);
        $manager=$this->createMember(['id'=>32]);
        DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$manager->id,'role'=>'team_manager']);
        $this->actingAs($manager);
        $profile=$this->api((string)$selected->id)->assertOk()->assertJsonPath('candidate.name','Selected Applicant')->assertJsonPath('can.manage',true);
        $this->assertStringNotContainsString('Other Applicant',$this->body($profile));
        $filters=['q'=>'Applicant','stage'=>'APPLIED'];
        $this->api('',$filters+['page'=>2])->assertOk()->assertJsonPath('meta.total',2)->assertJsonCount(0,'data');
        $list=$this->api('',$filters+['page'=>1])->assertOk()->assertJsonPath('meta.total',2)->assertJsonPath('can.manage',true)->assertJsonPath('can.write',true);
        $this->assertSame(['Other Applicant','Selected Applicant'],array_column($list->json('data'),'name'));
    }
    public function test_snapshot_matches_canonical_code_deduplicates_and_is_resumable(): void
    {
        $teacher=DB::table('erp_teachers')->insertGetId(['name'=>'Existing Teacher','code'=>'CT123','timezone'=>'UTC','status'=>'stopped']);
        $dir=sys_get_temp_dir().'/claps-recruit-test-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
        $files=[$dir.'/registration-0-3.json',$dir.'/application-1-2.json'];
        try {
            file_put_contents($files[0],json_encode([
                ['source_key'=>'reg:3','kind'=>'registration','name'=>'Existing Teacher','code'=>'CT123','phone'=>'919000012345','subjects'=>'Maths','bank_account'=>'DO NOT STORE'],
                ['source_key'=>'reg:4','kind'=>'registration','name'=>'Different Person','code'=>'CT123','phone'=>'9000099999'],
            ]));
            file_put_contents($files[1],json_encode([
                ['source_key'=>'app:2','kind'=>'application','name'=>'Existing Teacher','phone'=>'9000012345'],
                ['source_key'=>'app:3','kind'=>'application','name'=>'Unrelated Person','phone'=>'9000012345'],
            ]));
            $s=new TeacherSheetImporter(); $result=$s->execute($dir,$this->unit,$this->admin->id);
            $this->assertSame(4,$result['source_rows']);
            $this->assertDatabaseCount('erp_teacher_candidates',3);
            $this->assertDatabaseHas('erp_teacher_candidates',['name'=>'Existing Teacher','teacher_id'=>$teacher,'stage'=>'REGISTERED']);
            $this->assertDatabaseHas('erp_teacher_candidates',['name'=>'Different Person','teacher_id'=>null,'stage'=>'REVIEW']);
            $this->assertDatabaseHas('erp_teacher_candidates',['name'=>'Unrelated Person','stage'=>'REVIEW']);
            $this->assertSame('stopped',DB::table('erp_teachers')->value('status'));
            $this->assertStringNotContainsString('DO NOT STORE',DB::table('erp_teacher_candidate_sources')->where('source_key','reg:3')->value('payload'));
            $again=$s->execute($dir,$this->unit,$this->admin->id);
            $this->assertSame(4,$again['already_imported']);
            $this->assertSame(0,$again['new_profiles']);
            $this->assertDatabaseCount('erp_teachers',1);
        } finally { foreach($files as $file) unlink($file);rmdir($dir); }
    }
}
