export type Person = { id: number; name: string };
export type Stage = {
  id: number;
  name: string;
  slug: string;
  position: number;
  probability: number;
};
export type CustomerSummary = {
  id: number;
  customer_code: string;
  first_name: string;
  last_name: string;
};
export type Customer = CustomerSummary & {
  email: string | null;
  phone: string | null;
  company: string | null;
  address: string | null;
  status: "active" | "inactive";
  version: number;
  assignees: Person[];
  creator: Person;
  created_at: string;
  updated_at: string;
};
export type Lead = {
  id: number;
  title: string;
  source: string;
  estimated_value: string;
  expected_close_date: string | null;
  notes: string | null;
  version: number;
  customer_id: number | null;
  assigned_to: number;
  pipeline_stage_id: number;
  status: string;
  stage: Stage;
  assignee: Person;
  customer: CustomerSummary | null;
  converted_at: string | null;
  created_at: string;
  updated_at: string;
};
export type Activity = {
  id: number;
  activity_type: string;
  description: string;
  user: Person;
  created_at: string;
};
export type Note = {
  id: number;
  body: string;
  user: Person;
  created_at: string;
  updated_at: string;
};
export type Page<T> = {
  success: boolean;
  message: string;
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};
export type Filters = Record<string, string | number | undefined>;
