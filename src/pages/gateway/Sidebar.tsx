import type { SettingsElement } from '@wedevs/plugin-ui';
import {
  Button,
  InputGroup,
  InputGroupAddon,
  InputGroupInput,
  ScrollArea,
  cn,
  useSettings,
} from '@wedevs/plugin-ui';
import { RawHTML, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { CircleCheck, Search } from 'lucide-react';
import type { ChangeEvent, FormEvent } from 'react';
import BetaBadge from './components/BetaBadge';

type Props = {
  connectedGateways: string[];
};

const Sidebar = ({ connectedGateways }: Props) => {
  const { schema, activePage, setActivePage } = useSettings();
  const [search, setSearch] = useState<string>('');

  const items: SettingsElement[] = useMemo(
    () => schema.filter((p: SettingsElement) => p.display !== false),
    [schema]
  );

  const filtered: SettingsElement[] = useMemo(() => {
    const q: string = search.trim().toLowerCase();
    if (!q) return items;
    return items.filter((p: SettingsElement) =>
      (p.label ?? p.id).toLowerCase().includes(q)
    );
  }, [items, search]);

  return (
    <aside className="flex w-full flex-col overflow-hidden bg-background md:w-64 md:shrink-0 md:border-r md:border-border">
      <div className="px-4 pt-4">
        <h2 className="text-sm font-semibold text-foreground">
          {__('Available Gateways', 'texty')}
        </h2>
      </div>
      {/* Own <form>: browsers pair the nearest text input with the
          gateway's password field and autofill saved logins into it. */}
      <form
        role="search"
        autoComplete="off"
        className="px-3 pt-3 pb-2"
        onSubmit={(e: FormEvent<HTMLFormElement>) => e.preventDefault()}
      >
        <InputGroup className="h-8">
          <InputGroupAddon>
            <Search className="size-4 text-muted-foreground" />
          </InputGroupAddon>
          <InputGroupInput
            type="search"
            name="texty-gateway-search"
            value={search}
            onChange={(e: ChangeEvent<HTMLInputElement>) =>
              setSearch(e.target.value)
            }
            placeholder={__('Search', 'texty')}
            aria-label={__('Search gateways', 'texty')}
            autoComplete="off"
            autoCorrect="off"
            autoCapitalize="off"
            spellCheck={false}
            data-1p-ignore
            data-lpignore="true"
            data-form-type="other"
          />
        </InputGroup>
      </form>
      <ScrollArea className="flex-1">
        {/* ScrollArea content is min-width: fit-content, so the widest label
            would set the width of every row; inline-size containment stops
            labels from widening the list so they truncate instead. */}
        <nav className="flex min-w-0 flex-col gap-0.5 px-2 pb-3 [contain:inline-size]">
          {filtered.map((page: SettingsElement) => {
            const active: boolean = activePage === page.id;
            const connected: boolean = connectedGateways.includes(page.id);
            return (
              <Button
                key={page.id}
                variant="ghost"
                onClick={() => setActivePage(page.id)}
                aria-current={active ? 'page' : undefined}
                className={cn(
                  'w-full min-w-0 shrink justify-between gap-2 font-normal text-foreground/80',
                  active &&
                    'bg-muted font-semibold text-foreground hover:bg-muted'
                )}
                title={page.label ?? page.id}
              >
                {/* min-w-0 lets long labels truncate instead of widening the row. */}
                <span className="min-w-0 flex-1 text-left">
                  <RawHTML className="truncate">{page.label ?? page.id}</RawHTML>
                </span>
                <span className="flex shrink-0 items-center gap-1.5">
                  <BetaBadge source={page} />
                  {connected && (
                    <CircleCheck
                      className="size-4 shrink-0 fill-emerald-100 text-emerald-600"
                      aria-label={__('Connected', 'texty')}
                    />
                  )}
                </span>
              </Button>
            );
          })}
          {filtered.length === 0 && (
            <div className="px-3 py-4 text-sm text-muted-foreground">
              {__('No matches', 'texty')}
            </div>
          )}
        </nav>
      </ScrollArea>
    </aside>
  );
};

export default Sidebar;
