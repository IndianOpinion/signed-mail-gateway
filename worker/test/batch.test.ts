import { describe, expect, it } from "vitest";
import { resetConfigReports } from "../src/config";
import { harness, json, message, providerError, signedRequest, type Harness } from "./helpers";
import { logLines } from "./setup";

async function batch(h: Harness, body: unknown): Promise<Response> {
  return h.fetch(await signedRequest({ path: "/v1/batch", body: JSON.stringify(body) }));
}

function many(n: number): Record<string, unknown>[] {
  return Array.from({ length: n }, (_v, i) => message({ subject: `Batch ${String(i)}` }));
}

describe("batch (cases 49 to 54)", () => {
  it("T47: batch of 3 with the middle one invalid: two sent, results in order, middle has its 400", async () => {
    const h = harness();
    const res = await batch(h, {
      messages: [
        message({ subject: "one" }),
        message({ to: ["not-an-address"] }),
        message({ subject: "three" }),
      ],
    });
    expect(res.status).toBe(200);
    const body = await json(res);
    expect(body).toMatchObject({ ok: true, batch: true, n: 3, sent: 2 });
    const results = body.results as Record<string, unknown>[];
    expect(results).toHaveLength(3);
    expect(results[0]).toMatchObject({ ok: true, status: 200, domain: "mail.example.org" });
    expect(results[1]).toMatchObject({ ok: false, status: 400, error: "bad_recipient" });
    expect(results[2]).toMatchObject({ ok: true, status: 200 });
    expect(h.binding.calls.map((c) => (c as { subject: string }).subject)).toEqual(["one", "three"]);
  });

  it("results keep input order even when later messages finish first", async () => {
    const h = harness({ BATCH_CONCURRENCY: "3" });
    let call = 0;
    const original = h.binding.send.bind(h.binding);
    h.binding.send = async (m: unknown) => {
      const delay = [30, 10, 0][call++] ?? 0;
      await new Promise((resolve) => setTimeout(resolve, delay));
      return original(m);
    };
    const res = await batch(h, {
      messages: [message({ subject: "a" }), message({ subject: "b" }), message({ subject: "c" })],
    });
    const results = (await json(res)).results as { id: string }[];
    // msg ids are assigned in completion order: c, b, a.
    expect(results.map((r) => r.id)).toEqual(["msg-3", "msg-2", "msg-1"]);
  });

  it("T48: batch of 11 gives 400 batch_too_large and sends nothing", async () => {
    const h = harness();
    const res = await batch(h, { messages: many(11) });
    expect(res.status).toBe(400);
    expect((await json(res)).error).toBe("batch_too_large");
    expect(h.binding.calls).toHaveLength(0);
  });

  it.each([
    ["missing", {}],
    ["empty", { messages: [] }],
    ["not an array", { messages: { a: 1 } }],
    ["top level array", [message()]],
  ])("T49: messages %s gives bad_json", async (_label, body) => {
    const h = harness();
    const res = await batch(h, body);
    expect(res.status).toBe(400);
    expect((await json(res)).error).toBe("bad_json");
    expect(h.binding.calls).toHaveLength(0);
  });

  it.each([
    [undefined, 4],
    ["2", 2],
    ["6", 6],
  ])("T50: in-flight sends never exceed BATCH_CONCURRENCY=%s", async (setting, expected) => {
    const h = harness(setting === undefined ? {} : { BATCH_CONCURRENCY: setting });
    h.binding.delayMs = 15;
    const res = await batch(h, { messages: many(10) });
    expect(res.status).toBe(200);
    expect((await json(res)).sent).toBe(10);
    expect(h.binding.maxInFlight).toBe(expected);
  });

  it("T51: bad MAX_BATCH and BATCH_CONCURRENCY fall back to defaults; 99 is clamped", async () => {
    resetConfigReports();
    const before = logLines.length;
    const bad = harness({ MAX_BATCH: "ten", BATCH_CONCURRENCY: "-3" });
    const check = await bad.fetch(await signedRequest({ path: "/v1/check" }));
    expect(await json(check)).toMatchObject({ batch_max: 10, batch_concurrency: 4 });
    const logged = logLines.slice(before).join("\n");
    expect(logged).toContain('"var":"MAX_BATCH"');
    expect(logged).toContain('"var":"BATCH_CONCURRENCY"');
    expect(logged).toContain("config_default");

    const big = harness({ MAX_BATCH: "99", BATCH_CONCURRENCY: "99" });
    const check2 = await big.fetch(await signedRequest({ path: "/v1/check" }));
    expect(await json(check2)).toMatchObject({ batch_max: 50, batch_concurrency: 6 });

    big.binding.delayMs = 5;
    const res = await batch(big, { messages: many(12) });
    expect((await json(res)).sent).toBe(12);
    expect(big.binding.maxInFlight).toBe(6);
  });

  it("T66: one failing message still gives 200 with sent < n; a throwing binding is called once, never retried", async () => {
    const h = harness();
    h.binding.failWhen = (m) =>
      m.subject === "fails"
        ? providerError("E_DELIVERY_FAILED", "delivery failed for x@example.com")
        : undefined;
    const res = await batch(h, {
      messages: [message({ subject: "ok" }), message({ subject: "fails" }), message({ subject: "ok2" })],
    });
    expect(res.status).toBe(200);
    const body = await json(res);
    expect(body).toMatchObject({ n: 3, sent: 2 });
    const results = body.results as Record<string, unknown>[];
    expect(results[1]).toMatchObject({
      ok: false,
      status: 502,
      error: "provider_error",
      provider_code: "E_DELIVERY_FAILED",
    });
    expect(String(results[1]?.message)).not.toContain("@");
    const failedCalls = h.binding.calls.filter((c) => (c as { subject: string }).subject === "fails");
    expect(failedCalls).toHaveLength(1);
  });

  it("a batch spends one nonce: the same request again gives 409", async () => {
    const h = harness();
    const req = await signedRequest({ path: "/v1/batch", body: JSON.stringify({ messages: many(2) }) });
    const again = req.clone();
    expect((await h.fetch(req)).status).toBe(200);
    const res = await h.fetch(again);
    expect(res.status).toBe(409);
    expect(h.binding.calls).toHaveLength(2);
  });
});
