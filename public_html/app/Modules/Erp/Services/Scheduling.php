<?php

namespace App\Modules\Erp\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** All dates entered here are local dates; lesson timestamps and audit timestamps are UTC. */
class Scheduling
{
    private function fail($message) { throw ValidationException::withMessages(['schedule' => $message]); }
    private function now() { return gmdate('Y-m-d H:i:s'); }
    private function required(array $p, array $keys) { foreach ($keys as $key) if (!isset($p[$key]) || $p[$key] === '') $this->fail("{$key} is required."); }
    private function date($value) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value, new DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d') !== $value) $this->fail('Use a valid YYYY-MM-DD date.');
        return $value;
    }
    private function integer($value, $min, $max, $label) {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) $this->fail("Invalid {$label}.");
        return (int) $value;
    }
    private function zone($name) {
        if (!in_array($name, DateTimeZone::listIdentifiers(), true) && $name !== 'UTC') $this->fail('Use an IANA timezone.');
        return new DateTimeZone($name);
    }
    /** Reject both nonexistent and ambiguous DST wall-clock times rather than silently shifting them. */
    public function localToUtc(string $date, string $time, string $timezone): string {
        $this->date($date);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) $this->fail('Use HH:mm time.');
        $zone = $this->zone($timezone);
        $wall = strtotime($date.' '.$time.':00 UTC');
        $offsets = [];
        foreach ($zone->getTransitions($wall - 172800, $wall + 172800) ?: [] as $t) $offsets[$t['offset']] = true;
        $offsets[$zone->getOffset(new DateTimeImmutable('@'.$wall))] = true;
        $matches = [];
        foreach (array_keys($offsets) as $offset) {
            $candidate = (new DateTimeImmutable('@'.($wall - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i') === $date.' '.$time) $matches[] = $wall - $offset;
        }
        if (count($matches) !== 1) $this->fail('This local time is nonexistent or ambiguous because of DST. Choose an unambiguous time.');
        return gmdate('Y-m-d H:i:s', $matches[0]);
    }
    private function row($table, $id, $unitId = null, bool $current = false) {
        $q = DB::table($table)->where('id', $id);
        if ($unitId !== null) $q->where('unit_id', $unitId);
        // FOR UPDATE is an InnoDB current read, unlike snapshot reads under REPEATABLE READ.
        if ($current) $q->lockForUpdate();
        $row = $q->first();
        if (!$row) $this->fail('Record not found in this unit.');
        return $row;
    }
    private function parents($unitId, $teacherId, $studentId, bool $requireActive = false) {
        // Same lock order in every scheduling mutation; teachers are global, not unit-scoped.
        $teacher = DB::table('erp_teachers')->where('id', $teacherId)->lockForUpdate()->first();
        $student = DB::table('erp_students')->where('id', $studentId)->lockForUpdate()->first();
        if (!$teacher || !$student || (int)$student->unit_id !== (int)$unitId) $this->fail('Student or teacher does not belong to this unit.');
        if (DB::table('erp_teacher_units')->where('teacher_id', $teacherId)->lockForUpdate()->get()->count() !== 1) $this->fail('Teacher must belong to exactly one unit pool. Correct the teacher membership before scheduling.');
        if (!DB::table('erp_teacher_units')->where('teacher_id', $teacherId)->where('unit_id', $unitId)->lockForUpdate()->first()) $this->fail('Teacher does not belong to this unit.');
        if ($requireActive && ($teacher->status !== 'active' || $student->status !== 'active')) $this->fail('Student and teacher must be active before creating or changing scheduled teaching.');
        return [$teacher, $student];
    }
    private function insert($table, array $values) {
        $values['created_at'] = $values['updated_at'] = $this->now();
        return $this->row($table, DB::table($table)->insertGetId($values));
    }
    private function audit($unit, $actor, $action, $type, $id, array $payload = []) { Audit::record($unit, $actor, $action, $type, $id, $payload); }

    public function assign(int $unitId, int $actorId, array $p) {
        $this->required($p, ['student_id','teacher_id','subject_id','starts_on','student_rate_paise','teacher_rate_paise']);
        return DB::transaction(function () use ($unitId, $actorId, $p) {
            [$teacher, $student] = $this->parents($unitId, $p['teacher_id'], $p['student_id'],true);
            if ($teacher->status !== 'active' || $student->status !== 'active') $this->fail('Student and teacher must be active.');
            $start = $this->date($p['starts_on']); $end = isset($p['ends_on']) && $p['ends_on'] !== '' ? $this->date($p['ends_on']) : null;
            if ($end && $end < $start) $this->fail('End date precedes start date.');
            $this->row('erp_subjects', $p['subject_id']);
            if (!DB::table('erp_eligibilities')->where('teacher_id',$teacher->id)->where('subject_id',$p['subject_id'])->where('grade',$student->grade)->where('syllabus',$student->syllabus)->lockForUpdate()->first()) $this->fail('Teacher is not eligible for this subject, grade and syllabus.');
            $q = DB::table('erp_enrollments')->where('student_id',$student->id)->where('subject_id',$p['subject_id'])->where(function ($q) use ($start) { $q->whereNull('ends_on')->orWhere('ends_on','>=',$start); });
            if ($end) $q->where('starts_on','<=',$end);
            if ($q->lockForUpdate()->first()) $this->fail('An assignment already covers these dates for this student and subject. To add another weekly class to it, use Add a weekly slot on the Timetable.');
            $row = $this->insert('erp_enrollments', ['unit_id'=>$unitId,'student_id'=>$student->id,'teacher_id'=>$teacher->id,'subject_id'=>$p['subject_id'],'starts_on'=>$start,'ends_on'=>$end,'student_rate_paise'=>$this->integer($p['student_rate_paise'],0,100000000,'student rate'),'teacher_rate_paise'=>$this->integer($p['teacher_rate_paise'],0,100000000,'teacher rate'),'currency'=>'INR','status'=>'active']);
            $this->audit($unitId,$actorId,'assignment.created','enrollment',$row->id); return $row;
        });
    }
    /** Close prospectively, then create a new assignment for a teacher/rate change. Never mutate old rates. */
    /** Cancel every scheduled lesson for a student within a date window (exam break, holiday). Lessons after it stay booked. */
    public function pauseClasses(int $unitId, int $actorId, array $p) {
        $this->required($p,['student_id','from','through','reason']);
        $from = $this->date($p['from']); $through = $this->date($p['through']); $reason = trim((string) $p['reason']);
        if ($through < $from) $this->fail('The pause must end on or after the day it starts.');
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($through))->days > 366) $this->fail('Pause for at most a year. To end classes, stop them instead.');
        return DB::transaction(function () use ($unitId,$actorId,$p,$from,$through,$reason) {
            $student = $this->row('erp_students',$p['student_id'],$unitId,true);
            $start = $this->localToUtc($from,'00:00',$student->timezone);
            $end = $this->localToUtc((new DateTimeImmutable($through))->modify('+1 day')->format('Y-m-d'),'00:00',$student->timezone);
            if ($start < $this->now()) $start = $this->now();
            $ids = $this->cancelScheduled($unitId,$student->id,$start,$end,'Paused: '.$reason);
            $this->audit($unitId,$actorId,'student.classes_paused','student',$student->id,['from'=>$from,'through'=>$through,'reason'=>$reason,'cancelled_lesson_ids'=>$ids]);
            return (object) ['student_id'=>$student->id,'cancelled'=>count($ids)];
        });
    }

    /** Stop a student's classes after their last class day: cancel later lessons and end every assignment. */
    public function stopClasses(int $unitId, int $actorId, array $p) {
        $this->required($p,['student_id','last_day','reason']);
        $last = $this->date($p['last_day']); $reason = trim((string) $p['reason']);
        if ($last < gmdate('Y-m-d', strtotime('-1 day'))) $this->fail('Choose today or a later last class day.');
        return DB::transaction(function () use ($unitId,$actorId,$p,$last,$reason) {
            $student = $this->row('erp_students',$p['student_id'],$unitId,true);
            $cutoff = $this->localToUtc((new DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d'),'00:00',$student->timezone);
            $ids = $this->cancelScheduled($unitId,$student->id,max($cutoff,$this->now()),'9999-12-31 00:00:00','Stopped: '.$reason);
            $ended = [];
            foreach (DB::table('erp_enrollments')->where('unit_id',$unitId)->where('student_id',$student->id)->where('status','active')->where(function($q) use ($last) { $q->whereNull('ends_on')->orWhere('ends_on','>',$last); })->orderBy('id')->get() as $e) {
                $this->endAssignment($unitId,$actorId,['enrollment_id'=>$e->id,'ends_on'=>max($last,$e->starts_on,gmdate('Y-m-d'))]);
                $ended[] = $e->id;
            }
            $remaining = DB::table('erp_lessons')->where('unit_id',$unitId)->where('student_id',$student->id)->where('status','scheduled')->where('ends_at','>',$this->now())->exists();
            if (!$remaining) DB::table('erp_students')->where('id',$student->id)->update(['status'=>'stopped','updated_at'=>$this->now()]);
            $this->audit($unitId,$actorId,'student.classes_stopped','student',$student->id,['last_day'=>$last,'reason'=>$reason,'cancelled_lesson_ids'=>$ids,'ended_enrollment_ids'=>$ended,'status_stopped'=>!$remaining]);
            return (object) ['student_id'=>$student->id,'cancelled'=>count($ids),'ended_assignments'=>count($ended),'stopped'=>!$remaining];
        });
    }

    /** Move one subject to a new teacher from a date: same student, subject and weekly slots, re-confirmed with the new teacher. */
    public function switchTeacher(int $unitId, int $actorId, array $p) {
        $this->required($p,['enrollment_id','teacher_id','from','teacher_rate_paise','reason','confirmation_channel','teacher_confirmed','student_confirmed','confirmation_note']);
        $from = $this->date($p['from']); $reason = trim((string) $p['reason']);
        return DB::transaction(function () use ($unitId,$actorId,$p,$from,$reason) {
            $old = $this->row('erp_enrollments',$p['enrollment_id'],$unitId,true);
            if ((int) $old->teacher_id === (int) $p['teacher_id']) $this->fail('Choose a different teacher.');
            $student = $this->row('erp_students',$old->student_id,$unitId,true);
            $today = (new DateTimeImmutable('now',$this->zone($student->timezone)))->format('Y-m-d');
            if ($from <= $today) $this->fail('Switch from tomorrow or later; today\'s classes stay with the current teacher.');
            if ($from <= $old->starts_on || ($old->ends_on && $from > $old->ends_on)) $this->fail('The switch date must fall within the current assignment.');
            $cutoff = $this->localToUtc($from,'00:00',$student->timezone);
            $agreements = DB::table('erp_agreements')->where('unit_id',$unitId)->where('enrollment_id',$old->id)->where('status','confirmed')->where(function($q) use ($from) { $q->whereNull('ends_on')->orWhere('ends_on','>=',$from); })->orderBy('id')->get();
            $through = [];
            foreach ($agreements as $a) {
                $last = DB::table('erp_lessons')->where('agreement_id',$a->id)->where('status','scheduled')->where('starts_at','>=',$cutoff)->max('starts_at');
                if ($last) $through[$a->id] = (new DateTimeImmutable($last.' UTC'))->setTimezone($this->zone($a->timezone))->format('Y-m-d');
            }
            $cancelled = DB::table('erp_lessons as l')->where('l.unit_id',$unitId)->where('l.enrollment_id',$old->id)->where('l.status','scheduled')->where('l.starts_at','>=',$cutoff)
                ->whereNotExists(function($q) { $q->selectRaw('1')->from('erp_attendance as a')->whereColumn('a.lesson_id','l.id'); })->lockForUpdate()->pluck('l.id')->all();
            if ($cancelled) DB::table('erp_lessons')->whereIn('id',$cancelled)->update(['status'=>'cancelled','cancellation_reason'=>mb_substr('Teacher changed: '.$reason,0,500),'updated_at'=>$this->now()]);
            $this->endAssignment($unitId,$actorId,['enrollment_id'=>$old->id,'ends_on'=>(new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d')]);
            $new = $this->assign($unitId,$actorId,['student_id'=>$old->student_id,'teacher_id'=>$p['teacher_id'],'subject_id'=>$old->subject_id,'starts_on'=>$from,'ends_on'=>$old->ends_on,
                'student_rate_paise'=>$p['student_rate_paise'] ?? $old->student_rate_paise,'teacher_rate_paise'=>$p['teacher_rate_paise']]);
            $lessons = 0;
            foreach ($agreements as $a) {
                $starts = max($from,$a->starts_on);
                $proposal = $this->proposeAgreement($unitId,$actorId,['enrollment_id'=>$new->id,'weekday'=>$a->weekday,'start_time'=>substr($a->start_time,0,5),'duration_minutes'=>$a->duration_minutes,'timezone'=>$a->timezone,'starts_on'=>$starts,'ends_on'=>$a->ends_on && (!$old->ends_on || $a->ends_on <= $old->ends_on) ? $a->ends_on : $old->ends_on]);
                $this->confirmAgreement($unitId,$actorId,['agreement_id'=>$proposal->id,'confirmation_channel'=>$p['confirmation_channel'],'teacher_confirmed'=>$p['teacher_confirmed'],'student_confirmed'=>$p['student_confirmed'],'confirmation_note'=>$p['confirmation_note']]);
                if (isset($through[$a->id])) {
                    $this->materialize($unitId,$actorId,['agreement_id'=>$proposal->id,'from'=>$starts,'through'=>$through[$a->id]]);
                    $lessons += DB::table('erp_lessons')->where('agreement_id',$proposal->id)->count();
                }
            }
            $this->audit($unitId,$actorId,'assignment.teacher_switched','enrollment',$new->id,['previous_enrollment_id'=>$old->id,'from'=>$from,'reason'=>$reason,'cancelled_lesson_ids'=>$cancelled]);
            return (object) ['enrollment_id'=>$new->id,'cancelled'=>count($cancelled),'lessons'=>$lessons];
        });
    }

    private function cancelScheduled(int $unitId, int $studentId, string $from, string $to, string $reason): array {
        $ids = DB::table('erp_lessons as l')->where('l.unit_id',$unitId)->where('l.student_id',$studentId)->where('l.status','scheduled')->where('l.starts_at','>=',$from)->where('l.starts_at','<',$to)
            ->whereNotExists(function($q) { $q->selectRaw('1')->from('erp_attendance as a')->whereColumn('a.lesson_id','l.id'); })->lockForUpdate()->pluck('l.id')->all();
        if ($ids) DB::table('erp_lessons')->whereIn('id',$ids)->update(['status'=>'cancelled','cancellation_reason'=>mb_substr($reason,0,500),'updated_at'=>$this->now()]);
        return array_map('intval',$ids);
    }

    public function endAssignment(int $unitId, int $actorId, array $p) {
        $this->required($p,['enrollment_id','ends_on']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $e = $this->row('erp_enrollments',$p['enrollment_id'],$unitId); $this->parents($unitId,$e->teacher_id,$e->student_id);
            $e = $this->row('erp_enrollments',$e->id,$unitId,true);
            $end = $this->date($p['ends_on']);
            if ($e->ends_on && $end > $e->ends_on) $this->fail('An ended assignment cannot be extended. Create a new effective-dated assignment.');
            if ($end < gmdate('Y-m-d') || $end < $e->starts_on) $this->fail('Close assignments prospectively, not in the past.');
            $cutoff = $this->localToUtc((new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d'),'00:00',$this->row('erp_students',$e->student_id,$unitId,true)->timezone);
            if (DB::table('erp_lessons')->where('enrollment_id',$e->id)->where('starts_at','>=',$cutoff)->whereIn('status',['scheduled','completed','no_show'])->lockForUpdate()->first()) $this->fail('Cancel or resolve later lessons before ending this assignment.');
            DB::table('erp_enrollments')->where('id',$e->id)->update(['ends_on'=>$end,'updated_at'=>$this->now()]);
            DB::table('erp_agreements')->where('enrollment_id',$e->id)->where(function($q) use ($end) { $q->whereNull('ends_on')->orWhere('ends_on','>',$end); })->update(['ends_on'=>$end,'updated_at'=>$this->now()]);
            $this->audit($unitId,$actorId,'assignment.ended','enrollment',$e->id,['ends_on'=>$end]); return $this->row('erp_enrollments',$e->id,$unitId);
        });
    }
    public function availability(int $unitId, int $actorId, array $p) {
        $this->required($p,['weekday','start_time','end_time','timezone','starts_on']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $studentId = !empty($p['student_id']) ? $p['student_id'] : null; $teacherId = !empty($p['teacher_id']) ? $p['teacher_id'] : null;
            if (($studentId === null) === ($teacherId === null)) $this->fail('Specify exactly one student or teacher.');
            if ($studentId) { $this->row('erp_students',$studentId,$unitId); DB::table('erp_students')->where('id',$studentId)->lockForUpdate()->first(); }
            if ($teacherId) { $this->row('erp_teachers',$teacherId); DB::table('erp_teachers')->where('id',$teacherId)->lockForUpdate()->first(); if (DB::table('erp_teacher_units')->where('teacher_id',$teacherId)->lockForUpdate()->get()->count() !== 1) $this->fail('Teacher must belong to exactly one unit pool.'); if (!DB::table('erp_teacher_units')->where('teacher_id',$teacherId)->where('unit_id',$unitId)->lockForUpdate()->first()) $this->fail('Teacher does not belong to this unit.'); }
            $start = $this->date($p['starts_on']); $end = !empty($p['ends_on']) ? $this->date($p['ends_on']) : null;
            $this->localToUtc($start,$p['start_time'],$p['timezone']); $this->localToUtc($start,$p['end_time'],$p['timezone']);
            if ($p['end_time'] <= $p['start_time'] || ($end && $end < $start)) $this->fail('Availability must end after it starts; split overnight windows.');
            $row = $this->insert('erp_availability',['unit_id'=>$unitId,'student_id'=>$studentId,'teacher_id'=>$teacherId,'weekday'=>$this->integer($p['weekday'],1,7,'weekday'),'start_time'=>$p['start_time'],'end_time'=>$p['end_time'],'timezone'=>$p['timezone'],'starts_on'=>$start,'ends_on'=>$end]);
            $this->audit($unitId,$actorId,'availability.created','availability',$row->id); return $row;
        });
    }
    /** Full local-date or bounded same-date unavailability; never silently cancel lessons. */
    public function exception(int $unitId, int $actorId, array $p) {
        $this->required($p,['date','reason']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $studentId=!empty($p['student_id']) ? $p['student_id'] : null; $teacherId=!empty($p['teacher_id']) ? $p['teacher_id'] : null;
            if (($studentId === null) === ($teacherId === null)) $this->fail('Specify exactly one student or teacher.');
            $field=$teacherId ? 'teacher_id' : 'student_id'; $id=$teacherId ?: $studentId;
            $person=DB::table($teacherId ? 'erp_teachers' : 'erp_students')->where('id',$id)->lockForUpdate()->first();
            if (!$person || ($studentId && (int)$person->unit_id !== $unitId)) $this->fail('Person not found in this unit.');
            if ($teacherId && (DB::table('erp_teacher_units')->where('teacher_id',$id)->lockForUpdate()->get()->count() !== 1 || !DB::table('erp_teacher_units')->where('teacher_id',$id)->where('unit_id',$unitId)->lockForUpdate()->first())) $this->fail('Teacher must belong only to this unit pool.');
            $day=$this->date($p['date']);
            if (!is_string($p['reason']) || trim($p['reason']) === '') $this->fail('An exception reason is required.');
            $reason=trim($p['reason']);
            $hasStart=isset($p['start_time']) && $p['start_time'] !== ''; $hasEnd=isset($p['end_time']) && $p['end_time'] !== '';
            if ($hasStart !== $hasEnd) $this->fail('Provide both start and end times, or leave both blank for a full day.');
            if ($hasStart) $this->required($p,['timezone']);
            $timezone=isset($p['timezone']) && $p['timezone'] !== '' ? $p['timezone'] : $person->timezone;
            if (!is_string($timezone) || ($hasStart && (!is_string($p['start_time']) || !is_string($p['end_time'])))) $this->fail('Use local HH:mm times and an IANA timezone.');
            // 00:00 is the exclusive next-midnight endpoint, never an implicit general overnight range.
            $nextMidnight=$hasStart && $p['end_time'] === '00:00' && $p['start_time'] !== '00:00';
            $start=$this->localToUtc($day,$hasStart ? $p['start_time'] : '00:00',$timezone);
            $end=$this->localToUtc($hasStart && !$nextMidnight ? $day : (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'),$hasEnd ? $p['end_time'] : '00:00',$timezone);
            if ($end <= $start) $this->fail('An exception must end after it starts on the same local date; split overnight windows into separate dates.');
            if (DB::table('erp_lessons')->where($field,$id)->where('status','scheduled')->where('starts_at','<',$end)->where('ends_at','>',$start)->lockForUpdate()->first()) $this->fail('Reschedule or cancel existing lessons overlapping this exception before recording it.');
            foreach ($this->exceptionOverlaps($unitId,$field,$id,$start,$end,$person->timezone) as $existing) {
                // Legacy full days remain date-based and are never upgraded or reinterpreted by replay.
                $same=$existing->starts_at === null
                    ? (!$hasStart && $existing->date === $day && $timezone === $person->timezone)
                    : ($existing->date === $day && $existing->starts_at === $start && $existing->ends_at === $end && $existing->timezone === $timezone);
                if ($same && $existing->reason === $reason) return $existing;
                $this->fail('An overlapping exception already exists. Exact retries must retain the same interval, timezone and reason.');
            }
            $row=$this->insert('erp_exceptions',['unit_id'=>$unitId,'student_id'=>$studentId,'teacher_id'=>$teacherId,'date'=>$day,'reason'=>$reason,'starts_at'=>$start,'ends_at'=>$end,'timezone'=>$timezone]);
            $this->audit($unitId,$actorId,'availability.exception_created','exception',$row->id,['date'=>$day,'timezone'=>$timezone,'starts_at'=>$start,'ends_at'=>$end,'full_day'=>!$hasStart,'reason'=>$reason]); return $row;
        });
    }
    /** Half-open UTC intervals, with a separate compatibility branch for untouched legacy dates. */
    private function exceptionOverlaps($unitId,$field,$id,$start,$end,$personTimezone) {
        $base=DB::table('erp_exceptions')->where('unit_id',$unitId)->where($field,$id);
        // Only the all-null triple is legacy. A damaged hybrid must not widen a window or vanish.
        $invalid=(clone $base)->where(function ($q) {
            $q->where(function ($q) { $q->whereNull('starts_at')->where(function ($q) { $q->whereNotNull('ends_at')->orWhereNotNull('timezone'); }); })
                ->orWhere(function ($q) { $q->whereNotNull('starts_at')->where(function ($q) { $q->whereNull('ends_at')->orWhereNull('timezone')->orWhere('timezone',''); }); })
                ->orWhereColumn('ends_at','<=','starts_at');
        })->lockForUpdate()->first();
        if ($invalid) $this->fail('This participant has an incomplete or invalid exception interval. Correct the saved exception before scheduling.');
        $zone=$this->zone($personTimezone);
        $first=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
        $last=(new DateTimeImmutable($end,new DateTimeZone('UTC')))->modify('-1 second')->setTimezone($zone)->format('Y-m-d');
        return (clone $base)->where(function ($q) use ($start,$end,$first,$last) {
            $q->where(function ($q) use ($start,$end) { $q->where('starts_at','<',$end)->where('ends_at','>',$start); })
                ->orWhere(function ($q) use ($first,$last) { $q->whereNull('starts_at')->whereNull('ends_at')->whereNull('timezone')->whereBetween('date',[$first,$last]); });
        })->orderBy('id')->lockForUpdate()->get();
    }
    /** End a recurring version prospectively; rates and all historic lesson rows remain unchanged. */
    public function endAgreement(int $unitId, int $actorId, array $p) {
        $this->required($p,['agreement_id','ends_on']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $a=$this->row('erp_agreements',$p['agreement_id'],$unitId); $e=$this->row('erp_enrollments',$a->enrollment_id,$unitId);
            $this->parents($unitId,$e->teacher_id,$e->student_id); $e=$this->row('erp_enrollments',$e->id,$unitId,true); $a=$this->row('erp_agreements',$a->id,$unitId,true);
            $end=$this->date($p['ends_on']); $today=(new DateTimeImmutable('now',$this->zone($a->timezone)))->format('Y-m-d');
            if ($end < $a->starts_on || $end < $today || ($a->ends_on && $end > $a->ends_on)) $this->fail('A recurring agreement may only be shortened prospectively.');
            $cutoff=$this->localToUtc((new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d'),'00:00',$a->timezone);
            // Replacement lessons retain lineage through originals; also protect replacements of this agreement.
            $ids=DB::table('erp_lessons')->where('agreement_id',$a->id)->lockForUpdate()->pluck('id')->all(); $all=$ids;
            while ($ids) { $ids=DB::table('erp_lessons')->whereIn('replaces_lesson_id',$ids)->lockForUpdate()->pluck('id')->all(); $all=array_merge($all,$ids); }
            if ($all && DB::table('erp_lessons')->whereIn('id',$all)->whereIn('status',['scheduled','completed','no_show'])->where('starts_at','>=',$cutoff)->lockForUpdate()->first()) $this->fail('Resolve generated lessons and their replacements beyond the end date first.');
            DB::table('erp_agreements')->where('id',$a->id)->update(['ends_on'=>$end,'updated_at'=>$this->now()]);
            $this->audit($unitId,$actorId,'agreement.ended','agreement',$a->id,['ends_on'=>$end]); return $this->row('erp_agreements',$a->id,$unitId);
        });
    }
    public function proposeAgreement(int $unitId, int $actorId, array $p) {
        $this->required($p,['enrollment_id','weekday','start_time','duration_minutes','timezone','starts_on']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $e = $this->row('erp_enrollments',$p['enrollment_id'],$unitId); $this->parents($unitId,$e->teacher_id,$e->student_id,true);
            $e = $this->row('erp_enrollments',$e->id,$unitId,true);
            $start = $this->date($p['starts_on']); $end = !empty($p['ends_on']) ? $this->date($p['ends_on']) : $e->ends_on;
            if ($start < $e->starts_on || ($end && $end < $start) || ($e->ends_on && (!$end || $end > $e->ends_on))) $this->fail('Agreement must fit assignment dates.');
            $previousId=!empty($p['replaces_agreement_id']) ? $p['replaces_agreement_id'] : null;
            if ($previousId) {
                $previous=$this->row('erp_agreements',$previousId,$unitId,true);
                if ((int)$previous->enrollment_id !== (int)$e->id || !$previous->ends_on || $start <= $previous->ends_on) $this->fail('End the previous version first; the replacement must use the same assignment and start after its end.');
                if (DB::table('erp_agreements')->where('replaces_agreement_id',$previousId)->lockForUpdate()->first()) $this->fail('This agreement already has a replacement version.');
            }
            $this->localToUtc($start,$p['start_time'],$p['timezone']);
            $row = $this->insert('erp_agreements',['unit_id'=>$unitId,'enrollment_id'=>$e->id,'replaces_agreement_id'=>$previousId,'weekday'=>$this->integer($p['weekday'],1,7,'weekday'),'start_time'=>$p['start_time'],'duration_minutes'=>$this->integer($p['duration_minutes'],1,480,'duration'),'timezone'=>$p['timezone'],'starts_on'=>$start,'ends_on'=>$end,'status'=>'proposed']);
            $this->audit($unitId,$actorId,'agreement.proposed','agreement',$row->id); return $row;
        });
    }
    public function confirmAgreement(int $unitId, int $actorId, array $p) {
        $this->required($p,['agreement_id','confirmation_channel','teacher_confirmed','student_confirmed','confirmation_note']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $a = $this->row('erp_agreements',$p['agreement_id'],$unitId); $e = $this->row('erp_enrollments',$a->enrollment_id,$unitId); $this->parents($unitId,$e->teacher_id,$e->student_id,true);
            $e = $this->row('erp_enrollments',$e->id,$unitId,true);
            $a = $this->row('erp_agreements',$a->id,$unitId,true);
            if ($a->starts_on < $e->starts_on || ($e->ends_on && $a->starts_on > $e->ends_on)) $this->fail('Agreement no longer fits the current assignment dates.');
            if ($a->status !== 'proposed') $this->fail('Only proposed agreements can be confirmed.');
            if (!in_array($p['teacher_confirmed'],[true,1,'1'],true) || !in_array($p['student_confirmed'],[true,1,'1'],true) || !in_array($p['confirmation_channel'],['whatsapp','call'],true) || !trim($p['confirmation_note'])) $this->fail('Record bilateral WhatsApp/call confirmation and evidence.');
            $through = min($a->ends_on ?: '9999-12-31',(new DateTimeImmutable($a->starts_on))->modify('+370 days')->format('Y-m-d'));
            foreach ($this->occurrences($a,$a->starts_on,$through) as [$start,$end]) { $this->coverage($unitId,$e,$start,$end); $this->conflicts($e,$start,$end,null,$a->id); }
            // Compare future agreements too, including those whose start lies beyond the first horizon.
            foreach ($this->agreementsFor($e,$a->id) as $other) {
                $from = max($a->starts_on,$other->starts_on); $to = min($a->ends_on ?: '9999-12-31',$other->ends_on ?: '9999-12-31',(new DateTimeImmutable($from))->modify('+370 days')->format('Y-m-d'));
                foreach ($this->occurrences($a,$from,$to) as [$start,$end]) $this->agreementConflict($other,$start,$end);
            }
            DB::table('erp_agreements')->where('id',$a->id)->update(['status'=>'confirmed','teacher_confirmed_at'=>$this->now(),'student_confirmed_at'=>$this->now(),'confirmation_channel'=>$p['confirmation_channel'],'confirmation_note'=>trim($p['confirmation_note']),'confirmed_by'=>$actorId,'updated_at'=>$this->now()]);
            $this->audit($unitId,$actorId,'agreement.confirmed','agreement',$a->id,['channel'=>$p['confirmation_channel'],'note'=>trim($p['confirmation_note'])]); return $this->row('erp_agreements',$a->id,$unitId);
        });
    }
    private function occurrences($a,$from,$to) {
        $out=[]; $from=max($from,$a->starts_on); $to=min($to,$a->ends_on ?: '9999-12-31');
        for ($day=new DateTimeImmutable($from,new DateTimeZone('UTC')); $day->format('Y-m-d') <= $to; $day=$day->modify('+1 day')) {
            if ((int)$day->format('N') !== (int)$a->weekday) continue;
            $start=$this->localToUtc($day->format('Y-m-d'),$a->start_time,$a->timezone); $out[]=[$start,gmdate('Y-m-d H:i:s',strtotime($start.' UTC')+$a->duration_minutes*60)];
        } return $out;
    }
    private function coverage($unitId,$e,$start,$end) {
        foreach (array_filter(['student_id'=>$e->student_id,'teacher_id'=>$e->teacher_id],fn($id)=>$id!==null) as $field=>$id) {
            $person=$this->row($field === 'student_id' ? 'erp_students' : 'erp_teachers',$id,null,true);
            if ($this->exceptionOverlaps($unitId,$field,$id,$start,$end,$person->timezone)->isNotEmpty()) $this->fail('A date-specific holiday or unavailability exception overlaps this lesson.');
            $covered=false;
            foreach (DB::table('erp_availability')->where('unit_id',$unitId)->where($field,$id)->lockForUpdate()->get() as $window) {
                $day=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone($this->zone($window->timezone))->format('Y-m-d');
                if ($day < $window->starts_on || ($window->ends_on && $day > $window->ends_on) || (int)(new DateTimeImmutable($day))->format('N') !== (int)$window->weekday) continue;
                $ws=$this->localToUtc($day,$window->start_time,$window->timezone); $we=$this->localToUtc($day,$window->end_time,$window->timezone);
                if ($start >= $ws && $end <= $we) { $covered=true; break; }
            }
            if (!$covered) $this->fail('The entire lesson must be covered by both student and teacher availability.');
        }
    }
    private function agreementsFor($e,$except=null) {
        $q=DB::table('erp_agreements as a')->join('erp_enrollments as e','e.id','=','a.enrollment_id')->where('a.status','confirmed')->where(function($q) use ($e) { $q->where('e.teacher_id',$e->teacher_id)->orWhere('e.student_id',$e->student_id); })->select('a.*');
        if ($except) $q->where('a.id','<>',$except); return $q->lockForUpdate()->get();
    }
    private function agreementConflict($a,$start,$end,$exemptOccurrence=null) {
        $local=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone($this->zone($a->timezone));
        foreach ($this->occurrences($a,$local->modify('-1 day')->format('Y-m-d'),$local->modify('+1 day')->format('Y-m-d')) as [$os,$oe]) if ((!$exemptOccurrence || (int)$a->id !== (int)$exemptOccurrence[0] || $os !== $exemptOccurrence[1]) && $os < $end && $oe > $start) $this->fail('A confirmed recurring agreement overlaps globally for this teacher or student.');
    }
    private function conflicts($e,$start,$end,$exceptLesson=null,$exceptAgreement=null,$exemptOccurrence=null) {
        $q=DB::table('erp_lessons')->whereIn('status',['scheduled','completed','no_show'])->where('starts_at','<',$end)->where('ends_at','>',$start)->where(function($q) use ($e) { $q->where('teacher_id',$e->teacher_id)->orWhere('student_id',$e->student_id); });
        if ($exceptLesson) $q->where('id','<>',$exceptLesson);
        if ($q->lockForUpdate()->first()) $this->fail('Teacher or student has an overlapping lesson, possibly in another unit.');
        foreach ($this->agreementsFor($e,$exceptAgreement) as $a) $this->agreementConflict($a,$start,$end,$exemptOccurrence);
        $this->demoConflicts($e,$start,$end);
    }
    /** Same checks for a tentative match and its transactional booking. */
    public function checkMatchedSlot(int $unit,int $teacher,?int $student,string $start,string $end): void
    {
        $t=$this->row('erp_teachers',$teacher,null,true);
        if($t->status!=='active' || DB::table('erp_teacher_units')->where('teacher_id',$teacher)->count()!==1 || !DB::table('erp_teacher_units')->where('unit_id',$unit)->where('teacher_id',$teacher)->exists())$this->fail('Teacher must be active in exactly this unit pool.');
        if($student)$this->parents($unit,$teacher,$student,true);
        $e=(object)['teacher_id'=>$teacher,'student_id'=>$student];
        $this->coverage($unit,$e,$start,$end);
        $this->conflicts($e,$start,$end);
    }
    private function demoConflicts($e,string $start,string $end): void
    {
        $query=DB::table('erp_demos as d')->join('erp_units as u','u.id','=','d.unit_id')
            ->leftJoin('erp_leads as l','l.id','=','d.lead_id')->whereIn('d.status',['SCHEDULED','RESCHEDULED'])
            ->where(function($q)use($e){$q->where('d.teacher_id',$e->teacher_id);if($e->student_id)$q->orWhere('d.student_id',$e->student_id)->orWhere('l.student_id',$e->student_id);})
            ->select('d.*','u.timezone as unit_timezone')->lockForUpdate();
        foreach($query->get() as $demo){
            if($demo->starts_at && $demo->ends_at){$ds=$demo->starts_at;$de=$demo->ends_at;}
            else{
                // Legacy demos have no duration. Do not invent a free end time:
                // reserve that local date until an operator cancels/rebooks it.
                $ds=$this->localToUtc($demo->scheduled_on,'00:00',$demo->unit_timezone);
                $de=$this->localToUtc((new DateTimeImmutable($demo->scheduled_on))->modify('+1 day')->format('Y-m-d'),'00:00',$demo->unit_timezone);
            }
            if($ds<$end && $de>$start)$this->fail('A scheduled demo overlaps this slot. Legacy demos without duration reserve their local date until reviewed.');
        }
    }
    public function materialize(int $unitId, int $actorId, array $p) {
        $this->required($p,['agreement_id','from','through']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $a=$this->row('erp_agreements',$p['agreement_id'],$unitId); $e=$this->row('erp_enrollments',$a->enrollment_id,$unitId); $this->parents($unitId,$e->teacher_id,$e->student_id,true);
            $e=$this->row('erp_enrollments',$e->id,$unitId,true);
            $a=$this->row('erp_agreements',$a->id,$unitId,true);
            if ($a->status !== 'confirmed') $this->fail('Only bilaterally confirmed agreements generate lessons.');
            $from=$this->date($p['from']); $through=$this->date($p['through']);
            if ($through < $from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($through))->days > 366) $this->fail('Generate at most 366 days per request.');
            $created=[];
            foreach ($this->occurrences($a,max($from,$e->starts_on),min($through,$e->ends_on ?: '9999-12-31')) as [$start,$end]) {
                // Includes cancelled/rescheduled originals: generation must never resurrect them.
                $existing=DB::table('erp_lessons')->where('agreement_id',$a->id)->where('starts_at',$start)->lockForUpdate()->first();
                if ($existing) continue;
                $this->coverage($unitId,$e,$start,$end); $this->conflicts($e,$start,$end,null,$a->id);
                $created[]=$this->insert('erp_lessons',['unit_id'=>$unitId,'enrollment_id'=>$e->id,'agreement_id'=>$a->id,'student_id'=>$e->student_id,'teacher_id'=>$e->teacher_id,'subject_id'=>$e->subject_id,'starts_at'=>$start,'ends_at'=>$end,'status'=>'scheduled','kind'=>'regular','student_rate_paise'=>$e->student_rate_paise,'teacher_rate_paise'=>$e->teacher_rate_paise,'currency'=>$e->currency]);
            }
            $this->audit($unitId,$actorId,'lessons.materialized','agreement',$a->id,['count'=>count($created),'from'=>$from,'through'=>$through]); return $created;
        });
    }
    /** Explicit, bilaterally agreed one-off lesson. Demo rates are snapshots, never authorization to bill. */
    public function scheduleExtra(int $unitId, int $actorId, array $p) {
        $this->required($p,['enrollment_id','date','start_time','timezone','duration_minutes','kind','teacher_confirmed','student_confirmed','confirmation_channel','confirmation_note','reference']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $e=$this->row('erp_enrollments',$p['enrollment_id'],$unitId);
            [$teacher,$student]=$this->parents($unitId,$e->teacher_id,$e->student_id);
            $e=$this->row('erp_enrollments',$e->id,$unitId,true);
            $reference=trim((string)$p['reference']);
            if ($reference === '' || strlen($reference)>120) $this->fail('Provide a stable request reference of at most 120 characters.');
            if (!in_array($p['kind'],['demo','extra'],true)) $this->fail('One-off lesson kind must be demo or extra.');
            if (!in_array($p['teacher_confirmed'],[true,1,'1'],true) || !in_array($p['student_confirmed'],[true,1,'1'],true) || !in_array($p['confirmation_channel'],['whatsapp','call'],true) || !trim($p['confirmation_note'])) $this->fail('Record bilateral WhatsApp/call confirmation for this one-off lesson.');
            $minutes=$this->integer($p['duration_minutes'],1,480,'duration');
            $start=$this->localToUtc($p['date'],$p['start_time'],$p['timezone']);
            $end=gmdate('Y-m-d H:i:s',strtotime($start.' UTC')+$minutes*60);
            $request=['unit_id'=>$unitId,'enrollment_id'=>(int)$e->id,'date'=>$p['date'],'start_time'=>$p['start_time'],'timezone'=>$p['timezone'],'duration_minutes'=>$minutes,'kind'=>$p['kind'],'teacher_confirmed'=>true,'student_confirmed'=>true,'confirmation_channel'=>$p['confirmation_channel'],'confirmation_note'=>trim($p['confirmation_note']),'reference'=>$reference];
            $fingerprint=hash('sha256',json_encode($request,JSON_THROW_ON_ERROR));
            $existing=DB::table('erp_lessons')->where('request_reference',$reference)->lockForUpdate()->first();
            if ($existing) {
                if ((int)$existing->unit_id !== $unitId || (int)$existing->enrollment_id !== (int)$e->id) $this->fail('This request reference belongs to a different lesson request.');
                $audit=DB::table('erp_audit')->where('unit_id',$unitId)->where('action','lesson.one_off_scheduled')->where('entity_type','lesson')->where('entity_id',$existing->id)->lockForUpdate()->first();
                $saved=$audit ? json_decode($audit->payload,true) : [];
                if (!isset($saved['request_fingerprint']) || !hash_equals($saved['request_fingerprint'],$fingerprint)) $this->fail('This request reference was already used with different details.');
                // Return the preserved original even after cancellation/rescheduling; never recreate it.
                return $existing;
            }
            if ($teacher->status !== 'active' || $student->status !== 'active' || $e->status !== 'active') $this->fail('An active student, teacher and assignment are required.');
            $zone=$this->zone($student->timezone);
            $effectiveStart=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
            $effectiveEnd=(new DateTimeImmutable($end,new DateTimeZone('UTC')))->modify('-1 second')->setTimezone($zone)->format('Y-m-d');
            if ($effectiveStart < $e->starts_on || ($e->ends_on && $effectiveEnd > $e->ends_on)) $this->fail('The whole lesson must fit the assignment effective dates.');
            $this->coverage($unitId,$e,$start,$end); $this->conflicts($e,$start,$end);
            $lesson=$this->insert('erp_lessons',['unit_id'=>$unitId,'enrollment_id'=>$e->id,'agreement_id'=>null,'student_id'=>$e->student_id,'teacher_id'=>$e->teacher_id,'subject_id'=>$e->subject_id,'starts_at'=>$start,'ends_at'=>$end,'status'=>'scheduled','kind'=>$p['kind'],'request_reference'=>$reference,'student_rate_paise'=>$e->student_rate_paise,'teacher_rate_paise'=>$e->teacher_rate_paise,'currency'=>$e->currency]);
            $this->audit($unitId,$actorId,'lesson.one_off_scheduled','lesson',$lesson->id,['request_fingerprint'=>$fingerprint,'request'=>$request,'starts_at'=>$start,'ends_at'=>$end,'confirmed_at'=>$this->now(),'demo_free_and_unpaid'=>$p['kind']==='demo']);
            return $lesson;
        });
    }
    public function reschedule(int $unitId, int $actorId, array $p) {
        $this->required($p,['lesson_id','date','start_time','timezone','reason','teacher_confirmed','student_confirmed','confirmation_channel','confirmation_note']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $l=$this->row('erp_lessons',$p['lesson_id'],$unitId); $e=$this->row('erp_enrollments',$l->enrollment_id,$unitId); $this->parents($unitId,$l->teacher_id,$l->student_id,true); $l=$this->row('erp_lessons',$l->id,$unitId,true);
            $e=$this->row('erp_enrollments',$l->enrollment_id,$unitId,true);
            if ($l->status !== 'scheduled' || DB::table('erp_attendance')->where('lesson_id',$l->id)->lockForUpdate()->first()) $this->fail('Only scheduled lessons without attendance can be rescheduled.');
            if (!trim($p['reason'])) $this->fail('A rescheduling reason is required.');
            if (!in_array($p['teacher_confirmed'],[true,1,'1'],true) || !in_array($p['student_confirmed'],[true,1,'1'],true) || !in_array($p['confirmation_channel'],['whatsapp','call'],true) || !trim($p['confirmation_note'])) $this->fail('Record renewed bilateral WhatsApp/call confirmation for the replacement time.');
            $start=$this->localToUtc($p['date'],$p['start_time'],$p['timezone']); $end=gmdate('Y-m-d H:i:s',strtotime($start.' UTC')+strtotime($l->ends_at.' UTC')-strtotime($l->starts_at.' UTC'));
            $student=$this->row('erp_students',$e->student_id,$unitId,true); $effective=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone($this->zone($student->timezone))->format('Y-m-d');
            if ($effective < $e->starts_on || ($e->ends_on && $effective > $e->ends_on)) $this->fail('Replacement must remain within assignment dates.');
            if ($start === $l->starts_at) $this->fail('Choose a different replacement time.');
            $root=$l;
            while ($root->replaces_lesson_id) $root=$this->row('erp_lessons',$root->replaces_lesson_id,$unitId,true);
            $exempt=$root->agreement_id ? [$root->agreement_id,$root->starts_at] : null;
            $this->coverage($unitId,$e,$start,$end); $this->conflicts($e,$start,$end,$l->id,null,$exempt);
            DB::table('erp_lessons')->where('id',$l->id)->update(['status'=>'rescheduled','cancellation_reason'=>trim($p['reason']),'updated_at'=>$this->now()]);
            $next=$this->insert('erp_lessons',['unit_id'=>$unitId,'enrollment_id'=>$l->enrollment_id,'agreement_id'=>null,'student_id'=>$l->student_id,'teacher_id'=>$l->teacher_id,'subject_id'=>$l->subject_id,'starts_at'=>$start,'ends_at'=>$end,'status'=>'scheduled','kind'=>$l->kind,'replaces_lesson_id'=>$l->id,'student_rate_paise'=>$l->student_rate_paise,'teacher_rate_paise'=>$l->teacher_rate_paise,'currency'=>$l->currency]);
            $this->audit($unitId,$actorId,'lesson.rescheduled','lesson',$l->id,['replacement_id'=>$next->id,'reason'=>trim($p['reason']),'teacher_confirmed'=>true,'student_confirmed'=>true,'confirmation_channel'=>$p['confirmation_channel'],'confirmation_note'=>trim($p['confirmation_note']),'confirmed_at'=>$this->now(),'replacement_starts_at'=>$start]); return $next;
        });
    }
    public function cancel(int $unitId, int $actorId, array $p) {
        $this->required($p,['lesson_id','reason']);
        return DB::transaction(function () use ($unitId,$actorId,$p) {
            $l=$this->row('erp_lessons',$p['lesson_id'],$unitId); $this->parents($unitId,$l->teacher_id,$l->student_id); $l=$this->row('erp_lessons',$l->id,$unitId,true);
            $e=$this->row('erp_enrollments',$l->enrollment_id,$unitId,true);
            if ($l->status !== 'scheduled' || DB::table('erp_attendance')->where('lesson_id',$l->id)->lockForUpdate()->first()) $this->fail('Only scheduled lessons without attendance can be cancelled.');
            if (!trim($p['reason'])) $this->fail('A cancellation reason is required.');
            DB::table('erp_lessons')->where('id',$l->id)->update(['status'=>'cancelled','cancellation_reason'=>trim($p['reason']),'updated_at'=>$this->now()]);
            $this->audit($unitId,$actorId,'lesson.cancelled','lesson',$l->id,['reason'=>trim($p['reason'])]); return $this->row('erp_lessons',$l->id,$unitId);
        });
    }
}
