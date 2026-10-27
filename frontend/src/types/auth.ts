export type User = {
  id: number;
  name: string;
  email: string;
  status: "active" | "inactive";
  last_login_at: string | null;
  roles: string[];
  permissions: string[];
};
export type Envelope<T> = { success: boolean; message: string; data: T };
