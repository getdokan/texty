import { applyFilters } from '@wordpress/hooks';
import type { ComponentType, ReactElement, ReactNode } from 'react';
import { cloneElement, createElement, isValidElement } from 'react';
import {
  createSearchParams,
  redirect,
  replace,
  useLocation,
  useMatches,
  useNavigate,
  useNavigation,
  useParams,
  useSearchParams,
  type Location,
  type NavigateFunction,
  type Navigation,
  type Params,
  type RedirectFunction,
  type UIMatch,
} from 'react-router-dom';
import routes from './routes';

export type TextyRoute = {
  id: string;
  title?: string;
  icon?: ReactNode;
  path: string;
  element: ReactElement | ComponentType<any>;
  header?: ReactNode;
  footer?: ReactNode;
  backUrl?: string;
  backButtonLabel?: string;
};

export interface RouterProps {
  navigate: NavigateFunction;
  params: Readonly<Params<string>>;
  location: Location;
  matches: UIMatch<unknown, unknown>[];
  navigation: Navigation;
  searchParams: URLSearchParams;
  setSearchParams: ReturnType<typeof useSearchParams>[1];
  redirect: RedirectFunction;
  replace: RedirectFunction;
  createSearchParams: typeof createSearchParams;
}

/**
 * HOC that injects react-router props into the wrapped component.
 *
 * Accepts either a component type (`Dashboard`) or a JSX element
 * (`<Dashboard />`) — mirroring dokan-lite's `withRouter` so extensions
 * can register either style in their route entry.
 */
export function withRouter(Component: ReactElement | ComponentType<any>) {
  return function ComponentWithRouterProp(props: Record<string, unknown>) {
    const navigate = useNavigate();
    const params = useParams();
    const location = useLocation();
    const matches = useMatches();
    const navigation = useNavigation();
    const [searchParams, setSearchParams] = useSearchParams();

    const routerProps: RouterProps = {
      navigate,
      params,
      location,
      matches,
      navigation,
      searchParams,
      setSearchParams,
      redirect,
      replace,
      createSearchParams,
    };

    if (isValidElement(Component)) {
      return cloneElement(Component, { ...props, ...routerProps });
    }

    return createElement(Component as ComponentType<any>, {
      ...props,
      ...routerProps,
    });
  };
}

/**
 * Resolve the final route list after running the `texty_routes` filter.
 * Extensions can add or remove routes via `addFilter`.
 */
const getRoutes = (): TextyRoute[] => {
  return applyFilters('texty_routes', routes) as TextyRoute[];
};

export default getRoutes;
