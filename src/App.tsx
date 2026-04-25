import { Toaster } from '@wedevs/plugin-ui';
import type { ComponentType } from 'react';
import { createHashRouter, Navigate, RouterProvider } from 'react-router-dom';

import AppLayout from './components/AppLayout';
import ErrorBoundary from './components/errors/ErrorBoundary';
import NotFound from './components/errors/NotFound';
import Layout from './layout';
import getRoutes, { withRouter } from './routing';

const App = () => {
  const routes = getRoutes();

  const mappedRoutes = [
    {
      path: '/',
      element: <AppLayout />,
      errorElement: <ErrorBoundary />,
      children: [
        { index: true, element: <Navigate to="/dashboard" replace /> },
        ...routes.map((route) => {
          const WithRouterComponent = withRouter(
            route.element
          ) as ComponentType;

          return {
            path: route.path,
            errorElement: <ErrorBoundary />,
            element: (
              <Layout
                route={route}
                title={route.title}
                backUrl={route.backUrl}
                backButtonLabel={route.backButtonLabel}
                header={route.header}
                footer={route.footer}
              >
                <WithRouterComponent />
              </Layout>
            ),
          };
        }),
        {
          path: '*',
          element: <NotFound />,
        },
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
};

export default App;
