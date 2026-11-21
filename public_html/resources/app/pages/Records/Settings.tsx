// Application settings (/app/records/settings), read-only.
import {Card} from '@astryxdesign/core/Card';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Skeleton} from '@astryxdesign/core/Skeleton';
import {Page, PageError} from '../../components/PageState';
import {useResource} from '../../lib/hooks';
import {dateTime} from '../../lib/format';
import {AdminOnly, CRUMBS, RECORDS_API} from './shared';

interface SettingsData {
  settings: null | {
    name: string | null;
    address: string | null;
    phone: string | null;
    supportMail: string | null;
    adminMail: string | null;
    careerMail: string | null;
    facebook: string | null;
    instagram: string | null;
    youtube: string | null;
    linkedin: string | null;
    twitter: string | null;
    map: string | null;
    updatedAt: string | null;
  };
}

const FIELDS: [keyof NonNullable<SettingsData['settings']>, string, 'text' | 'mail' | 'url' | 'code'][] = [
  ['name', 'Organization name', 'text'],
  ['address', 'Address', 'text'],
  ['phone', 'Phone', 'text'],
  ['supportMail', 'Support email', 'mail'],
  ['adminMail', 'Admin email', 'mail'],
  ['careerMail', 'Careers email', 'mail'],
  ['facebook', 'Facebook', 'url'],
  ['instagram', 'Instagram', 'url'],
  ['youtube', 'YouTube', 'url'],
  ['linkedin', 'LinkedIn', 'url'],
  ['twitter', 'Twitter / X', 'url'],
  ['map', 'Map embed', 'code'],
];

function SettingsView() {
  const res = useResource<SettingsData>(`${RECORDS_API}/settings`);
  const s = res.data?.settings;
  return (
    <Page
      title="Application settings"
      description={s?.updatedAt ? `Last changed ${dateTime(s.updatedAt)}. Read-only here.` : 'Organization details shown on public pages. Read-only here.'}
      crumbs={CRUMBS}>
      {res.error && !res.data && <PageError error={res.error} onRetry={res.reload} />}
      {!res.data && !res.error && <Skeleton height={320} width="100%" />}
      {res.data && !s && <EmptyState title="No settings saved" description="The original settings record was never created." />}
      {s && (
        <Card padding={4}>
          <MetadataList label={{position: 'start'}}>
            {FIELDS.map(([key, label, kind]) => {
              const value = s[key];
              let content;
              if (!value) content = <Text color="secondary">Not set</Text>;
              else if (kind === 'mail') content = <Link href={`mailto:${value}`}>{value}</Link>;
              else if (kind === 'url' && /^https?:\/\//i.test(value)) content = <Link href={value} isExternalLink>{value}</Link>;
              else if (kind === 'code') content = <Text type="code" maxLines={3}>{value}</Text>;
              else content = <Text>{value}</Text>;
              return (
                <MetadataListItem key={key} label={label}>
                  {content}
                </MetadataListItem>
              );
            })}
          </MetadataList>
        </Card>
      )}
    </Page>
  );
}

export default function RecordsSettings() {
  return (
    <AdminOnly title="Application settings">
      <SettingsView />
    </AdminOnly>
  );
}
