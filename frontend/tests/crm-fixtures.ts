import type { Customer, Lead, Stage, Page, Person } from "@/types/crm";
export const person: Person = { id: 2, name: "Taylor Reed" };
export const stages: Stage[] = [
  { id: 1, name: "New", slug: "new", position: 0, probability: 0 },
  { id: 2, name: "Qualified", slug: "qualified", position: 1, probability: 30 },
  { id: 3, name: "Won", slug: "won", position: 2, probability: 100 },
];
export const customer: Customer = {
  id: 10,
  customer_code: "CUS-TEST",
  first_name: "Ada",
  last_name: "Lovelace",
  email: "ada@example.test",
  phone: null,
  company: "Analytical Works",
  address: null,
  status: "active",
  version: 1,
  assignees: [person],
  creator: person,
  created_at: "2026-10-26T12:00:00Z",
  updated_at: "2026-10-26T12:00:00Z",
};
export const lead: Lead = {
  id: 5,
  title: "Support agreement",
  source: "Referral",
  estimated_value: "1200.50",
  expected_close_date: "2027-01-15",
  notes: "Discuss scope",
  version: 1,
  customer_id: null,
  assigned_to: 2,
  pipeline_stage_id: 1,
  status: "new",
  stage: stages[0],
  assignee: person,
  customer: null,
  converted_at: null,
  created_at: "2026-10-26T12:00:00Z",
  updated_at: "2026-10-26T12:00:00Z",
};
export function page<T>(data: T[], total = data.length): Page<T> {
  return {
    success: true,
    message: "Retrieved",
    data,
    meta: { current_page: 1, last_page: 1, per_page: 20, total },
  };
}
