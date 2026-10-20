import {
  Skeleton,
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import {
  CircleCheck,
  CircleX,
  Info,
  MessageSquareText,
  Percent,
} from 'lucide-react';
import type { ReactNode } from 'react';

type StatCardProps = {
  icon: ReactNode;
  value: ReactNode;
  label: string;
  hint?: string;
  loading?: boolean;
};

const StatCard = ({ icon, value, label, hint, loading }: StatCardProps) => {
  return (
    <div className="flex items-center gap-4 rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-xs">
      <div className="flex size-12 shrink-0 items-center justify-center rounded-full border border-gray-200 text-gray-600">
        {icon}
      </div>
      <div className="min-w-0 flex-1">
        {loading ? (
          <Skeleton className="h-6 w-16" />
        ) : (
          <div className="text-2xl font-semibold leading-none text-gray-900">
            {value}
          </div>
        )}
        <div className="mt-1.5 flex items-center gap-1 text-sm text-gray-500">
          <span>{label}</span>
          {hint && (
            <Tooltip>
              <TooltipTrigger
                render={
                  <button
                    type="button"
                    className="inline-flex size-4 items-center justify-center text-gray-400 hover:text-gray-600"
                    aria-label={__('More info', 'texty')}
                  />
                }
              >
                <Info className="size-3.5" />
              </TooltipTrigger>
              <TooltipContent>{hint}</TooltipContent>
            </Tooltip>
          )}
        </div>
      </div>
    </div>
  );
};

type Props = {
  loading: boolean;
  smsSent: number;
  delivered: number;
  failed: number;
  deliveryRate: number;
};

const formatNumber = (n: number): string => n.toLocaleString();

const StatCards = ({
  loading,
  smsSent,
  delivered,
  failed,
  deliveryRate,
}: Props) => {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <StatCard
        icon={<MessageSquareText className="size-5" />}
        value={formatNumber(smsSent)}
        label={__('SMS Sent', 'texty')}
        loading={loading}
      />
      <StatCard
        icon={<CircleCheck className="size-5" />}
        value={formatNumber(delivered)}
        label={__('Delivered', 'texty')}
        hint={__('Messages accepted by the gateway in this period.', 'texty')}
        loading={loading}
      />
      <StatCard
        icon={<Percent className="size-5" />}
        value={`${formatNumber(deliveryRate)}%`}
        label={__('Delivery Rate', 'texty')}
        hint={__(
          'Delivered messages divided by total sent in this period.',
          'texty'
        )}
        loading={loading}
      />
      <StatCard
        icon={<CircleX className="size-5" />}
        value={formatNumber(failed)}
        label={__('Failed', 'texty')}
        loading={loading}
      />
    </div>
  );
};

export default StatCards;
