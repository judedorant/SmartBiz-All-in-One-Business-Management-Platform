"use client";
import { useQuery } from "@tanstack/react-query";
import { useUser } from "@/hooks/use-user";
import { get } from "@/features/crm/api";
import { CrmState, PageHeading } from "@/features/crm/components";
import { LeadForm } from "./lead-form";
import { CustomerForm } from "@/features/customers/customer-form";
import type { Lead } from "@/types/crm";
export function LeadEditor({
  id,
  convert = false,
}: {
  id?: number;
  convert?: boolean;
}) {
  const { data: user } = useUser();
  const query = useQuery({
    queryKey: ["crm", "lead", id],
    queryFn: () => get<Lead>(`/leads/${id}`),
    enabled: !!id,
    refetchOnWindowFocus: false,
  });
  const permitted = convert
    ? user?.permissions.includes("leads.manage") &&
      user?.permissions.includes("customers.manage")
    : id
      ? user?.permissions.includes("leads.manage") ||
        user?.permissions.includes("leads.update_assigned")
      : user?.permissions.includes("leads.manage");
  if (!permitted)
    return (
      <p role="alert">You do not have permission to perform this action.</p>
    );
  return (
    <div className="max-w-4xl">
      <PageHeading
        title={
          convert ? "Convert lead to customer" : id ? "Edit lead" : "New lead"
        }
        description={
          convert
            ? "Create a customer without losing the lead’s history."
            : "Capture the opportunity and assign a clear owner."
        }
        back={id ? `/leads/${id}` : "/leads"}
      />
      {id ? (
        <>
          <CrmState
            pending={query.isPending}
            error={query.error}
            retry={() => query.refetch()}
          />
          {query.data &&
            !query.error &&
            (convert ? (
              query.data.customer_id ? (
                <p role="alert">This lead is already linked to a customer.</p>
              ) : (
                <CustomerForm key={query.data.id} convertingLead={query.data} />
              )
            ) : (
              <LeadForm key={query.data.id} lead={query.data} />
            ))}
        </>
      ) : (
        <LeadForm />
      )}
    </div>
  );
}
