import { Button } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import { Compass } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

const NotFound = () => {
  const navigate = useNavigate();

  return (
    <div className="flex flex-col h-screen items-center justify-center rounded-xl border border-gray-200 bg-white px-6 py-16 text-center shadow-xs">
      <div className="flex size-16 items-center justify-center rounded-full bg-gray-100 text-gray-500">
        <Compass className="size-8" />
      </div>
      <p className="mt-6 text-5xl font-bold tracking-tight text-gray-900">
        {__('404', 'texty')}
      </p>
      <h2 className="mt-2 text-xl font-semibold text-gray-900">
        {__('Page not found', 'texty')}
      </h2>
      <p className="mt-2 max-w-md text-sm text-gray-600">
        {__(
          "The page you're looking for doesn't exist or may have been moved.",
          'texty'
        )}
      </p>
      <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
        <Button
          onClick={() => navigate(-1)}
          className="border border-gray-200 bg-white text-gray-700 hover:bg-gray-50"
        >
          {__('Go Back', 'texty')}
        </Button>
        <Button
          onClick={() => navigate('/dashboard')}
          className="bg-gray-900 text-white hover:bg-gray-800"
        >
          {__('Back to Dashboard', 'texty')}
        </Button>
      </div>
    </div>
  );
};

export default NotFound;
