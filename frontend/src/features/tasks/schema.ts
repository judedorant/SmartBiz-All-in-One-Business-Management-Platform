import { z } from "zod";
import { statuses, priorities } from "./types";
import { preserveUtcInstant } from "@/lib/date-time";
export const taskSchema = z.object({
  title: z.string().trim().min(1, "Enter a task title").max(200),
  description: z.string().max(10000),
  status: z.enum(statuses),
  priority: z.enum(priorities),
  due_date: z
    .string()
    .refine(
      (v) => !v || Number.isFinite(new Date(v).valueOf()),
      "Choose a valid date",
    ),
  assigned_to: z.number().int().positive("Choose a task owner"),
  customer_id: z.number().nullable(),
  lead_id: z.number().nullable(),
});
export type TaskValues = z.infer<typeof taskSchema>;
export function taskPayload(
  values: TaskValues,
  canAssign: boolean,
  originalDue?: string,
  version?: number,
  dirty?: Partial<Record<keyof TaskValues, boolean>>,
) {
  const { assigned_to, customer_id, lead_id, ...fields } = values;
  return {
    ...fields,
    description: fields.description || null,
    due_date: fields.due_date
      ? preserveUtcInstant(fields.due_date, originalDue)
      : null,
    ...(version ? { version } : {}),
    ...(canAssign
      ? {
          ...(!version || dirty?.assigned_to ? { assigned_to } : {}),
          ...(!version || dirty?.customer_id ? { customer_id } : {}),
          ...(!version || dirty?.lead_id ? { lead_id } : {}),
        }
      : {}),
  };
}
