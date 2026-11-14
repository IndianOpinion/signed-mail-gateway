import { describe, expect, it, vi } from "vitest";
import {
  DOMAIN,
  SECRET_A,
  SECRET_B,
  T0,
  harness,
  json,
  message,
  randomNonce,
  send,
  signedRequest,
  vector,
  vectorRequest,
} from "./helpers";
import { logLines } from "./setup";

describe("routing (case 1)", () => {
  it("T1: GET on /v1/send gives 405 with Allow: POST", async () => {
    const h = harness();
    const res = await h.fetch(new Request("https://gateway.example/v1/send"));
    expect(res.status).toBe(405);
    expect(res.headers.get("allow")).toBe("POST");
    expect(res.headers.get("content-type")).toContain("text/plain");
  });

  it("T1: POST on an unknown path gives 404 plain text", async () => {
    const h = harness();
    const res = await h.fetch(new Request("https://gateway.example/x", { method: "POST", body: "{}" }));
    expect(res.status).toBe(404);
    expect(res.headers.get("content-type")).toContain("text/plain");
  });
});

describe("header shape (cases 2, 12)", () => {
  it.each([
    ["missing", undefined],
    ["9 digits", "176722560"],
    ["non-digit", "17672256x0"],
    ["11 digits", "17672256000"],
  ])("T2: timestamp %s gives 401 auth_failed", async (_label, ts) => {
    const h = harness();
    const req = await signedRequest({ body: JSON.stringify(message()) });
    const headers = new Headers(req.headers);
    if (ts === undefined) {
      headers.delete("x-gateway-timestamp");
    } else {
      headers.set("x-gateway-timestamp", ts);
    }
    const res = await h.fetch(new Request(req, { headers }));
    expect(res.status).toBe(401);
    expect((await json(res)).error).toBe("auth_failed");
  });

  it.each([
    ["31 characters", "0".repeat(31)],
    ["65 characters", "0".repeat(65)],
    ["non-hex", "g".repeat(32)],
  ])("T3: nonce of %s gives 401", async (_label, nonce) => {
    const h = harness();
    const res = await h.fetch(await signedRequest({ nonce, body: JSON.stringify(message()) }));
    expect(res.status).toBe(401);
    expect((await json(res)).error).toBe("auth_failed");
    expect(h.binding.calls).toHaveLength(0);
  });

  it("T3: header names are case-insensitive", async () => {
    const h = harness();
    const req = await signedRequest({ body: JSON.stringify(message()) });
    const headers = new Headers();
    req.headers.forEach((value, name) => {
      headers.set(name.toUpperCase(), value);
    });
    const res = await h.fetch(new Request(req, { headers }));
    expect(res.status).toBe(200);
  });
});

describe("signature (cases 3 to 7)", () => {
  it("T4: vector V1 verifies and sends; the binding gets the parsed message", async () => {
    const h = harness();
    const res = await h.fetch(await vectorRequest(vector("V1")));
    expect(res.status).toBe(200);
    expect(h.binding.calls).toEqual([
      {
        from: { email: "news@mail.example.org", name: "Example News" },
        to: ["reader@example.com"],
        subject: "Hello",
        text: "Hello, reader.",
      },
    ]);
  });

  it("T5: V2 body with the V1 signature gives 401", async () => {
    const h = harness();
    const res = await h.fetch(await vectorRequest(vector("V2-with-V1-signature")));
    expect(res.status).toBe(401);
    expect(await json(res)).toMatchObject({ error: "auth_failed", message: "bad signature" });
    expect(h.binding.calls).toHaveLength(0);
  });

  it("T6: the signature is checked with crypto.subtle.verify", async () => {
    const h = harness();
    const spy = vi.spyOn(crypto.subtle, "verify");
    const res = await h.fetch(await vectorRequest(vector("V1")));
    expect(res.status).toBe(200);
    expect(spy).toHaveBeenCalled();
    expect(spy.mock.calls[0]?.[0]).toBe("HMAC");
    spy.mockRestore();
  });

  it("T7: upper case signature hex verifies", async () => {
    const h = harness();
    const res = await h.fetch(await vectorRequest(vector("V5")));
    expect(res.status).toBe(200);
  });

  it("T7: upper case nonce is refused as malformed", async () => {
    const h = harness();
    const res = await h.fetch(await vectorRequest(vector("V6")));
    expect(res.status).toBe(401);
    expect(await json(res)).toMatchObject({ error: "auth_failed", message: "malformed headers" });
  });

  it("T8: /v1/check with an empty body and with {} both verify", async () => {
    const h = harness();
    const empty = await h.fetch(await signedRequest({ path: "/v1/check", body: "" }));
    expect(empty.status).toBe(200);
    const object = await h.fetch(await signedRequest({ path: "/v1/check", body: "{}" }));
    expect(object.status).toBe(200);
  });

  it("T8: /v1/check ignores a body that is not JSON", async () => {
    const h = harness();
    const res = await h.fetch(await signedRequest({ path: "/v1/check", body: "not json" }));
    expect(res.status).toBe(200);
  });
});

