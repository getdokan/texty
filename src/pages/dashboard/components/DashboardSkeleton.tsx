import { Skeleton } from '@wedevs/plugin-ui';

const SKELETON_STAT_CARDS: number = 4;

const StatCardSkeleton = () => (
  <div className="flex items-center gap-4 rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-xs">
    <Skeleton className="size-12 shrink-0 rounded-full" />
    <div className="flex-1 space-y-2">
      <Skeleton className="h-6 w-20" />
      <Skeleton className="h-3 w-24" />
    </div>
  </div>
);

const TopCardSkeleton = () => (
  <div className="rounded-xl border border-gray-200 bg-white px-6 py-5 shadow-xs">
    <div className="flex items-center justify-between gap-4">
      <div className="space-y-2">
        <Skeleton className="h-3 w-28" />
        <Skeleton className="h-6 w-32" />
      </div>
      <Skeleton className="h-6 w-24 rounded-full" />
    </div>
  </div>
);

const VolumeAnalyticsSkeleton = () => (
  <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
    <div className="flex items-start justify-between gap-4">
      <div className="flex items-center gap-3">
        <Skeleton className="size-10 rounded-full" />
        <Skeleton className="h-5 w-44" />
      </div>
      <Skeleton className="h-9 w-40" />
    </div>
    <Skeleton className="mt-6 h-70 w-full" />
  </div>
);

const QuickSendSkeleton = () => (
  <div className="rounded-xl border border-gray-200 bg-white shadow-xs">
    <div className="space-y-2 border-b border-gray-100 px-6 py-4">
      <Skeleton className="h-5 w-36" />
      <Skeleton className="h-3 w-72" />
    </div>
    <div className="space-y-5 px-6 py-5">
      <div className="space-y-2">
        <Skeleton className="h-3 w-24" />
        <Skeleton className="h-10 w-full" />
      </div>
      <div className="space-y-2">
        <Skeleton className="h-3 w-20" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-3 w-28" />
      </div>
      <Skeleton className="h-9 w-32" />
    </div>
  </div>
);

const DashboardSkeleton = () => (
  <div className="space-y-6">
    <TopCardSkeleton />

    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {Array.from({ length: SKELETON_STAT_CARDS }).map(
        (_value: unknown, index: number) => (
          <StatCardSkeleton key={index} />
        )
      )}
    </div>

    <VolumeAnalyticsSkeleton />

    <QuickSendSkeleton />
  </div>
);

export default DashboardSkeleton;
