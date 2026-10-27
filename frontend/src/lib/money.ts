/** Keep exact decimal strings for financial text. Charts alone use numeric plotting coordinates. */
export function formatMoney(amount: string, currency: string) {
  const [whole, fraction = ""] = amount.split(".");
  try {
    return `${currency} ${BigInt(whole).toLocaleString()}.${fraction.padEnd(2, "0").slice(0, 2)}`;
  } catch {
    return `${currency} ${amount}`;
  }
}
