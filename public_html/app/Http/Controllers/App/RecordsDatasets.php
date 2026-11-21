<?php
namespace App\Http\Controllers\App;

use App\Models\User;
use App\Models\WorkHour;
use App\Modules\Academics\Services\AssessmentHistory;
use App\Modules\IdentityAccess\Services\ReportAccess;
use App\Modules\People\Services\StaffProvisioner;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The read-only "Records & administration" sets (the classic /admin screens), one entry per set.
 * Each set lists its columns explicitly; credentials (teacher_dob, dob, submission_key/hash,
 * password and 2FA fields) are never selected. Legacy figures are spreadsheet text, so they are
 * returned exactly as stored and only cast for sorting and totals.
 *
 * Column: [label, type, sortable]. Types: text, number, money, date, datetime, bool, link, list, phone.
 * The row key of a column is camelCase; the SQL alias inside the set's query is its snake_case form.
 */
final class RecordsDatasets
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public const GROUPS = [
        'academic' => ['title' => 'Academic history', 'description' => 'Subjects, topics, assessment records and the original student and teacher registers.'],
        'operations' => ['title' => 'Historical operations', 'description' => 'Original schedules, work-hour records, attendance history and due reports.'],
        'site' => ['title' => 'Site administration', 'description' => 'Site accounts, roles and application settings (read-only here).'],
    ];

    /** Every set slug, including the two non-table pages. */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    /** Paged sets (everything but settings). A constant, so loading routes never builds the registry or touches the database. */
    public const TABLE_SLUGS = ['topics', 'subjects', 'assessments', 'assignments', 'students', 'teachers', 'assessment-reports', 'monthly-reports', 'teacher-hours',
        'work-hours', 'attendance', 'student-attendance', 'dues', 'timetable', 'student-timetable', 'users', 'roles'];

    public static function tableSlugs(): array
    {
        return self::TABLE_SLUGS;
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** CAST a varchar spreadsheet figure for numeric sorting and sums. */
    public static function num(string $expr): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "CAST($expr AS REAL)" : "CAST($expr AS DECIMAL(20,6))";
    }

    /** Copy of TimeTableController::safeMeetingLink: only http(s) links ever reach an href. */
    public static function safeLink(?string $link): ?string
    {
        $link = trim((string) $link);
        if ($link === '') return null;
        if (!preg_match('~^https?://~i', $link)) {
            if (strpos($link, ':') !== false || strpos($link, '//') === 0) return null;
            $link = 'https://'.$link;
        }
        return filter_var($link, FILTER_VALIDATE_URL) ? $link : null;
    }

    /** ReportAccess (classic /markreport and /attendanceReport) reads teacher_name/student_name from a request. */
    private static function reportScope($eloquent, Request $request, array $f)
    {
        $scoped = Request::create('/', 'GET', array_filter([
            'teacher_name' => $f['teacher'] ?? null,
            'student_name' => $f['student'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        $scoped->setUserResolver(fn () => $request->user());
        return app(ReportAccess::class)->scopeTeacherReport($eloquent, $scoped)->toBase();
    }

    private static function distinct(string $table, string $column): \Closure
    {
        return fn () => DB::table($table)->whereNotNull($column)->where($column, '<>', '')
            ->distinct()->orderBy($column)->limit(2000)->pluck($column)->map(fn ($v) => (string) $v)->values()->all();
    }

    private static function timetableTeachers(): array
    {
        $names = collect();
        foreach (self::DAYS as $day) {
            $names = $names->merge(DB::table('time_tables')->whereNotNull($day.'_teacher')->where($day.'_teacher', '<>', '')->distinct()->pluck($day.'_teacher'));
        }
        return $names->map(fn ($v) => (string) $v)->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    private static function lessons(): Builder
    {
        $union = null;
        foreach (self::DAYS as $order => $day) {
            $part = DB::table('time_tables')->whereNotNull($day.'_time')->where($day.'_time', '<>', '')
                ->select(DB::raw("(id * 10 + $order) as id"), 'student_name', 'section', 'class_syllabus', DB::raw("'$day' as day"), DB::raw("$order as day_order"),
                    $day.'_time as time', $day.'_subject as subject', $day.'_teacher as teacher_name', $day.'_hour as hour', $day.'_number as teacher_number', 'link', 'student_number');
            $union = $union ? $union->unionAll($part) : $part;
        }
        return DB::query()->fromSub($union, 'lessons');
    }

    private static function workHourQuery(): Builder
    {
        $onTime = DB::connection()->getDriverName() === 'sqlite'
            ? "CASE WHEN strftime('%Y-%m', w.date_submitted) = strftime('%Y-%m', w.date) THEN 1 ELSE 0 END"
            : 'CASE WHEN YEAR(w.date_submitted) = YEAR(w.date) AND MONTH(w.date_submitted) = MONTH(w.date) THEN 1 ELSE 0 END';
        return DB::table('work_hours as w')->select(
            'w.id', DB::raw('(SELECT m.team FROM masters m WHERE m.student_code = w.student_code ORDER BY m.id LIMIT 1) as team'),
            DB::raw($onTime.' as on_time'), 'w.teacher_name', 'w.teacher_code', 'w.student_name', 'w.student_code', 'w.subject', 'w.date',
            'w.date_submitted', 'w.chapter', 'w.topic', 'w.hour_taken', 'w.homework_status', 'w.hour_payment', 'w.payment_per_hour',
            'w.profit_per_hour', 'w.total_amount');
    }

    private static function attendanceQuery(Request $request, array $f): Builder
    {
        // Same scope and fields as WorkHourController::attendanceReport (admin branch).
        return self::reportScope(WorkHour::query(), $request, $f)
            ->select('id', 'teacher_name', 'teacher_code', 'student_name', 'student_code', 'subject', 'topic', 'date', 'hour_taken', 'date_submitted');
    }

    private static function roleNames(Collection $ids, string $modelType): Collection
    {
        return DB::table('model_has_roles as mr')->join('roles', 'roles.id', '=', 'mr.role_id')
            ->where('mr.model_type', $modelType)->whereIn('mr.model_id', $ids)->orderBy('roles.name')
            ->get(['mr.model_id', 'roles.name'])->groupBy('model_id')->map(fn ($g) => $g->pluck('name')->values()->all());
    }

    public static function all(): array
    {
        static $sets = null;
        if ($sets !== null) return $sets;
        $num = [self::class, 'num'];
        $hours = fn (string $col) => 'SUM('.$num('r.'.$col).')';
        $from = fn (string $col, string $label = 'From') => ['label' => $label, 'type' => 'from', 'column' => $col];
        $to = fn (string $col, string $label = 'To') => ['label' => $label, 'type' => 'to', 'column' => $col];

        return $sets = [
            // ---------------------------------------------------------------- Academic history
            'topics' => [
                'group' => 'academic', 'title' => 'Topics', 'classic' => '/admin/topics',
                'description' => 'Topic names used when teachers report lessons.',
                'query' => fn () => DB::table('topics')->select('id', 'name', 'created_at'),
                'columns' => ['name' => ['Topic', 'text', true], 'createdAt' => ['Added', 'datetime', true]],
                'search' => ['name'], 'sort' => ['name', 'asc'],
                'card' => ['title' => 'name', 'subtitle' => ['createdAt']],
            ],
            'subjects' => [
                'group' => 'academic', 'title' => 'Subjects', 'classic' => '/admin/subjects',
                'description' => 'The original subject list used by mark lists and work-hour records.',
                'query' => fn () => DB::table('subjects')->select('id', 'name', 'description', 'created_at'),
                'columns' => ['name' => ['Subject', 'text', true], 'description' => ['Description', 'text', false], 'createdAt' => ['Added', 'datetime', true]],
                'search' => ['name', 'description'], 'sort' => ['name', 'asc'],
                'card' => ['title' => 'name', 'subtitle' => ['description']],
            ],
            'assessments' => [
                'group' => 'academic', 'title' => 'Assessment records', 'classic' => '/admin/marklist',
                'description' => 'Marks recorded in the original mark list (admin and teacher form entries).',
                // Same resolution as MarklistController::index: admin rows store IDs, public rows store names.
                'query' => fn () => DB::table('marklists')
                    ->leftJoin('subjects', 'marklists.subject', '=', 'subjects.id')
                    ->leftJoin('students', fn ($j) => $j->on('marklists.student_name', '=', DB::raw('CAST(students.id AS CHAR)'))->on('marklists.student_code', '=', 'students.student_code'))
                    ->leftJoin('teachers', fn ($j) => $j->on('marklists.teacher_name', '=', DB::raw('CAST(teachers.id AS CHAR)'))->on('marklists.teacher_code', '=', 'teachers.teacher_code'))
                    ->select('marklists.id', DB::raw('COALESCE(teachers.teacher_name, marklists.teacher_name) as teacher_name'), 'marklists.teacher_code',
                        DB::raw('COALESCE(students.student_name, marklists.student_name) as student_name'), 'marklists.student_code', 'subjects.name as subject',
                        'marklists.chapter', 'marklists.mark_scored', 'marklists.total_mark', 'marklists.mode_of_exam', 'marklists.examination_date', 'marklists.remark', 'marklists.updated_at'),
                'columns' => [
                    'teacherName' => ['Teacher', 'text', true], 'teacherCode' => ['Teacher code', 'text', true], 'studentName' => ['Student', 'text', true],
                    'studentCode' => ['Student code', 'text', true], 'subject' => ['Subject', 'text', true], 'chapter' => ['Chapter / topic', 'text', false],
                    'markScored' => ['Scored', 'number', true], 'totalMark' => ['Out of', 'number', true], 'modeOfExam' => ['Mode', 'text', true],
                    'examinationDate' => ['Exam date', 'date', true], 'remark' => ['Remarks', 'text', false], 'updatedAt' => ['Updated', 'datetime', true],
                ],
                'search' => ['teacher_name', 'teacher_code', 'student_name', 'student_code', 'subject', 'chapter'], 'sort' => ['updatedAt', 'desc'],
                'filters' => [
                    'subject' => ['label' => 'Subject', 'type' => 'select', 'column' => 'subject', 'options' => self::distinct('subjects', 'name')],
                    'from' => $from('examination_date', 'Exam from'), 'to' => $to('examination_date', 'Exam to'),
                ],
                'card' => ['title' => 'studentName', 'subtitle' => ['subject', 'teacherName', 'examinationDate'], 'trailing' => ['markScored', 'totalMark']],
            ],
            'assignments' => [
                'group' => 'academic', 'title' => 'Assignment records', 'classic' => '/admin/master',
                'description' => 'The master sheet: which teacher taught which student, at what hourly rates.',
                'query' => fn () => DB::table('masters')->select('id', 'student_name', 'student_code', 'teacher_name', 'teacher_code', 'team', 'hour_payment', 'payment_per_hour', 'profit_per_hour'),
                'columns' => [
                    'studentName' => ['Student', 'text', true], 'studentCode' => ['Student code', 'text', true], 'teacherName' => ['Teacher', 'text', true],
                    'teacherCode' => ['Teacher code', 'text', true], 'team' => ['Team', 'text', true], 'hourPayment' => ['Hour payment', 'money', true],
                    'paymentPerHour' => ['Payment per hour', 'money', true], 'profitPerHour' => ['Profit per hour', 'money', true],
                ],
                'search' => ['student_name', 'student_code', 'teacher_name', 'teacher_code'], 'sort' => ['studentName', 'asc'],
                'filters' => [
                    'team' => ['label' => 'Team', 'type' => 'select', 'column' => 'team', 'options' => self::distinct('masters', 'team')],
                    'teacher' => ['label' => 'Teacher', 'type' => 'select', 'column' => 'teacher_name', 'options' => self::distinct('masters', 'teacher_name')],
                ],
                'card' => ['title' => 'studentName', 'subtitle' => ['teacherName', 'team'], 'trailing' => ['hourPayment']],
            ],
            'students' => [
                'group' => 'academic', 'title' => 'Student records', 'classic' => '/admin/students',
                'description' => 'The original student register imported from the spreadsheet.',
                'query' => fn () => DB::table('students')->select('id', 'student_name', 'student_code', 'status', 'hour_rate', 'category', 'source', 'hours'),
                'columns' => [
                    'studentName' => ['Student', 'text', true], 'studentCode' => ['Code', 'text', true], 'status' => ['Status', 'text', true],
                    'hourRate' => ['Hour rate', 'money', true], 'category' => ['Category', 'text', true], 'source' => ['Source', 'text', true], 'hours' => ['Hours', 'number', true],
                ],
                'search' => ['student_name', 'student_code'], 'sort' => ['studentName', 'asc'],
                'filters' => [
                    'status' => ['label' => 'Status', 'type' => 'select', 'column' => 'status', 'options' => self::distinct('students', 'status')],
                    'category' => ['label' => 'Category', 'type' => 'select', 'column' => 'category', 'options' => self::distinct('students', 'category')],
                ],
                'card' => ['title' => 'studentName', 'subtitle' => ['studentCode', 'status', 'category'], 'trailing' => ['hourRate']],
            ],
            'teachers' => [
                'group' => 'academic', 'title' => 'Teacher records', 'classic' => '/admin/teachers',
                'description' => 'The original teacher register imported from the spreadsheet.',
                'query' => fn () => DB::table('teachers')->select('id', 'teacher_name', 'teacher_code', 'status', 'type', 'subject', 'medium'),
                'columns' => [
                    'teacherName' => ['Teacher', 'text', true], 'teacherCode' => ['Code', 'text', true], 'status' => ['Status', 'text', true],
                    'type' => ['Type', 'text', true], 'subject' => ['Subject', 'text', true], 'medium' => ['Medium', 'text', true],
                ],
                'search' => ['teacher_name', 'teacher_code', 'subject'], 'sort' => ['teacherName', 'asc'],
                'filters' => [
                    'status' => ['label' => 'Status', 'type' => 'select', 'column' => 'status', 'options' => self::distinct('teachers', 'status')],
                    'type' => ['label' => 'Type', 'type' => 'select', 'column' => 'type', 'options' => self::distinct('teachers', 'type')],
                    'medium' => ['label' => 'Medium', 'type' => 'select', 'column' => 'medium', 'options' => self::distinct('teachers', 'medium')],
                ],
                'card' => ['title' => 'teacherName', 'subtitle' => ['teacherCode', 'subject', 'medium'], 'trailing' => ['status']],
            ],
            'assessment-reports' => [
                'group' => 'academic', 'title' => 'Assessment reports', 'classic' => '/admin/marklistreport',
                'description' => 'Every assessment result: saved results plus the historical mark-list import. Pick a student for their report.',
                'query' => fn (Request $request, array $f) => self::reportScope(app(AssessmentHistory::class)->query(), $request, $f)
                    ->select('id', 'teacher_name', 'teacher_code', 'student_name', 'student_code', 'subject', 'chapter', 'mark_scored', 'total_mark', 'mode_of_exam', 'date_submitted', 'examination_date', 'remark'),
                'columns' => [
                    'studentName' => ['Student', 'text', true], 'studentCode' => ['Student code', 'text', true], 'teacherName' => ['Teacher', 'text', true],
                    'teacherCode' => ['Teacher code', 'text', true], 'subject' => ['Subject', 'text', true], 'chapter' => ['Chapter / topic', 'text', false],
                    'markScored' => ['Scored', 'number', true], 'totalMark' => ['Out of', 'number', true], 'modeOfExam' => ['Mode', 'text', true],
                    'examinationDate' => ['Exam date', 'date', true], 'dateSubmitted' => ['Submitted', 'datetime', true], 'remark' => ['Remarks', 'text', false],
                ],
                'search' => ['student_name', 'teacher_name', 'subject'], 'sort' => ['dateSubmitted', 'desc'],
                // Saved and historical ids overlap in the union, so ties need more than the id.
                'tiebreak' => ['r.examination_date', 'r.student_code', 'r.teacher_code', 'r.subject', 'r.chapter', 'r.mark_scored'],
                'filters' => [
                    'teacher' => ['label' => 'Teacher', 'type' => 'select', 'scoped' => true, 'options' => fn () => app(AssessmentHistory::class)->query()->toBase()->whereNotNull('teacher_name')->where('teacher_name', '<>', '')->distinct()->orderBy('teacher_name')->limit(2000)->pluck('teacher_name')->map(fn ($v) => (string) $v)->all()],
                    'student' => ['label' => 'Student', 'type' => 'select', 'scoped' => true, 'options' => fn () => app(AssessmentHistory::class)->query()->toBase()->whereNotNull('student_name')->where('student_name', '<>', '')->distinct()->orderBy('student_name')->limit(2000)->pluck('student_name')->map(fn ($v) => (string) $v)->all()],
                    'from' => $from('examination_date', 'Exam from'), 'to' => $to('examination_date', 'Exam to'),
                ],
                'totals' => [
                    'assessments' => ['Assessments', 'COUNT(*)', 'number'],
                    'averagePercent' => ['Average score', 'AVG(CASE WHEN '.$num('r.total_mark').' > 0 THEN '.$num('r.mark_scored').' * 100.0 / '.$num('r.total_mark').' END)', 'percent'],
                    'bestPercent' => ['Best score', 'MAX(CASE WHEN '.$num('r.total_mark').' > 0 THEN '.$num('r.mark_scored').' * 100.0 / '.$num('r.total_mark').' END)', 'percent'],
                ],
                'card' => ['title' => 'studentName', 'subtitle' => ['subject', 'teacherName', 'examinationDate'], 'trailing' => ['markScored', 'totalMark']],
            ],

            // ---------------------------------------------------------------- Historical operations
            'monthly-reports' => [
                'group' => 'operations', 'title' => 'Monthly reports', 'classic' => '/admin/monthlyreport',
                'description' => 'Hours taken and class fees per student for a date range.',
                'query' => fn (Request $request, array $f) => DB::table('get_students as g')
                    ->leftJoin('work_hours as w', function ($join) use ($f) {
                        $join->on('w.student_code', '=', 'g.student_code');
                        if (!empty($f['from'])) $join->whereDate('w.date', '>=', $f['from']);
                        if (!empty($f['to'])) $join->whereDate('w.date', '<=', $f['to']);
                    })
                    ->groupBy('g.id', 'g.student_name', 'g.student_code', 'g.team')
                    ->select('g.id', 'g.student_name', 'g.student_code', 'g.team', DB::raw('SUM('.self::num('w.hour_taken').') as hour_taken'), DB::raw('SUM('.self::num('w.total_amount').') as total_amount')),
                'columns' => [
                    'studentName' => ['Student', 'text', true], 'studentCode' => ['Code', 'text', true], 'team' => ['Team', 'text', true],
                    'hourTaken' => ['Total hours', 'number', true], 'totalAmount' => ['Class fees', 'money', true],
                ],
                'search' => ['student_name', 'student_code'], 'sort' => ['studentName', 'asc'],
                'filters' => [
                    'team' => ['label' => 'Team', 'type' => 'select', 'column' => 'team', 'options' => self::distinct('get_students', 'team')],
                    'from' => ['label' => 'From', 'type' => 'from', 'scoped' => true], 'to' => ['label' => 'To', 'type' => 'to', 'scoped' => true],
                ],
                'totals' => ['hours' => ['Total hours', $hours('hour_taken'), 'number'], 'fees' => ['Class fees', $hours('total_amount'), 'money']],
                'card' => ['title' => 'studentName', 'subtitle' => ['studentCode', 'hourTaken'], 'trailing' => ['totalAmount']],
            ],
            'teacher-hours' => [
                'group' => 'operations', 'title' => 'Teacher hour reports', 'classic' => '/admin/workreport',
                'description' => 'Lesson reports teachers submitted through the original spreadsheet form.',
                'query' => fn () => DB::table('teacher_reports')->select('id', 'teacher_name', 'teacher_code', 'student_name', 'student_code', 'subject', 'date', 'date_submitted', 'chapter', 'topic', 'hour_taken', 'homework_status'),
                'columns' => [
                    'teacherName' => ['Teacher', 'text', true], 'teacherCode' => ['Teacher code', 'text', true], 'studentName' => ['Student', 'text', true],
                    'studentCode' => ['Student code', 'text', true], 'subject' => ['Subject', 'text', true], 'date' => ['Class date', 'date', true],
                    'dateSubmitted' => ['Submitted', 'datetime', true], 'chapter' => ['Chapter', 'text', false], 'topic' => ['Topic', 'text', false],
                    'hourTaken' => ['Hours', 'number', true], 'homeworkStatus' => ['Homework', 'text', true],
                ],
                'search' => ['student_name', 'teacher_name', 'student_code', 'teacher_code'], 'sort' => ['dateSubmitted', 'desc'],
                'filters' => [
                    'teacher' => ['label' => 'Teacher', 'type' => 'select', 'column' => 'teacher_name', 'options' => self::distinct('teacher_reports', 'teacher_name')],
                    'from' => $from('date'), 'to' => $to('date'),
                ],
                'totals' => ['hours' => ['Total hours', $hours('hour_taken'), 'number']],
                'card' => ['title' => 'studentName', 'subtitle' => ['teacherName', 'subject', 'date'], 'trailing' => ['hourTaken']],
            ],
            'work-hours' => [
                'group' => 'operations', 'title' => 'Work-hour records', 'classic' => '/admin/workhour',
                'description' => 'Every recorded class hour with its rates and payable amount. Export downloads the filtered rows.',
                'query' => fn () => self::workHourQuery(),
                'columns' => [
                    'onTime' => ['Submission', 'bool', true], 'team' => ['Team', 'text', true], 'teacherName' => ['Teacher', 'text', true],
                    'teacherCode' => ['Teacher code', 'text', true], 'studentName' => ['Student', 'text', true], 'studentCode' => ['Student code', 'text', true],
                    'subject' => ['Subject', 'text', true], 'date' => ['Class date', 'date', true], 'dateSubmitted' => ['Submitted', 'datetime', true],
                    'chapter' => ['Chapter', 'text', false], 'topic' => ['Topic', 'text', false], 'hourTaken' => ['Hours', 'number', true],
                    'homeworkStatus' => ['Homework', 'text', true], 'hourPayment' => ['Hour payment', 'money', true], 'paymentPerHour' => ['Payment per hour', 'money', true],
                    'profitPerHour' => ['Profit per hour', 'money', true], 'totalAmount' => ['Total payable', 'money', true],
                ],
                'search' => ['teacher_name', 'teacher_code', 'student_name', 'student_code', 'subject', 'date'], 'sort' => ['date', 'desc'],
                'filters' => [
                    'teacher' => ['label' => 'Teacher', 'type' => 'select', 'column' => 'teacher_name', 'options' => self::distinct('work_hours', 'teacher_name')],
                    'student' => ['label' => 'Student', 'type' => 'select', 'column' => 'student_name', 'options' => self::distinct('work_hours', 'student_name')],
                    'status' => ['label' => 'Submission', 'type' => 'enum', 'column' => 'on_time', 'options' => ['1' => 'Submitted in the class month', '0' => 'Submitted late']],
                    'from' => $from('date'), 'to' => $to('date'),
                ],
                'totals' => ['hours' => ['Total hours', $hours('hour_taken'), 'number'], 'payable' => ['Total payable', $hours('total_amount'), 'money']],
                'card' => ['title' => 'studentName', 'subtitle' => ['teacherName', 'subject', 'date'], 'trailing' => ['hourTaken']],
            ],
            'attendance' => [
                'group' => 'operations', 'title' => 'Attendance history', 'classic' => '/attendanceReport',
                'description' => 'Delivered hours by teacher, with a total for the period (the classic attendance report).',
                'query' => fn (Request $request, array $f) => self::attendanceQuery($request, $f),
                'columns' => [
                    'teacherName' => ['Teacher', 'text', true], 'studentName' => ['Student', 'text', true], 'date' => ['Date', 'date', true],
                    'hourTaken' => ['Hours', 'number', true], 'subject' => ['Subject', 'text', true], 'topic' => ['Topic', 'text', false],
                ],
                'search' => ['teacher_name', 'student_name', 'subject', 'topic'], 'sort' => ['date', 'desc'],
                'filters' => [
                    'teacher' => ['label' => 'Teacher', 'type' => 'select', 'scoped' => true, 'options' => self::distinct('work_hours', 'teacher_name')],
                    'student' => ['label' => 'Student', 'type' => 'select', 'scoped' => true, 'options' => self::distinct('work_hours', 'student_name')],
                    'from' => $from('date'), 'to' => $to('date'),
                ],
                'totals' => ['hours' => ['Total hours', $hours('hour_taken'), 'number'], 'classes' => ['Classes', 'COUNT(*)', 'number']],
                'card' => ['title' => 'studentName', 'subtitle' => ['teacherName', 'subject', 'date'], 'trailing' => ['hourTaken']],
            ],
            'student-attendance' => [
                'group' => 'operations', 'title' => 'Student attendance history', 'classic' => '/student-attendance-report',
                'description' => 'One student\'s delivered hours across all teachers, with a total for the period.',
                'query' => fn (Request $request, array $f) => self::attendanceQuery($request, $f),
                'columns' => [
                    'studentName' => ['Student', 'text', true], 'date' => ['Date', 'date', true], 'hourTaken' => ['Hours', 'number', true],
                    'subject' => ['Subject', 'text', true], 'topic' => ['Topic', 'text', false], 'teacherName' => ['Teacher', 'text', true],
                ],
                'search' => ['student_name', 'student_code', 'subject', 'topic'], 'sort' => ['date', 'desc'],
                'filters' => [
                    'student' => ['label' => 'Student', 'type' => 'select', 'scoped' => true, 'options' => self::distinct('work_hours', 'student_name')],
                    'from' => $from('date'), 'to' => $to('date'),
                ],
                'totals' => ['hours' => ['Total hours', $hours('hour_taken'), 'number'], 'classes' => ['Classes', 'COUNT(*)', 'number']],
                'card' => ['title' => 'date', 'subtitle' => ['studentName', 'subject', 'teacherName'], 'trailing' => ['hourTaken']],
            ],
            'dues' => [
                'group' => 'operations', 'title' => 'Historical dues', 'classic' => '/admin/duereport',
                'description' => 'The original due report, exactly as imported from the spreadsheet (figures are the sheet\'s text).',
                'query' => fn () => DB::table('due_reports')->select('id', 'student_name', 'student_code', 'phone', 'status', 'hour_rate', 'monthly', 'advance_expected', 'section',
                    'admin_staff', 'last_month_balance', 'recived', 'total_advance', 'hour_taken', 'c_amount', 'error', 'balance', 'current_status'),
                'columns' => [
                    'studentName' => ['Student', 'text', true], 'studentCode' => ['Code', 'text', true], 'phone' => ['WhatsApp', 'phone', false],
                    'status' => ['Status', 'text', true], 'hourRate' => ['Hour rate', 'money', true], 'monthly' => ['Monthly (TT)', 'number', true],
                    'advanceExpected' => ['Advance expected', 'money', true], 'section' => ['Section', 'text', true], 'adminStaff' => ['Admin staff', 'text', true],
                    'lastMonthBalance' => ['Last month balance', 'money', true], 'recived' => ['Received', 'money', true], 'totalAdvance' => ['Total advance', 'money', true],
                    'hourTaken' => ['Hours taken', 'number', true], 'cAmount' => ['C-amount', 'money', true], 'error' => ['Error', 'text', false],
                    'balance' => ['Current balance', 'money', true], 'currentStatus' => ['Current status', 'text', true],
                ],
                'search' => ['student_name', 'student_code', 'admin_staff'], 'sort' => ['studentName', 'asc'],
                'filters' => [
                    'staff' => ['label' => 'Admin staff', 'type' => 'select', 'column' => 'admin_staff', 'options' => self::distinct('due_reports', 'admin_staff')],
                    'status' => ['label' => 'Current status', 'type' => 'select', 'column' => 'current_status', 'options' => self::distinct('due_reports', 'current_status')],
                    'section' => ['label' => 'Section', 'type' => 'select', 'column' => 'section', 'options' => self::distinct('due_reports', 'section')],
                ],
                'card' => ['title' => 'studentName', 'subtitle' => ['studentCode', 'adminStaff', 'currentStatus'], 'trailing' => ['balance']],
            ],
            'timetable' => [
                'group' => 'operations', 'title' => 'Historical timetable', 'classic' => '/admin/timetable',
                'description' => 'The original weekly schedule by teacher.',
                'query' => fn () => self::lessons(),
                'columns' => [
                    'day' => ['Day', 'text', true], 'time' => ['Time', 'text', true], 'teacherName' => ['Teacher', 'text', true], 'studentName' => ['Student', 'text', true],
                    'subject' => ['Subject', 'text', true], 'hour' => ['Duration', 'text', false], 'link' => ['Class link', 'link', false], 'studentNumber' => ['Student number', 'phone', false],
                ],
                'search' => ['student_name', 'teacher_name', 'subject'], 'sort' => ['day', 'asc'], 'sortExpr' => ['day' => 'r.day_order'],
                'filters' => [
                    'teacher' => ['label' => 'Teacher', 'type' => 'select', 'column' => 'teacher_name', 'options' => fn () => self::timetableTeachers()],
                    'day' => ['label' => 'Day', 'type' => 'enum', 'column' => 'day', 'options' => array_combine(self::DAYS, array_map('ucfirst', self::DAYS))],
                ],
                'card' => ['title' => 'studentName', 'subtitle' => ['day', 'time', 'subject'], 'trailing' => ['hour']],
            ],
            'student-timetable' => [
                'group' => 'operations', 'title' => 'Student timetable', 'classic' => '/admin/studentTimetable',
                'description' => 'The original weekly schedule by student.',
                'query' => fn () => self::lessons(),
                'columns' => [
                    'day' => ['Day', 'text', true], 'time' => ['Time', 'text', true], 'studentName' => ['Student', 'text', true], 'teacherName' => ['Teacher', 'text', true],
                    'subject' => ['Subject', 'text', true], 'hour' => ['Duration', 'text', false], 'link' => ['Class link', 'link', false], 'teacherNumber' => ['Teacher number', 'phone', false],
                ],
                'search' => ['student_name', 'teacher_name', 'subject'], 'sort' => ['day', 'asc'], 'sortExpr' => ['day' => 'r.day_order'],
                'filters' => [
                    'student' => ['label' => 'Student', 'type' => 'select', 'column' => 'student_name', 'options' => self::distinct('time_tables', 'student_name')],
                    'day' => ['label' => 'Day', 'type' => 'enum', 'column' => 'day', 'options' => array_combine(self::DAYS, array_map('ucfirst', self::DAYS))],
                ],
                'card' => ['title' => 'teacherName', 'subtitle' => ['day', 'time', 'subject'], 'trailing' => ['hour']],
            ],

            // ---------------------------------------------------------------- Site administration
            'users' => [
                'group' => 'site', 'title' => 'User accounts', 'classic' => '/admin/users',
                'description' => 'Site staff accounts, read-only. Create, edit and deactivate accounts in Teams & accounts.',
                // Same organization boundary as UsersController::index (StaffProvisioner::staffQuery).
                'query' => fn (Request $request) => app(StaffProvisioner::class)->staffQuery($request->user())->toBase()->select('id', 'name', 'email', 'phone'),
                'columns' => ['name' => ['Name', 'text', true], 'email' => ['Email', 'text', true], 'phone' => ['Phone', 'phone', false], 'roles' => ['Site roles', 'list', false]],
                'search' => ['name', 'email'], 'sort' => ['name', 'asc'],
                'decorate' => function (Collection $rows) {
                    $roles = self::roleNames($rows->pluck('id'), User::class);
                    return $rows->map(function ($row) use ($roles) { $row->roles = $roles[$row->id] ?? []; return $row; });
                },
                'card' => ['title' => 'name', 'subtitle' => ['email', 'phone']],
            ],
            'roles' => [
                'group' => 'site', 'title' => 'Site roles', 'classic' => '/admin/roles',
                'description' => 'Site-wide roles and the permissions each grants. Team roles are managed per team in Team & access.',
                'query' => fn () => DB::table('roles as ro')->select('ro.id', 'ro.name', 'ro.guard_name', DB::raw('(SELECT COUNT(*) FROM model_has_roles mr WHERE mr.role_id = ro.id) as members')),
                'columns' => ['name' => ['Role', 'text', true], 'guardName' => ['Guard', 'text', true], 'members' => ['Accounts', 'number', true], 'permissions' => ['Permissions', 'list', false]],
                'search' => ['name'], 'sort' => ['name', 'asc'],
                'decorate' => function (Collection $rows) {
                    $perms = DB::table('role_has_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                        ->whereIn('rp.role_id', $rows->pluck('id'))->orderBy('p.name')->get(['rp.role_id', 'p.name'])->groupBy('role_id');
                    return $rows->map(function ($row) use ($perms) { $row->permissions = ($perms[$row->id] ?? collect())->pluck('name')->values()->all(); return $row; });
                },
                'card' => ['title' => 'name', 'subtitle' => ['permissions']],
            ],
            'settings' => [
                'group' => 'site', 'title' => 'Application settings', 'classic' => '/admin/settings',
                'description' => 'Organization name, contact addresses and social links shown on public pages.',
            ],
        ];
    }
}
