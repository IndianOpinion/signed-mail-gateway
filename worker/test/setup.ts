import { afterAll, beforeAll, vi } from "vitest";
import { SECRET_A, SECRET_B } from "./helpers";

/**
 * T61: every log line written by any test in a file is checked at the end.
 * No address, subject, body, nonce or secret may appear in a log line.
 */
export const logLines: string[] = [];

const FORBIDDEN: (string | RegExp)[] = [
  "@",
  SECRET_A,
  SECRET_B,
  /[0-9a-fA-F]{32}/,
  "Hello",
  "reader",
  "Caf",
  "न",
  "SECRET-SUBJECT",
  "SECRET-BODY",
  "SECRET-HEADER-VALUE",
  "Example News",
];

beforeAll(() => {
  vi.spyOn(console, "log").mockImplementation((...args: unknown[]) => {
    logLines.push(args.map(String).join(" "));
  });
});

afterAll(() => {
  for (const line of logLines) {
    for (const pattern of FORBIDDEN) {
      const hit = typeof pattern === "string" ? line.includes(pattern) : pattern.test(line);
      if (hit) {
        throw new Error(`log line leaks ${String(pattern)}: ${line}`);
      }
    }
  }
  vi.restoreAllMocks();
});
