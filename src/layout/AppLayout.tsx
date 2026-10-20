import { Outlet } from 'react-router-dom';

import Notices from '@/components/Notices';

// The header renders in its own root above the captured admin notices — see
// `src/index.tsx` and `Texty\Admin\Menu::render_page`.
const AppLayout = () => (
  <div className="wrap texty mt-8">
    <div className="texty-container">
      <Notices />
      <Outlet />
    </div>
  </div>
);

export default AppLayout;
