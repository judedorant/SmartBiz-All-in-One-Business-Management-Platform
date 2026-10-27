import { AppointmentEditor } from "@/features/appointments/appointment-editor";
export default async function Page({
  searchParams,
}: {
  searchParams: Promise<{ start?: string; end?: string }>;
}) {
  const { start, end } = await searchParams;
  return <AppointmentEditor initialStart={start} initialEnd={end} />;
}
