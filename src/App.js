<<<<<<< HEAD
import { HashRouter as Router, Switch, Route, Redirect } from 'react-router-dom';
=======
import { HashRouter as Router, Switch, Route } from 'react-router-dom';
>>>>>>> develop
import { ToastContainer } from 'react-toastify';

import Header from './components/Header';
import Dashboard from './pages/Dashboard';
import Notifications from './pages/Notifications';
import Settings from './pages/Settings';
import Tools from './pages/Tools';

function App() {
  return (
    <Router>
      <ToastContainer
        position="top-right"
        autoClose={5000}
        hideProgressBar={false}
        newestOnTop
        closeOnClick
        rtl={false}
        pauseOnFocusLoss={false}
        draggable
        pauseOnHover
      />

      <Header />

      <div className="wrap texty">
        <div className="texty-container">
          <Switch>
            <Route path="/dashboard" component={Dashboard} />
            <Route path="/notifications" component={Notifications} />
            <Route path="/tools" component={Tools} />
            <Route path="/settings" component={Settings} />
            <Redirect from="/" to="/dashboard" />
          </Switch>
        </div>
      </div>
    </Router>
  );
}

export default App;
