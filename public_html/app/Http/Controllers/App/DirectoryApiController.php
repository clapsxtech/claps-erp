<?php
namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Admissions, SalesPipeline, TeacherDirectory, TeamPermissions};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales teacher directory (classic Admissions "Teachers" view, erp/crm/teachers):
 * find a teacher, call or message them, and send their profile to a parent by
 * WhatsApp. Only the fields the classic cards show.
 */
class DirectoryApiController extends Controller
{
    public function index(Request $request, int $unit)
    {
        $request->validate(['page' => 'nullable|integer|min:1|max:100000', 'for_lead' => 'nullable|integer|min:1']);
        $business = DB::table('erp_units')->where('id', $unit)->first(['id', 'name', 'timezone']);
        // The service pages with "teachers_page" and validates its own filters.
        $request->query->set('teachers_page', (int) $request->query('page', 1));
        $teachers = (new TeacherDirectory())->list($unit, $request->query(), $business->timezone ?: 'UTC');
        $profileLead = null;
        if ($request->filled('for_lead')) {
            // Only an enquiry this user may see, exactly like the classic for_lead.
            $profileLead = (new Admissions())->visibleLeads($unit, (int) $request->user()->id)->where('l.id', $request->integer('for_lead'))->first();
        }
        $parentWhatsapp = $profileLead ? SalesPipeline::contact($profileLead)['whatsapp'] : null;
        $subjects = DB::table('erp_subjects')->whereIn('id', DB::table('erp_eligibilities')->whereIn('teacher_id', DB::table('erp_teacher_units')->where('unit_id', $unit)->select('teacher_id'))->select('subject_id'))->orderBy('name')->get(['id', 'name']);
        return response()->json([
            'data' => collect($teachers->items())->map(function ($t) use ($business, $parentWhatsapp) {
                $contact = SalesPipeline::teacherContact($t->phone);
                return [
                    'id' => (int) $t->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'ttHours' => $t->tt_hours,
                    'registeredOn' => $t->registered_sort ? substr((string) $t->registered_sort, 0, 10) : null,
                    'category' => $t->category,
                    'levels' => $t->level_list,
                    'subjects' => $t->subjects,
                    'classes' => $t->classes,
                    'mediums' => $t->mediums,
                    'qualification' => $t->qualification,
                    'dial' => $contact['dial'],
                    'whatsapp' => $contact['whatsapp'],
                    'profileUrl' => SalesPipeline::whatsappMessage($parentWhatsapp, SalesPipeline::teacherProfileMessage($t, $business)),
                ];
            })->values(),
            'meta' => ['page' => $teachers->currentPage(), 'perPage' => $teachers->perPage(), 'total' => $teachers->total()],
            'filters' => [
                'levels' => collect(TeacherDirectory::LEVELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'categories' => TeacherDirectory::CATEGORIES,
                'subjects' => $subjects->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->values(),
                'sorts' => collect(TeacherDirectory::SORTS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            ],
            'profileLead' => $profileLead ? ['id' => (int) $profileLead->id, 'name' => SalesPipeline::name($profileLead), 'hasWhatsapp' => $parentWhatsapp !== null] : null,
            'can' => ['classify' => (new TeamPermissions())->allows($request->user(), $unit, 'action', 'teacher-classify')],
        ]);
    }
}
