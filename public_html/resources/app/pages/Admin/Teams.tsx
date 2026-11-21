// Teams & accounts (global, superadmin only).
// Writes post to the /erp/administration routes, which answer with a redirect;
// adminPost treats that as saved. Account actions need the admin's own password.
import {useEffect, useMemo, useState} from 'react';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Selector} from '@astryxdesign/core/Selector';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {Table, pixel, type TableColumn} from '@astryxdesign/core/Table';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {Status} from '../../components/Status';
import {Metric, MetricRow} from '../../components/Metric';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {number} from '../../lib/format';
import {AdminFormDrawer, ConfirmAction, type AdminField} from './adminForm';

interface Team extends Record<string, unknown> {
  id: number;
  code: string;
  name: string;
  timezone: string;
  phone: string | null;
  legalName: string | null;
  address: string | null;
  state: string | null;
  taxMode: 'none' | 'gst';
  taxId: string | null;
  invoicePrefix: string | null;
  isArchived: boolean;
  memberCount: number;
}

interface TeamsData {
  data: Team[];
  states: string[];
  roles: {value: string; label: string}[];
}

interface Account extends Record<string, unknown> {
  id: number;
  name: string;
  email: string;
  isActive: boolean;
  isAdmin: boolean;
  isOwner: boolean;
  isSelf: boolean;
  memberships: {unitId: number; unitName: string; role: string; roleLabel: string}[];
}

interface AccountList {
  data: Account[];
  meta: ListMeta;
}

const DEFAULTS = {tab: 'teams', q: '', status: '', page: '1', per_page: '25'};
const BASE = '/erp/administration';

type Dialog =
  | {kind: 'team'; team?: Team}
  | {kind: 'archive'; team: Team}
  | {kind: 'onboard'}
  | {kind: 'account'; account: Account}
  | {kind: 'profile'; account: Account}
  | null;

