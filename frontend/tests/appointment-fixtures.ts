import type { Appointment, Availability } from "@/types/appointments";
import { customer, person } from "./crm-fixtures";
export const appointment: Appointment = {
  id: 20,
  title: "Discovery meeting",
  description: "Discuss scope",
  customer_id: customer.id,
  assigned_to: person.id,
  customer,
  assignee: person,
  start_at: "2027-01-04T10:00:00Z",
  end_at: "2027-01-04T11:00:00Z",
  status: "scheduled",
  version: 1,
  reminder_sent_at: null,
};
export const schedule: Availability = {
  user_id: person.id,
  timezone: "UTC",
  version: 1,
  windows: [{ day_of_week: 1, start_time: "09:00", end_time: "17:00" }],
};
