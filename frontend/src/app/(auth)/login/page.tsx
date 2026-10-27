import { LoginForm } from "@/features/auth/login-form";
import { Layers, ShieldCheck, ArrowUpRight } from "lucide-react";
export default function LoginPage() {
  return (
    <main className="grid min-h-screen lg:grid-cols-2">
      <section className="relative hidden flex-col justify-between overflow-hidden bg-[#142444] p-14 text-white lg:flex">
        <div className="flex items-center gap-3 text-2xl font-bold">
          <Layers className="size-9 text-indigo-300" />
          SmartBiz
          <span className="rounded border border-white/20 px-2 py-1 text-[10px] font-normal tracking-widest">
            WORKSPACE
          </span>
        </div>
        <div>
          <p className="mb-5 text-xs font-semibold uppercase tracking-[.25em] text-indigo-300">
            Clarity for every workday
          </p>
          <h1 className="max-w-lg text-5xl font-semibold leading-tight">
            Your business.
            <br />
            One connected
            <br />
            <span className="text-indigo-300">workspace.</span>
          </h1>
          <p className="mt-7 max-w-md text-lg leading-relaxed text-slate-300">
            Bring your people, relationships, and daily work together with
            SmartBiz.
          </p>
          <div className="mt-10 inline-flex items-center gap-3 rounded-full border border-white/15 px-4 py-3 text-sm text-slate-200">
            <ShieldCheck className="size-5 text-indigo-300" />
            Secure access. Clear responsibilities.
            <ArrowUpRight className="size-4" />
          </div>
        </div>
        <p className="text-xs text-slate-400">
          SMARTBIZ · ALL-IN-ONE BUSINESS MANAGEMENT
        </p>
        <div className="pointer-events-none absolute -right-44 top-24 size-[540px] rounded-full border-[70px] border-indigo-300/5" />
      </section>
      <section className="flex items-center justify-center p-6 sm:p-12">
        <div className="w-full max-w-sm">
          <div className="mb-12 flex items-center gap-2 text-xl font-bold lg:hidden">
            <Layers className="text-primary" />
            SmartBiz
          </div>
          <p className="mb-3 text-xs font-bold uppercase tracking-widest text-primary">
            Welcome back
          </p>
          <h2 className="text-3xl font-bold tracking-tight">
            Sign in to your workspace
          </h2>
          <p className="mb-9 mt-3 text-sm leading-6 text-muted-foreground">
            Use the account provided by your administrator.
          </p>
          <LoginForm />
          <div className="mt-8 flex items-center gap-2 border-t border-border pt-6 text-xs text-muted-foreground">
            <ShieldCheck className="size-4" />
            Your session is protected with secure cookie authentication.
          </div>
        </div>
      </section>
    </main>
  );
}
