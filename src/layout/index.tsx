import { SlotFillProvider } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';

import type { TextyRoute } from '../routing';
import LayoutHeader from './Header';
import LayoutFooter from './Footer';

type Props = {
  children: ReactNode;
  route: TextyRoute;
  title?: string;
  backUrl?: string;
  header?: ReactNode;
  footer?: ReactNode;
};

const Layout = ({
  children,
  route,
  title,
  backUrl,
  header,
  footer,
}: Props) => {
  const location = useLocation();

  /**
   * Allow extensions to act on every page render.
   *
   * @param location.pathname Current hash path
   * @param route             The matched route
   */
  applyFilters('texty_layout_before_render', location.pathname, route);

  const headerNode: ReactNode =
    header !== undefined ? (
      header
    ) : (
      <LayoutHeader route={route} title={title} backUrl={backUrl} />
    );

  const footerNode: ReactNode =
    footer !== undefined ? footer : <LayoutFooter />;

  return (
    <SlotFillProvider>
      <div className="texty-page-layout flex flex-col gap-6">
        {headerNode}
        <main className="texty-page-content">{children}</main>
        {footerNode}
      </div>
    </SlotFillProvider>
  );
};

export default Layout;
