<?php
namespace Tests\Feature;

use App\Models\Marklist;
use App\Models\MarklistReport;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentHistoryWorkflowTest extends TestCase
{
    public function test_saved_result_is_immediately_reportable_without_sheets_and_retries_do_not_duplicate(): void
    {
        $this->createMaster(); $subject = $this->createSubject();
        $payload = ['teacher_name'=>'QA Teacher','teacher_code'=>'T-QA','student_name'=>'QA Student','student_code'=>'S-QA',
            'password'=>'01/01/1990','subject'=>$subject->id,'chapter'=>'Mobile test','total_mark'=>'20','mark_scored'=>'0',
            'examination_date'=>'2026-10-25','mode_of_exam'=>'Written (live)','remark'=>null,'submission_key'=>(string) Str::uuid()];
        $this->postJson('/upload-result', array_merge($payload,['mark_scored'=>'21']))->assertStatus(422);
        $this->postJson('/upload-result', $payload)->assertOk()->assertJsonPath('success',true);
        $this->postJson('/upload-result', $payload)->assertOk();
        $this->assertDatabaseCount('marklists',1);
        $this->actingAs($this->createAdmin());
        // The classic /get-students-mark student picker is now the 'student' filter of the assessment report.
        $report = $this->getJson('/app-api/records/assessment-reports?teacher=QA+Teacher')->assertOk();
        $students = collect($report->json('set.filters'))->firstWhere('key','student')['options'];
        $this->assertContains('QA Student', array_column($students,'value'));
        $report->assertJsonPath('meta.total',1)->assertJsonPath('data.0.studentName','QA Student')
            ->assertJsonPath('data.0.markScored','0')->assertJsonPath('data.0.subject','QA Mathematics');
        $this->getJson('/app-api/records/assessment-reports?teacher=QA+Teacher&from=2026-10-26')->assertOk()->assertJsonPath('meta.total',0);
        $this->getJson('/app-api/records/assessment-reports?teacher=QA+Teacher&student=Unassigned')->assertOk()->assertJsonPath('meta.total',0);
        $this->getJson('/app-api/records/assessment-reports?teacher=QA+Teacher&student=QA+Student')->assertOk()->assertJsonPath('meta.total',1);
    }

    public function test_live_and_imported_results_are_combined_scoped_and_exact_copies_are_not_counted_twice(): void
    {
        $this->createMaster();$subject=$this->createSubject();$this->actingAs($this->createAdmin());
        $fields=['teacher_name'=>'QA Teacher','teacher_code'=>'T-QA','student_name'=>'QA Student','student_code'=>'S-QA',
            'chapter'=>'Algebra','mark_scored'=>'8','total_mark'=>'10','mode_of_exam'=>'Written (live)',
            'examination_date'=>'2026-10-25','remark'=>null];
        $saved=Marklist::create($fields+['subject'=>$subject->id]);
        MarklistReport::create($fields+['subject'=>'QA Mathematics','date_submitted'=>$saved->created_at->format('Y-m-d H:i:s')]);
        MarklistReport::create(array_merge($fields,['chapter'=>'Older result','subject'=>'History','date_submitted'=>'2026-09-01 12:00:00']));
        Marklist::create(array_merge($fields,['subject'=>$subject->id,'teacher_code'=>'OTHER','student_name'=>'Private student']));
        Marklist::create(array_merge($fields,['subject'=>$subject->id,'teacher_name'=>'Another Teacher','student_name'=>'Elsewhere student']));
        $response=$this->getJson('/app-api/records/assessment-reports?teacher=QA+Teacher');
        $response->assertOk()->assertJsonPath('meta.total',3)->assertJsonCount(3,'data')->assertJsonFragment(['studentName'=>'Private student']);
        $this->assertNotContains('Elsewhere student', array_column($response->json('data'),'studentName'));
    }

    public function test_assessment_report_is_for_administrators_only(): void
    {
        $this->actingAs($this->createMember());
        $this->getJson('/app-api/records/assessment-reports')->assertForbidden();
    }
}
