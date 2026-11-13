import { describe, expect, it } from "vitest";
import {
  harness,
  json,
  vector,
  vectorRequest,
  vectors,
  SECRET_A,
  SECRET_B,
  signFor,
  type Vector,
} from "./helpers";

const encoder = new TextEncoder();

function secretOf(slot: "A" | "B"): string {
  return slot === "A" ? SECRET_A : SECRET_B;
}

describe("test vectors (section 12.4)", () => {
  it("every signature in the file is the HMAC of its own body", async () => {
    for (const v of vectors.vectors) {
      expect(encoder.encode(v.body).byteLength, v.id).toBe(v.body_bytes);
      const expected = await signFor(
        secretOf(v.signed_with),
        v.timestamp,
        v.nonce.toLowerCase(),
        encoder.encode(v.body),
      );
      const tampered = v.id.includes("-with-");
      if (!tampered) {
        expect(v.signature.toLowerCase(), v.id).toBe(expected);
      } else {
        expect(v.signature.toLowerCase(), v.id).not.toBe(expected);
      }
    }
  });

  it.each(vectors.vectors.map((v): [string, Vector] => [v.id, v]))("T62: %s", async (_id, v) => {
    const h = harness(v.next_secret !== undefined ? { GATEWAY_SECRET_NEXT: secretOf(v.next_secret) } : {});
    h.clock.now = v.clock_at ?? Number(v.timestamp);
    const res = await h.fetch(await vectorRequest(v));
    expect(res.status).toBe(v.expect.status);
    const body = await json(res);
    if (v.expect.error !== undefined) {
      expect(body.error).toBe(v.expect.error);
      expect(h.binding.calls).toHaveLength(0);
    }
    if (v.expect.key_slot !== undefined && v.path === "/v1/check") {
      expect(body.key_slot).toBe(v.expect.key_slot);
    }
    if (v.expect.then !== undefined) {
      const again = await h.fetch(await vectorRequest(v));
      expect(again.status).toBe(v.expect.then.status);
      expect((await json(again)).error).toBe(v.expect.then.error);
      expect(h.binding.calls).toHaveLength(1);
    }
  });

  it("T58: V3a and V3b each verify as sent, send the same subject and text, and fail when swapped", async () => {
    const sent: unknown[] = [];
    for (const id of ["V3a", "V3b"]) {
      const h = harness();
      const v = vector(id);
      const res = await h.fetch(await vectorRequest(v));
      expect(res.status).toBe(200);
      sent.push(h.binding.calls[0]);
    }
    expect(sent[0]).toEqual(sent[1]);
    expect(sent[0]).toMatchObject({ subject: "नमस्ते", text: "Café" });
    for (const id of ["V3a-with-V3b-signature", "V3b-with-V3a-signature"]) {
      const h = harness();
      const v = vector(id);
      const res = await h.fetch(await vectorRequest(v));
      expect(res.status).toBe(401);
      expect(h.binding.calls).toHaveLength(0);
    }
  });
});
