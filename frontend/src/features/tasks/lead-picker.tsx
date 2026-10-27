"use client";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { list } from "@/features/crm/api";
import { useDebounced } from "@/hooks/use-debounced";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/tables/pagination";
import type { Lead } from "@/types/crm";
export function LeadPicker({
  value,
  onChange,
}: {
  value: { id: number; title: string } | null;
  onChange: (value: { id: number; title: string } | null) => void;
}) {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const term = useDebounced(search);
  const query = useQuery({
    queryKey: ["crm", "task-lead-picker", term, page],
    queryFn: () => list<Lead>("/leads", { search: term, page, per_page: 10 }),
  });
  return (
    <fieldset className="space-y-3 rounded-lg border border-border p-3">
      <legend className="px-1 text-sm font-medium">
        Linked lead (optional)
      </legend>
      {value && (
        <div className="flex items-center justify-between text-sm">
          {value.title}
          <Button type="button" variant="ghost" onClick={() => onChange(null)}>
            Unlink lead
          </Button>
        </div>
      )}
      <Input
        aria-label="Search leads to link"
        value={search}
        onChange={(e) => {
          setSearch(e.target.value);
          setPage(1);
        }}
        placeholder="Search leads"
      />
      {query.isPending && <p role="status">Loading leads…</p>}
      {query.error && <p role="alert">{query.error.message}</p>}
      <div className="max-h-40 overflow-auto">
        {query.data?.data.map((lead) => (
          <button
            type="button"
            key={lead.id}
            className="block w-full rounded p-2 text-left text-sm hover:bg-muted"
            onClick={() => onChange(lead)}
          >
            {lead.title}
          </button>
        ))}
        {query.data?.data.length === 0 && <p>No matching leads.</p>}
      </div>
      {query.data && (
        <Pagination
          page={page}
          lastPage={query.data.meta.last_page}
          total={query.data.meta.total}
          onChange={setPage}
        />
      )}
    </fieldset>
  );
}
