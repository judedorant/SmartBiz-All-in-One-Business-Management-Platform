import { notFound } from "next/navigation";
import { CustomerEditor } from "@/features/customers/customer-editor";
export default async function Page({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  if (!/^\d+$/.test(id) || !Number.isSafeInteger(Number(id)) || Number(id) < 1)
    notFound();
  return <CustomerEditor id={Number(id)} />;
}