export default function Teams() {
  const [query, setQuery] = useListQuery(DEFAULTS);
  const [searchText, setSearchText] = useState(query.q);
  const debounced = useDebounced(searchText);
  useEffect(() => {
    if (debounced !== query.q) setQuery({q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  const [dialog, setDialog] = useState<Dialog>(null);
  const close = (open: boolean) => {
    if (!open) setDialog(null);
  };

  const teams = useResource<TeamsData>('/app-api/admin/teams');
  const accounts = useResource<AccountList>(query.tab === 'accounts' ? '/app-api/admin/accounts' : null, {
    q: query.q,
    status: query.status,
    page: query.page,
    per_page: query.per_page,
  });

  const teamColumns: TableColumn<Team>[] = [
    {
      key: 'name',
      header: 'Team',
      renderCell: t => (
        <VStack gap={0.5}>
          {t.isArchived ? <Text>{t.name}</Text> : <Link href={`/app/u/${t.id}/home`}>{t.name}</Link>}
          <Text color="secondary" size="sm">{t.code}</Text>
        </VStack>
      ),
    },
    {key: 'timezone', header: 'Time zone', renderCell: t => <Text>{t.timezone}</Text>},
    {key: 'taxMode', header: 'Tax', renderCell: t => <Text>{t.taxMode === 'gst' ? `GST · ${t.taxId ?? '—'}` : 'No tax'}</Text>},
    {key: 'memberCount', header: 'People', align: 'end', renderCell: t => <Text hasTabularNumbers>{number(t.memberCount)}</Text>},
    {key: 'isArchived', header: 'Status', renderCell: t => <Status value={t.isArchived ? 'archived' : 'active'} />},
    {
      key: 'id',
      width: pixel(250),
      header: '',
      align: 'end',
      renderCell: t => (
        <HStack gap={1} hAlign="end">
          {!t.isArchived && <Button label="Access" size="sm" variant="ghost" href={`/app/u/${t.id}/team`} />}
          <Button label="Edit" size="sm" variant="ghost" onClick={() => setDialog({kind: 'team', team: t})} />
          <Button label={t.isArchived ? 'Restore' : 'Archive'} size="sm" variant="ghost" onClick={() => setDialog({kind: 'archive', team: t})} />
        </HStack>
      ),
    },
  ];

  const accountColumns = useMemo<DataColumn<Account>[]>(
    () => [
      {
        key: 'name',
        header: 'Account',
        renderCell: a => (
          <VStack gap={0.5}>
            <Text>{a.isSelf ? `${a.name} (you)` : a.name}</Text>
            <Text color="secondary" size="sm">{a.email}</Text>
          </VStack>
        ),
      },
      {
        key: 'memberships',
        header: 'Teams',
        renderCell: a => (
          <Text color={a.memberships.length ? undefined : 'secondary'}>
            {a.isAdmin ? 'Superadmin · all teams' : a.memberships.length ? a.memberships.map(m => `${m.unitName} (${m.roleLabel})`).join(', ') : 'No team assignments'}
          </Text>
        ),
      },
      {key: 'isActive', header: 'Status', renderCell: a => <Status value={a.isActive ? 'active' : 'blocked'} label={a.isActive ? 'Active' : 'Suspended'} />},
      {
        key: 'id',
        width: pixel(200),
        header: '',
        align: 'end',
        renderCell: a => (
          <HStack gap={1} hAlign="end">
            <Button label="Edit details" size="sm" variant="ghost" onClick={() => setDialog({kind: 'profile', account: a})} />
            <Button label="Manage" size="sm" variant="ghost" onClick={() => setDialog({kind: 'account', account: a})} />
          </HStack>
        ),
      },
    ],
    [],
  );

  const teamFields = (data: TeamsData): AdminField[] => [
    {kind: 'text', name: 'code', label: 'Code', required: true, description: 'Capitals, digits, _ or -, e.g. TEAM_C', placeholder: 'TEAM_C'},
    {kind: 'text', name: 'name', label: 'Name', required: true},
    {kind: 'text', name: 'timezone', label: 'Time zone', required: true, description: 'Cannot change once the team has dated lessons.'},
    {kind: 'tel', name: 'phone', label: 'Phone'},
    {kind: 'text', name: 'legal_name', label: 'Legal name on invoices'},
    {kind: 'textarea', name: 'address', label: 'Address'},
    {kind: 'select', name: 'state', label: 'State', options: data.states.map(s => ({value: s, label: s}))},
    {
      kind: 'select',
      name: 'tax_mode',
      label: 'Tax',
      required: true,
      options: [
        {value: 'none', label: 'No tax (plain invoices)'},
        {value: 'gst', label: 'GST registered (18%, rates include GST)'},
      ],
    },
    {kind: 'text', name: 'tax_id', label: 'GSTIN', placeholder: '32ABCDE1234F1Z5'},
    {kind: 'text', name: 'invoice_prefix', label: 'Invoice prefix'},
  ];

  const onboardFields = (data: TeamsData): AdminField[] => [
    {kind: 'text', name: 'name', label: 'Name', required: true},
    {kind: 'email', name: 'email', label: 'Email', required: true, autoComplete: 'off'},
    {kind: 'tel', name: 'phone', label: 'Phone', required: true, description: '10 digits'},
    {
      kind: 'select',
      name: 'gender',
      label: 'Gender',
      required: true,
      options: [
        {value: 'OTHER', label: 'Other / not specified'},
        {value: 'FEMALE', label: 'Female'},
        {value: 'MALE', label: 'Male'},
      ],
    },
    {kind: 'password', name: 'password', label: 'Password', required: true, autoComplete: 'new-password', description: 'At least 12 characters. Share it privately.'},
    {kind: 'password', name: 'confirm_password', label: 'Confirm password', required: true, autoComplete: 'new-password'},
    {kind: 'select', name: 'initial_role', label: 'Role', required: true, options: data.roles},
    {
      kind: 'checkboxes',
      name: 'team_ids',
      label: 'Teams',
      required: true,
      options: data.data.filter(t => !t.isArchived).map(t => ({value: String(t.id), label: t.name})),
    },
  ];

  const accountFields = (account: Account): AdminField[] => [
    {
      kind: 'select',
      name: 'action',
      label: 'Action',
      required: true,
      options: [
        {value: 'revoke_sessions', label: 'Sign out all sessions'},
        {value: 'reset_password', label: 'Reset password'},
        account.isActive ? {value: 'suspend', label: 'Suspend account'} : {value: 'activate', label: 'Reactivate account'},
        {value: 'promote', label: 'Grant superadmin access'},
        ...(account.isOwner ? [] : [{value: 'demote', label: 'Remove superadmin and site roles'}]),
      ],
    },
    {kind: 'password', name: 'password', label: 'New password', autoComplete: 'new-password', description: 'Only for a password reset. At least 12 characters.'},
    {kind: 'password', name: 'password_confirmation', label: 'Confirm new password', autoComplete: 'new-password'},
    {kind: 'password', name: 'current_password', label: 'Your current password', required: true, autoComplete: 'current-password'},
  ];

  if (teams.error && !teams.data) {
    return (
      <Page title="Teams & accounts">
        <PageError error={teams.error} onRetry={teams.reload} />
      </Page>
    );
  }

  const active = teams.data?.data.filter(t => !t.isArchived).length ?? 0;
  const archived = (teams.data?.data.length ?? 0) - active;
  const hasFilters = Boolean(query.q || query.status);

  return (
    <Page
      title="Teams & accounts"
      description="Create teams, onboard staff and control account access."
      actions={
        <>
          <Button label="Operations & audit" variant="secondary" href="/app/admin/operations" />
          {teams.data && <Button label="New team" variant="secondary" onClick={() => setDialog({kind: 'team'})} />}
          {teams.data && <Button label="Add staff" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setDialog({kind: 'onboard'})} />}
        </>
      }>
      <MetricRow>
        <Metric label="Active teams" value={number(active)} onClick={() => setQuery({tab: 'teams'})} />
        <Metric label="Archived teams" value={number(archived)} />
        <Metric label="Accounts" value={accounts.data ? number(accounts.data.meta.total) : '—'} hint={accounts.data ? undefined : 'Open the Accounts tab'} onClick={() => setQuery({tab: 'accounts'})} />
      </MetricRow>

      <TabList value={query.tab} onChange={tab => setQuery({tab})}>
        <Tab value="teams" label="Teams" />
        <Tab value="accounts" label="Accounts" />
      </TabList>

      {query.tab === 'teams' && (
        <Card padding={4}>
          {!teams.data ? (
            <EmptyState title="Loading teams" isCompact />
          ) : teams.data.data.length === 0 ? (
            <EmptyState title="No teams yet" actions={<Button label="New team" variant="secondary" onClick={() => setDialog({kind: 'team'})} />} />
          ) : (
            <Table<Team> data={teams.data.data} columns={teamColumns} idKey="id" hasHover textOverflow="truncate" />
          )}
        </Card>
      )}

      {query.tab === 'accounts' && (
        <DataTable<Account>
          label="Accounts"
          rows={accounts.data?.data}
          meta={accounts.data?.meta}
          columns={accountColumns}
          idKey="id"
          isLoading={accounts.isLoading}
          isRefreshing={accounts.isRefreshing}
          search={searchText}
          onSearch={setSearchText}
          searchPlaceholder="Search by name or email"
          filters={
            <Selector
              label="Status"
              isLabelHidden
              placeholder="Any status"
              hasClear
              value={query.status || null}
              onChange={value => setQuery({status: value ?? ''})}
              options={[
                {value: 'active', label: 'Active'},
                {value: 'suspended', label: 'Suspended'},
              ]}
            />
          }
          onSort={() => undefined}
          onPage={page => setQuery({page: String(page)}, {resetPage: false})}
          onPageSize={size => setQuery({per_page: String(size)})}
          hasActiveFilters={hasFilters}
          onClearFilters={() => {
            setSearchText('');
            setQuery({q: '', status: ''});
          }}
          emptyTitle="No accounts"
          phoneCard={{
            title: a => a.name,
            subtitle: a => `${a.email} · ${a.isActive ? 'Active' : 'Suspended'}${a.isAdmin ? ' · Superadmin' : ''}`,
          }}
        />
      )}

      {teams.data && (
        <>
          <AdminFormDrawer
            isOpen={dialog?.kind === 'team'}
            onOpenChange={close}
            title={dialog?.kind === 'team' && dialog.team ? `Edit ${dialog.team.name}` : 'New team'}
            description="Changing tax settings first posts existing charges under the current settings."
            fields={teamFields(teams.data)}
            nullable={dialog?.kind === 'team' && dialog.team ? ['phone', 'legal_name', 'address', 'state', 'tax_id', 'invoice_prefix'] : []}
            initial={
              dialog?.kind === 'team' && dialog.team
                ? {
                    code: dialog.team.code,
                    name: dialog.team.name,
                    timezone: dialog.team.timezone,
                    phone: dialog.team.phone ?? '',
                    legal_name: dialog.team.legalName ?? '',
                    address: dialog.team.address ?? '',
                    state: dialog.team.state ?? '',
                    tax_mode: dialog.team.taxMode,
                    tax_id: dialog.team.taxId ?? '',
                    invoice_prefix: dialog.team.invoicePrefix ?? '',
                  }
                : {timezone: 'Asia/Kolkata', tax_mode: 'none'}
            }
            action={dialog?.kind === 'team' && dialog.team ? `${BASE}/teams/${dialog.team.id}` : `${BASE}/teams`}
            submitLabel={dialog?.kind === 'team' && dialog.team ? 'Save team' : 'Create team'}
            successMessage="Team saved."
            onSaved={teams.reload}
          />
          <AdminFormDrawer
            isOpen={dialog?.kind === 'onboard'}
            onOpenChange={close}
            title="Add staff"
            description="Creates a login and its team assignments together. No invitation email is sent."
            fields={onboardFields(teams.data)}
            initial={{gender: 'OTHER', initial_role: 'team_manager', team_ids: []}}
            action={`${BASE}/onboard`}
            submitLabel="Create account"
            successMessage="Account created. Share the staff login URL and password privately."
            onSaved={() => {
              teams.reload();
              accounts.reload();
            }}
          />
        </>
      )}

      {dialog?.kind === 'archive' && (
        <ConfirmAction
          isOpen
          onOpenChange={close}
          title={dialog.team.isArchived ? `Restore ${dialog.team.name}?` : `Archive ${dialog.team.name}?`}
          description={
            dialog.team.isArchived
              ? 'Staff with access can work in this team again.'
              : 'Records and memberships are kept, but staff lose access until the team is restored. Superadmins can still view the records.'
          }
          actionLabel={dialog.team.isArchived ? 'Restore team' : 'Archive team'}
          isDestructive={!dialog.team.isArchived}
          action={`${BASE}/teams/${dialog.team.id}/archive`}
          body={{archived: dialog.team.isArchived ? 0 : 1}}
          successMessage={dialog.team.isArchived ? 'Team restored.' : 'Team archived. Records are preserved.'}
          onDone={teams.reload}
        />
      )}

      {dialog?.kind === 'profile' && (
        <ProfileDrawer account={dialog.account} onOpenChange={close} onSaved={accounts.reload} />
      )}

      <AdminFormDrawer
        isOpen={dialog?.kind === 'account'}
        onOpenChange={close}
        title={dialog?.kind === 'account' ? `Manage ${dialog.account.name}` : 'Manage account'}
        description="Every action signs the account out everywhere. Removing superadmin also removes site roles; team assignments are kept."
        fields={dialog?.kind === 'account' ? accountFields(dialog.account) : []}
        initial={{action: 'revoke_sessions'}}
        action={dialog?.kind === 'account' ? `${BASE}/accounts/${dialog.account.id}` : BASE}
        submitLabel="Apply"
        successMessage="Account updated. Existing sessions and remembered logins were revoked."
        confirm={{
          title: 'Apply this account action?',
          description: 'The account is signed out of every session. Suspending or removing superadmin takes effect immediately.',
          actionLabel: 'Apply',
          isDestructive: true,
        }}
        onSaved={accounts.reload}
      />
    </Page>
  );
}

interface Profile {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  gender: string | null;
  dob: string | null;
  position: string | null;
  language: string | null;
  bloodGroup: string | null;
  alternateNumber: string | null;
  emergencyContactNumber: string | null;
  permanentAddress: string | null;
  currentAddress: string | null;
  serviceExpiry: string | null;
  bank: Record<string, string | null>;
}

const BANK_LABELS: Record<string, string> = {
  account_holder_name: 'Account holder name',
  account_number: 'Account number',
  bank_name: 'Bank name',
  bank_code: 'IFSC / bank code',
  branch: 'Branch',
  tax_payer_id: 'PAN / tax ID',
};

/** Edit a staff member's personal, contact and bank details; the password is changed only when both fields are filled. */
function ProfileDrawer({account, onOpenChange, onSaved}: {account: Account; onOpenChange: (open: boolean) => void; onSaved: () => void}) {
  const profile = useResource<Profile>(`/app-api/admin/accounts/${account.id}/profile`);
  const data = profile.data;
  const fields: AdminField[] = [
    {kind: 'text', name: 'name', label: 'Full name', required: true},
    {kind: 'email', name: 'email', label: 'Email', required: true},
    {kind: 'tel', name: 'phone', label: 'Phone', required: true, description: '10 digits'},
    {kind: 'select', name: 'gender', label: 'Gender', required: true, options: [{value: 'MALE', label: 'Male'}, {value: 'FEMALE', label: 'Female'}, {value: 'OTHER', label: 'Other'}]},
    {kind: 'text', name: 'dob', label: 'Date of birth', placeholder: 'YYYY-MM-DD'},
    {kind: 'text', name: 'position', label: 'Position'},
    {kind: 'text', name: 'language', label: 'Language'},
    {kind: 'select', name: 'blood_group', label: 'Blood group', options: ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'].map(v => ({value: v, label: v}))},
    {kind: 'tel', name: 'alternate_number', label: 'Alternate number'},
    {kind: 'tel', name: 'emergency_contact_number', label: 'Emergency contact'},
    {kind: 'textarea', name: 'current_address', label: 'Current address'},
    {kind: 'textarea', name: 'permanent_address', label: 'Permanent address'},
    {kind: 'text', name: 'service_expiry', label: 'Service end date', placeholder: 'YYYY-MM-DD'},
    ...Object.entries(BANK_LABELS).map(([key, label]): AdminField => ({kind: 'text', name: `bank_${key}`, label})),
    {kind: 'password', name: 'password', label: 'New password', autoComplete: 'new-password', description: 'Leave empty to keep the current password. At least 8 characters.'},
    {kind: 'password', name: 'confirm_password', label: 'Confirm new password', autoComplete: 'new-password'},
  ];
  const initial = data
    ? {
        name: data.name ?? '',
        email: data.email ?? '',
        phone: data.phone ?? '',
        gender: data.gender ?? '',
        dob: data.dob ?? '',
        position: data.position ?? '',
        language: data.language ?? '',
        blood_group: data.bloodGroup ?? '',
        alternate_number: data.alternateNumber ?? '',
        emergency_contact_number: data.emergencyContactNumber ?? '',
        current_address: data.currentAddress ?? '',
        permanent_address: data.permanentAddress ?? '',
        service_expiry: data.serviceExpiry ?? '',
        ...Object.fromEntries(Object.keys(BANK_LABELS).map(key => [`bank_${key}`, data.bank[key] ?? ''])),
      }
    : undefined;
  return (
    <AdminFormDrawer
      isOpen={Boolean(data)}
      onOpenChange={onOpenChange}
      title={`Edit ${account.name}`}
      description="Personal, contact and bank details. Roles and team access are changed under Manage and Team & access."
      fields={fields}
      initial={initial}
      action={`${BASE}/accounts/${account.id}/profile`}
      submitLabel="Save details"
      successMessage="Details saved."
      nullable={['dob', 'position', 'language', 'blood_group', 'alternate_number', 'emergency_contact_number', 'current_address', 'permanent_address', 'service_expiry', ...Object.keys(BANK_LABELS).map(key => `bank_${key}`)]}
      onSaved={onSaved}
    />
  );
}
