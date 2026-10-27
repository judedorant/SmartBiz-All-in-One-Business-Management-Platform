import { z } from "zod";
export const customerSchema = z.object({
  first_name: z.string().trim().min(1, "Enter a first name.").max(100),
  last_name: z.string().trim().min(1, "Enter a last name.").max(100),
  email: z.union([z.email("Enter a valid email address."), z.literal("")]),
  phone: z.string().max(40),
  company: z.string().max(200),
  address: z.string().max(5000),
  status: z.enum(["active", "inactive"]),
  assigned_user_ids: z.array(z.number().int().positive()).max(100),
});
export type CustomerValues = z.infer<typeof customerSchema>;
