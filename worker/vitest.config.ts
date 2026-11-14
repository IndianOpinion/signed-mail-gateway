import { cloudflareTest } from "@cloudflare/vitest-plugin";
import { defineConfig } from "vitest/config";

// The tests run inside the real Workers runtime. They do not read
// wrangler.toml, whose bindings are placeholders for the operator to fill.
export default defineConfig({
  plugins: [
    cloudflareTest({
      main: "./src/index.ts",
      miniflare: {
        compatibilityDate: "2026-10-01",
        compatibilityFlags: ["nodejs_compat"],
        durableObjects: {
          NONCE_STORE: { className: "NonceStore", useSQLite: true },
        },
        bindings: {
          GATEWAY_SECRET: "gateway-test-secret-A-0123456789abcdef",
          SENDERS: '{"mail.example.org":"MAIL_SENDER"}',
          ENABLED_DOMAINS: "mail.example.org",
        },
      },
    }),
  ],
  test: {
    include: ["test/**/*.test.ts"],
    setupFiles: ["./test/setup.ts"],
  },
});
