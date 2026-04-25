import { __ } from '@wordpress/i18n';

type Props = {
  gatewayName: string;
};

const formatGatewayName = (name: string): string =>
  name ? `${name.charAt(0).toUpperCase()}${name.slice(1)}` : '';

const GatewayStatus = ({ gatewayName }: Props) => {
  const displayName: string = formatGatewayName(gatewayName);

  return (
    <div className="rounded-xl border border-gray-200 bg-white px-6 py-5 shadow-xs">
      <div className="flex items-center justify-between gap-4">
        <div>
          <p className="m-0 text-sm text-gray-500">
            {__('Gateway Status', 'texty')}
          </p>
          <p className="m-0 mt-1 text-xl font-bold text-gray-900">
            {displayName}
          </p>
        </div>
        <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-700">
          <span className="size-1.5 rounded-full bg-emerald-500" />
          {__('Activated', 'texty')}
        </span>
      </div>
    </div>
  );
};

export default GatewayStatus;
