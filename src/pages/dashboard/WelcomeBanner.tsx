import { Button } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import { Check, Settings } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

type Props = {
  gatewayConnected: boolean;
  gatewayName?: string;
};

const features = [
  __('SMS Notifications for events.', 'texty'),
  __('Supports WordPress, WooCommerce, Dokan.', 'texty'),
  __('User Consent for data collection.', 'texty'),
  __('Forecasting the SMS Marketing', 'texty'),
  __('Assuring users vendor, affiliates and subscribers.', 'texty'),
];

const WelcomeBanner = ({ gatewayConnected, gatewayName }: Props) => {
  const navigate = useNavigate();
  const ctaLabel: string = gatewayConnected
    ? __('Manage Gateway', 'texty')
    : __('Connect Gateway', 'texty');

  return (
    <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
      <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
        <div>
          <h2 className="m-0 text-xl font-semibold text-gray-900">
            {__('Welcome to Texty!', 'texty')}
          </h2>
          <p className="mt-2 mb-4 text-sm text-gray-600">
            {__(
              'By connecting your sms gateway provider; you will be able to do:',
              'texty'
            )}
          </p>
          <ul className="m-0 grid list-none gap-2 p-0">
            {features.map((label) => (
              <li
                key={label}
                className="flex items-start gap-2 text-sm text-gray-700"
              >
                <Check className="mt-0.5 size-4 shrink-0 text-emerald-500" />
                <span>{label}</span>
              </li>
            ))}
          </ul>
        </div>

        <div className="flex h-full flex-col justify-center rounded-lg bg-gray-50 p-5">
          <span className="inline-flex items-center gap-1.5 self-start rounded-full bg-violet-100 px-2.5 py-1 text-xs font-medium text-violet-700">
            <span className="size-1.5 rounded-full bg-violet-500" />
            {__('Offering', 'texty')}
          </span>
          <h3 className="mt-2 text-lg font-semibold leading-snug text-gray-900">
            {gatewayConnected
              ? __('Gateway connected.', 'texty')
              : __('5+ SMS Gateway Integrations.', 'texty')}
          </h3>
          <p className="mt-1 mb-4 text-sm text-gray-600">
            {gatewayConnected
              ? __(
                  `Currently sending via ${
                    gatewayName ?? ''
                  }. Update credentials any time.`,
                  'texty'
                )
              : __('Please connect your preferred one.', 'texty')}
          </p>
          <Button
            onClick={() => navigate('/settings')}
            className="self-start gap-2 bg-gray-900 text-white hover:bg-gray-800"
          >
            <Settings className="size-4" />
            {ctaLabel}
          </Button>
        </div>
      </div>
    </div>
  );
};

export default WelcomeBanner;
