"use client";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { useDebounced } from "@/hooks/use-debounced";
import { list } from "./api";
import type { Person, Customer, CustomerSummary } from "@/types/crm";
export function AssigneePicker({
  value,
  onChange,
  multiple = true,
  endpoint = "/crm/assignees",
  label,
}: {
  value: Person[];
  onChange: (people: Person[]) => void;
  multiple?: boolean;
  endpoint?: string;
  label?: string;
}) {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const term = useDebounced(search);
  const query = useQuery({
    queryKey: ["crm", "assignees", endpoint, term, page],
    queryFn: () => list<Person>(endpoint, { search: term, page, per_page: 10 }),
  });
  return (
    <fieldset className="space-y-3 rounded-lg border border-border p-3">
      <legend className="px-1 text-sm font-medium">
        {label ?? (multiple ? "Assigned staff" : "Lead owner")}
      </legend>
      <div className="flex flex-wrap gap-2">
        {value.map((person) => (
          <span
            key={person.id}
            className="flex items-center gap-2 rounded bg-primary/10 px-2 py-1 text-xs"
          >
            {person.name}
            <button
              type="button"
              aria-label={`Remove ${person.name}`}
              onClick={() => onChange(value.filter((p) => p.id !== person.id))}
            >
              ×
            </button>
          </span>
        ))}
      </div>
      <Input
        aria-label="Search staff"
        placeholder="Search active staff by name"
        value={search}
        onChange={(e) => {
          setSearch(e.target.value);
          setPage(1);
        }}
      />
      {query.isPending && (
        <p role="status" className="text-xs text-muted-foreground">
          Loading staff…
        </p>
      )}
      {query.error && (
        <p role="alert" className="text-xs text-red-600">
          {query.error.message}
        </p>
      )}
      <div className="max-h-40 overflow-auto">
        {query.data?.data.map((person) => (
          <label
            key={person.id}
            className="flex cursor-pointer items-center gap-2 rounded p-2 text-sm hover:bg-muted"
          >
            <input
              type="checkbox"
              checked={value.some((p) => p.id === person.id)}
              onChange={(e) =>
                onChange(
                  e.target.checked
                    ? multiple
                      ? [...value, person]
                      : [person]
                    : value.filter((p) => p.id !== person.id),
                )
              }
            />
            {person.name}
          </label>
        ))}
        {query.data?.data.length === 0 && (
          <p className="py-2 text-xs text-muted-foreground">
            No matching staff.
          </p>
        )}
      </div>
      {query.data && query.data.meta.last_page > 1 && (
        <div className="flex gap-2">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={page === 1}
            onClick={() => setPage(page - 1)}
          >
            Previous staff
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={page >= query.data.meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            More staff
          </Button>
        </div>
      )}
    </fieldset>
  );
}
export function CustomerPicker({
  value,
  onChange,
}: {
  value: CustomerSummary | null;
  onChange: (customer: CustomerSummary | null) => void;
}) {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const term = useDebounced(search);
  const query = useQuery({
    queryKey: ["crm", "customer-picker", term, page],
    queryFn: () =>
      list<Customer>("/customers", {
        search: term,
        page,
        per_page: 10,
        sort: "last_name",
        direction: "asc",
      }),
  });
  return (
    <fieldset className="space-y-3 rounded-lg border border-border p-3">
      <legend className="px-1 text-sm font-medium">
        Linked customer (optional)
      </legend>
      {value && (
        <div className="flex items-center justify-between text-sm">
          <span>
            {value.first_name} {value.last_name} · {value.customer_code}
          </span>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => onChange(null)}
          >
            Unlink
          </Button>
        </div>
      )}
      <Input
        aria-label="Search customers to link"
        placeholder="Search customers by name, email or company"
        value={search}
        onChange={(e) => {
          setSearch(e.target.value);
          setPage(1);
        }}
      />
      {query.error && (
        <p role="alert" className="text-xs text-red-600">
          {query.error.message}
        </p>
      )}
      {query.isPending && (
        <p role="status" className="text-xs text-muted-foreground">
          Loading customers…
        </p>
      )}
      <div className="max-h-40 overflow-auto">
        {query.data?.data.map((customer) => (
          <button
            type="button"
            className={`block w-full rounded p-2 text-left text-sm hover:bg-muted ${value?.id === customer.id ? "bg-primary/10" : ""}`}
            key={customer.id}
            onClick={() => onChange(customer)}
          >
            {customer.first_name} {customer.last_name}
            <span className="ml-2 text-xs text-muted-foreground">
              {customer.company || customer.customer_code}
            </span>
          </button>
        ))}
        {query.data?.data.length === 0 && (
          <p className="text-xs text-muted-foreground">
            No matching customers.
          </p>
        )}
      </div>
      {query.data && query.data.meta.last_page > 1 && (
        <div className="flex gap-2">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={page === 1}
            onClick={() => setPage(page - 1)}
          >
            Previous customers
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={page >= query.data.meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            More customers
          </Button>
        </div>
      )}
    </fieldset>
  );
}
