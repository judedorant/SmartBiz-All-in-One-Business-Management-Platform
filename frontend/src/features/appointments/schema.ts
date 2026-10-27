import { z } from "zod";
export const appointmentSchema = z
  .object({
    title: z.string().trim().min(1, "Enter an appointment title.").max(200),
    description: z.string().max(10000),
    customer_id: z.number().int().positive("Choose a customer."),
    assigned_to: z.number().int().positive("Choose a staff member."),
    start_at: z
      .string()
      .min(1, "Choose a start time.")
      .refine(
        (v) => Number.isFinite(new Date(v).valueOf()),
        "Choose a valid start time.",
      ),
    end_at: z
      .string()
      .min(1, "Choose an end time.")
      .refine(
        (v) => Number.isFinite(new Date(v).valueOf()),
        "Choose a valid end time.",
      ),
    status: z.enum(["scheduled", "confirmed", "completed", "cancelled"]),
  })
  .refine((v) => new Date(v.end_at) > new Date(v.start_at), {
    path: ["end_at"],
    message: "End time must be after start time.",
  })
  .refine(
    (v) => {
      const minutes =
        (new Date(v.end_at).valueOf() - new Date(v.start_at).valueOf()) / 60000;
      return minutes >= 5 && minutes <= 480;
    },
    {
      path: ["end_at"],
      message: "Choose a duration from 5 minutes to 8 hours.",
    },
  );
export type AppointmentValues = z.infer<typeof appointmentSchema>;
const clock = z
  .string()
  .regex(/^([01]\d|2[0-3]):[0-5]\d$/, "Choose a valid time.");
export const availabilitySchema = z
  .object({
    timezone: z
      .string()
      .min(1, "Choose a timezone.")
      .refine((value) => {
        try {
          new Intl.DateTimeFormat("en", { timeZone: value });
          return true;
        } catch {
          return false;
        }
      }, "Enter a valid IANA timezone."),
    windows: z
      .array(
        z
          .object({
            day_of_week: z.number().int().min(0).max(6),
            start_time: clock,
            end_time: clock,
          })
          .refine((w) => w.end_time > w.start_time, {
            path: ["end_time"],
            message: "End time must be after start time.",
          }),
      )
      .max(42),
  })
  .refine(
    (values) => {
      const windows = [...values.windows].sort(
        (a, b) =>
          a.day_of_week - b.day_of_week ||
          a.start_time.localeCompare(b.start_time),
      );
      return windows.every(
        (w, i) =>
          i === 0 ||
          windows[i - 1].day_of_week !== w.day_of_week ||
          windows[i - 1].end_time <= w.start_time,
      );
    },
    { path: ["windows"], message: "Availability windows cannot overlap." },
  );
export type AvailabilityValues = z.infer<typeof availabilitySchema>;
