import { IntegrationWorkspace } from "@/features/automation/integrations";
export default async function Page({
  searchParams,
}: {
  searchParams: Promise<{ oauth?: string }>;
}) {
  const { oauth } = await searchParams;
  return <IntegrationWorkspace oauth={oauth} />;
}
