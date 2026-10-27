import { z } from "zod";
export const leadSchema = z.object({
  title: z.string().trim().min(1, "Enter a lead title.").max(200),
  source: z.string().trim().min(1, "Enter a lead source.").max(100),
  estimated_value: z
    .string()
    .regex(
      /^\d{1,13}(?:\.\d{1,2})?$/,
      "Enter a non-negative value with up to 2 decimal places.",
    ),
  expected_close_date: z.union([
    z.iso.date("Enter a valid date."),
    z.literal(""),
  ]),
  notes: z.string().max(10000),
  assigned_to: z.number().int().positive("Select a lead owner."),
  customer_id: z.number().int().positive().nullable(),
});
export type LeadValues = z.infer<typeof leadSchema>;
