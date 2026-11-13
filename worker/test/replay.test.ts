import { env } from "cloudflare:workers";
import { runDurableObjectAlarm, runInDurableObject } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import type { NonceStore } from "../src/index";
import { SWEEP_PER_CLAIM } from "../src/nonce-store";
import { SECRET_B, T0, harness, json, message, randomNonce, send, signedRequest } from "./helpers";

const namespace = env.NONCE_STORE as unknown as DurableObjectNamespace<NonceStore>;

function store(): DurableObjectStub<NonceStore> {
  return namespace.get(namespace.idFromName(`direct-${crypto.randomUUID()}`));
}

async function rows(stub: DurableObjectStub<NonceStore>): Promise<{ k: string; claimed_at: number }[]> {
  return runInDurableObject(stub, (_instance, state) =>
    state.storage.sql
      .exec<{ k: string; claimed_at: number }>("SELECT k, claimed_at FROM nonces ORDER BY k")
      .toArray(),
  );
}

function key(ts: number, nonce = randomNonce()): string {
  return `${String(ts)}.${nonce}`;
}

describe("replay (case 15)", () => {
  it("T15: the same request twice gives 200 then 409 replay", async () => {
    const h = harness();
    const req = await signedRequest({ body: JSON.stringify(message()) });
    const first = await h.fetch(req.clone());
    expect(first.status).toBe(200);
    const second = await h.fetch(req);
    expect(second.status).toBe(409);
    expect((await json(second)).error).toBe("replay");
    expect(h.binding.calls).toHaveLength(1);
  });

  it("T16: a nonce store that throws gives 503 nonce_store_unavailable and no send", async () => {
    const broken = {
      idFromName: () => ({}),
      get: () => ({
        claim: () => Promise.reject(new Error("store down")),
      }),
    } as unknown as DurableObjectNamespace;
    const h = harness({ NONCE_STORE: broken });
    const res = await send(h, message());
    expect(res.status).toBe(503);
    expect((await json(res)).error).toBe("nonce_store_unavailable");
    expect(h.binding.calls).toHaveLength(0);
  });

  it("T17: a request refused as bad_recipient spends its nonce", async () => {
    const h = harness();
    const req = await signedRequest({ body: JSON.stringify(message({ to: [] })) });
    const first = await h.fetch(req.clone());
    expect(first.status).toBe(400);
    expect((await json(first)).error).toBe("bad_recipient");
    const again = await h.fetch(req);
    expect(again.status).toBe(409);
  });

  it("T46: a request signed with the old key is still a replay after rotation", async () => {
    const h = harness();
    const req = await signedRequest({ body: JSON.stringify(message()) });
    expect((await h.fetch(req.clone())).status).toBe(200);
    h.env.GATEWAY_SECRET_NEXT = SECRET_B;
    expect((await h.fetch(req.clone())).status).toBe(409);
  });

  it("T57: the same nonce with a different timestamp is a different key", async () => {
    const h = harness();
    const nonce = randomNonce();
    expect((await send(h, message(), { nonce, timestamp: String(T0) })).status).toBe(200);
    expect((await send(h, message(), { nonce, timestamp: String(T0 - 1) })).status).toBe(200);
    expect((await send(h, message(), { nonce, timestamp: String(T0) })).status).toBe(409);
  });

  it("T18/T96: two identical requests at the same moment: exactly one is sent", async () => {
    const h = harness();
    const req = await signedRequest({ body: JSON.stringify(message()) });
    const [a, b] = await Promise.all([h.fetch(req.clone()), h.fetch(req.clone())]);
    expect([a.status, b.status].sort()).toEqual([200, 409]);
    expect(h.binding.calls).toHaveLength(1);
  });
});

