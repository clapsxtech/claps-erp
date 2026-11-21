<?php
namespace App\Modules\Integrations\Services;

use App\Models\MarklistReport;
use App\Models\Master;
use App\Models\TimeTable;
use Carbon\Carbon;
use Google\Client;
use Google\Service\Sheets;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scheduled refresh of the Sheets-era snapshots shown read-only under Records
 * (assignments, timetable, mark list). Run by `fetch:sheet-data` when
 * integrations.sheets.enabled is on. Each import validates every row first and
 * replaces the table in one transaction, so a bad sheet changes nothing.
 * Moved unchanged from the old admin controllers when their screens were removed.
 */
class LegacySnapshotSync
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function syncAll(): void
    {
        $this->importMasters(app(SheetSnapshotReader::class)->read('MIXED'));
        $this->importTimetable($this->sheet('TT'));
        $this->importMarklist($this->sheet('MARKLIST'));
    }

    /** @return array<int, array<int, mixed>> */
    private function sheet(string $range): array
    {
        if (!config('integrations.sheets.spreadsheet_id') || !is_readable((string) config('integrations.sheets.credentials_path'))) {
            throw new \RuntimeException('Spreadsheet synchronization is not configured.');
        }
        $client = new Client();
        $client->setApplicationName('claps-erp');
        $client->setScopes([Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig(config('integrations.sheets.credentials_path'));
        return (new Sheets($client))->spreadsheets_values->get(config('integrations.sheets.spreadsheet_id'), $range)->getValues() ?: [];
    }

        public function importMasters(array $values): void
    {
        $records = []; $teachers = []; $names = []; $students = []; $pairs = [];
        foreach (SheetSnapshotReader::rows($values, 8) as $row) {
            $record = array_combine(['student_code', 'student_name', 'teacher_code', 'teacher_name', 'teacher_dob',
                'hour_payment', 'profit_per_hour', 'payment_per_hour', 'team'], array_slice(array_pad($row, 9, ''), 0, 9));
            SheetSnapshotReader::requireFields($record, ['student_code', 'student_name', 'teacher_code', 'teacher_name', 'teacher_dob']);
            $record['hour_payment'] = SheetSnapshotReader::decimal($record['hour_payment']);
            $record['payment_per_hour'] = SheetSnapshotReader::decimal($record['payment_per_hour']);
            $record['profit_per_hour'] = SheetSnapshotReader::decimal($record['profit_per_hour'], true);
            $teacher = [$record['teacher_name'], $record['teacher_dob']];
            $teacherCode = $record['teacher_code']; $studentCode = $record['student_code'];
            $name = mb_strtolower($record['teacher_name']);
            if (isset($teachers[$teacherCode]) && $teachers[$teacherCode] !== $teacher) SheetSnapshotReader::invalid();
            if (isset($names[$name]) && $names[$name] !== $teacherCode) SheetSnapshotReader::invalid();
            if (isset($students[$studentCode]) && $students[$studentCode] !== $record['student_name']) SheetSnapshotReader::invalid();
            $teachers[$teacherCode] = $teacher; $names[$name] = $teacherCode; $students[$studentCode] = $record['student_name'];
            $key = json_encode([$teacherCode, $studentCode]);
            if (isset($pairs[$key]) && $pairs[$key] !== $record) SheetSnapshotReader::invalid();
            $pairs[$key] = $record;
        }
        app(ReplaceSnapshot::class)->replace(Master::class, array_values($pairs));
    }

    public function importTimetable(array $values): void
    {
        $records = $this->timetableRecords($values);
        DB::transaction(function () use ($records) {
            TimeTable::query()->delete();
            foreach (array_chunk($records, 100) as $chunk) {
                TimeTable::insert($chunk);
            }
        });
    }

    public function importMarklist(array $values): void
    {
        $records = $this->marklistRecords($values);
        DB::transaction(function () use ($records) {
            MarklistReport::query()->delete();
            foreach (array_chunk($records, 100) as $chunk) {
                MarklistReport::insert($chunk);
            }
        });
    }

        private function timetableRecords(array $values): array
    {
        $columns = ['student_name', 'section', 'class_syllabus'];
        foreach (self::DAYS as $day) {
            foreach (['time', 'subject', 'teacher', 'hour', 'number'] as $field) {
                $columns[] = $day.'_'.$field;
            }
        }
        $columns = array_merge($columns, ['link', 'student_number']);
        $records = [];
        foreach (array_slice($values, 1) as $row) {
            if (!is_array($row) || count($row) < 3 || count($row) > count($columns)) {
                throw ValidationException::withMessages(['spreadsheet' => 'The timetable contains an invalid row. No data was changed.']);
            }
            $record = [];
            foreach ($columns as $index => $column) {
                $value = $row[$index] ?? null;
                if ($value !== null && (!is_scalar($value) || mb_strlen((string) $value) > 255)) {
                    throw ValidationException::withMessages(['spreadsheet' => 'The timetable contains an invalid cell. No data was changed.']);
                }
                $record[$column] = $value === null ? null : trim((string) $value);
            }
            if (empty($record['student_name'])) {
                throw ValidationException::withMessages(['spreadsheet' => 'Each timetable row must identify a student. No data was changed.']);
            }
            $hasSchedule = false;
            foreach (self::DAYS as $day) {
                $hasTime = !empty($record[$day.'_time']);
                $hasTeacher = !empty($record[$day.'_teacher']);
                if ($hasTime !== $hasTeacher) {
                    throw ValidationException::withMessages(['spreadsheet' => 'Each lesson must identify its time and teacher. No data was changed.']);
                }
                $hasSchedule = $hasSchedule || $hasTime;
            }
            if (!$hasSchedule) {
                throw ValidationException::withMessages(['spreadsheet' => 'The timetable contains a row without lessons. No data was changed.']);
            }
            $record['created_at'] = now();
            $record['updated_at'] = now();
            $records[] = $record;
        }
        if (!$records) {
            throw ValidationException::withMessages(['spreadsheet' => 'The timetable contains no importable rows. No data was changed.']);
        }
        return $records;
    }

        private function marklistRecords(array $values): array
    {
        // Canonical result submissions contain 12 fields. A legacy thirteenth
        // credential field is deliberately ignored and never copied or exported.
        $columns = ['teacher_name', 'teacher_code', 'student_name', 'student_code', 'subject', 'chapter', 'mark_scored', 'total_mark', 'mode_of_exam', 'examination_date', 'remark', 'date_submitted'];
        $records = [];
        foreach (array_slice($values, 1) as $row) {
            if (!is_array($row) || count($row) < 12 || count($row) > 13) {
                throw ValidationException::withMessages(['spreadsheet' => 'The mark-list contains an invalid row. No data was changed.']);
            }
            $record = [];
            foreach ($columns as $index => $column) {
                $value = $row[$index] ?? null;
                if ($value !== null && (!is_scalar($value) || mb_strlen((string) $value) > 255)) {
                    throw ValidationException::withMessages(['spreadsheet' => 'The mark-list contains an invalid cell. No data was changed.']);
                }
                $record[$column] = $value === null ? null : trim((string) $value);
            }
            foreach (['teacher_name', 'teacher_code', 'student_name', 'student_code', 'subject'] as $field) {
                if (empty($record[$field])) {
                    throw ValidationException::withMessages(['spreadsheet' => 'Each mark-list row must identify its teacher, student and subject. No data was changed.']);
                }
            }
            if (!is_numeric($record['mark_scored']) || !is_numeric($record['total_mark'])
                || (float) $record['mark_scored'] < 0 || (float) $record['total_mark'] <= 0
                || (float) $record['mark_scored'] > (float) $record['total_mark']) {
                throw ValidationException::withMessages(['spreadsheet' => 'The mark-list contains invalid marks. No data was changed.']);
            }
            $record['examination_date'] = $this->parseDate($record['examination_date'], ['Y-m-d', 'd/m/Y'], 'Y-m-d');
            $record['date_submitted'] = $this->parseDate($record['date_submitted'], ['Y-m-d H:i:s', 'd/m/Y H:i:s', 'Y-m-d', 'd/m/Y'], 'Y-m-d H:i:s');
            $record['created_at'] = now();
            $record['updated_at'] = now();
            $records[] = $record;
        }
        if (!$records) {
            throw ValidationException::withMessages(['spreadsheet' => 'The mark-list contains no importable rows. No data was changed.']);
        }
        return $records;
    }

        private function parseDate(?string $value, array $formats, string $output): string
    {
        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, (string) $value);
                if ($date && $date->format($format) === $value) {
                    return $date->format($output);
                }
            } catch (\Throwable $exception) {
                // Try the next explicitly supported spreadsheet format.
            }
        }
        throw ValidationException::withMessages(['spreadsheet' => 'The mark-list contains an invalid date. No data was changed.']);
    }
}
