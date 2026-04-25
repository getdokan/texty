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
import type { ChangeEvent } from 'react';

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
      <div className="px-3 pt-3 pb-2">
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
      </div>
      <ScrollArea className="flex-1">
        <nav className="flex flex-col gap-0.5 px-2 pb-3">
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
                  'w-full justify-between font-normal text-foreground/80',
                  active &&
                    'bg-muted font-semibold text-foreground hover:bg-muted'
                )}
              >
                <span className="truncate">
                  <RawHTML>{page.label ?? page.id}</RawHTML>
                </span>
                {connected && (
                  <CircleCheck
                    className="size-4 shrink-0 fill-emerald-100 text-emerald-600"
                    aria-label={__('Connected', 'texty')}
                  />
                )}
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
