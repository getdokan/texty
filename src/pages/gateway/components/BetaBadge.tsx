import type { SettingsElement } from '@wedevs/plugin-ui';
import { Badge, Tooltip, TooltipContent, TooltipTrigger } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import type { GatewayMeta } from '../types';

type Props = {
  source: SettingsElement;
};

const isBeta = (source: SettingsElement): boolean => {
  const meta: GatewayMeta | undefined = source.meta;

  return meta?.status === 'beta';
};

const BetaBadge = ({ source }: Props) => {
  if (!isBeta(source)) {
    return null;
  }

  return (
    <Tooltip>
      <TooltipTrigger render={<span className="inline-flex" />}>
        <Badge variant="warning">{__('Beta', 'texty')}</Badge>
      </TooltipTrigger>
      <TooltipContent className="max-w-64">
        {__(
          'Built from the provider’s API documentation and not yet verified against a live account.',
          'texty'
        )}
      </TooltipContent>
    </Tooltip>
  );
};

export default BetaBadge;
