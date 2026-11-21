// The A4 frame every printable document shares. Astryx has no print primitives,
// so one small print stylesheet lives here: A4 page box, white sheet, and the
// controls bar (class print-controls) hidden when printing.
import {useEffect, type ReactNode} from 'react';
import {Outlet} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {PrinterIcon} from '@heroicons/react/24/outline';
import {Text} from '@astryxdesign/core/Text';
import {Table, TableBody, TableCell, TableRow} from '@astryxdesign/core/Table';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {PageError, PageLoading} from '../../components/PageState';

const PRINT_CSS = `
@page { size: A4; margin: 14mm; }
html, body { background: var(--color-background-card); }
@media print {
  .print-controls { display: none !important; }
  html, body { background: white !important; }
  .print-sheet { padding: 0 !important; max-width: none !important; }
}
`;

export function PrintFrame() {
  return (
    <>
      <style>{PRINT_CSS}</style>
      <VStack className="print-sheet" maxWidth="210mm" width="100%" padding={5} gap={4} style={{marginInline: 'auto'}}>
        <Outlet />
      </VStack>
    </>
  );
}

/** Print and Back buttons; never printed. */
export function PrintControls({printLabel, back, children}: {printLabel: string; back?: {label: string; href: string}; children?: ReactNode}) {
  return (
    <HStack className="print-controls" gap={2} wrap="wrap" vAlign="center">
      <Button label={printLabel} variant="primary" icon={<Icon icon={PrinterIcon} size="sm" />} onClick={() => window.print()} />
      {/* A full load: the staff screens need the staff session, which this page does not embed. */}
      {back && <Button label={back.label} variant="ghost" onClick={() => window.location.assign(back.href)} />}
      {children}
    </HStack>
  );
}

/** Loading and error states for a document, plus the browser tab title. */
export function useDocumentTitle(title: string | undefined) {
  useEffect(() => {
    if (title) document.title = title;
  }, [title]);
}

export function DocumentState({error, onRetry}: {error?: Error; onRetry?: () => void}) {
  if (error) return <PageError error={error} onRetry={onRetry} />;
  return <PageLoading />;
}

/** A label / amount table without a header row, like the classic totals tables. */
export function SummaryTable({rows}: {rows: {label: string; amount: string; isTotal?: boolean}[]}) {
  return (
    <Table density="compact">
      <TableBody>
        {rows.map(row => (
          <TableRow key={row.label}>
            <TableCell scope="row">
              <Text weight={row.isTotal ? 'semibold' : undefined}>{row.label}</Text>
            </TableCell>
            <TableCell>
              <HStack hAlign="end">
                <Text hasTabularNumbers weight={row.isTotal ? 'semibold' : undefined}>{row.amount}</Text>
              </HStack>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

/** Unknown /print URL. Links leave the print area with a full load (see PrintControls). */
export function PrintNotFound() {
  return (
    <EmptyState
      title="This document does not exist"
      description="The link may be old. Open the document again from the app."
      actions={<Button label="Go to the app" variant="secondary" onClick={() => window.location.assign('/app')} />}
    />
  );
}
