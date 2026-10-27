import Link from "next/link";
export default function NotFound() {
  return (
    <main className="p-12">
      <h1 className="text-2xl font-bold">Page not found</h1>
      <Link
        className="mt-5 inline-block text-primary underline"
        href="/dashboard"
      >
        Return to dashboard
      </Link>
    </main>
  );
}
