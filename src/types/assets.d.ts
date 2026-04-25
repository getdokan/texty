declare module '*.css';
declare module '*.scss';

declare module '@wordpress/api-fetch' {
  type ApiFetchOptions = {
    path?: string;
    url?: string;
    method?: string;
    data?: unknown;
    parse?: boolean;
    headers?: Record<string, string>;
    body?: BodyInit | null;
    signal?: AbortSignal;
  };

  function apiFetch<T = unknown>(options: ApiFetchOptions): Promise<T>;
  export default apiFetch;
}
