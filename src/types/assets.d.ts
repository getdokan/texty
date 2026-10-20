declare module '*.css';
declare module '*.scss';

// Stylesheets imported with a webpack resource query, e.g.
// `react-phone-input-2/lib/style.css?texty-components` in src/components/index.tsx.
// TypeScript allows one `*` per pattern, so the query is matched, not the extension.
declare module '*?texty-components';

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
