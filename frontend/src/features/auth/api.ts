import { api, csrf } from "@/lib/api";
import type { User } from "@/types/auth";
import type { LoginValues } from "./schema";
export const authApi = {
  me: () => api<User>("/me"),
  login: async (values: LoginValues) => {
    await csrf();
    return api<User>("/login", {
      method: "POST",
      body: JSON.stringify(values),
    });
  },
  logout: async () => {
    await csrf();
    return api<null>("/logout", { method: "POST" });
  },
};
