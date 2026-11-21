import '@astryxdesign/core/reset.css';
import '@astryxdesign/core/astryx.css';
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';
import '../../src/themes/claps/claps.css';
// Touch-screen sizing on top of the compact theme; see the file for why it is plain CSS.
import './touch.css';

import {StrictMode} from 'react';
import {createRoot} from 'react-dom/client';
import {App} from './App';

const root = document.getElementById('erp-root');
if (root) {
  createRoot(root).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}
