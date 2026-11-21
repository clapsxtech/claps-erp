// Account security (global, every signed-in user) inside the staff shell. The
// panel is shared with /account/security; see pages/Guest/SecurityPanel.tsx for
// how the setup key and recovery codes are kept out of every GET.
import {Page} from '../../components/PageState';
import {SecurityPanel} from '../Guest/SecurityPanel';

export default function Security() {
  return (
    <Page title="Account security" description="Protect your sign-in with an authenticator app." width={760}>
      <SecurityPanel />
    </Page>
  );
}