describe("size (cases 8, 11)", () => {
  it("T9: Content-Length over the limit gives 413 without reading the body", async () => {
    const h = harness({ MAX_BODY_BYTES: "1024" });
    let pulled = 0;
    const stream = new ReadableStream<Uint8Array>({
      pull(controller) {
        pulled++;
        controller.enqueue(new Uint8Array(512));
        if (pulled > 4) {
          controller.close();
        }
      },
    });
    const template = await signedRequest({ body: "{}" });
    const headers = new Headers(template.headers);
    headers.set("content-length", "2048");
    const req = new Request("https://gateway.example/v1/send", { method: "POST", body: stream, headers });
    const res = await h.fetch(req);
    expect(res.status).toBe(413);
    expect((await json(res)).error).toBe("body_too_large");
    expect(req.bodyUsed).toBe(false);
  });

  it("T10: a streamed body over the limit with no length gives 413", async () => {
    const h = harness({ MAX_BODY_BYTES: "1024" });
    let sent = 0;
    const stream = new ReadableStream<Uint8Array>({
      pull(controller) {
        sent += 400;
        controller.enqueue(new Uint8Array(400).fill(0x20));
        if (sent >= 1_000_000) {
          controller.close();
        }
      },
    });
    const template = await signedRequest({ body: "{}" });
    const headers = new Headers(template.headers);
    headers.delete("content-length");
    const res = await h.fetch(
      new Request("https://gateway.example/v1/send", { method: "POST", body: stream, headers }),
    );
    expect(res.status).toBe(413);
    // Reading stopped soon after the cap, not at the end of the stream. The
    // runtime pumps the request body through its own buffer, which may pull
    // ahead of the reader under load, so the bound leaves room for that while
    // staying far below the 1,000,000 bytes the source would produce.
    expect(sent).toBeLessThan(256 * 1024);
  });

  it("T56: a body exactly at the limit is accepted; one byte over is refused", async () => {
    const h = harness({ MAX_BODY_BYTES: "1024" });
    const base = JSON.stringify(message({ text: "" }));
    const pad = 1024 - new TextEncoder().encode(base).byteLength;
    const exact = JSON.stringify(message({ text: "x".repeat(pad) }));
    expect(new TextEncoder().encode(exact).byteLength).toBe(1024);
    const ok = await h.fetch(await signedRequest({ body: exact }));
    expect(ok.status).toBe(200);
    const over = JSON.stringify(message({ text: "x".repeat(pad + 1) }));
    const refused = await h.fetch(await signedRequest({ body: over }));
    expect(refused.status).toBe(413);
  });

  it("measures size in bytes, not characters", async () => {
    const h = harness({ MAX_BODY_BYTES: "1024" });
    // 400 three-byte characters: 400 characters but 1200 bytes.
    const body = JSON.stringify(message({ text: "न".repeat(400) }));
    const res = await h.fetch(await signedRequest({ body }));
    expect(res.status).toBe(413);
  });
});

