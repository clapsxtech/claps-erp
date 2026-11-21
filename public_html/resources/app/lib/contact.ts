// Phone links for one-tap calling and WhatsApp from a phone.

/** Digits of an international number, or undefined when it has no country code. */
function internationalDigits(phone: string | null | undefined): string | undefined {
  if (!phone) return undefined;
  const trimmed = phone.trim();
  const digits = trimmed.replace(/\D/g, '');
  // wa.me needs the country code; a bare 10-digit local number is ambiguous.
  if (digits.length < 11 || digits.length > 15) return undefined;
  if (!trimmed.startsWith('+') && !trimmed.startsWith('00') && digits.length < 12) return undefined;
  return digits.replace(/^00/, '');
}

export function telHref(phone: string): string {
  return `tel:${phone.replace(/[^\d+]/g, '')}`;
}

/** A wa.me chat link, or undefined when the number cannot be used for WhatsApp. */
export function whatsappHref(phone: string | null | undefined): string | undefined {
  const digits = internationalDigits(phone);
  return digits ? `https://wa.me/${digits}` : undefined;
}
