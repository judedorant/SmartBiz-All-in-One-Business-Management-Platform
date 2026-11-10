import type { NextConfig } from "next";
const config: NextConfig = {
  distDir: process.env.SMARTBIZ_NEXT_DIST_DIR || ".next",
  poweredByHeader: false,
  logging: { incomingRequests: { ignore: [/^\/reset-password(?:[/?]|$)/] } },
  experimental: { useTypeScriptCli: false },
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "X-Frame-Options", value: "DENY" },
        ],
      },
      {
        source: "/reset-password",
        headers: [
          { key: "Referrer-Policy", value: "no-referrer" },
          { key: "Cache-Control", value: "no-store, private" },
        ],
      },
    ];
  },
};
export default config;
