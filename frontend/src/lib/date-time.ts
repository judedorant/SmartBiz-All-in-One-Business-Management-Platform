export function isoSeconds(value: string | Date) {
  return new Date(value).toISOString().replace(/\.\d{3}Z$/, "Z");
}
export function localDateTime(value: string) {
  const date = new Date(value);
  if (!Number.isFinite(date.valueOf())) return "";
  return new Date(date.valueOf() - date.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
}
/** Keep an unchanged local input in the correct occurrence of a repeated DST hour. */
export function preserveUtcInstant(value: string, original?: string) {
  return original && value === localDateTime(original)
    ? isoSeconds(original)
    : isoSeconds(value);
}
