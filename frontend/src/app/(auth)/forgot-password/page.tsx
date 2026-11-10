import { PasswordForm } from "@/features/auth/password-form";
export const metadata = {
  title: "Forgot password | SmartBiz",
  robots: { index: false, follow: false },
};
export default function ForgotPasswordPage() {
  return (
    <main className="flex min-h-screen items-center justify-center p-6">
      <section className="w-full max-w-md space-y-6 rounded-xl border border-border bg-card p-6 sm:p-8">
        <p className="font-semibold text-primary">SmartBiz</p>
        <h1 className="text-2xl font-bold">Forgot your password?</h1>
        <p className="text-sm text-muted-foreground">
          Enter your account email to request a secure password reset link.
        </p>
        <PasswordForm mode="forgot" />
      </section>
    </main>
  );
}
