// Money and signed figures: tabular digits, right-aligned by the column, and
// colour plus sign together so direction never depends on colour alone.
import * as stylex from '@stylexjs/stylex';
import {Text} from '@astryxdesign/core/Text';
import {money} from '../lib/format';

const styles = stylex.create({
  error: {color: 'var(--color-text-error)'},
  success: {color: 'var(--color-text-success)'},
  warning: {color: 'var(--color-text-warning)'},
});

export type Tone = keyof typeof styles;

export function Toned({tone, children, strong, size}: {tone?: Tone; children: string; strong?: boolean; size?: 'xl' | 'lg'}) {
  return (
    <Text hasTabularNumbers size={size} weight={strong ? 'semibold' : undefined} xstyle={tone ? styles[tone] : undefined}>
      {children}
    </Text>
  );
}

/** A balance: negative means the family owes money. */
export function Balance({paise, whole}: {paise: number | null | undefined; whole?: boolean}) {
  if (paise === null || paise === undefined) return <Text color="secondary">—</Text>;
  return (
    <Toned tone={paise < 0 ? 'error' : undefined} strong={paise < 0}>
      {money(paise, {whole})}
    </Toned>
  );
}
