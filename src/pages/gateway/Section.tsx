import { FieldRenderer, cn } from '@wedevs/plugin-ui';
import type { SettingsElement } from '@wedevs/plugin-ui';
import { RawHTML } from '@wordpress/element';

type Props = {
  section: SettingsElement;
  isFirst?: boolean;
};

const Section = ({ section, isFirst = false }: Props) => {
  const sectionLabel: string = section.label ?? section.title ?? '';
  const hasHeading: boolean = Boolean(sectionLabel || section.description);

  return (
    <section className={cn(!isFirst && 'border-t border-border')}>
      {hasHeading && (
        <div className="px-6 pt-5 pb-2">
          {sectionLabel && (
            <h3 className="text-sm font-semibold text-foreground">
              <RawHTML>{sectionLabel}</RawHTML>
            </h3>
          )}
          {section.description && (
            <p className="mt-0.5 text-xs text-muted-foreground">
              <RawHTML>{section.description}</RawHTML>
            </p>
          )}
        </div>
      )}

      <div className="px-2">
        {section.children?.map((child: SettingsElement) => (
          <FieldRenderer key={child.id} element={child} />
        ))}
      </div>
    </section>
  );
};

export default Section;