describe("order of checks (cases 9 to 16)", () => {
  it("T11: a bad signature on invalid JSON gives 401, not 400, and leaves the store untouched", async () => {
    const h = harness();
    const nonce = randomNonce();
    const bad = await h.fetch(await signedRequest({ body: "{", nonce, signature: "0".repeat(64) }));
    expect(bad.status).toBe(401);
    expect((await json(bad)).error).toBe("auth_failed");
    // The same timestamp and nonce, correctly signed, are still fresh.
    const good = await h.fetch(await signedRequest({ body: JSON.stringify(message()), nonce }));
    expect(good.status).toBe(200);
  });

  it.each([
    ["unset", undefined],
    ["31 bytes", "x".repeat(31)],
  ])("T12: secret %s gives 503 misconfigured", async (_label, secret) => {
    const h = harness({ GATEWAY_SECRET: secret });
    const res = await h.fetch(
      await signedRequest({ body: JSON.stringify(message()), secret: "x".repeat(31) }),
    );
    expect(res.status).toBe(503);
    expect((await json(res)).error).toBe("misconfigured");
    expect(logLines.some((l) => l.includes('"outcome":"misconfigured"'))).toBe(true);
  });

  it("T12: a missing nonce store binding gives 503 misconfigured", async () => {
    const h = harness({ NONCE_STORE: undefined });
    const res = await h.fetch(await signedRequest({ body: JSON.stringify(message()) }));
    expect(res.status).toBe(503);
    expect((await json(res)).error).toBe("misconfigured");
  });

  it("T12: a 32-byte secret is enough", async () => {
    const secret = "k".repeat(32);
    const h = harness({ GATEWAY_SECRET: secret });
    const res = await h.fetch(await signedRequest({ body: JSON.stringify(message()), secret }));
    expect(res.status).toBe(200);
  });

  it.each([
    ["301 s old", T0 - 301],
    ["301 s ahead", T0 + 301],
  ])("T13: a timestamp %s gives 401 stale_timestamp with server_time", async (_label, ts) => {
    const h = harness();
    const res = await h.fetch(
      await signedRequest({ body: JSON.stringify(message()), timestamp: String(ts) }),
    );
    expect(res.status).toBe(401);
    expect(await json(res)).toMatchObject({ error: "stale_timestamp", server_time: T0 });
  });

  it("T14: a stale timestamp with a bad signature gives auth_failed and no clock", async () => {
    const h = harness();
    const res = await h.fetch(
      await signedRequest({ body: "{}", timestamp: String(T0 - 5000), signature: "a".repeat(64) }),
    );
    expect(res.status).toBe(401);
    const body = await json(res);
    expect(body.error).toBe("auth_failed");
    expect(body).not.toHaveProperty("server_time");
  });

  it("T55: a timestamp exactly 300 s off is accepted; 301 is refused", async () => {
    for (const offset of [-300, 300]) {
      const h = harness();
      const res = await send(h, message(), { timestamp: String(T0 + offset) });
      expect(res.status).toBe(200);
    }
    for (const offset of [-301, 301]) {
      const h = harness();
      const res = await send(h, message(), { timestamp: String(T0 + offset) });
      expect(res.status).toBe(401);
    }
  });

  it("uses MAX_SKEW_SECONDS when set", async () => {
    const h = harness({ MAX_SKEW_SECONDS: "60" });
    expect((await send(h, message(), { timestamp: String(T0 - 60) })).status).toBe(200);
    expect((await send(h, message(), { timestamp: String(T0 - 61) })).status).toBe(401);
  });
});

