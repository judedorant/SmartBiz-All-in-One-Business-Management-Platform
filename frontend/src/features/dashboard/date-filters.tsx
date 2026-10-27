"use client";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Input, Field, controlClass } from "@/components/forms/field";
import type { DashboardFilters } from "@/types/dashboard";
export const customRangeSchema = z
  .object({
    start_date: z.iso
      .date("Choose a valid start date.")
      .refine(
        (value) => value >= "1900-01-01" && value <= "2100-12-31",
        "Choose a date from 1900 through 2100.",
      ),
    end_date: z.iso
      .date("Choose a valid end date.")
      .refine(
        (value) => value >= "1900-01-01" && value <= "2100-12-31",
        "Choose a date from 1900 through 2100.",
      ),
  })
  .refine((v) => v.end_date >= v.start_date, {
    path: ["end_date"],
    message: "End date must be on or after start date.",
  })
  .refine(
    (v) => {
      const days =
        (Date.parse(v.end_date) - Date.parse(v.start_date)) / 86400000;
      return days <= 365;
    },
    { path: ["end_date"], message: "Select at most 366 calendar days." },
  );
export function DateFilters({
  value,
  onChange,
  currencies,
}: {
  value: DashboardFilters;
  onChange: (filters: DashboardFilters) => void;
  currencies: string[];
}) {
  const form = useForm<z.infer<typeof customRangeSchema>>({
    resolver: zodResolver(customRangeSchema),
    defaultValues: {
      start_date: value.start_date ?? "",
      end_date: value.end_date ?? "",
    },
  });
  const selectedPreset = useWatch({
    control: form.control,
    name: "start_date",
  });
  return (
    <div className="space-y-4 rounded-xl border border-border bg-card p-4">
      <div className="flex flex-wrap items-end gap-4">
        <Field label="Date range">
          <select
            className={`${controlClass} min-w-44`}
            value={value.preset}
            onChange={(e) => {
              const preset = e.target.value as DashboardFilters["preset"];
              onChange({
                ...value,
                preset,
                start_date: undefined,
                end_date: undefined,
              });
            }}
          >
            <option value="today">Today</option>
            <option value="last_7_days">Last 7 days</option>
            <option value="last_30_days">Last 30 days</option>
            <option value="this_month">This month</option>
            <option value="custom">Custom date range</option>
          </select>
        </Field>
        <Field label="Revenue currency">
          <select
            className={controlClass}
            value={value.currency}
            onChange={(e) => onChange({ ...value, currency: e.target.value })}
          >
            {(currencies.length ? currencies : [value.currency]).map(
              (currency) => (
                <option key={currency} value={currency}>
                  {currency}
                </option>
              ),
            )}
          </select>
        </Field>
        <p className="pb-2 text-xs text-muted-foreground">
          Calendar dates in {value.timezone}. Currencies are never combined.
        </p>
      </div>
      {value.preset === "custom" && (
        <form
          aria-label="Custom date range"
          className="flex flex-wrap items-start gap-4"
          onSubmit={form.handleSubmit((dates) =>
            onChange({ ...value, ...dates }),
          )}
          noValidate
        >
          <Field
            label="Start date"
            error={form.formState.errors.start_date?.message}
          >
            <Input
              type="date"
              min="1900-01-01"
              max="2100-12-31"
              {...form.register("start_date")}
            />
          </Field>
          <Field
            label="End date"
            error={form.formState.errors.end_date?.message}
          >
            <Input
              type="date"
              min={selectedPreset || "1900-01-01"}
              max="2100-12-31"
              {...form.register("end_date")}
            />
          </Field>
          <Button className="mt-7" type="submit">
            Apply dates
          </Button>
        </form>
      )}
    </div>
  );
}
