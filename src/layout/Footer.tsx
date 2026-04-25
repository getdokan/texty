import { applyFilters } from '@wordpress/hooks';
import type { ReactNode } from 'react';

const LayoutFooter = () => {
  /**
   * Allow extensions to render a global page footer.
   *
   * Default is `null` — no footer chrome. Return any ReactNode to inject one.
   */
  const content: ReactNode = applyFilters(
    'texty_layout_footer',
    null
  ) as ReactNode;

  return content ? (
    <footer className="texty-page-footer pt-4">{content}</footer>
  ) : null;
};

export default LayoutFooter;
