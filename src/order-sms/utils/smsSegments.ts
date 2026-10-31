// GSM 03.38 basic character set; anything outside it switches the message to
// UCS-2 (Unicode), which fits far fewer characters per SMS.
const GSM_BASIC =
  '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

// Extension characters: allowed in GSM but each counts as two.
const GSM_EXTENDED = '^{}\\[~]|€\f';

export type SegmentInfo = {
  encoding: 'GSM' | 'Unicode';
  /** Characters as the gateway counts them (GSM extension chars count twice). */
  length: number;
  segments: number;
};

/**
 * How many SMS a message will be split into.
 *
 * GSM: 160 chars in one SMS, 153 per part when split.
 * Unicode (e.g. Bangla, emoji): 70 in one SMS, 67 per part when split.
 */
export const smsSegments = (text: string): SegmentInfo => {
  const chars: string[] = Array.from(text);
  let gsm = true;
  let gsmLength = 0;

  for (const char of chars) {
    if (GSM_BASIC.includes(char)) {
      gsmLength += 1;
    } else if (GSM_EXTENDED.includes(char)) {
      gsmLength += 2;
    } else {
      gsm = false;
      break;
    }
  }

  if (gsm) {
    return {
      encoding: 'GSM',
      length: gsmLength,
      segments:
        gsmLength === 0 ? 0 : gsmLength <= 160 ? 1 : Math.ceil(gsmLength / 153),
    };
  }

  // UCS-2 counts UTF-16 code units (an emoji is two).
  const units: number = text.length;

  return {
    encoding: 'Unicode',
    length: units,
    segments: units <= 70 ? 1 : Math.ceil(units / 67),
  };
};
