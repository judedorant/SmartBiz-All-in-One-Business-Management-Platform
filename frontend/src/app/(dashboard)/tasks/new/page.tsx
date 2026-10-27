"use client";
import { useUser } from "@/hooks/use-user";
import { PageHeading } from "@/features/crm/components";
import { TaskForm } from "@/features/tasks/form";
export default function Page() {
  const { data: user } = useUser();
  if (!user) return null;
  if (!user.permissions.includes("tasks.assign"))
    return <p role="alert">You do not have permission to create tasks.</p>;
  return (
    <>
      <PageHeading
        title="New task"
        description="Assign the next step to someone on your team."
        back="/tasks"
      />
      <TaskForm />
    </>
  );
}
