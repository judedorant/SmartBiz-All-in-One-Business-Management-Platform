import { notFound } from "next/navigation";
import { AppointmentEditor } from "@/features/appointments/appointment-editor";
export default async function Page({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  if (!/^\d+$/.test(id) || !Number.isSafeInteger(Number(id)) || Number(id) < 1)
    notFound();
  return <AppointmentEditor id={Number(id)} />;
}
