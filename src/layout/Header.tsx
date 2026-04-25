import { Slot } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { ChevronLeft } from 'lucide-react';
import { useNavigate, useParams } from 'react-router-dom';

import getRoutes, { type TextyRoute } from '../routing';

type Props = {
  route: TextyRoute;
  title?: string;
  backUrl?: string;
};

const parseBackUrl = (
  urlString: string,
  params: Readonly<Record<string, string | undefined>>
): string =>
  urlString.replace(
    /:(\w+)/g,
    (_match: string, key: string) => params[key] ?? ''
  );

const resolveBackLabel = (backUrl: string): string => {
  const path: string = backUrl.split(/[?#]/)[0];
  const destination: TextyRoute | undefined = getRoutes().find(
    (r: TextyRoute) => r.path === path
  );
  return destination?.title ?? __('Back', 'texty');
};

const LayoutHeader = ({ route, title, backUrl }: Props) => {
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

  const backLabel: string = filteredBackUrl
    ? resolveBackLabel(filteredBackUrl)
    : '';

  return (
    <div className="texty-page-header flex justify-between gap-4">
      <div className="flex gap-4 items-center">
        {filteredBackUrl && (
          <button
            type="button"
            onClick={() => navigate(parseBackUrl(filteredBackUrl, params))}
            className="inline-flex cursor-pointer items-center gap-1 self-start text-sm text-gray-600 hover:text-gray-900"
          >
            <ChevronLeft className="size-4" />
            {backLabel}
          </button>
        )}
        {filteredTitle && (
          <h1 className="m-0 text-2xl font-bold text-gray-900">
            {filteredTitle}
          </h1>
        )}
      </div>
      <Slot
        name={`texty_${route.id}_header_actions`}
        fillProps={{ route }}
      />
    </div>
  );
};

export default LayoutHeader;