describe("nonce store (cases 17 to 22)", () => {
  it("T18: concurrent claims of one key give exactly one true", async () => {
    const stub = store();
    const k = key(T0);
    const results = await Promise.all(Array.from({ length: 20 }, () => stub.claim(k, T0, 600)));
    expect(results.filter(Boolean)).toHaveLength(1);
  });

  it("T19: after a claim, keys older than 2x skew are deleted, at most 128 per claim", async () => {
    const stub = store();
    const old = T0 - 601;
    for (let i = 0; i < 200; i++) {
      // Old claims, made when they were fresh.
      expect(await stub.claim(key(old - (i % 5)), old, 600)).toBe(true);
    }
    // Each of those claims swept nothing: all 200 are inside their own window.
    expect(await rows(stub)).toHaveLength(200);

    expect(await stub.claim(key(T0), T0, 600)).toBe(true);
    expect(await rows(stub)).toHaveLength(200 - SWEEP_PER_CLAIM + 1);

    expect(await stub.claim(key(T0), T0, 600)).toBe(true);
    expect(await rows(stub)).toHaveLength(2);
  });

  it("T20: keys newer than 2x skew survive the sweep", async () => {
    const stub = store();
    const keep = [key(T0 - 600), key(T0 - 300), key(T0 - 1)];
    const drop = key(T0 - 601);
    expect(await stub.claim(drop, T0 - 601, 600)).toBe(true);
    for (const k of keep) {
      expect(await stub.claim(k, T0 - 1, 600)).toBe(true);
    }
    expect(await stub.claim(key(T0), T0, 600)).toBe(true);
    const left = (await rows(stub)).map((r) => r.k);
    expect(left).not.toContain(drop);
    for (const k of keep) {
      expect(left).toContain(k);
    }
  });

  it("T21: the stored value contains no message data", async () => {
    const h = harness();
    const nonce = randomNonce();
    const res = await send(h, message({ subject: "SECRET-SUBJECT", text: "SECRET-BODY" }), { nonce });
    expect(res.status).toBe(200);
    // Read every row of every table in a store used through the handler.
    const stub = store();
    await stub.claim(`${String(T0)}.${nonce}`, T0, 600);
    const dump = await runInDurableObject(stub, (_instance, state) => {
      const tables = state.storage.sql
        .exec<{
          name: string;
        }>("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE '_cf_%'")
        .toArray();
      return tables.map((t) => state.storage.sql.exec(`SELECT * FROM "${t.name}"`).toArray());
    });
    const text = JSON.stringify(dump);
    expect(text).toContain(nonce);
    expect(text).not.toContain("SECRET");
    expect(text).not.toContain("@");
    const [nonces] = dump;
    expect(Object.keys(nonces?.[0] ?? {}).sort()).toEqual(["claimed_at", "k"]);
  });

  it("T63: with no traffic, keys stay until the alarm; the alarm sweeps old keys, and so does the next claim", async () => {
    const stub = store();
    const realNow = Math.floor(Date.now() / 1000);
    // Two claims made at a time when both were fresh.
    expect(await stub.claim(key(realNow - 700), realNow - 700, 600)).toBe(true);
    expect(await stub.claim(key(realNow - 10), realNow - 700, 600)).toBe(true);
    // No further traffic: nothing deletes them before the alarm runs.
    expect(await rows(stub)).toHaveLength(2);
    const alarmSet = await runInDurableObject(stub, (_i, state) => state.storage.getAlarm());
    expect(alarmSet).not.toBeNull();

    // The alarm removes keys older than the window by the object's clock.
    expect(await runDurableObjectAlarm(stub)).toBe(true);
    const left = await rows(stub);
    expect(left).toHaveLength(1);
    expect(left[0]?.k.startsWith(String(realNow - 10))).toBe(true);
    // Keys remain, so the alarm is scheduled again.
    expect(await runInDurableObject(stub, (_i, state) => state.storage.getAlarm())).not.toBeNull();
  });

  it("the alarm stops rescheduling once the store is empty", async () => {
    const stub = store();
    const realNow = Math.floor(Date.now() / 1000);
    expect(await stub.claim(key(realNow - 700), realNow - 700, 600)).toBe(true);
    expect(await runDurableObjectAlarm(stub)).toBe(true);
    expect(await rows(stub)).toHaveLength(0);
    expect(await runInDurableObject(stub, (_i, state) => state.storage.getAlarm())).toBeNull();
  });
});
