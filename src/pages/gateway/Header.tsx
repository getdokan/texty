import type { SettingsElement } from '@wedevs/plugin-ui';
import { RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ArrowUpRight } from 'lucide-react';

type Props = {
  source: SettingsElement;
};

const Header = ({ source }: Props) => {
  const logoUrl: string | undefined = source.image_url;
  const label: string = source.label ?? '';

  return (
    <div className="flex items-start gap-3 px-6 pt-6 pb-5">
      {logoUrl && (
        <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-primary/10">
          <img
            src={logoUrl}
            alt={label}
            className="max-h-6 max-w-6 object-contain"
          />
        </div>
      )}
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        {label && (
          <h2 className="text-lg leading-tight font-semibold text-foreground">
            <RawHTML>{label}</RawHTML>
          </h2>
        )}
        {(source.description || source.doc_link) && (
          <div className="flex flex-wrap items-center gap-x-1 gap-y-0.5 text-sm text-muted-foreground">
            {source.description && (
              <span>
                <RawHTML>{source.description}</RawHTML>
              </span>
            )}
            {source.description && source.doc_link && (
              <span>{__('Or', 'texty')}</span>
            )}
            {source.doc_link && (
              <a
                href={source.doc_link}
                target="_blank"
                rel="noopener noreferrer"
                className="text-primary inline-flex items-center gap-0.5 hover:underline"
              >
                <RawHTML>
                  {source.doc_link_text || __('Learn more', 'texty')}
                </RawHTML>
                <ArrowUpRight className="size-3.5" />
              </a>
            )}
          </div>
        )}
      </div>
    </div>
  );
};

export default Header;
