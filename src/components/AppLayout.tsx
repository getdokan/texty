import { Outlet } from 'react-router-dom';
import Header from './Header';

function AppLayout() {
  return (
    <>
      <Header />
      <div className="wrap texty">
        <div className="texty-container">
          <Outlet />
        </div>
      </div>
    </>
  );
}

export default AppLayout;
