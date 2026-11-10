"use client";

import { useEffect, useRef } from "react";
import { Button } from "@/components/ui/button";
import { AppointmentForm } from "./appointment-form";

export function AppointmentModal({ initialStart, initialEnd, onClose }: {
  initialStart?: string;
  initialEnd?: string;
  onClose: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const element = dialog.current;
    const previousOverflow = document.body.style.overflow;
    element?.showModal();
    document.body.style.overflow = "hidden";
    return () => {
      element?.close();
      document.body.style.overflow = previousOverflow;
    };
  }, []);

  return (
    <dialog
      ref={dialog}
      aria-labelledby="new-appointment-title"
      aria-describedby="new-appointment-description"
      onCancel={(event) => { event.preventDefault(); onClose(); }}
      className="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-3xl overflow-y-auto rounded-xl border border-border bg-card p-5 text-foreground shadow-xl backdrop:bg-black/60 sm:p-6"
    >
      <div className="mb-6 flex items-start justify-between gap-4">
        <div>
          <h2 id="new-appointment-title" className="text-xl font-semibold">New appointment</h2>
          <p id="new-appointment-description" className="mt-1 text-sm text-muted-foreground">Coordinate customer meetings within configured staff working hours.</p>
        </div>
        <Button type="button" variant="ghost" onClick={onClose} aria-label="Close new appointment">Close</Button>
      </div>
      <AppointmentForm initialStart={initialStart} initialEnd={initialEnd} onSaved={onClose} onCancel={onClose} />
    </dialog>
  );
}
