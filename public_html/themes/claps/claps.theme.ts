// The claps theme. `astryx experimental theme editor` wrote this file once and never changes it again.
// The checked settings live in claps.settings.json and the generated claps.settings.ts;
// change them with `astryx experimental theme editor set claps ...`. Add your own changes below: they win over the settings.
import {defineTheme} from '@astryxdesign/core/theme';
import {clapsSettings} from './claps.settings';

export const clapsTheme = defineTheme({
  extends: clapsSettings,
  name: 'claps',
});
