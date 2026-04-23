import { createHashRouter, RouterProvider, Navigate } from 'react-router-dom';
import { ToastContainer } from 'react-toastify';

import AppLayout from './components/AppLayout';
import getRoutes, { withRouter } from './routing';

function App() {
  const routes = getRoutes();

  const mappedRoutes = [
    {
      path: '/',
      element: <AppLayout />,
      children: [
        { index: true, element: <Navigate to="/dashboard" replace /> },
        ...routes.map((route) => {
          const WithRouterComponent = withRouter(route.element);

          return {
            path: route.path,
            element: <WithRouterComponent />,
          };
        }),
      ],
    },
  ];

  const router = createHashRouter(mappedRoutes);

  return (
    <>
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
      <RouterProvider router={router} />
    </>
  );
}

export default App;
