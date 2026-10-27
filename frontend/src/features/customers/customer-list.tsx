"use client";
import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Plus, ArrowUpRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { controlClass } from "@/components/forms/field";
import { Pagination } from "@/components/tables/pagination";
import { useUser } from "@/hooks/use-user";
import { useDebounced } from "@/hooks/use-debounced";
import { list } from "@/features/crm/api";
import { Badge, CrmState, PageHeading } from "@/features/crm/components";
import type { Customer } from "@/types/crm";
export function CustomerList() {
  const { data: user } = useUser();
  const [search, setSearch] = useState("");
  const term = useDebounced(search);
  const [status, setStatus] = useState("");
  const [sort, setSort] = useState("created_at");
  const [direction, setDirection] = useState("desc");
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ["crm", "customers", term, status, sort, direction, page],
    queryFn: () =>
      list<Customer>("/customers", {
        search: term,
        status,
        sort,
        direction,
        page,
      }),
  });
  return (
    <div>
      <PageHeading
        title="Customers"
        description="Build lasting relationships with a shared customer record."
        action={
          user?.permissions.includes("customers.manage") && (
            <Button asChild>
              <Link href="/customers/new">
                <Plus className="size-4" />
                New customer
              </Link>
            </Button>
          )
        }
      />
      <section className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <div className="grid gap-3 border-b border-border p-4 sm:grid-cols-[1fr_160px_180px_140px]">
          <Input
            aria-label="Search customers"
            placeholder="Search name, email, company or code…"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
          <select
            className={controlClass}
            aria-label="Customer status filter"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <select
            aria-label="Sort customers by"
            className={controlClass}
            value={sort}
            onChange={(e) => {
              setSort(e.target.value);
              setPage(1);
            }}
          >
            <option value="created_at">Created date</option>
            <option value="last_name">Last name</option>
            <option value="first_name">First name</option>
            <option value="company">Company</option>
          </select>
          <select
            aria-label="Customer sort direction"
            className={controlClass}
            value={direction}
            onChange={(e) => {
              setDirection(e.target.value);
              setPage(1);
            }}
          >
            <option value="desc">Descending</option>
            <option value="asc">Ascending</option>
          </select>
        </div>
        <CrmState
          pending={query.isPending}
          error={query.error}
          retry={() => query.refetch()}
          empty={query.data?.data.length === 0}
        />
        {query.data && !query.error && query.data.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-background text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                  {[
                    "Customer",
                    "Company",
                    "Contact",
                    "Assigned staff",
                    "Status",
                    "Details",
                  ].map((h) => (
                    <th
                      key={h}
                      scope="col"
                      className="whitespace-nowrap px-5 py-4"
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {query.data.data.map((customer) => (
                  <tr
                    key={customer.id}
                    className="border-t border-border hover:bg-muted/40"
                  >
                    <td className="px-5 py-4">
                      <Link
                        className="font-semibold hover:text-primary"
                        href={`/customers/${customer.id}`}
                      >
                        {customer.first_name} {customer.last_name}
                      </Link>
                      <p className="mt-1 text-xs text-muted-foreground">
                        {customer.customer_code}
                      </p>
                    </td>
                    <td className="px-5 py-4">{customer.company || "—"}</td>
                    <td className="px-5 py-4">
                      <p>{customer.email || "—"}</p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        {customer.phone || ""}
                      </p>
                    </td>
                    <td className="px-5 py-4">
                      {customer.assignees.map((p) => p.name).join(", ") ||
                        "Unassigned"}
                    </td>
                    <td className="px-5 py-4">
                      <Badge>{customer.status}</Badge>
                    </td>
                    <td className="px-5 py-4">
                      <Link
                        aria-label={`View ${customer.first_name} ${customer.last_name}`}
                        href={`/customers/${customer.id}`}
                        className="inline-flex rounded-lg p-2 text-primary hover:bg-primary/10"
                      >
                        <ArrowUpRight className="size-4" />
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {query.data && !query.error && (
          <Pagination
            page={query.data.meta.current_page}
            lastPage={query.data.meta.last_page}
            total={query.data.meta.total}
            onChange={setPage}
          />
        )}
      </section>
    </div>
  );
}
