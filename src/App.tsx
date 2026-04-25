import { Toaster } from '@wedevs/plugin-ui';
import { createHashRouter, Navigate, RouterProvider } from 'react-router-dom';

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
          const WithRouterComponent = withRouter(
            route.element
          ) as React.ComponentType;

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
      <Toaster position="top-right" richColors closeButton />
      <RouterProvider router={router} />
    </>
  );
}

export default App;
