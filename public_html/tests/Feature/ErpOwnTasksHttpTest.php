<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpOwnTasksHttpTest extends TestCase
{
    private $sales; private $teacher; private $unit; private $other; private $own; private $theirs; private $cross; private $item; private $otherItem;
    protected function setUp(): void {
        parent::setUp(); $this->sales=$this->createMember(['name'=>'Synthetic Sales']); $this->teacher=$this->createMember(['name'=>'Synthetic Teacher']);
        $this->unit=DB::table('erp_units')->insertGetId(['code'=>'OWN-AA','name'=>'Synthetic unit']);
        $this->other=DB::table('erp_units')->insertGetId(['code'=>'OWN-BB','name'=>'Other unit']);
        DB::table('erp_memberships')->insert([['unit_id'=>$this->unit,'user_id'=>$this->sales->id,'role'=>'sales'],['unit_id'=>$this->unit,'user_id'=>$this->teacher->id,'role'=>'teacher']]);
        $this->own=DB::table('erp_tasks')->insertGetId(['unit_id'=>$this->unit,'assigned_to'=>$this->sales->id,'title'=>'My sales task','notes'=>'Assigned task instructions','status'=>'in_progress','created_by'=>$this->sales->id]);
        $this->theirs=DB::table('erp_tasks')->insertGetId(['unit_id'=>$this->unit,'assigned_to'=>$this->teacher->id,'title'=>'Other assignee secret','notes'=>'Other task notes','status'=>'open','created_by'=>$this->sales->id]);
        $this->cross=DB::table('erp_tasks')->insertGetId(['unit_id'=>$this->other,'assigned_to'=>$this->sales->id,'title'=>'Unauthorized unit task','status'=>'open','created_by'=>$this->sales->id]);
        $this->item=DB::table('erp_task_items')->insertGetId(['unit_id'=>$this->unit,'task_id'=>$this->own,'title'=>'My checklist item']);
        $this->otherItem=DB::table('erp_task_items')->insertGetId(['unit_id'=>$this->unit,'task_id'=>$this->theirs,'title'=>'Other assignee checklist']);
        DB::table('erp_task_items')->insert(['unit_id'=>$this->other,'task_id'=>$this->own,'title'=>'Corrupt cross-unit checklist']);
        DB::table('erp_students')->insert(['unit_id'=>$this->unit,'code'=>'HIDDEN-STUDENT','name'=>'Sensitive student name','registered_on'=>'2026-01-01','timezone'=>'UTC','status'=>'active','grade'=>'8','syllabus'=>'Test','guardian_contact'=>'PRIVATE-GUARDIAN-CONTACT']);
        DB::table('erp_ledger')->insert(['unit_id'=>$this->unit,'kind'=>'payment','amount_paise'=>99988,'currency'=>'INR','reference'=>'PRIVATE-FINANCE-REFERENCE','event_key'=>'own-http-private','occurred_at'=>now(),'created_by'=>$this->sales->id]);
    }
    /** The own-task list now comes from the unit's My tasks JSON read. */
    private function tasks(int $unit, array $query = []): string { return '/app-api/units/'.$unit.'/overview/tasks'.($query ? '?'.http_build_query($query) : ''); }
    /** Decoded-and-re-encoded body, so text checks see the same characters the old page showed. */
    private function body($response): string { return json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    private function assertSeesOnly($response, array $see, array $dontSee): void
    {
        $body = $this->body($response);
        foreach ($see as $text) $this->assertStringContainsString($text, $body);
        foreach ($dontSee as $text) $this->assertStringNotContainsString($text, $body);
    }
    public function test_own_task_read_shows_only_own_tasks_without_people_finance_or_other_assignment_data(): void {
        // The same privacy rules, on the read that replaced the classic own-task page, for a current
        // staff role without the unit-wide Follow-ups register (legacy "sales" members list own tasks via /app-api/me/tasks).
        DB::table('erp_memberships')->where('user_id',$this->sales->id)->update(['role'=>'academics']);
        $response=$this->actingAs($this->sales)->getJson($this->tasks($this->unit))->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.title','My sales task');
        $this->assertSeesOnly($response,['My sales task','My checklist item','Assigned task instructions'],['Other assignee secret','Other assignee checklist','Unauthorized unit task','Corrupt cross-unit checklist','Sensitive student name','PRIVATE-GUARDIAN-CONTACT','PRIVATE-FINANCE-REFERENCE','99988']);
        $this->getJson($this->tasks($this->unit,['scope'=>'all']))->assertForbidden();
        $this->getJson($this->tasks($this->other))->assertForbidden();
    }
    public function test_legacy_sales_and_teacher_members_can_read_their_own_tasks(): void {
        $response=$this->actingAs($this->sales)->getJson('/app-api/me/tasks')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.title','My sales task');
        $this->assertSeesOnly($response,['My sales task','My checklist item','Assigned task instructions'],['Other assignee secret','Other assignee checklist','Unauthorized unit task','Corrupt cross-unit checklist','Sensitive student name','PRIVATE-GUARDIAN-CONTACT','PRIVATE-FINANCE-REFERENCE','99988']);
        $response=$this->actingAs($this->teacher)->getJson('/app-api/me/tasks')->assertOk();
        $this->assertSeesOnly($response,['Other assignee secret'],['My sales task']);
        DB::table('erp_memberships')->where('user_id',$this->teacher->id)->delete(); $this->getJson('/app-api/me/tasks')->assertForbidden();
    }
    public function test_own_checklist_completion_gates_task_completion_and_is_audited_idempotently(): void {
        $this->actingAs($this->sales);
        $this->postJson(route('erp.tasks.complete',$this->own))->assertStatus(422);
        $this->assertDatabaseHas('erp_tasks',['id'=>$this->own,'status'=>'in_progress']);
        $this->postJson(route('erp.tasks.item.complete',[$this->own,$this->item]))->assertOk()->assertJsonPath('message','Checklist item completed.');
        $this->postJson(route('erp.tasks.item.complete',[$this->own,$this->item]))->assertOk();
        $this->postJson(route('erp.tasks.complete',$this->own))->assertOk()->assertJsonPath('message','Your assigned task is complete.');
        $this->postJson(route('erp.tasks.complete',$this->own))->assertOk();
        $this->assertDatabaseHas('erp_tasks',['id'=>$this->own,'status'=>'completed']);
        $this->assertDatabaseHas('erp_task_items',['id'=>$this->item,'completed_by'=>$this->sales->id]);
        $this->assertSame(1,DB::table('erp_audit')->where('action','task.item_completed')->where('entity_id',$this->item)->count());
        $this->assertSame(1,DB::table('erp_audit')->where('action','task.completed')->where('entity_id',$this->own)->count());
    }
    public function test_other_assignee_crossunit_and_swapped_checklist_ids_are_forbidden(): void {
        $this->actingAs($this->sales);
        $this->post(route('erp.tasks.complete',$this->theirs))->assertForbidden();
        $this->post(route('erp.tasks.complete',$this->cross))->assertForbidden();
        $this->post(route('erp.tasks.item.complete',[$this->own,$this->otherItem]))->assertForbidden();
        $this->post(route('erp.tasks.item.complete',[$this->theirs,$this->otherItem]))->assertForbidden();
        DB::table('erp_tasks')->where('id',$this->own)->update(['assigned_to'=>$this->teacher->id]);
        $this->post(route('erp.tasks.item.complete',[$this->own,$this->item]))->assertForbidden();
        $this->assertSame(0,DB::table('erp_task_items')->whereNotNull('completed_at')->count());
    }
    public function test_teacher_can_complete_own_task_but_membership_revocation_blocks_access(): void {
        $this->actingAs($this->teacher);
        $this->postJson(route('erp.tasks.item.complete',[$this->theirs,$this->otherItem]))->assertOk();
        $this->postJson(route('erp.tasks.complete',$this->theirs))->assertOk();
        $this->assertDatabaseHas('erp_tasks',['id'=>$this->theirs,'status'=>'completed']);
        DB::table('erp_tasks')->where('id',$this->theirs)->update(['status'=>'open']);
        DB::table('erp_memberships')->where('user_id',$this->teacher->id)->delete();
        $this->postJson(route('erp.tasks.complete',$this->theirs))->assertForbidden();
        $this->assertDatabaseHas('erp_tasks',['id'=>$this->theirs,'status'=>'open']);
    }
    public function test_superadmin_without_team_membership_sees_an_empty_task_list(): void {
        $admin=$this->createMember(['user_type'=>'superadmin']);
        $response=$this->actingAs($admin)->getJson($this->tasks($this->unit))->assertOk()->assertJsonPath('meta.total',0)->assertJsonCount(0,'data');
        $this->assertSeesOnly($response,[],['My sales task','Other assignee secret']);
    }
    public function test_deleted_wrong_site_and_guest_access_is_denied(): void {
        // Positive control on a current role, so the refusals below come from the account state, not the role.
        DB::table('erp_memberships')->where('user_id',$this->sales->id)->update(['role'=>'sales_followups']);
        $this->actingAs($this->sales)->getJson($this->tasks($this->unit))->assertOk();
        $this->sales->forceFill(['deleted_at'=>now()])->save(); $this->actingAs($this->sales)->getJson($this->tasks($this->unit))->assertForbidden();
        $this->postJson(route('erp.tasks.complete',$this->own))->assertForbidden();
        $this->sales->forceFill(['deleted_at'=>null,'business_id'=>2])->save(); $this->actingAs($this->sales)->getJson($this->tasks($this->unit))->assertForbidden();
        $this->app['auth']->guard()->logout(); $this->get($this->tasks($this->unit))->assertRedirect(route('login'));
        $this->post(route('erp.tasks.complete',$this->own))->assertRedirect(route('login'));
        $this->assertDatabaseHas('erp_tasks',['id'=>$this->own,'status'=>'in_progress']);
    }
    public function test_admin_own_task_portal_remains_assignment_only(): void {
        $admin=$this->createAdmin(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$admin->id,'role'=>'admin']);
        $task=DB::table('erp_tasks')->insertGetId(['unit_id'=>$this->unit,'assigned_to'=>$admin->id,'title'=>'My administrator assignment','status'=>'open','created_by'=>$admin->id]);
        $response=$this->actingAs($admin)->getJson($this->tasks($this->unit))->assertOk()->assertJsonPath('meta.total',1);
        $this->assertSeesOnly($response,['My administrator assignment'],['My sales task','Other assignee secret','PRIVATE-FINANCE-REFERENCE']);
        $this->postJson(route('erp.tasks.complete',$this->own))->assertForbidden();
        $this->postJson(route('erp.tasks.complete',$task))->assertOk();
        $this->assertDatabaseHas('erp_tasks',['id'=>$task,'status'=>'completed']);
    }

}
