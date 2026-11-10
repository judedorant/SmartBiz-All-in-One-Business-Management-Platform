import { PasswordForm } from "@/features/auth/password-form";
export const metadata = {
  title: "Reset password | SmartBiz",
  robots: { index: false, follow: false },
  referrer: "no-referrer" as const,
};
export default async function ResetPasswordPage({
  searchParams,
}: {
  searchParams: Promise<{ token?: string; email?: string }>;
}) {
  const { token, email } = await searchParams;
  return (
    <main className="flex min-h-screen items-center justify-center p-6">
      <section className="w-full max-w-md space-y-6 rounded-xl border border-border bg-card p-6 sm:p-8">
        <p className="font-semibold text-primary">SmartBiz</p>
        <h1 className="text-2xl font-bold">Reset your password</h1>
        <PasswordForm
          mode="reset"
          token={typeof token === "string" ? token : ""}
          email={typeof email === "string" ? email : ""}
        />
      </section>
    </main>
  );
}
