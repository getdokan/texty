import { cn } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import { ChevronRight, Settings } from 'lucide-react';
import {
  createElement,
  isValidElement,
  type ComponentType,
  type MouseEvent,
  type ReactNode,
} from 'react';

export type IntegrationCardProps = {
  title: string;
  description: string;
  icon: ComponentType<{ className?: string }> | ReactNode;
  iconBgClassName?: string;
  iconColorClassName?: string;
  active?: boolean;
  onConfigure?: () => void;
  onOpen?: () => void;
};

const IntegrationCard = ({
  title,
  description,
  icon,
  iconBgClassName = 'bg-violet-100',
  iconColorClassName = 'text-violet-600',
  active = false,
  onConfigure,
  onOpen,
}: IntegrationCardProps) => {
  const renderedIcon: ReactNode = isValidElement(icon)
    ? icon
    : createElement(icon as ComponentType<{ className?: string }>, {
        className: cn('size-5', iconColorClassName),
      });

  const handleHeaderClick = (): void => {
    if (onOpen) {
      onOpen();
    }
  };

  const handleConfigureClick = (event: MouseEvent<HTMLButtonElement>): void => {
    event.stopPropagation();
    if (onConfigure) {
      onConfigure();
    }
  };

  return (
    <div
      onClick={handleHeaderClick}
      className={cn(
        'rounded-xl border border-border bg-background p-6 shadow-xs transition-colors',
        onOpen && 'cursor-pointer hover:border-foreground/20'
      )}
    >
      <div className="flex items-start gap-4">
        <div
          className={cn(
            'flex size-10 shrink-0 items-center justify-center rounded-md',
            iconBgClassName
          )}
        >
          {renderedIcon}
        </div>
        <div className="flex min-w-0 flex-1 flex-col gap-2">
          <div className="flex items-start justify-between gap-2">
            <h3 className="m-0 text-base leading-tight font-bold text-foreground">
              {title}
            </h3>
            {active && (
              <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
            )}
          </div>
          <p className="m-0 text-sm leading-relaxed text-muted-foreground">
            {description}
          </p>

          {onConfigure && (
            <button
              type="button"
              onClick={handleConfigureClick}
              className="mt-3 inline-flex w-fit cursor-pointer items-center gap-1.5 bg-transparent p-0 text-sm font-medium text-foreground underline-offset-4 hover:underline"
            >
              <Settings className="size-4" />
              <span className="underline">{__('Configure', 'texty')}</span>
            </button>
          )}
        </div>
      </div>
    </div>
  );
};

export default IntegrationCard;
