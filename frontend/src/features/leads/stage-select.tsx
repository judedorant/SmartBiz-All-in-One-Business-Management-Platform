"use client";
import { useQuery } from "@tanstack/react-query";
import { controlClass } from "@/components/forms/field";
import { get, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import type { Lead, Stage } from "@/types/crm";
export function StageSelect({ lead }: { lead: Lead }) {
  const stages = useQuery({
    queryKey: ["crm", "stages"],
    queryFn: () => get<Stage[]>("/pipeline-stages"),
  });
  const move = useCrmWrite<number, Lead>(
    (id) =>
      write(`/leads/${lead.id}/stage`, "PATCH", {
        pipeline_stage_id: id,
        version: lead.version,
      }),
    "Lead stage updated",
  );
  return (
    <div className="space-y-2">
      <label className="block text-sm font-medium">
        Move lead to stage
        <select
          aria-label={`Move ${lead.title} to stage`}
          className={`${controlClass} mt-2`}
          value={lead.pipeline_stage_id}
          disabled={move.isPending || !stages.data}
          onChange={(e) => move.mutate(Number(e.target.value))}
        >
          {stages.data?.map((stage) => (
            <option key={stage.id} value={stage.id}>
              {stage.name}
            </option>
          ))}
        </select>
      </label>
      {(stages.error || move.error) && (
        <p role="alert" className="text-sm text-red-600">
          {stages.error?.message || move.error?.message}
        </p>
      )}
      <p className="text-xs text-muted-foreground">
        Won and lost are pipeline outcomes; they do not create sales records.
      </p>
    </div>
  );
}
