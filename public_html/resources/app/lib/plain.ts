// One plain-language word per money concept, shared by every screen that shows
// these states to non-accountants. The server keeps its precise terms; the app
// translates them here so the same idea always reads the same way.
//
//   import a class for charge  -> bill the class to the family
//   teacher accrual            -> added to the teacher's pay
//   duplicate review           -> possible duplicate (recorded twice)
//   posting                    -> recording / adding to an account

const FINANCE_STATES: Record<string, string> = {
  'Pending import': 'Not billed yet',
  Imported: 'Billed',
  'Free demo': 'Free demo',
  'Pending approval': 'Waiting for approval',
  'Approved, not accrued': 'Approved, not yet in pay',
  Accrued: 'Added to pay',
  'Unpaid demo': 'Demo, unpaid',
  'Rejected / reversed': 'Rejected',
};

/** The plain wording for a student-charge or teacher-pay state from the server. */
export function financeLabel(state: string): string {
  return FINANCE_STATES[state] ?? state;
}
