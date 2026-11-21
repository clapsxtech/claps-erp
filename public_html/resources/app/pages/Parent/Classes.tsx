import {useEffect, useState} from 'react';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {List, ListItem} from '@astryxdesign/core/List';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Drawer, DrawerHeader} from '@astryxdesign/core/Drawer';
import {TextArea} from '@astryxdesign/core/TextArea';
import {ApiError} from '../../lib/api';
import {PageError, PageLoading} from '../../components/PageState';
import {Status} from '../../components/Status';
import {kindSuffix, lessonDay, lessonTime, lessonWhen, parentPost, useParentData, type Lesson} from './shared';

interface PastLesson extends Lesson {
  status: 'completed' | 'cancelled' | 'no_show' | 'scheduled';
  attendanceId: number | null;
  minutes: number | null;
  beingChecked: boolean;
  querySent: boolean;
}

interface ClassesData {
  upcoming: Lesson[];
  past: PastLesson[];
}

function whatHappened(lesson: PastLesson): string {
  if (lesson.status === 'cancelled') return 'Cancelled (no charge)';
  if (lesson.status === 'no_show') return 'Marked as not attended (no charge)';
  if (lesson.attendanceId) return `${lesson.minutes} minutes taught`;
  return "Waiting for the teacher's record";
}

function DisputeDrawer({lesson, onClose, onSent}: {lesson: PastLesson | null; onClose: () => void; onSent: (message: string) => void}) {
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string>();
  const [busy, setBusy] = useState(false);

  // A fresh form for each class, so a note typed for one class is never sent for another.
  useEffect(() => {
    setNote('');
    setErrors({});
    setFormError(undefined);
  }, [lesson?.id]);

  async function send() {
    if (!lesson?.attendanceId) return;
    setErrors({});
    setFormError(undefined);
    if (note.trim().length < 10) {
      setErrors({note: ['Please describe what is wrong (at least 10 characters).']});
      return;
    }
    setBusy(true);
    try {
      const result = await parentPost<{message: string}>('/parent/disputes', {attendance_id: lesson.attendanceId, note});
      setNote('');
      onSent(result.message);
    } catch (reason) {
      if (reason instanceof ApiError) {
        if (reason.fieldErrors.note) setErrors({note: reason.fieldErrors.note});
        else setFormError(Object.values(reason.fieldErrors).flat().join(' ') || reason.message);
      } else {
        setFormError('Could not reach the centre. Nothing was sent.');
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Drawer
      isOpen={lesson !== null}
      onOpenChange={open => {
        if (!open) {
          setErrors({});
          setFormError(undefined);
          onClose();
        }
      }}
      label="Something wrong with this class?"
      width={480}
      purpose="form"
      padding={5}
      experimental={{isFullWidthOnMobile: true}}>
      <VStack gap={5}>
        <DrawerHeader title="Something wrong with this class?" subtitle={lesson ? `${lesson.subject} · ${lessonWhen(lesson.startsAt)} · ${lesson.teacher}` : undefined} onOpenChange={open => !open && onClose()} />
        <Text color="secondary">Tell the centre what is wrong. A class still waiting for approval won't be billed until the centre has checked it.</Text>
        {formError && <Banner status="error" title={formError} />}
        <TextArea
          label="What's wrong with this class?"
          value={note}
          onChange={setNote}
          rows={5}
          maxLength={1000}
          isRequired
          placeholder="For example: the class was only 40 minutes"
          status={errors.note?.length ? {type: 'error', message: errors.note[0]} : undefined}
        />
        <HStack gap={2} hAlign="end">
          <Button label="Cancel" variant="ghost" onClick={onClose} />
          <Button label="Send to the centre" variant="primary" isLoading={busy} onClick={send} />
        </HStack>
      </VStack>
    </Drawer>
  );
}

export default function ParentClasses() {
  const {data, error, reload} = useParentData<ClassesData>('/parent-api/sections/classes');
  const [disputing, setDisputing] = useState<PastLesson | null>(null);
  const [sent, setSent] = useState<string>();

  if (error) return <PageError error={error} onRetry={reload} />;
  if (!data) return <PageLoading />;

  return (
    <VStack gap={5}>
      {sent && <Banner status="success" title={sent} isDismissable onDismiss={() => setSent(undefined)} />}
      <VStack gap={2}>
        <Heading level={2}>Upcoming</Heading>
        {data.upcoming.length === 0 ? (
          <EmptyState title="No upcoming classes in the next 60 days" description="If you were expecting classes, please contact the centre." isCompact />
        ) : (
          <Card padding={0}>
            <List hasDividers>
              {data.upcoming.map(lesson => (
                <ListItem
                  key={lesson.id}
                  label={`${lesson.subject}${kindSuffix(lesson.kind)}`}
                  description={<Text color="secondary">with {lesson.teacher}</Text>}
                  endContent={
                    <VStack gap={0} hAlign="end">
                      <Text weight="semibold">{lessonDay(lesson.startsAt)}</Text>
                      <Text color="secondary" size="sm" hasTabularNumbers>
                        {lessonTime(lesson.startsAt)}
                      </Text>
                    </VStack>
                  }
                />
              ))}
            </List>
          </Card>
        )}
      </VStack>

      <VStack gap={2}>
        <Heading level={2}>Past 60 days</Heading>
        <Text color="secondary">If a class record looks wrong, tell us here. It won't be billed until the centre has checked it.</Text>
        {data.past.length === 0 ? (
          <EmptyState title="No classes in the last 60 days" description="Past classes will be listed here." isCompact />
        ) : (
          <Card padding={0}>
            <List hasDividers>
              {data.past.map(lesson => (
                <ListItem
                  key={lesson.id}
                  align="start"
                  label={lessonWhen(lesson.startsAt)}
                  description={
                    <VStack gap={1}>
                      <Text color="secondary">
                        {lesson.subject}
                        {kindSuffix(lesson.kind)} · {lesson.teacher}
                      </Text>
                      <Text>{whatHappened(lesson)}</Text>
                      {lesson.beingChecked && <Text color="secondary" size="sm">Being checked by the centre</Text>}
                    </VStack>
                  }
                  endContent={
                    lesson.querySent ? (
                      <Status value="submitted" label="Query sent" />
                    ) : lesson.attendanceId ? (
                      <Button label="Something wrong?" variant="secondary" size="sm" onClick={() => setDisputing(lesson)} />
                    ) : undefined
                  }
                />
              ))}
            </List>
          </Card>
        )}
      </VStack>

      <DisputeDrawer
        lesson={disputing}
        onClose={() => setDisputing(null)}
        onSent={message => {
          setDisputing(null);
          setSent(message);
          reload();
          window.scrollTo({top: 0, behavior: 'smooth'});
        }}
      />
    </VStack>
  );
}
