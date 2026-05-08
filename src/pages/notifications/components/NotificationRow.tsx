import {
  Button,
  Combobox,
  ComboboxChip,
  ComboboxChips,
  ComboboxChipsInput,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxItem,
  ComboboxList,
  Switch,
  Textarea,
  cn,
} from '@wedevs/plugin-ui';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ChevronDown, Info, Plus } from 'lucide-react';
import type { ChangeEvent, MouseEvent } from 'react';


import type {
  NotificationItem,
  NotificationPatch,
  RoleOption,
} from '../types';

const SMS_SEGMENT_LENGTH = 160;

type Props = {
  item: NotificationItem;
  roles: RoleOption[];
  onPatch: (id: string, patch: NotificationPatch) => Promise<void> | void;
};

const toArray = (value: unknown): string[] =>
  Array.isArray(value) ? value.filter((v) => typeof v === 'string') : [];

const NotificationRow = ({ item, roles, onPatch }: Props) => {
  const itemRecipients: string[] = toArray(item.recipients);
  const itemReplacements: string[] = toArray(item.replacements);

  const [open, setOpen] = useState<boolean>(false);
  const [draftMessage, setDraftMessage] = useState<string>(item.message ?? '');
  const [draftRecipients, setDraftRecipients] =
    useState<string[]>(itemRecipients);
  const [saving, setSaving] = useState<boolean>(false);
  const [togglePending, setTogglePending] = useState<boolean>(false);
  const anchorRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    setDraftMessage(item.message ?? '');
    setDraftRecipients(toArray(item.recipients));
  }, [item.message, item.recipients]);

  const dirty: boolean =
    draftMessage !== (item.message ?? '') ||
    draftRecipients.join('|') !== itemRecipients.join('|');

  const selectedRoles: RoleOption[] = roles.filter((role: RoleOption) =>
    draftRecipients.includes(role.value)
  );

  const handleRolesChange = (
    value: RoleOption[] | RoleOption | null
  ): void => {
    const next: RoleOption[] = Array.isArray(value)
      ? value
      : value
        ? [value]
        : [];
    setDraftRecipients(next.map((option: RoleOption) => option.value));
  };

  const handleToggle = async (checked: boolean): Promise<void> => {
    setTogglePending(true);
    try {
      await onPatch(item.id, { enabled: checked });
    } finally {
      setTogglePending(false);
    }
  };

  const handleSave = async (): Promise<void> => {
    setSaving(true);
    try {
      await onPatch(item.id, {
        message: draftMessage,
        recipients: draftRecipients,
      });
    } finally {
      setSaving(false);
    }
  };

  const handleCancel = (): void => {
    setDraftMessage(item.message ?? '');
    setDraftRecipients(itemRecipients);
  };

  const insertVariable = (variable: string): void => {
    const token: string = `{${variable}}`;
    setDraftMessage((prev: string) =>
      prev.length > 0 && !prev.endsWith(' ') ? `${prev} ${token}` : `${prev}${token}`
    );
  };

  const messageLength: number = draftMessage.length;
  const isRoleType: boolean = item.type === 'role';

  return (
    <div className="border-t border-border">
      <button
        type="button"
        onClick={() => setOpen((prev: boolean) => !prev)}
        className="flex w-full cursor-pointer items-center justify-between gap-4 bg-transparent px-6 py-4 text-left hover:bg-muted/30"
      >
        <div className="min-w-0">
          <p className="m-0 text-sm font-semibold text-foreground">
            {item.title}
          </p>
          {item.message && !open && (
            <p className="m-0 mt-0.5 truncate text-xs text-muted-foreground">
              {item.message}
            </p>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-3">
          <span
            onClick={(event: MouseEvent<HTMLSpanElement>) =>
              event.stopPropagation()
            }
          >
            <Switch
              checked={item.enabled}
              disabled={togglePending}
              onCheckedChange={handleToggle}
              aria-label={__('Enable notification', 'texty')}
            />
          </span>
          <span className="h-5 w-px bg-border" />
          <ChevronDown
            className={cn(
              'size-4 text-muted-foreground transition-transform',
              open && 'rotate-180'
            )}
          />
        </div>
      </button>

      {open && (
        <div className="flex flex-col gap-5 px-6 pt-1 pb-5">
          {isRoleType && (
            <div className="flex flex-col gap-2">
              <label className="text-xs font-semibold text-foreground">
                {__('Recipients', 'texty')}
              </label>
              <Combobox
                items={roles}
                multiple
                value={selectedRoles}
                onValueChange={handleRolesChange}
                itemToStringLabel={(role: RoleOption) => role.label}
                itemToStringValue={(role: RoleOption) => role.value}
              >
                <ComboboxChips ref={anchorRef} className="w-full">
                  {selectedRoles.map((role: RoleOption) => (
                    <ComboboxChip key={role.value}>{role.label}</ComboboxChip>
                  ))}
                  <ComboboxChipsInput placeholder={__('Type', 'texty')} />
                </ComboboxChips>
                <ComboboxContent anchor={anchorRef}>
                  <ComboboxList>
                    {roles.map((role: RoleOption) => (
                      <ComboboxItem key={role.value} value={role}>
                        {role.label}
                      </ComboboxItem>
                    ))}
                  </ComboboxList>
                  <ComboboxEmpty>{__('No matches.', 'texty')}</ComboboxEmpty>
                </ComboboxContent>
              </Combobox>
              <p className="m-0 inline-flex items-center gap-1 text-xs text-muted-foreground">
                <Info className="size-3.5" />
                {__(
                  'Select roles. Users with phone numbers will be texted.',
                  'texty'
                )}
              </p>
            </div>
          )}

          <div className="flex flex-col gap-2">
            <label className="text-xs font-semibold text-foreground">
              {__('Message Content', 'texty')}
            </label>
            <div className="relative">
              <Textarea
                value={draftMessage}
                onChange={(event: ChangeEvent<HTMLTextAreaElement>) =>
                  setDraftMessage(event.target.value)
                }
                rows={4}
                className="resize-y pr-16"
              />
              <span
                className={cn(
                  'pointer-events-none absolute top-2 right-3 text-xs text-muted-foreground',
                  messageLength > SMS_SEGMENT_LENGTH && 'text-destructive'
                )}
              >
                {messageLength}/{SMS_SEGMENT_LENGTH}
              </span>
            </div>

            {itemReplacements.length > 0 && (
              <>
                <div className="mt-1 flex flex-wrap gap-2">
                  {itemReplacements.map((variable: string) => (
                    <button
                      key={variable}
                      type="button"
                      onClick={() => insertVariable(variable)}
                      className="inline-flex cursor-pointer items-center gap-1 rounded-md border border-border bg-muted/40 px-2 py-1 text-xs text-foreground/80 hover:border-foreground/30 hover:bg-muted"
                    >
                      <span className="font-mono">{`{${variable}}`}</span>
                      <Plus className="size-3" />
                    </button>
                  ))}
                </div>
                <p className="m-0 mt-1 text-xs text-muted-foreground">
                  {__('Available Variables for shortcut using', 'texty')}
                </p>
              </>
            )}
          </div>

          <div className="flex justify-end gap-2 pt-1">
            <Button
              variant="outline"
              onClick={handleCancel}
              disabled={!dirty || saving}
            >
              {__('Cancel', 'texty')}
            </Button>
            <Button onClick={handleSave} disabled={!dirty || saving}>
              {saving ? __('Saving…', 'texty') : __('Save', 'texty')}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
};

export default NotificationRow;