describe("key rotation (cases 43 to 48)", () => {
  it("T44: a request signed with the next secret verifies; the log and check say next", async () => {
    const h = harness({ GATEWAY_SECRET_NEXT: SECRET_B });
    const res = await h.fetch(await vectorRequest(vector("V7")));
    expect(res.status).toBe(200);
    expect(logLines.at(-1)).toContain('"key_slot":"next"');
    const check = await h.fetch(await signedRequest({ path: "/v1/check", secret: SECRET_B }));
    expect((await json(check)).key_slot).toBe("next");
  });

  it("T45: a next secret shorter than 32 bytes is ignored", async () => {
    const shortNext = "short-next-secret";
    const h = harness({ GATEWAY_SECRET_NEXT: shortNext });
    const res = await h.fetch(await signedRequest({ body: JSON.stringify(message()), secret: shortNext }));
    expect(res.status).toBe(401);
    const current = await h.fetch(await signedRequest({ body: JSON.stringify(message()) }));
    expect(current.status).toBe(200);
  });

  it("T65: rotation sequence: the client key verifies at every step", async () => {
    const h = harness();
    const check = async (secret: string) =>
      h.fetch(await signedRequest({ path: "/v1/check", secret })).then((r) => json(r));

    // 1. Current only (A).
    expect(await check(SECRET_A)).toMatchObject({ ok: true, key_slot: "current" });
    // 2. New key B added as next. Both verify; the client still on A works.
    h.env.GATEWAY_SECRET_NEXT = SECRET_B;
    expect(await check(SECRET_A)).toMatchObject({ key_slot: "current" });
    // 3. Client moves to B: slot next.
    expect(await check(SECRET_B)).toMatchObject({ key_slot: "next" });
    // 4. B promoted to current, next removed: slot current.
    h.env.GATEWAY_SECRET = SECRET_B;
    delete h.env.GATEWAY_SECRET_NEXT;
    expect(await check(SECRET_B)).toMatchObject({ key_slot: "current" });
    // The old key no longer verifies.
    expect(await check(SECRET_A)).toMatchObject({ ok: false, error: "auth_failed" });
  });

  it("T65: emergency rotation: the old key gives 401 at once", async () => {
    const h = harness();
    expect((await send(h, message())).status).toBe(200);
    h.env.GATEWAY_SECRET = SECRET_B;
    const res = await send(h, message());
    expect(res.status).toBe(401);
    expect((await json(res)).error).toBe("auth_failed");
  });

  it("T67: a secret with non-ASCII characters verifies when both sides use its UTF-8 bytes", async () => {
    const secret = "clé-secrète-नमस्ते-0123456789abcdef";
    const h = harness({ GATEWAY_SECRET: secret });
    const res = await h.fetch(await signedRequest({ body: JSON.stringify(message()), secret }));
    expect(res.status).toBe(200);
  });
});

describe("failure modes (cases 60, 61)", () => {
  it("T53: a thrown error elsewhere gives 500 internal_error with no detail", async () => {
    const h = harness();
    Object.defineProperty(h.env, "ENABLED_DOMAINS", {
      get() {
        throw new RangeError("boom reader@example.com");
      },
    });
    const res = await h.fetch(await signedRequest({ body: JSON.stringify(message()) }));
    expect(res.status).toBe(500);
    const text = await res.text();
    expect(JSON.parse(text)).toEqual({ ok: false, error: "internal_error", message: "internal error" });
    expect(text).not.toContain("boom");
    const line = logLines.at(-1) ?? "";
    expect(line).toContain('"error_name":"RangeError"');
    expect(line).not.toContain("boom");
  });

  it("T54: every JSON reply has cache-control: no-store", async () => {
    const h = harness({ GATEWAY_SECRET_NEXT: SECRET_B });
    const replies = [
      await send(h, message()),
      await send(h, message({ to: [] })),
      await h.fetch(await signedRequest({ signature: "0".repeat(64) })),
      await h.fetch(await signedRequest({ path: "/v1/check" })),
      await h.fetch(await signedRequest({ path: "/v1/batch", body: "{}" })),
      await send(h, message(), { timestamp: String(T0 - 1000) }),
    ];
    for (const res of replies) {
      expect(res.headers.get("content-type")).toBe("application/json");
      expect(res.headers.get("cache-control")).toBe("no-store");
    }
  });
});

describe("check route (case 55)", () => {
  it("T52: check sends nothing and returns the documented fields", async () => {
    const h = harness({ ENABLED_DOMAINS: `${DOMAIN}, other.example.org` });
    const res = await h.fetch(await signedRequest({ path: "/v1/check", body: "{}" }));
    expect(res.status).toBe(200);
    expect(await json(res)).toEqual({
      ok: true,
      check: true,
      key_slot: "current",
      server_time: T0,
      enabled_domains: [DOMAIN, "other.example.org"],
      max_recipients: 10,
      batch_max: 10,
      batch_concurrency: 4,
      max_body_bytes: 4194304,
      max_skew_seconds: 300,
      version: "0.1.0",
    });
    expect(h.binding.calls).toHaveLength(0);
  });
});
