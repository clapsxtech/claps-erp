// Team & access for one unit (page "staff"). Superadmin only.
// Writes go to the business-actions routes: membership-grant/revoke and
// teacher-link/unlink, where Operations keeps the last-admin and teacher-pool safeguards.
import {useState} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Icon} from '@astryxdesign/core/Icon';
import {type TableColumn} from '@astryxdesign/core/Table';
import {RowTable} from '../../components/RowTable';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Status} from '../../components/Status';
import {Metric, MetricRow} from '../../components/Metric';
import {useResource} from '../../lib/hooks';
import {number} from '../../lib/format';
import {session, unitById} from '../../lib/session';
import {AdminFormDrawer, ConfirmAction} from './adminForm';

interface Member extends Record<string, unknown> {
  userId: number;
  name: string;
  email: string | null;
  role: string;
  roleLabel: string;
  isActive: boolean;
  isSelf: boolean;
}

interface Teacher extends Record<string, unknown> {
  id: number;
  code: string;
  name: string;
  status: string;
  linkedUserId: number | null;
  linkedUserName: string | null;
}

interface TeamAccessData {
  team: {id: number; code: string; name: string; isArchived: boolean};
  members: Member[];
  teachers: Teacher[];
  accounts: {id: number; name: string; email: string}[];
  roles: {value: string; label: string}[];
}

type Dialog =
  | {kind: 'grant'; member?: Member}
  | {kind: 'revoke'; member: Member}
  | {kind: 'link'; teacher: Teacher}
  | {kind: 'unlink'; teacher: Teacher}
  | null;

