<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Overview & reports JSON reads keep the classic role matrix. Synthetic data only. */
class AppOverviewApiTest extends TestCase
{
    private int $unit;
    private int $other;
    private int $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = DB::table('erp_units')->insertGetId(['code' => 'OV-A', 'name' => 'Overview Unit A', 'timezone' => 'UTC']);
        $this->other = DB::table('erp_units')->insertGetId(['code' => 'OV-B', 'name' => 'Private Unit B', 'timezone' => 'UTC']);
        $this->student = DB::table('erp_students')->insertGetId(['unit_id' => $this->unit, 'code' => 'OV-S1', 'name' => 'Synthetic Owing Student', 'registered_on' => '2026-01-05', 'timezone' => 'UTC', 'status' => 'active', 'grade' => '8', 'syllabus' => 'CBSE']);
        DB::table('erp_students')->insert(['unit_id' => $this->unit, 'code' => 'OV-S2', 'name' => 'Synthetic Paused Student', 'registered_on' => '2026-01-06', 'timezone' => 'UTC', 'status' => 'paused', 'grade' => '9', 'syllabus' => 'ICSE']);
        DB::table('erp_ledger')->insert(['unit_id' => $this->unit, 'student_id' => $this->student, 'kind' => 'lesson_charge', 'amount_paise' => -45000, 'currency' => 'INR', 'reference' => 'OV-CHG-1', 'event_key' => 'ov-chg-1', 'occurred_at' => now(), 'created_by' => 1]);
        $teacher = DB::table('erp_teachers')->insertGetId(['code' => 'OV-T1', 'name' => 'Synthetic Teacher', 'timezone' => 'UTC']);
        $subject = DB::table('erp_subjects')->insertGetId(['name' => 'Synthetic Maths']);
        $lesson = ['unit_id' => $this->unit, 'enrollment_id' => 1, 'student_id' => $this->student, 'teacher_id' => $teacher, 'subject_id' => $subject, 'status' => 'scheduled', 'student_rate_paise' => 50000, 'teacher_rate_paise' => 30000];
        DB::table('erp_lessons')->insert($lesson + ['starts_at' => now('UTC')->startOfDay()->addHours(23)->format('Y-m-d H:i:s'), 'ends_at' => now('UTC')->startOfDay()->addHours(23)->addMinutes(30)->format('Y-m-d H:i:s')]);
        DB::table('erp_lessons')->insert($lesson + ['starts_at' => now('UTC')->addDays(3)->format('Y-m-d H:i:s'), 'ends_at' => now('UTC')->addDays(3)->addHour()->format('Y-m-d H:i:s')]);
        DB::table('erp_migration_history')->insert([
            ['batch_id' => 1, 'unit_id' => $this->unit, 'enrollment_id' => 1, 'external_id' => 'OV-HIST-1', 'starts_at' => '2025-06-01 10:00:00', 'actual_minutes' => 60, 'status' => 'completed', 'financial_effect' => 'none'],
            ['batch_id' => 1, 'unit_id' => $this->unit, 'enrollment_id' => 1, 'external_id' => 'OV-HIST-2', 'starts_at' => '2025-06-02 10:00:00', 'actual_minutes' => 45, 'status' => 'no_show', 'financial_effect' => 'none'],
            ['batch_id' => 1, 'unit_id' => $this->other, 'enrollment_id' => 2, 'external_id' => 'OV-SECRET', 'starts_at' => '2025-06-03 10:00:00', 'actual_minutes' => 30, 'status' => 'completed', 'financial_effect' => 'none'],
        ]);
    }

    private function member(string $role, ?int $unit = null)
    {
        $user = $this->createMember(['name' => 'Synthetic '.$role]);
        DB::table('erp_memberships')->insert(['unit_id' => $unit ?? $this->unit, 'user_id' => $user->id, 'role' => $role]);
        return $user;
    }

    private function task(array $values): int
    {
        return DB::table('erp_tasks')->insertGetId($values + ['unit_id' => $this->unit, 'status' => 'open', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_home_shows_every_tile_to_a_manager(): void
    {
        $manager = $this->member('team_manager');
        $this->task(['title' => 'Call the parent', 'assigned_to' => $manager->id, 'due_on' => '2020-01-01']);
        $this->task(['title' => 'Someone else', 'due_on' => '2020-01-01']);
        $json = $this->actingAs($manager)->getJson('/app-api/units/'.$this->unit.'/overview/home')->assertOk()->json();
        $this->assertSame(['today', 'timezone', 'metrics', 'dues', 'todayLessons', 'myTasks', 'unitTasks', 'followupsDue', 'queues'], array_keys($json));
        $this->assertSame(['activeStudents' => 1, 'scheduledLessons' => 2, 'pendingApproval' => 0, 'duplicateReviews' => 0], $json['metrics']);
        $this->assertSame(['count' => 1, 'paise' => -45000], $json['dues']);
        $this->assertSame(1, $json['todayLessons']['count']);
        $this->assertSame(['id', 'startsAt', 'studentName', 'teacherName', 'subjectName', 'kind', 'status'], array_keys($json['todayLessons']['rows'][0]));
        $this->assertStringEndsWith('Z', $json['todayLessons']['rows'][0]['startsAt']);
        $this->assertSame(1, $json['myTasks']['count']);
        $this->assertSame(1, $json['myTasks']['overdue']);
        $this->assertSame(['id', 'title', 'dueOn', 'status'], array_keys($json['myTasks']['rows'][0]));
        $this->assertSame(['count' => 2, 'overdue' => 2], $json['unitTasks']);
        $this->assertSame(['missingSubmission', 'studentImport', 'teacherApproval'], array_keys($json['queues']));
    }

    public function test_home_hides_tiles_for_pages_the_role_lacks(): void
    {
        $finance = $this->actingAs($this->member('finance'))->getJson('/app-api/units/'.$this->unit.'/overview/home')->assertOk()->json();
        $this->assertSame(['activeStudents' => null, 'scheduledLessons' => null, 'pendingApproval' => null, 'duplicateReviews' => null], $finance['metrics']);
        $this->assertSame(-45000, $finance['dues']['paise']);
        $this->assertNull($finance['todayLessons']);
        $this->assertNull($finance['unitTasks']);
        $this->assertNull($finance['queues']['missingSubmission']);
        $this->assertNull($finance['queues']['teacherApproval']);
        $this->assertSame(0, $finance['queues']['studentImport']);

        $sales = $this->actingAs($this->member('sales_followups'))->getJson('/app-api/units/'.$this->unit.'/overview/home')->assertOk()->json();
        $this->assertSame(-45000, $sales['dues']['paise']);
        $this->assertNull($sales['queues']);
        $this->assertSame(0, $sales['followupsDue']);

        $academics = $this->actingAs($this->member('academics'))->getJson('/app-api/units/'.$this->unit.'/overview/home')->assertOk()->json();
        $this->assertNull($academics['dues']);
        $this->assertSame(1, $academics['metrics']['activeStudents']);
        $this->assertNull($academics['unitTasks']);
    }

    public function test_home_and_tasks_refuse_other_units_and_guests(): void
    {
        $this->actingAs($this->member('team_manager'))->getJson('/app-api/units/'.$this->other.'/overview/home')->assertForbidden();
        $this->getJson('/app-api/units/'.$this->other.'/overview/tasks')->assertForbidden();
        $this->getJson('/app-api/units/'.$this->other.'/overview/exports')->assertForbidden();
    }

    public function test_tasks_default_to_mine_and_whitelist_fields(): void
    {
        $academics = $this->member('academics');
        $mine = $this->task(['title' => 'Mark the test', 'assigned_to' => $academics->id, 'student_id' => $this->student, 'due_on' => '2026-02-01']);
        $this->task(['title' => 'Not mine', 'due_on' => '2026-01-01']);
        DB::table('erp_task_items')->insert(['unit_id' => $this->unit, 'task_id' => $mine, 'title' => 'Collect papers', 'created_at' => now(), 'updated_at' => now()]);
        $json = $this->actingAs($academics)->getJson('/app-api/units/'.$this->unit.'/overview/tasks')->assertOk()->json();
        $this->assertSame(1, $json['meta']['total']);
        $this->assertSame(['id', 'title', 'notes', 'dueOn', 'status', 'remainingItems', 'assignedToMe', 'studentName', 'assigneeName', 'items'], array_keys($json['data'][0]));
        $this->assertSame(1, $json['data'][0]['remainingItems']);
        $this->assertNull($json['data'][0]['studentName']);
        $this->assertSame(['id', 'title', 'completedAt'], array_keys($json['data'][0]['items'][0]));
        $this->assertSame(['seeAll' => false, 'create' => false, 'complete' => false, 'completeItem' => false, 'completeOwn' => true], $json['can']);
        $this->getJson('/app-api/units/'.$this->unit.'/overview/tasks?scope=all')->assertForbidden();
        $this->getJson('/app-api/units/'.$this->unit.'/overview/tasks?sort=password')->assertStatus(422);
    }

    public function test_followups_roles_see_all_unit_tasks_with_filters(): void
    {
        $sales = $this->member('sales_followups');
        $this->task(['title' => 'Alpha reminder', 'assigned_to' => $sales->id, 'student_id' => $this->student, 'due_on' => '2026-03-01']);
        $this->task(['title' => 'Beta reminder', 'notes' => 'Private note for the assignee', 'due_on' => '2026-01-01']);
        $this->task(['title' => 'Gamma done', 'status' => 'completed']);
        $json = $this->actingAs($sales)->getJson('/app-api/units/'.$this->unit.'/overview/tasks?scope=all')->assertOk()->json();
        $this->assertSame(2, $json['meta']['total']);
        $this->assertSame('Beta reminder', $json['data'][0]['title']);
        $this->assertNull($json['data'][0]['notes']);
        $this->getJson('/app-api/units/'.$this->unit.'/overview/tasks?scope=all&q=Private')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame('Synthetic Owing Student', $json['data'][1]['studentName']);
        $this->assertSame(['mineOpen' => 1, 'allOpen' => 2], $json['counts']);
        $this->assertTrue($json['can']['create']);
        $this->assertTrue($json['can']['complete']);
        $this->getJson('/app-api/units/'.$this->unit.'/overview/tasks?scope=all&status=completed')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Gamma done');
        $this->getJson('/app-api/units/'.$this->unit.'/overview/tasks?scope=all&q=Alpha')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/app-api/units/'.$this->unit.'/overview/tasks?scope=all&sort=title&dir=desc')->assertOk()->assertJsonPath('data.0.title', 'Beta reminder');
    }

    public function test_read_only_cannot_complete_and_own_task_route_confirms_in_json(): void
    {
        $readOnly = $this->member('read_only');
        $json = $this->actingAs($readOnly)->getJson('/app-api/units/'.$this->unit.'/overview/tasks')->assertOk()->json();
        $this->assertFalse($json['can']['completeOwn']);
        $this->assertFalse($json['can']['complete']);

        // The React page relies on this: the own-task route confirms in JSON; HTML forms still redirect.
        $academics = $this->member('academics');
        $task = $this->task(['title' => 'Mine to close', 'assigned_to' => $academics->id]);
        $this->actingAs($academics)->postJson('/erp/my-tasks/'.$task.'/complete')->assertOk()->assertJson(['message' => 'Your assigned task is complete.']);
        $this->assertSame('completed', DB::table('erp_tasks')->where('id', $task)->value('status'));
    }

    public function test_insights_are_for_managers_and_list_explicit_fields(): void
    {
        $json = $this->actingAs($this->member('read_only'))->getJson('/app-api/units/'.$this->unit.'/overview/insights')->assertOk()->json();
        $this->assertCount(6, $json['months']);
        $this->assertSame(['month', 'label', 'short', 'enquiries', 'demos', 'joined', 'conversion', 'newStudents', 'activeStudents', 'hours', 'cancelled', 'noShows', 'noShowRate', 'billedPaise', 'collectedPaise', 'refundsPaise', 'teacherCostPaise', 'marginPaise', 'duesPaise'], array_keys($json['months'][0]));
        $this->assertSame(45000, $json['months'][5]['billedPaise']);
        $this->actingAs($this->member('academics'))->getJson('/app-api/units/'.$this->unit.'/overview/insights')->assertForbidden();
        $this->actingAs($this->member('finance'))->getJson('/app-api/units/'.$this->unit.'/overview/insights')->assertForbidden();
    }

    public function test_exports_and_history_are_scoped_filtered_and_whitelisted(): void
    {
        $manager = $this->member('team_manager');
        $json = $this->actingAs($manager)->getJson('/app-api/units/'.$this->unit.'/overview/exports')->assertOk()->json();
        $students = collect($json['datasets'])->firstWhere('dataset', 'students');
        $this->assertSame(['dataset' => 'students', 'count' => 2, 'canExport' => true], $students);
        $this->assertSame([['kind' => 'lesson_charge', 'currency' => 'INR', 'entries' => 1, 'amountPaise' => -45000]], $json['ledgerByKind']);
        $this->assertSame(105, $json['archivedHistoricalMinutes']);

        $history = $this->getJson('/app-api/units/'.$this->unit.'/overview/exports/history')->assertOk()->json();
        $this->assertSame(2, $history['meta']['total']);
        $this->assertSame('OV-HIST-2', $history['data'][0]['externalId']);
        $this->assertSame(['id', 'externalId', 'enrollmentId', 'startsAt', 'actualMinutes', 'status', 'financialEffect'], array_keys($history['data'][0]));
        $this->getJson('/app-api/units/'.$this->unit.'/overview/exports/history?status=completed')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.externalId', 'OV-HIST-1');
        $this->getJson('/app-api/units/'.$this->unit.'/overview/exports/history?q=SECRET')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/app-api/units/'.$this->unit.'/overview/exports/history?sort=actual_minutes&dir=asc')->assertOk()->assertJsonPath('data.0.actualMinutes', 45);
        $this->getJson('/app-api/units/'.$this->unit.'/overview/exports/history?sort=batch_id')->assertStatus(422);

        $this->actingAs($this->member('finance'))->getJson('/app-api/units/'.$this->unit.'/overview/exports')->assertForbidden();
        $this->actingAs($this->member('sales_followups'))->getJson('/app-api/units/'.$this->unit.'/overview/exports/history')->assertForbidden();
    }

    public function test_sales_report_follows_the_admissions_page_and_money_rule(): void
    {
        $json = $this->actingAs($this->member('sales_followups'))->getJson('/app-api/units/'.$this->unit.'/overview/sales-report?from=2026-01-01&to=2026-01-31')->assertOk()->json();
        $this->assertSame(['from', 'to', 'timezone', 'staff', 'due', 'counsellors', 'campaigns', 'collections'], array_keys($json));
        $this->assertSame('2026-01-01', $json['from']);
        $this->assertNull($json['collections']);
        $this->getJson('/app-api/units/'.$this->unit.'/overview/sales-report?from=2026-01-01&to=2026-12-31')->assertStatus(422);
        $this->getJson('/app-api/units/'.$this->unit.'/overview/sales-report?from=yesterday')->assertStatus(422);

        $manager = $this->actingAs($this->member('team_manager'))->getJson('/app-api/units/'.$this->unit.'/overview/sales-report')->assertOk()->json();
        $this->assertIsArray($manager['collections']);
        $this->actingAs($this->member('academics'))->getJson('/app-api/units/'.$this->unit.'/overview/sales-report')->assertForbidden();
    }
    public function test_own_tasks_across_teams_for_legacy_roles_with_explicit_fields(): void
    {
        $user = $this->member('sales');
        DB::table('erp_memberships')->insert(['unit_id' => $this->other, 'user_id' => $user->id, 'role' => 'read_only']);
        $archived = DB::table('erp_units')->insertGetId(['code' => 'OV-ARC', 'name' => 'Archived Unit', 'timezone' => 'UTC', 'archived_at' => now()]);
        DB::table('erp_memberships')->insert(['unit_id' => $archived, 'user_id' => $user->id, 'role' => 'sales']);
        $noMembership = DB::table('erp_units')->insertGetId(['code' => 'OV-NOM', 'name' => 'Former Unit', 'timezone' => 'UTC']);
        $later = $this->task(['title' => 'Later own task', 'assigned_to' => $user->id, 'due_on' => '2026-05-01', 'notes' => 'Own notes', 'student_id' => $this->student]);
        $sooner = $this->task(['title' => 'Read-only team task', 'unit_id' => $this->other, 'assigned_to' => $user->id, 'due_on' => '2026-04-01']);
        $this->task(['title' => 'Undated own task', 'assigned_to' => $user->id, 'status' => 'completed']);
        $this->task(['title' => 'Archived team task', 'unit_id' => $archived, 'assigned_to' => $user->id]);
        $this->task(['title' => 'Former team task', 'unit_id' => $noMembership, 'assigned_to' => $user->id]);
        $this->task(['title' => 'Colleague task', 'assigned_to' => $this->member('sales')->id]);
        DB::table('erp_task_items')->insert([['unit_id' => $this->unit, 'task_id' => $later, 'title' => 'Open item'], ['unit_id' => $this->other, 'task_id' => $later, 'title' => 'Corrupt item']]);

        $json = $this->actingAs($user)->getJson('/app-api/me/tasks')->assertOk()->assertJsonPath('meta.total', 3)->json();
        $this->assertSame(['Read-only team task', 'Later own task', 'Undated own task'], array_column($json['data'], 'title'));
        $this->assertSame(['id', 'unitId', 'unitName', 'title', 'notes', 'dueOn', 'status', 'remainingItems', 'canComplete', 'items'], array_keys($json['data'][1]));
        $this->assertSame(['Open item'], array_column($json['data'][1]['items'], 'title'));
        $this->assertSame(1, $json['data'][1]['remainingItems']);
        $this->assertSame('Overview Unit A', $json['data'][1]['unitName']);
        $this->assertTrue($json['data'][1]['canComplete']);
        $this->assertFalse($json['data'][0]['canComplete'], 'Read-only members cannot complete (Operations::ownedTask).');
        $body = json_encode($json);
        foreach (['Archived team task', 'Former team task', 'Colleague task', 'Corrupt item', 'Synthetic Owing Student'] as $hidden) $this->assertStringNotContainsString($hidden, $body);
        $this->assertSame(['Read-only team task', 'Later own task'], array_column($this->getJson('/app-api/me/tasks?status=open')->assertOk()->json('data'), 'title'));
        $this->getJson('/app-api/me/tasks?status=bogus')->assertUnprocessable();
        // The legacy role still has no unit screens.
        $this->getJson('/app-api/units/'.$this->unit.'/overview/tasks')->assertForbidden();
    }

    public function test_own_tasks_refuse_guests_and_users_without_a_current_membership(): void
    {
        $this->getJson('/app-api/me/tasks')->assertUnauthorized();
        $this->actingAs($this->createMember(['name' => 'Synthetic No Team']))->getJson('/app-api/me/tasks')->assertForbidden();
        $this->actingAs($this->createMember(['user_type' => 'superadmin']))->getJson('/app-api/me/tasks')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
