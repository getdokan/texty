import apiFetch from '@wordpress/api-fetch';
import {
  Button,
  ComboboxControl,
  ExternalLink,
  Flex,
  FlexBlock,
  Notice,
  SelectControl,
  TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { OrderSmsConfig, SendResponse, Suggestion } from '../types';
import { appendOrderNote } from '../utils/appendOrderNote';
import { smsSegments, type SegmentInfo } from '../utils/smsSegments';

type Props = {
  config: OrderSmsConfig;
};

type Result = {
  status: 'success' | 'error';
  message: string;
};

const digits = (value: string): string => value.replace(/\D/g, '');

// A typed number counts as usable once it has at least 6 digits.
const isPhoneLike = (value: string): boolean => digits(value).length >= 6;

type NumberItem = { label: string; value: string };

// Dropdown row: source on the left ("Billing"), number on the right.
const NumberOption = ({ item }: { item: NumberItem }) => {
  const [source, phone] = item.label.split(' — ');

  return (
    <Flex>
      <FlexBlock>{source}</FlexBlock>
      {phone && <span className="description">{phone}</span>}
    </Flex>
  );
};

/**
 * "Send SMS" box for the WooCommerce order sidebar.
 */
const OrderSmsBox = ({ config }: Props) => {
  const [to, setTo] = useState<string>(config.suggestions[0]?.phone ?? '');
  const [message, setMessage] = useState<string>('');
  const [sending, setSending] = useState<boolean>(false);
  const [result, setResult] = useState<Result | null>(null);
  const [query, setQuery] = useState<string>('');

  const findSuggestion = (value: string): Suggestion | undefined =>
    config.suggestions.find(
      (suggestion: Suggestion) =>
        value !== '' && digits(suggestion.phone) === digits(value)
    );

  const selectedSuggestion: Suggestion | undefined = findSuggestion(to);

  // Suggestions first. Any other number can be used too: the one already
  // chosen is listed as just the number (that's what the field shows), and
  // one being typed is offered as "Use …".
  const numberOptions: NumberItem[] = config.suggestions.map(
    (suggestion: Suggestion) => ({
      value: suggestion.phone,
      label: `${suggestion.label} — ${suggestion.phone}`,
    })
  );

  const isListed = (value: string): boolean =>
    Boolean(findSuggestion(value)) ||
    numberOptions.some((option: NumberItem) => option.value === value);

  if (isPhoneLike(to) && !isListed(to)) {
    numberOptions.push({ value: to, label: to });
  }

  if (isPhoneLike(query) && !isListed(query)) {
    numberOptions.push({
      value: query,
      label: sprintf(
        /* translators: %s: phone number typed by the user */
        __('Use %s', 'texty'),
        query
      ),
    });
  }

  // A typed number is used as the receiver straight away, so it works even
  // if the user moves on without picking "Use …" from the dropdown. Picking
  // a suggestion afterwards still replaces it.
  const handleQuery = (value: string): void => {
    setQuery(value);
    if (isPhoneLike(value)) {
      setTo(value);
    }
  };

  const segments: SegmentInfo = smsSegments(message);

  const tokenOptions: { label: string; value: string }[] = [
    { label: __('Insert order detail…', 'texty'), value: '' },
    ...Object.entries(config.tokens).map(
      ([token, label]: [string, string]) => ({
        label: `${label} ${token}`,
        value: token,
      })
    ),
  ];

  const insertToken = (token: string): void => {
    if (!token) {
      return;
    }
    setMessage((current: string) =>
      current === '' || /\s$/.test(current)
        ? `${current}${token}`
        : `${current} ${token}`
    );
  };

  const handleSend = async (): Promise<void> => {
    setSending(true);
    setResult(null);
    try {
      const response = await apiFetch<SendResponse>({
        path: `/texty/v1/orders/${config.orderId}/sms`,
        method: 'POST',
        data: { to, message },
      });
      appendOrderNote(response.note);
      setResult({
        status: 'success',
        message: sprintf(
          /* translators: %s: phone number */
          __('SMS sent to %s.', 'texty'),
          to
        ),
      });
      setMessage('');
    } catch (err) {
      console.error(err);
      const error = err as {
        message?: string;
        data?: { note?: SendResponse['note'] };
      };
      if (error.data?.note) {
        appendOrderNote(error.data.note);
      }
      setResult({
        status: 'error',
        message: error.message ?? __('The SMS could not be sent.', 'texty'),
      });
    } finally {
      setSending(false);
    }
  };

  if (!config.gateway) {
    return (
      <Notice status="warning" isDismissible={false}>
        {__('No SMS gateway is active.', 'texty')}{' '}
        <ExternalLink href={config.settingsUrl}>
          {__('Set one up', 'texty')}
        </ExternalLink>
      </Notice>
    );
  }

  return (
    <Flex direction="column" gap={3} className="texty-order-sms">
      <ComboboxControl
        __nextHasNoMarginBottom
        __next40pxDefaultSize
        label={__('To', 'texty')}
        value={to}
        options={numberOptions}
        onChange={(value?: string | null) => setTo(value ?? '')}
        onFilterValueChange={handleQuery}
        placeholder={__('Type or pick a number', 'texty')}
        expandOnFocus
        allowReset
        help={
          selectedSuggestion
            ? sprintf(
                /* translators: %s: receiver, e.g. Billing or Vendor (Store name) */
                __('Receiver: %s', 'texty'),
                selectedSuggestion.label
              )
            : undefined
        }
        __experimentalRenderItem={({
          item,
        }: {
          item: { label: string; value: string };
        }) => <NumberOption item={item} />}
      />

      <TextareaControl
        __nextHasNoMarginBottom
        label={__('Message', 'texty')}
        value={message}
        onChange={(value: string) =>
          setMessage(value.slice(0, config.maxLength))
        }
        rows={5}
        disabled={sending}
        help={sprintf(
          /* translators: 1: character count, 2: number of SMS parts, 3: encoding name (GSM or Unicode) */
          _n(
            '%1$d characters · %2$d SMS (%3$s)',
            '%1$d characters · %2$d SMS parts (%3$s)',
            Math.max(segments.segments, 1),
            'texty'
          ),
          segments.length,
          segments.segments,
          segments.encoding
        )}
      />

      <SelectControl
        __nextHasNoMarginBottom
        __next40pxDefaultSize
        label={__('Order details', 'texty')}
        hideLabelFromVision
        value=""
        options={tokenOptions}
        onChange={(token: string) => insertToken(token)}
        disabled={sending}
      />

      {result && (
        <Notice status={result.status} onRemove={() => setResult(null)}>
          {result.message}
        </Notice>
      )}

      <Flex justify="space-between" align="center">
        <span className="description">
          {sprintf(
            /* translators: %s: gateway name */
            __('Via %s', 'texty'),
            config.gateway
          )}
        </span>
        <Button
          variant="primary"
          onClick={handleSend}
          isBusy={sending}
          disabled={sending || !digits(to) || !message.trim()}
        >
          {sending ? __('Sending…', 'texty') : __('Send SMS', 'texty')}
        </Button>
      </Flex>
    </Flex>
  );
};

export default OrderSmsBox;