export default function TeamAccess() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const {data, error, isLoading, reload} = useResource<TeamAccessData>(`/app-api/units/${unitId}/admin/team`);
  const [dialog, setDialog] = useState<Dialog>(null);
  const close = (open: boolean) => {
    if (!open) setDialog(null);
  };

  if (isLoading) return <PageLoading />;
  if (error || !data) {
    return (
      <Page title="Team & access">
        <PageError error={error ?? new Error('Not found')} onRetry={reload} />
      </Page>
    );
  }

  const readOnly = data.team.isArchived || Boolean(unit?.isArchived) || session().sheetsAuthority;
  const action = (name: string) => `/erp/units/${unitId}/business-actions/${name}`;
  const teacherMembers = data.members.filter(m => m.role === 'teacher' && m.isActive);

  const memberColumns: TableColumn<Member>[] = [
    {
      key: 'name',
      header: 'Person',
      renderCell: m => (
        <VStack gap={0.5}>
          <Text>{m.isSelf ? `${m.name} (you)` : m.name}</Text>
          {m.email && <Text color="secondary" size="sm">{m.email}</Text>}
        </VStack>
      ),
    },
    {key: 'roleLabel', header: 'Role', renderCell: m => <Text>{m.roleLabel}</Text>},
    {key: 'isActive', header: 'Account', renderCell: m => <Status value={m.isActive ? 'active' : 'inactive'} />},
    {
      key: 'userId',
      header: '',
      align: 'end',
      renderCell: m =>
        readOnly || m.isSelf ? (
          <Text> </Text>
        ) : (
          <HStack gap={1} hAlign="end">
            <Button label="Change role" size="sm" variant="ghost" onClick={() => setDialog({kind: 'grant', member: m})} />
            <Button label="Remove" size="sm" variant="ghost" onClick={() => setDialog({kind: 'revoke', member: m})} />
          </HStack>
        ),
    },
  ];

  const teacherColumns: TableColumn<Teacher>[] = [
    {
      key: 'name',
      header: 'Teacher',
      renderCell: t => (
        <VStack gap={0.5}>
          <Text>{t.name}</Text>
          <Text color="secondary" size="sm">{t.code}</Text>
        </VStack>
      ),
    },
    {key: 'status', header: 'Status', renderCell: t => <Status value={t.status} />},
    {key: 'linkedUserName', header: 'Login account', renderCell: t => <Text color={t.linkedUserName ? undefined : 'secondary'}>{t.linkedUserName ?? 'Not linked'}</Text>},
    {
      key: 'id',
      header: '',
      align: 'end',
      renderCell: t =>
        readOnly ? (
          <Text> </Text>
        ) : t.linkedUserId ? (
          <Button label="Unlink" size="sm" variant="ghost" onClick={() => setDialog({kind: 'unlink', teacher: t})} />
        ) : t.status === 'active' ? (
          <Button label="Link login" size="sm" variant="ghost" onClick={() => setDialog({kind: 'link', teacher: t})} />
        ) : (
          <Text> </Text>
        ),
    },
  ];

  const accountOptions = data.accounts.map(a => ({value: String(a.id), label: `${a.name} · ${a.email}`}));

  return (
    <Page
      title="Team & access"
      description={`Who can work in ${data.team.name}. Changes affect this team only.`}
      actions={
        <>
          <Button label="Teams & accounts" variant="secondary" href="/app/admin/teams" />
          {!readOnly && <Button label="Give access" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setDialog({kind: 'grant'})} />}
        </>
      }>
      {readOnly && (
        <Banner
          status="warning"
          title={data.team.isArchived ? 'This team is archived. Restore it from Teams & accounts to change access.' : 'Changes are paused while Sheets are authoritative.'}
        />
      )}
      <MetricRow>
        <Metric label="People with access" value={number(data.members.length)} />
        <Metric label="Inactive accounts" value={number(data.members.filter(m => !m.isActive).length)} tone={data.members.some(m => !m.isActive) ? 'warning' : undefined} />
        <Metric label="Teachers with a login" value={`${number(data.teachers.filter(t => t.linkedUserId).length)} of ${number(data.teachers.length)}`} />
      </MetricRow>

      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>People</Heading>
          <Text color="secondary">Only existing active accounts can be given access. New staff accounts are created in Teams & accounts.</Text>
          {data.members.length === 0 ? (
            <EmptyState title="Nobody has access yet" isCompact actions={!readOnly ? <Button label="Give access" variant="secondary" onClick={() => setDialog({kind: 'grant'})} /> : undefined} />
          ) : (
            <RowTable<Member> data={data.members} columns={memberColumns} idKey="userId" hasHover textOverflow="truncate" />
          )}
        </VStack>
      </Card>

      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Teacher logins</Heading>
          <Text color="secondary">Give the account the Teacher role in this team first, then link it. Identity is never guessed from names.</Text>
          {data.teachers.length === 0 ? (
            <EmptyState title="No teachers in this team" description="Teachers appear here once they are linked to this team." isCompact />
          ) : (
            <RowTable<Teacher> data={data.teachers} columns={teacherColumns} idKey="id" hasHover textOverflow="truncate" />
          )}
        </VStack>
      </Card>

      <AdminFormDrawer
        isOpen={dialog?.kind === 'grant'}
        onOpenChange={close}
        title={dialog?.kind === 'grant' && dialog.member ? `Change role for ${dialog.member.name}` : 'Give access to this team'}
        description="Choose an active account and the role it has in this team. Last-administrator and teacher-link safeguards apply."
        fields={[
          ...(dialog?.kind === 'grant' && dialog.member
            ? []
            : [{kind: 'select' as const, name: 'user_id', label: 'Account', required: true, options: accountOptions}]),
          {kind: 'select', name: 'role', label: 'Role', required: true, options: data.roles},
        ]}
        hidden={dialog?.kind === 'grant' && dialog.member ? {user_id: dialog.member.userId} : undefined}
        initial={dialog?.kind === 'grant' && dialog.member ? {role: dialog.member.role} : {role: 'team_manager'}}
        action={action('membership-grant')}
        submitLabel="Save access"
        successMessage="Access saved."
        onSaved={reload}
      />

      {dialog?.kind === 'revoke' && (
        <ConfirmAction
          isOpen
          onOpenChange={close}
          title={`Remove ${dialog.member.name} from ${data.team.name}?`}
          description="They lose access to this team immediately. Their history is kept. A linked teacher login must be unlinked first, and the last unit administrator cannot be removed."
          actionLabel="Remove access"
          action={action('membership-revoke')}
          body={{user_id: dialog.member.userId}}
          successMessage="Access removed."
          onDone={reload}
        />
      )}

      <AdminFormDrawer
        isOpen={dialog?.kind === 'link'}
        onOpenChange={close}
        title={dialog?.kind === 'link' ? `Link a login to ${dialog.teacher.name}` : 'Link a login'}
        description="The account needs the Teacher role in this team only, and the teacher must belong only to this team."
        fields={[
          {
            kind: 'select',
            name: 'user_id',
            label: 'Teacher account',
            required: true,
            options: teacherMembers.map(m => ({value: String(m.userId), label: m.email ? `${m.name} · ${m.email}` : m.name})),
            description: teacherMembers.length ? undefined : 'No account has the Teacher role here yet. Give access with the Teacher role first.',
          },
        ]}
        hidden={dialog?.kind === 'link' ? {teacher_id: dialog.teacher.id} : undefined}
        action={action('teacher-link')}
        submitLabel="Link login"
        successMessage="Teacher login linked."
        onSaved={reload}
      />

      <AdminFormDrawer
        isOpen={dialog?.kind === 'unlink'}
        onOpenChange={close}
        title={dialog?.kind === 'unlink' ? `Unlink ${dialog.teacher.linkedUserName ?? 'login'} from ${dialog.teacher.name}` : 'Unlink login'}
        description="Removes the login's teacher access immediately. Teacher history and scheduled lessons are kept. The membership can be removed afterwards."
        fields={[{kind: 'textarea', name: 'reason', label: 'Reason', required: true}]}
        hidden={dialog?.kind === 'unlink' ? {teacher_id: dialog.teacher.id} : undefined}
        action={action('teacher-unlink')}
        submitLabel="Unlink login"
        successMessage="Teacher login unlinked."
        confirm={{title: 'Unlink this teacher login?', description: 'The account stops seeing this teacher’s classes straight away.', actionLabel: 'Unlink', isDestructive: true}}
        onSaved={reload}
      />
    </Page>
  );
}
