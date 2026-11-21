// "New" menu: one tap from anywhere to start the most common records. Each entry
// needs the server action permission, which the session lists per unit.
import type {Session, UnitAccess} from '../lib/session';

export interface QuickCreate {
  key: string;
  label: string;
  description: string;
  href: string;
}

const ENTRIES: {key: string; label: string; description: string; action: string; path: string}[] = [
  {key: 'lead', label: 'Lead', description: 'A new enquiry or demo request', action: 'admission-create-lead', path: 'admissions?new=lead'},
  {key: 'student', label: 'Student', description: 'Register a student directly', action: 'student', path: 'students?new=student'},
  {key: 'payment', label: 'Payment received', description: 'Record a fee payment and issue a receipt', action: 'payment', path: 'payments?new=payment'},
  {key: 'request', label: 'Payment request', description: 'Ask a family to pay', action: 'request-create', path: 'payments?new=request'},
  {key: 'task', label: 'Task', description: 'Assign work to someone on the team', action: 'task', path: 'tasks?new=task'},
  {key: 'teacher', label: 'Teacher', description: 'Add a teacher to this team', action: 'teacher', path: 'teachers?new=teacher'},
];

export function quickCreateFor(unit: UnitAccess | undefined, sess: Session): QuickCreate[] {
  if (!unit || unit.isArchived || sess.sheetsAuthority) return [];
  return ENTRIES.filter(entry => sess.user.isAdmin || unit.actions.includes(entry.action)).map(entry => ({
    key: entry.key,
    label: entry.label,
    description: entry.description,
    href: `/app/u/${unit.id}/${entry.path}`,
  }));
}
