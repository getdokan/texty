import { Button, Skeleton } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import { Settings } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

import QuickSendForm from './components/QuickSendForm';
import { useGatewayStatus } from './hooks/useGatewayStatus';

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

const NoGateway = () => {
  const navigate = useNavigate();

  return (
    <div className="rounded-xl border border-gray-200 bg-white px-6 py-8 text-center shadow-xs">
      <h3 className="m-0 text-base font-semibold text-gray-900">
        {__('No SMS gateway connected', 'texty')}
      </h3>
      <p className="mx-auto mt-1 mb-4 max-w-md text-sm text-gray-500">
        {__('Connect a gateway before sending messages.', 'texty')}
      </p>
      <Button
        onClick={() => navigate('/gateway')}
        className="gap-2 bg-gray-900 text-white hover:bg-gray-800"
      >
        <Settings className="size-4" />
        {__('Connect Gateway', 'texty')}
      </Button>
    </div>
  );
};

const QuickSend = () => {
  const { loading, active, error } = useGatewayStatus();

  if (loading) {
    return <QuickSendSkeleton />;
  }

  return (
    <div className="space-y-6">
      {error && (
        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
          {error}
        </div>
      )}

      {active || error ? <QuickSendForm /> : <NoGateway />}
    </div>
  );
};

export default QuickSend;
