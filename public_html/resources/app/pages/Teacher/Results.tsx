// Record an assessment for one of your own active assignments, then see it in
// the history straight away. Retries reuse the same submission key, so a
// double tap or a dropped connection never saves the result twice.
import {useEffect, useState, type ComponentProps} from 'react';
import {useSearchParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Grid} from '@astryxdesign/core/Grid';
import {Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Selector} from '@astryxdesign/core/Selector';
import {TextInput} from '@astryxdesign/core/TextInput';
import {TextArea} from '@astryxdesign/core/TextArea';
import {NumberInput} from '@astryxdesign/core/NumberInput';
import {DateInput} from '@astryxdesign/core/DateInput';
import {useToast} from '@astryxdesign/core/Toast';
import {ApiError, postJson} from '../../lib/api';
import {PageError, PageLoading} from '../../components/PageState';
import {Filters, Section, SubHeading, type Workspace, useWorkspace} from './shared';
import {ResultsHistory} from './ResultsHistory';

type DateValue = ComponentProps<typeof DateInput>['value'];
const FIELDS = ['enrollment_id', 'assessment', 'assessed_on', 'score', 'maximum', 'notes'];

function newKey(): string {
  // randomUUID needs a secure context; plain-HTTP intranet hosts fall back to getRandomValues.
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function ResultForm({data, onSaved}: {data: Workspace; onSaved: () => void}) {
  const [params] = useSearchParams();
  const showToast = useToast();
  const today = data.range.today;
  const preselected = params.get('assignment') ?? '';
  const blank = {enrollment_id: data.assignments.some(a => String(a.id) === preselected) ? preselected : '', assessment: '', assessed_on: today, notes: ''};
  const [values, setValues] = useState<Record<string, string>>(blank);
  const [score, setScore] = useState<number | null>(null);
  const [maximum, setMaximum] = useState<number | null>(null);
  const [key, setKey] = useState(newKey);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string>();

  useEffect(() => {
    if (preselected && data.assignments.some(a => String(a.id) === preselected)) setValues(current => ({...current, enrollment_id: preselected}));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [preselected]);

  const set = (name: string) => (value: string | undefined | null) => setValues(current => ({...current, [name]: value ?? ''}));
  const statusFor = (name: string) => (errors[name]?.length ? {type: 'error' as const, message: errors[name][0]} : undefined);

  const submit = async () => {
    setSaving(true);
    setErrors({});
    setFormError(undefined);
    try {
      const result = await postJson<{message: string}>('/erp/teacher/results', {
        ...values,
        score: score ?? '',
        maximum: maximum ?? '',
        submission_key: key,
      });
      showToast({body: result.message, uniqueID: 'teacher-result'});
      setValues({...blank, enrollment_id: values.enrollment_id, assessed_on: values.assessed_on});
      setScore(null);
      setMaximum(null);
      setKey(newKey());
      onSaved();
    } catch (reason) {
      if (reason instanceof ApiError) {
        const placed: Record<string, string[]> = {};
        const other: string[] = [];
        for (const [name, messages] of Object.entries(reason.fieldErrors)) {
          if (FIELDS.includes(name)) placed[name] = messages;
          else other.push(...messages);
        }
        setErrors(placed);
        setFormError(other.length ? other.join(' ') : Object.keys(placed).length ? 'Please check the highlighted fields. Nothing has been saved yet.' : reason.message);
        // The key was already used for a different result: the earlier one is
        // saved. Refresh the history and start a new submission.
        if (reason.status === 409) {
          setKey(newKey());
          onSaved();
        }
      } else {
        setFormError('Could not reach the server. Check your connection and try again; it will not be saved twice.');
      }
    } finally {
      setSaving(false);
    }
  };

  if (data.assignments.length === 0) {
    return <Banner status="info" title="An active student assignment is needed before you can record a result." />;
  }
  return (
    <Card padding={4}>
      <VStack gap={4}>
        <SubHeading title="Record an assessment" note="Choose one of your assigned students. Results appear in your history as soon as you save." />
        {formError && <Banner status="error" title={formError} />}
        <Selector
          label="Student & subject"
          isRequired
          placeholder="Choose an assignment"
          options={data.assignments.map(a => ({value: String(a.id), label: `${a.studentName} · ${a.subjectName}`, description: a.studentCode}))}
          value={values.enrollment_id || undefined}
          onChange={set('enrollment_id')}
          hasSearch={data.assignments.length > 8}
          status={statusFor('enrollment_id')}
        />
        <TextInput label="Assessment / topic" isRequired value={values.assessment} onChange={set('assessment')} status={statusFor('assessment')} />
        <DateInput label="Assessment date" isRequired value={(values.assessed_on || undefined) as DateValue} max={today as DateValue} onChange={set('assessed_on')} status={statusFor('assessed_on')} />
        <Grid columns={2} gap={3}>
          <NumberInput label="Score" isRequired value={score} onChange={value => setScore(Number.isFinite(value) ? value : null)} min={0} isIntegerOnly status={statusFor('score')} />
          <NumberInput label="Out of" isRequired value={maximum} onChange={value => setMaximum(Number.isFinite(value) ? value : null)} min={1} max={1000000000} isIntegerOnly status={statusFor('maximum')} />
        </Grid>
        <TextArea label="Notes" isOptional value={values.notes} onChange={set('notes')} status={statusFor('notes')} />
        <HStack gap={2} hAlign="end" vAlign="center">
          <Text color="secondary" size="sm">Zero marks are accepted.</Text>
          <Button label="Save result" variant="primary" isLoading={saving} onClick={submit} />
        </HStack>
      </VStack>
    </Card>
  );
}

export default function Results() {
  const ws = useWorkspace();
  const data = ws.data;
  const title = 'Assessment results';
  if (ws.error && !data) return <Section title={title}><PageError error={ws.error} onRetry={ws.reload} /></Section>;
  if (!data) return <Section title={title}><PageLoading /></Section>;
  return (
    <Section title={title} description="Record results for your own students and review what you saved.">
      <ResultForm data={data} onSaved={ws.reload} />
      <Filters data={data} />
      <ResultsHistory data={data} />
    </Section>
  );
}
