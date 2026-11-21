// /account/security outside the staff shell (old links, teachers): same panel in the guest frame.
import {Link} from '@astryxdesign/core/Link';
import {GuestLayout} from './GuestLayout';
import {SecurityPanel} from './SecurityPanel';

export default function AccountSecurity() {
  return (
    <GuestLayout
      title="Account security"
      description="Protect your sign-in with an authenticator app."
      width={720}
      footer={<Link href="/">Back to your workspace</Link>}
    >
      <SecurityPanel isBare />
    </GuestLayout>
  );
}
