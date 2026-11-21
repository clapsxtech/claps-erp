// Add teacher, set level and category, and add subject eligibility. Shared by the
// teacher list (row menu) and the teacher profile.
import {FormDrawer} from '../../components/FormDrawer';
import {ActionDrawer} from './ActionDrawer';
import {businessAction, workspaceAction} from './access';

export type TeacherFormKind = 'add' | 'classify' | 'eligibility';

type Option = {value: string; label: string};

const SYLLABUSES = ['CBSE', 'ICSE', 'IGCSE', 'IB', 'State board', 'Other'].map(v => ({value: v, label: v}));

export function TeacherForms({
  unitId,
  timezone,
  kind,
  teacher,
  levels,
  categories,
  onClose,
  onSaved,
}: {
  unitId: number;
  timezone: string;
  kind: TeacherFormKind | null;
  teacher?: {id: number; name: string; levels: string[]; category: string | null};
  levels: Option[];
  categories: string[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const onOpenChange = (open: boolean) => {
    if (!open) onClose();
  };
  return (
    <>
      <FormDrawer
        isOpen={kind === 'add'}
        onOpenChange={onOpenChange}
        title="Add a teacher"
        description="Adds the teacher to this team's pool. Teachers recruited through Teacher hiring are added when their profile is approved."
        fields={[
          {kind: 'text', name: 'name', label: 'Full name', required: true},
          {kind: 'text', name: 'code', label: 'Teacher code', required: true, description: 'Unique across all teams, e.g. CT104'},
          {kind: 'text', name: 'timezone', label: 'Time zone', required: true, description: 'IANA name, e.g. Asia/Kolkata'},
        ]}
        initial={{timezone}}
        action={workspaceAction(unitId, 'teacher')}
        submitLabel="Add teacher"
        successMessage="Teacher added."
        onSaved={onSaved}
      />
      {teacher && (
        <ActionDrawer
          unitId={unitId}
          isOpen={kind === 'classify'}
          onOpenChange={onOpenChange}
          title="Level and category"
          description={`${teacher.name}. Sales filter teachers by these when they match a family.`}
          fields={[
            {kind: 'multi', name: 'levels', label: 'Levels', options: levels},
            {kind: 'select', name: 'category', label: 'Category', options: categories.map(c => ({value: c, label: c}))},
          ]}
          hidden={{teacher_id: teacher.id}}
          initial={{levels: teacher.levels, category: teacher.category ?? ''}}
          action={businessAction(unitId, 'teacher-classify')}
          submitLabel="Save"
          successMessage="Teacher level and category saved."
          onSaved={onSaved}
        />
      )}
      {teacher && (
        <ActionDrawer
          unitId={unitId}
          isOpen={kind === 'eligibility'}
          onOpenChange={onOpenChange}
          title="Add subject eligibility"
          description={`${teacher.name} can then be assigned classes in this subject, grade and syllabus.`}
          fields={[
            {kind: 'text', name: 'subject', label: 'Subject', required: true, placeholder: 'e.g. Mathematics', description: 'Matches an existing subject by name, ignoring case.'},
            {kind: 'text', name: 'grade', label: 'Grade', required: true, placeholder: 'e.g. 8'},
            {kind: 'select', name: 'syllabus', label: 'Syllabus', required: true, options: SYLLABUSES},
          ]}
          hidden={{teacher_id: teacher.id}}
          initial={{syllabus: 'CBSE'}}
          action={workspaceAction(unitId, 'eligibility')}
          submitLabel="Add eligibility"
          successMessage="Subject eligibility saved."
          onSaved={onSaved}
        />
      )}
    </>
  );
}
