export type Sale = {
  id: number;
  customer_id: number;
  lead_id: number | null;
  amount: string;
  currency: string;
  status: "completed" | "voided";
  version: number;
  closed_at: string;
  voided_at: string | null;
  void_reason: string | null;
  customer: { id: number; first_name: string; last_name: string } | null;
  lead: { id: number; title: string } | null;
  recorder: { id: number; name: string };
};
export type SalesOptions = { currencies: string[]; default_currency: string };
