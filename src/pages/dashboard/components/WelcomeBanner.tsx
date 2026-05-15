import { Button } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import { Check, Settings } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

const features: string[] = [
  __('SMS Notifications for events.', 'texty'),
  __('Supports WordPress, WooCommerce, Dokan.', 'texty'),
  __('User Consent for data collection.', 'texty'),
  __('Forecasting the SMS Marketing', 'texty'),
  __('Assuring users vendor, affiliates and subscribers.', 'texty'),
];

const WelcomeBanner = () => {
  const navigate = useNavigate();

  return (
    <div className="rounded-xl border border-border bg-background p-6 shadow-xs">
      <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
        <div>
          <h2 className="m-0 text-xl font-bold text-foreground">
            {__('Welcome to Texty!', 'texty')}
          </h2>
          <p className="my-2 mb-4 text-sm text-muted-foreground">
            {__(
              'By connecting your sms gateway provider; you will be able to do:',
              'texty'
            )}
          </p>
          <ul className="m-0 grid list-none gap-2 p-0">
            {features.map((label: string) => (
              <li
                key={label}
                className="flex items-start gap-2 text-sm text-foreground/80"
              >
                <Check className="mt-0.5 size-4 shrink-0 text-emerald-500" />
                <span>{label}</span>
              </li>
            ))}
          </ul>
        </div>

        <div className="flex h-full flex-col gap-2 justify-center rounded-lg bg-muted/40 p-6">
          <span className="inline-flex items-center gap-1.5 self-start rounded-full bg-violet-100 px-2.5 py-1 text-xs font-medium text-violet-700">
            <span className="size-1.5 rounded-full bg-violet-500" />
            {__('Offering', 'texty')}
          </span>
          <h3 className="mt-3 text-2xl leading-snug font-bold text-foreground">
            {__('4+ SMS Gateway Integrations.', 'texty')}
          </h3>
          <p className="mt-1 mb-4 text-sm text-muted-foreground">
            {__('Please connect your preferred one.', 'texty')}
          </p>
          <Button
            onClick={() => navigate('/gateway')}
            className="self-start gap-2 bg-gray-900 text-white hover:bg-gray-800"
          >
            <Settings className="size-4" />
            {__('Connect Gateway', 'texty')}
          </Button>
        </div>
      </div>
    </div>
  );
};

export default WelcomeBanner;
