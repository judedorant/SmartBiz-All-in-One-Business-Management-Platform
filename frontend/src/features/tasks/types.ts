import type { Person, CustomerSummary } from "@/types/crm";
export const statuses = [
  "pending",
  "in_progress",
  "completed",
  "cancelled",
] as const;
export const priorities = ["low", "medium", "high", "urgent"] as const;
export type Task = {
  id: number;
  title: string;
  description: string | null;
  assigned_to: number;
  created_by: number;
  customer_id: number | null;
  lead_id: number | null;
  priority: (typeof priorities)[number];
  status: (typeof statuses)[number];
  version: number;
  due_date: string | null;
  completed_at: string | null;
  is_overdue: boolean;
  created_at: string;
  updated_at: string;
  assignee: Person;
  creator: Person;
  customer: CustomerSummary | null;
  lead: { id: number; title: string } | null;
};
export type TaskComment = {
  id: number;
  comment: string;
  version: number;
  user: Person;
  created_at: string;
  updated_at: string;
};
export const label = (value: string) => value.replaceAll("_", " ");
