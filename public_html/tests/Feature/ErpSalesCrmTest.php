<?php
namespace Tests\Feature;

use App\Modules\Erp\Services\SalesPipeline;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpSalesCrmTest extends TestCase
{
    private int $unit;
    private $sales;
    private $colleague;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-26 20:00:00','UTC'));
        $this->unit = DB::table('erp_units')->insertGetId(['code'=>'CRM','name'=>'Claps Learning','timezone'=>'Asia/Kolkata']);
        $this->sales = $this->createMember(['name'=>'Asha Menon']);
        $this->colleague = $this->createMember(['name'=>'Priya Nair']);
        foreach ([$this->sales,$this->colleague] as $member) DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$member->id,'role'=>'sales_followups']);
        $this->actingAs($this->sales);
    }
    private function lead(string $name, array $values = []): int
    {
        return DB::table('erp_leads')->insertGetId(array_merge(['unit_id'=>$this->unit,'name'=>$name,'phone'=>'919876543210','country'=>'IN','enquiry_date'=>'2026-10-26','grade'=>'Grade 8','subject'=>'Mathematics','source'=>'Meta Ads','assigned_to'=>$this->sales->id,'created_by'=>$this->sales->id,'created_at'=>now(),'updated_at'=>now()],$values));
    }
    private function demo(int $lead, array $values = []): int
    {
        return DB::table('erp_demos')->insertGetId(array_merge(['unit_id'=>$this->unit,'lead_id'=>$lead,'scheduled_on'=>'2026-10-28','scheduled_time'=>'17:30','created_by'=>$this->sales->id,'status'=>'SCHEDULED'],$values));
    }
    /** The CRM views now load from the Admissions JSON reads. */
    private function api(string $path = '', array $query = [])
    {
        return $this->getJson('/app-api/units/'.$this->unit.'/admissions'.($path !== '' ? '/'.$path : '').($query ? '?'.http_build_query($query) : ''));
    }
    private function action(string $action, array $data)
    {
        return $this->postJson(route('erp.business.mutate',[$this->unit,'admission-'.$action]),$data);
    }
    private function body($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    public function test_pipeline_counts_filters_and_detail_use_the_same_scoped_stage_definition(): void
    {
        $new=$this->lead('Ananya Sharma',['is_hot'=>1]);
        $contacted=$this->lead('Rohan Nair',['next_follow_up_on'=>'2026-10-27']);
        $scheduled=$this->lead('Diya Menon',['next_follow_up_on'=>'2026-10-26','interest_status'=>'Interested']);
        $attended=$this->lead('Arjun Kumar',['next_follow_up_on'=>'2026-10-27']);
        $joined=$this->lead('Sara Joseph',['status'=>'JOINED','joined_on'=>'2026-10-26']);
        $lost=$this->lead('Aditya Rao',['status'=>'LOST','lost_reason'=>'Timing did not work']);
        $hidden=$this->lead('Private other counsellor',['assigned_to'=>$this->colleague->id]);
        $this->action('followup',['lead_id'=>$contacted,'channel'=>'call','outcome'=>'Interested','note'=>'Parent would like a demo after school.','next_due_on'=>'2026-10-27'])->assertOk();
        $this->demo($scheduled); $this->demo($attended,['status'=>'ATTENDED']);
        $this->demo($joined); $this->demo($lost); $this->demo($hidden);
        $expected=['new'=>1,'contacted'=>1,'demo_scheduled'=>1,'demo_attended'=>1,'joined'=>1,'lost'=>1];
        $page=$this->api()->assertOk()->assertJsonPath('meta.total',6);
        $this->assertSame($expected,array_column($page->json('stages'),'count','value'));
        $this->assertStringNotContainsString('Private other counsellor',$this->body($page));
        foreach (['new'=>$new,'contacted'=>$contacted,'demo_scheduled'=>$scheduled,'demo_attended'=>$attended,'joined'=>$joined,'lost'=>$lost] as $stage=>$id) {
            $this->api('',['stage'=>$stage])->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.id',$id)->assertJsonPath('data.0.stage',$stage);
        }
        $this->api('',['hot'=>1])->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.id',$new);
        // The detail is the lead itself, whatever list filters were active.
        $detail=$this->api((string)$contacted)->assertOk()->assertJsonPath('lead.id',$contacted)->assertJsonPath('lead.dial','+919876543210')->assertJsonPath('lead.whatsapp','https://wa.me/919876543210');
        $this->assertContains('Parent would like a demo after school.',array_column($detail->json('activities'),'summary'));
        $this->assertStringNotContainsString('Ananya Sharma',$this->body($detail));
        $this->api((string)$hidden)->assertNotFound();
        $this->api('demos')->assertOk();
        $this->getJson('/app-api/units/'.$this->unit.'/overview/sales-report')->assertOk();
    }
    public function test_today_uses_unit_date_and_reminders_are_scoped_and_paginated(): void
    {
        $lead=$this->lead('Today conversation',['next_follow_up_on'=>'2026-10-27']);
        $this->lead('Yesterday conversation',['next_follow_up_on'=>'2026-10-26']);
        $this->lead('Unscheduled conversation');
        $hidden=$this->lead('Hidden reminders',['assigned_to'=>$this->colleague->id]);
        foreach ([$lead,$hidden] as $id) foreach (range(1,21) as $i) DB::table('erp_lead_reminders')->insert(['unit_id'=>$this->unit,'lead_id'=>$id,'due_on'=>'2026-10-27','due_time'=>'10:00','text'=>'Check parent feedback '.$i,'created_by'=>$this->sales->id]);
        $future=DB::table('erp_lead_reminders')->insertGetId(['unit_id'=>$this->unit,'lead_id'=>$lead,'due_on'=>'2026-10-30','text'=>'Future reminder','created_by'=>$this->sales->id]);
        $this->api('',['followup'=>'today'])->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.id',$lead)->assertJsonPath('today','2026-10-27');
        $due=$this->api('reminders',['per_page'=>25])->assertOk()->assertJsonPath('meta.total',21)->assertJsonCount(21,'data');
        $this->assertStringNotContainsString('Hidden reminders',$this->body($due));
        $this->assertStringNotContainsString('Future reminder',$this->body($due));
        $this->api('reminders',['scope'=>'due','page'=>2])->assertOk()->assertJsonPath('meta.total',21)->assertJsonCount(0,'data');
        $all=$this->api('reminders',['scope'=>'all','page'=>1])->assertOk()->assertJsonPath('meta.total',22)->assertJsonCount(22,'data');
        $this->assertSame('Future reminder',$all->json('data.21.text'));
        $this->assertStringNotContainsString('Hidden reminders',$this->body($all));
        $this->action('complete-reminder',['reminder_id'=>$future])->assertOk();
        $this->assertDatabaseHas('erp_lead_reminders',['id'=>$future,'is_completed'=>1]);
        $this->api('reminders',['scope'=>'all'])->assertOk()->assertJsonPath('meta.total',21);
    }
    public function test_demos_include_leads_beyond_the_first_pipeline_page_without_leaking_other_owners(): void
    {
        $old=$this->lead('Old lead with upcoming demo');
        foreach(range(1,25) as $i) $this->lead('Newer lead '.$i);
        $this->demo($old);
        $hidden=$this->lead('Private demo',['assigned_to'=>$this->colleague->id]); $this->demo($hidden);
        $first=$this->api()->assertOk()->assertJsonPath('meta.total',26)->assertJsonCount(25,'data');
        $this->assertNotContains($old,array_column($first->json('data'),'id'));
        $demos=$this->api('demos')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.leadName','Old lead with upcoming demo');
        $this->assertStringNotContainsString('Private demo',$this->body($demos));
    }
    public function test_recipient_can_accept_transfer_before_accessing_the_lead_and_sender_loses_access(): void
    {
        $lead=$this->lead('Incoming enquiry');
        $this->api((string)$lead)->assertOk();
        $this->action('transfer',['lead_id'=>$lead,'to_user_id'=>$this->colleague->id])->assertOk();
        $transfer=DB::table('erp_lead_transfers')->value('id');
        $this->action('resolve-transfer',['transfer_id'=>$transfer,'status'=>'accepted'])->assertForbidden();
        $this->actingAs($this->colleague);
        $this->api((string)$lead)->assertNotFound();
        $page=$this->api('transfers')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.leadName','Incoming enquiry')->assertJsonPath('data.0.canAccept',true);
        $this->assertStringNotContainsString('919876543210',$this->body($page));
        $this->action('resolve-transfer',['transfer_id'=>$transfer,'status'=>'accepted'])->assertOk();
        $this->api((string)$lead)->assertOk();
        $this->actingAs($this->sales); $this->api((string)$lead)->assertNotFound();
    }
    public function test_mobile_save_returns_to_the_record_and_contact_numbers_are_not_guessed(): void
    {
        $created=$this->action('create-lead',['name'=>'New mobile enquiry','phone'=>'9000012345','country'=>'IN'])->assertOk();
        $lead=DB::table('erp_leads')->first();
        // The client opens the saved record from the returned id.
        $this->assertSame((int)$lead->id,(int)$created->json('result.id'));
        $this->assertSame('https://wa.me/919000012345',SalesPipeline::contact($lead)['whatsapp']);
        $this->assertNull(SalesPipeline::contact((object)['phone'=>'9000012345','country'=>null])['whatsapp']);
        $this->assertSame('https://wa.me/447700900123',SalesPipeline::contact((object)['phone'=>'+44 7700 900123','country'=>'GB'])['whatsapp']);
        $this->action('followup',['lead_id'=>$lead->id,'channel'=>'whatsapp','outcome'=>'Interested','note'=>'Discussed course','next_due_on'=>'2026-10-28'])->assertOk();
        $this->api((string)$lead->id)->assertOk()->assertJsonPath('lead.whatsapp','https://wa.me/919000012345')->assertJsonPath('lead.nextFollowUpOn','2026-10-28');
        $this->api('',['stage'=>'unsupported'])->assertStatus(422)->assertJsonValidationErrors('stage');
    }
}
