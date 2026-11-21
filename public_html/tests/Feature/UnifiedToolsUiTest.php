<?php
namespace Tests\Feature;
use App\Http\Controllers\App\RecordsDatasets;
use Tests\TestCase;

class UnifiedToolsUiTest extends TestCase
{
    /** Records-API set that replaced each classic /admin record and report screen. */
    private const CLASSIC_TO_SET = ['topics'=>'topics','subjects'=>'subjects','marklist'=>'assessments','master'=>'assignments','students'=>'students',
        'teachers'=>'teachers','monthlyreport'=>'monthly-reports','workreport'=>'work-hours','marklistreport'=>'assessment-reports','workhour'=>'teacher-hours',
        'duereport'=>'dues','timetable'=>'timetable','studentTimetable'=>'student-timetable','users'=>'users','roles'=>'roles'];

    public function test_all_record_and_administration_pages_use_the_workspace_shell(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->insert(['name'=>'ClapsLearn','phone'=>'','support_mail'=>'support@example.invalid','admin_mail'=>'admin@example.invalid','career_mail'=>'career@example.invalid']);
        $this->actingAs($this->createAdmin());
        foreach (self::CLASSIC_TO_SET as $classic => $set) {
            $this->assertContains($set, RecordsDatasets::tableSlugs());
            $this->getJson('/app-api/records/'.$set)->assertOk()->assertJsonPath('set.slug',$set)->assertJsonStructure(['data','meta'=>['total']]);
        }
        $this->getJson('/app-api/records/settings')->assertOk()->assertJsonPath('settings.name','ClapsLearn');
        $overview = $this->getJson('/app-api/records')->assertOk();
        $titles = array_column($overview->json('sets'),'title');
        $this->assertContains('Monthly reports', $titles);
        $this->assertContains('Topics', $titles);
        $this->get('/app/records')->assertOk()->assertSee('id="erp-session"',false);
        // The classic /admin screens, /legacy and /tools were removed with the old UI.
        $this->get('/admin/topics')->assertNotFound();
        $this->get('/tools')->assertNotFound();
    }

    public function test_teacher_routes_share_navigation_without_bypassing_authentication(): void
    {
        foreach (['timetableReport'=>'timetable','attendance'=>'attendance','resultform'=>'result'] as $path => $form) {
            $this->get('/'.$path)->assertOk()->assertSee('"kind":"public","form":"'.$form.'"',false)->assertSee('meta name="csrf-token"',false);
        }
        // The classic attendance, mark and student-attendance reports are records sets; guests are refused.
        foreach (['attendance','assessment-reports','student-attendance'] as $set) $this->getJson('/app-api/records/'.$set)->assertUnauthorized();
        $this->actingAs($this->createAdmin());
        foreach (['timetableReport','attendance','resultform'] as $path) $this->get('/'.$path)->assertOk()->assertSee('meta name="csrf-token"',false);
        foreach (['attendance','assessment-reports','student-attendance'] as $set) $this->getJson('/app-api/records/'.$set)->assertOk();
    }

    public function test_nonadministrators_cannot_access_the_redesigned_admin_routes(): void
    {
        $this->actingAs($this->createMember());
        foreach (['topics','assessment-reports','monthly-reports','users','roles','settings',''] as $set) $this->getJson('/app-api/records/'.$set)->assertForbidden();
        foreach (['topics','users'] as $set) $this->get('/app-api/records/'.$set.'/export')->assertForbidden();
    }
}
