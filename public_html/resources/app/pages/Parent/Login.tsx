// Sign in with the child's student ID and date of birth. The POST route
// checks the details and applies every lockout; this page only shows its answer.
import {useState} from 'react';
import {VStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {TextInput} from '@astryxdesign/core/TextInput';
import {DateInput} from '@astryxdesign/core/DateInput';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {ApiError} from '../../lib/api';
import {parentPost, parentSession} from './shared';

type ISODate = `${number}${number}${number}${number}-${number}${number}-${number}${number}`;

export default function ParentLogin() {
  const {status} = parentSession();
  const [code, setCode] = useState('');
  const [dob, setDob] = useState<ISODate | undefined>();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string>();
  const [busy, setBusy] = useState(false);
  const today = new Date().toISOString().slice(0, 10) as ISODate;

  async function submit() {
    setErrors({});
    setFormError(undefined);
    if (!code.trim() || !dob) {
      setErrors({...(code.trim() ? {} : {student_code: ['Enter the student ID.']}), ...(dob ? {} : {date_of_birth: ['Enter the date of birth.']})});
      return;
    }
    setBusy(true);
    try {
      const result = await parentPost<{redirect: string}>('/parent/login', {student_code: code, date_of_birth: dob}, {signedOutRedirect: false});
      window.location.assign(result.redirect);
    } catch (reason) {
      setBusy(false);
      if (reason instanceof ApiError && Object.keys(reason.fieldErrors).length) {
        setErrors(reason.fieldErrors);
      } else {
        setFormError(reason instanceof ApiError ? reason.message : 'Could not reach the centre. Check your connection and try again.');
      }
    }
  }

  const fieldStatus = (name: string) => (errors[name]?.length ? {type: 'error' as const, message: errors[name][0]} : undefined);

  return (
    <VStack hAlign="center">
            <VStack width="100%" maxWidth={480}>
          <VStack padding={4} gap={5}>
            <VStack gap={2}>
              <Text color="secondary" size="sm" weight="semibold">
                ClapsLearn · Parent portal
              </Text>
              <Heading level={1}>See your child's classes, results and fees</Heading>
              <Text color="secondary">Sign in with your child's student ID and date of birth. Each child has their own sign-in.</Text>
            </VStack>
            {status && <Banner status="success" title={status} />}
            {formError && <Banner status="error" title={formError} />}
            <Card padding={5}>
              <form
                onSubmit={event => {
                  event.preventDefault();
                  void submit();
                }}>
                <VStack gap={4}>
                  <TextInput
                    label="Student ID"
                    value={code}
                    onChange={setCode}
                    htmlName="student_code"
                    autoComplete="username"
                    autoCapitalize="characters"
                    hasAutoCorrect={false}
                    placeholder="For example CL-1031"
                    isRequired
                    size="lg"
                    status={fieldStatus('student_code')}
                  />
                  <DateInput label="Date of birth" value={dob} onChange={setDob} max={today} isRequired size="lg" status={fieldStatus('date_of_birth')} format="date_long" />
                  <Button label="Sign in" type="submit" variant="primary" size="lg" width="100%" isLoading={busy} />
                </VStack>
              </form>
            </Card>
            <Text color="secondary" size="sm">
              Your student ID is on fee requests and receipts from the centre. If you can't sign in, ask the centre to check the date of birth they have on record. For your security, repeated wrong attempts lock the ID for 15 minutes.
            </Text>
          </VStack>
            </VStack>
          </VStack>
  );
}
