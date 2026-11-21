// Formatting happens only at the edge. Values stay as integer paise, ISO dates
// and enum keys so sorting never reads a display string.

const inr = new Intl.NumberFormat('en-IN', {style: 'currency', currency: 'INR', minimumFractionDigits: 2});
const inrWhole = new Intl.NumberFormat('en-IN', {style: 'currency', currency: 'INR', maximumFractionDigits: 0});
const count = new Intl.NumberFormat('en-IN');

export function money(paise: number | null | undefined, opts: {whole?: boolean} = {}): string {
  if (paise === null || paise === undefined) return '—';
  return (opts.whole ? inrWhole : inr).format(paise / 100);
}

export function number(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : count.format(value);
}

export function date(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso.length === 10 ? `${iso}T00:00:00` : iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'});
}

export function dateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString('en-IN', {day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit'});
}

export function titleCase(value: string | null | undefined): string {
  if (!value) return '—';
  return value.replace(/[_-]+/g, ' ').replace(/^\w/, c => c.toUpperCase());
}
