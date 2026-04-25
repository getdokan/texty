import { Skeleton } from '@wedevs/plugin-ui';

type Props = {
  rows?: number;
};

const NotificationGroupSkeleton = ({ rows = 3 }: Props) => {
  return (
    <div className="overflow-hidden rounded-xl border border-border bg-background shadow-xs">
      {/* Group header — matches NotificationGroup */}
      <div className="flex items-center gap-3 px-6 py-5">
        <Skeleton className="size-10 shrink-0 rounded-md" />
        <div className="flex min-w-0 flex-1 flex-col gap-2">
          <Skeleton className="h-4 w-44" />
          <Skeleton className="h-3 w-56" />
        </div>
      </div>

      {/* Rows — matches NotificationRow collapsed header */}
      {Array.from({ length: rows }).map(
        (_value: unknown, index: number) => (
          <div
            key={index}
            className="flex items-center justify-between gap-4 border-t border-border px-6 py-4"
          >
            <div className="flex min-w-0 flex-1 flex-col gap-2">
              <Skeleton className="h-3.5 w-40" />
              <Skeleton className="h-3 w-2/3" />
            </div>
            <div className="flex shrink-0 items-center gap-3">
              <Skeleton className="h-5 w-9 rounded-full" />
              <span className="h-5 w-px bg-border" />
              <Skeleton className="size-4 rounded-full" />
            </div>
          </div>
        )
      )}
    </div>
  );
};

export default NotificationGroupSkeleton;
