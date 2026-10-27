import type { Person, CustomerSummary } from "./crm";
export type Appointment = {
  id: number;
  title: string;
  description: string | null;
  customer_id: number;
  assigned_to: number;
  customer: CustomerSummary | null;
  assignee: Person;
  start_at: string;
  end_at: string;
  status: "scheduled" | "confirmed" | "completed" | "cancelled";
  version: number;
  reminder_sent_at: string | null;
};
export type AvailabilityWindow = {
  day_of_week: number;
  start_time: string;
  end_time: string;
};
export type Availability = {
  user_id: number;
  timezone: string;
  version: number;
  windows: AvailabilityWindow[];
};
