import { cn } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

export type GatewayStatusValue = 'active' | 'connected' | 'inactive';
export type GatewayStatusSize = 'xs' | 'sm' | 'md';

type Props = {
  status: GatewayStatusValue;
  label?: string;
  size?: GatewayStatusSize;
  icon?: ReactNode;
  className?: string;
  dotClassName?: string;
  labelClassName?: string;
};

const TONE: Record<
  GatewayStatusValue,
  { wrapper: string; dot: string; label: string }
> = {
  active: {
    wrapper: 'bg-violet-100 text-violet-700',
    dot: 'bg-violet-500',
    label: __('Activated', 'texty'),
  },
  connected: {
    wrapper: 'bg-emerald-100 text-emerald-700',
    dot: 'bg-emerald-500',
    label: __('Connected', 'texty'),
  },
  inactive: {
    wrapper: 'bg-gray-100 text-gray-600',
    dot: 'bg-gray-400',
    label: __('Not Activated', 'texty'),
  },
};

const SIZE: Record<
  GatewayStatusSize,
  { wrapper: string; dot: string }
> = {
  xs: { wrapper: 'px-2 py-0.5 text-[10px] gap-1', dot: 'size-1' },
  sm: { wrapper: 'px-2.5 py-1 text-xs gap-1.5', dot: 'size-1.5' },
  md: { wrapper: 'px-3 py-1.5 text-sm gap-2', dot: 'size-2' },
};

const GatewayStatus = ({
  status,
  label,
  size = 'sm',
  icon,
  className,
  dotClassName,
  labelClassName,
}: Props) => {
  const tone = TONE[status];
  const dimensions = SIZE[size];

  return (
    <span
      className={cn(
        'inline-flex shrink-0 items-center rounded-full font-medium',
        tone.wrapper,
        dimensions.wrapper,
        className
      )}
    >
      {icon ?? (
        <span
          className={cn(
            'shrink-0 rounded-full',
            tone.dot,
            dimensions.dot,
            dotClassName
          )}
        />
      )}
      <span className={cn('truncate', labelClassName)}>
        {label ?? tone.label}
      </span>
    </span>
  );
};

export default GatewayStatus;
