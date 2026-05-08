import { toast } from '@wedevs/plugin-ui';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from 'react';

import type { LogsFilters, LogsResponse } from '../types';

const EMPTY_RESPONSE: LogsResponse = {
  items: [],
  total: 0,
  per_page: 10,
  current_page: 1,
  total_pages: 0,
};

const buildPath = (filters: LogsFilters): string => {
  const params = new URLSearchParams();
  params.set('page', String(filters.page));
  params.set('per_page', String(filters.per_page));
  if (filters.status) {
    params.set('status', filters.status);
  }
  if (filters.type) {
    params.set('type', filters.type);
  }
  if (filters.search) {
    params.set('search', filters.search);
  }
  return `/texty/v1/logs?${params.toString()}`;
};

type UseLogs = {
  data: LogsResponse;
  loading: boolean;
  error: string | null;
};

export const useLogs = (filters: LogsFilters): UseLogs => {
  const [data, setData] = useState<LogsResponse>(EMPTY_RESPONSE);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    const load = async (): Promise<void> => {
      setLoading(true);
      setError(null);
      try {
        const response = await apiFetch<LogsResponse>({
          path: buildPath(filters),
          method: 'GET',
        });
        if (cancelled) {
          return;
        }
        setData(response);
      } catch (err) {
        if (cancelled) {
          return;
        }
        console.error('Failed to load logs', err);
        const message: string =
          (err as { message?: string }).message ??
          __('Failed to load logs.', 'texty');
        setError(message);
        toast.error(message);
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    load();
    return () => {
      cancelled = true;
    };
  }, [
    filters.page,
    filters.per_page,
    filters.status,
    filters.type,
    filters.search,
  ]);

  return { data, loading, error };
};
