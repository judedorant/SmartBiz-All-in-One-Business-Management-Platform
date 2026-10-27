"use client";
import { useQuery } from "@tanstack/react-query";
import { useUser } from "@/hooks/use-user";
import { get } from "@/features/crm/api";
import { CrmState, PageHeading } from "@/features/crm/components";
import { CustomerForm } from "./customer-form";
import type { Customer } from "@/types/crm";
export function CustomerEditor({ id }: { id?: number }) {
  const { data: user } = useUser();
  const query = useQuery({
    queryKey: ["crm", "customer", id],
    queryFn: () => get<Customer>(`/customers/${id}`),
    enabled: !!id,
    refetchOnWindowFocus: false,
  });
  if (!user?.permissions.includes("customers.manage"))
    return <p role="alert">You do not have permission to manage customers.</p>;
  return (
    <div className="max-w-4xl">
      <PageHeading
        title={id ? "Edit customer" : "New customer"}
        description="Keep customer details and staff responsibilities up to date."
        back={id ? `/customers/${id}` : "/customers"}
      />
      {id ? (
        <>
          <CrmState
            pending={query.isPending}
            error={query.error}
            retry={() => query.refetch()}
          />
          {query.data && !query.error && (
            <CustomerForm key={query.data.id} customer={query.data} />
          )}
        </>
      ) : (
        <CustomerForm />
      )}
    </div>
  );
}
