// A table that becomes a stack of cards on phones and portrait tablets. Screens that show small,
// fixed tables (a student's classes, a report's rows, a team list) use this
// instead of Astryx's Table directly, so nothing scrolls sideways on a phone:
// the first column is the card's title, every other titled column is a
// "label  value" line, and untitled columns (row actions) sit underneath at
// full width where a thumb can reach them.
import type {ReactNode} from 'react';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {List, ListItem} from '@astryxdesign/core/List';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {useIsCompact} from '../lib/hooks';

type TableProps<T extends Record<string, unknown>> = Parameters<typeof Table<T>>[0];

export type RowTableProps<T extends Record<string, unknown>> = TableProps<T> & {
  /** Phone card link for a row (opens the record). */
  phoneHref?: (row: T) => string | undefined;
};

function cell<T extends Record<string, unknown>>(column: TableColumn<T>, row: T): ReactNode {
  if (column.renderCell) return column.renderCell(row);
  const value = row[column.key];
  return value === null || value === undefined || value === '' ? '—' : String(value);
}

export function RowTable<T extends Record<string, unknown>>(props: RowTableProps<T>) {
  const isCompact = useIsCompact();
  const {phoneHref, ...tableProps} = props;
  const {data, columns, idKey} = tableProps as {data?: T[]; columns?: TableColumn<T>[]; idKey?: keyof T | ((row: T) => string | number)};
  if (!isCompact || !data || !columns || columns.length === 0) return <Table<T> {...(tableProps as TableProps<T>)} />;

  const keyOf = (row: T, index: number) =>
    typeof idKey === 'function' ? idKey(row) : idKey ? String(row[idKey] ?? index) : index;
  const [first, ...rest] = columns;
  const titled = rest.filter(column => column.header !== undefined && column.header !== '' && column.header !== null);
  const untitled = rest.filter(column => !titled.includes(column));

  return (
    <List hasDividers edge="flush">
      {data.map((row, index) => {
        const actions = untitled.map(column => ({key: column.key, node: cell(column, row)})).filter(a => a.node !== '—');
        return (
          <ListItem
            key={String(keyOf(row, index))}
            href={phoneHref?.(row)}
            label={cell(first, row)}
            description={
              titled.length || actions.length ? (
                <VStack gap={1}>
                  {titled.map(column => (
                    <HStack key={column.key} gap={3} vAlign="start" hAlign="between">
                      <StackItem>
                        <Text size="sm" color="secondary" maxLines={1}>
                          {column.header}
                        </Text>
                      </StackItem>
                      <StackItem size="fill">
                        <HStack hAlign="end">
                          <Text size="sm" hasTabularNumbers>
                            {cell(column, row)}
                          </Text>
                        </HStack>
                      </StackItem>
                    </HStack>
                  ))}
                  {actions.map(action => (
                    <HStack key={action.key} gap={2} wrap="wrap">
                      {action.node}
                    </HStack>
                  ))}
                </VStack>
              ) : undefined
            }
          />
        );
      })}
    </List>
  );
}
