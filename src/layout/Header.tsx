import { Slot } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { ChevronLeft } from 'lucide-react';
import { useNavigate, useParams } from 'react-router-dom';

import type { TextyRoute } from '../routing';

type Props = {
  route: TextyRoute;
  title?: string;
  backUrl?: string;
  backButtonLabel?: string;
};

const parseBackUrl = (
  urlString: string,
  params: Readonly<Record<string, string | undefined>>
): string =>
  urlString.replace(
    /:(\w+)/g,
    (_match: string, key: string) => params[key] ?? ''
  );

const LayoutHeader = ({ route, title, backUrl, backButtonLabel }: Props) => {
  const navigate = useNavigate();
  const params = useParams();

  const rawTitle: string = title ?? route.title ?? '';
  const filteredTitle: string = applyFilters(
    `texty_${route.id}_header_title`,
    rawTitle,
    route
  ) as string;

  const rawBackUrl: string = backUrl ?? route.backUrl ?? '';
  const filteredBackUrl: string = applyFilters(
    `texty_${route.id}_header_back_url`,
    rawBackUrl,
    route
  ) as string;

  if (!filteredTitle && !filteredBackUrl) {
    return null;
  }

  const backLabel: string =
    backButtonLabel ?? route.backButtonLabel ?? __('Back', 'texty');

  return (
    <div className="texty-page-header flex items-center justify-between gap-4">
      <div className="flex items-center gap-4">
        {filteredBackUrl && (
          <button
            type="button"
            onClick={() => navigate(parseBackUrl(filteredBackUrl, params))}
            className="inline-flex cursor-pointer items-center gap-1 text-sm text-gray-600 hover:text-gray-900"
          >
            <ChevronLeft className="size-4" />
            {backLabel}
          </button>
        )}
        {filteredTitle && (
          <div className="text-2xl font-bold text-gray-900">
            {filteredTitle}
          </div>
        )}
      </div>
      <Slot name={`texty_${route.id}_header_actions`} fillProps={{ route }} />
    </div>
  );
};

export default LayoutHeader;
