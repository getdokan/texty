import { Button } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import { TriangleAlert } from 'lucide-react';
import {
  isRouteErrorResponse,
  useNavigate,
  useRouteError,
} from 'react-router-dom';

const formatErrorMessage = (error: unknown): string => {
  if (isRouteErrorResponse(error)) {
    return `${error.status} ${error.statusText}`;
  }
  if (error instanceof Error) {
    return error.message;
  }
  if (typeof error === 'string') {
    return error;
  }
  return __('An unknown error occurred.', 'texty');
};

const formatErrorStack = (error: unknown): string | null => {
  if (error instanceof Error && error.stack) {
    return error.stack;
  }
  return null;
};

const ErrorBoundary = () => {
  const error: unknown = useRouteError();
  const navigate = useNavigate();
  const message: string = formatErrorMessage(error);
  const stack: string | null = formatErrorStack(error);

  // Surface the stack to admins — the React app is gated behind manage_options,
  // so anyone seeing this page is already privileged to debug.
  if (typeof window !== 'undefined' && window.console) {
    console.error('Texty render error:', error);
  }

  return (
    <div className="flex flex-col h-screen items-center rounded-xl border border-gray-200 bg-white px-6 py-12 text-center shadow-xs">
      <div className="flex size-16 items-center justify-center rounded-full bg-red-50 text-red-600">
        <TriangleAlert className="size-8" />
      </div>
      <h2 className="mt-6 text-xl font-semibold text-gray-900">
        {__('Something went wrong', 'texty')}
      </h2>
      <p className="mt-2 max-w-md text-sm text-gray-600">{message}</p>

      {stack && (
        <pre className="mt-6 max-h-64 w-full max-w-4xl overflow-auto rounded-md bg-gray-50 p-4 text-left text-xs text-gray-700">
          {stack}
        </pre>
      )}

      <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
        <Button
          onClick={() => window.location.reload()}
          className="border border-gray-200 bg-white text-gray-700 hover:bg-gray-50"
        >
          {__('Reload Page', 'texty')}
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

export default ErrorBoundary;
