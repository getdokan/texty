import { Outlet } from 'react-router-dom';

import Header from '@/components/Header';
import Notices from '@/components/Notices';

const AppLayout = () => (
  <>
    <Header />
    <div className="wrap texty mt-8">
      <div className="texty-container">
        <Notices />
        <Outlet />
      </div>
    </div>
  </>
);

export default AppLayout;
