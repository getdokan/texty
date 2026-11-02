import { __, sprintf } from '@wordpress/i18n';

import type { OrderNote } from '../types';

/**
 * Show a note we just added in WooCommerce's "Order notes" box without a
 * reload, using WooCommerce's own markup so its Delete link keeps working
 * (WooCommerce handles `.delete_note` clicks by the `<li rel>` note id).
 *
 * Built with DOM APIs and textContent only — note text is never parsed as HTML.
 */
export const appendOrderNote = (note: OrderNote): void => {
  const list: HTMLUListElement | null = document.querySelector(
    '#woocommerce-order-notes ul.order_notes'
  );

  if (!list || !note.id) {
    return;
  }

  list.querySelector('li.no-items')?.remove();

  const item: HTMLLIElement = document.createElement('li');
  item.className = 'note';
  item.setAttribute('rel', String(note.id));

  const content: HTMLDivElement = document.createElement('div');
  content.className = 'note_content';
  const text: HTMLParagraphElement = document.createElement('p');
  text.textContent = note.content;
  content.appendChild(text);

  const meta: HTMLParagraphElement = document.createElement('p');
  meta.className = 'meta';
  const date: HTMLElement = document.createElement('abbr');
  date.className = 'exact-date';
  date.textContent = note.date;
  meta.appendChild(date);

  if (note.author) {
    meta.appendChild(
      document.createTextNode(
        ` ${sprintf(
          /* translators: %s: note author */
          __('by %s', 'texty'),
          note.author
        )} `
      )
    );
  }

  const remove: HTMLAnchorElement = document.createElement('a');
  remove.href = '#';
  remove.className = 'delete_note';
  remove.setAttribute('role', 'button');
  remove.textContent = __('Delete note', 'texty');
  meta.appendChild(remove);

  item.appendChild(content);
  item.appendChild(meta);
  list.prepend(item);
};
