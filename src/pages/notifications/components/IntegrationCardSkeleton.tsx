import { Skeleton } from '@wedevs/plugin-ui';

const IntegrationCardSkeleton = () => {
  return (
    <div className="rounded-xl border border-border bg-background p-6 shadow-xs">
      <div className="flex items-start gap-4">
        <Skeleton className="size-10 shrink-0 rounded-md" />
        <div className="flex min-w-0 flex-1 flex-col gap-3">
          <div className="flex items-start justify-between gap-2">
            <Skeleton className="h-4 w-44" />
            <Skeleton className="size-4 rounded-full" />
          </div>
          <Skeleton className="h-3 w-full" />
          <Skeleton className="h-3 w-4/5" />
          <div className="mt-3 flex items-center gap-2">
            <Skeleton className="size-4 rounded" />
            <Skeleton className="h-3 w-20" />
          </div>
        </div>
      </div>
    </div>
  );
};

export default IntegrationCardSkeleton;
