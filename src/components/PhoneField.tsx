import {
  Label,
  type FieldComponentProps,
  type SettingsElement,
} from '@wedevs/plugin-ui';
import { RawHTML } from '@wordpress/element';
import PhoneInput from 'react-phone-input-2';

const PhoneField = ({ element, onChange }: FieldComponentProps) => {
  const value: string = String(element.value ?? element.default ?? '');
  const label: string = element.label ?? element.title ?? '';

  // SettingsProvider keys its values map by the element id (see plugin-ui
  // FieldRenderer → updateValue), so changes must be reported under that key.
  // react-phone-input-2 emits digits without the leading "+"; re-add it so the
  // stored number stays E.164 (Twilio & co. require it for the From number).
  const handleChange = (val: string): void => {
    if (element.id) {
      onChange(element.id, val ? `+${val.replace(/^\+/, '')}` : '');
    }
  };

  return (
    <div
      className="flex w-full flex-col gap-3 p-4"
      id={element.id}
      data-testid={`settings-field-${element.id}`}
    >
      {label && (
        <Label
          htmlFor={element.id}
          className="text-sm font-medium text-foreground"
        >
          <RawHTML>{label}</RawHTML>
        </Label>
      )}

      <div className="w-full">
        <PhoneInput
          enableSearch
          value={value}
          onChange={handleChange}
          disabled={element.disabled}
          inputProps={{ id: element.id, name: element.id }}
          containerClass="texty-phone-input"
          inputClass="h-9 w-full rounded-md border border-input bg-background pl-14 text-sm text-foreground"
          buttonClass="rounded-l-md border-r border-input bg-background"
          dropdownClass="text-sm"
        />
        {element.description && (
          <div className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
            <RawHTML>{element.description}</RawHTML>
          </div>
        )}
      </div>

      {element.validationError && (
        <div className="text-sm text-destructive">
          <RawHTML>{element.validationError}</RawHTML>
        </div>
      )}
    </div>
  );
};

export type PhoneFieldElement = SettingsElement;
export default PhoneField;
