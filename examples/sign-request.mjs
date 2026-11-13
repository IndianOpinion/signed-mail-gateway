// Signs a request with Node's built-in crypto and checks the result against
// the shared test vectors. No dependencies.
//
//   node examples/sign-request.mjs            # verify every vector
//   node examples/sign-request.mjs "$SECRET"  # print headers for a /v1/check call

import { createHmac, randomBytes } from "node:crypto";
import { readFileSync } from "node:fs";

export function sign(secret, timestamp, nonce, body) {
  return createHmac("sha256", Buffer.from(secret, "utf8"))
    .update(Buffer.concat([Buffer.from(`${timestamp}.${nonce}.`, "utf8"), Buffer.from(body, "utf8")]))
    .digest("hex");
}

const secret = process.argv[2];
if (secret === undefined) {
  const data = JSON.parse(readFileSync(new URL("../test-vectors/v1.json", import.meta.url), "utf8"));
  let failures = 0;
  for (const v of data.vectors) {
    const expected = sign(data.secrets[v.signed_with], v.timestamp, v.nonce.toLowerCase(), v.body);
    const tampered = v.id.includes("-with-");
    const ok = tampered ? expected !== v.signature.toLowerCase() : expected === v.signature.toLowerCase();
    console.log(`${ok ? "ok  " : "FAIL"} ${v.id}`);
    if (!ok) failures++;
  }
  process.exit(failures === 0 ? 0 : 1);
} else {
  const timestamp = String(Math.floor(Date.now() / 1000)).padStart(10, "0");
  const nonce = randomBytes(16).toString("hex");
  const body = "{}";
  console.log(`X-Gateway-Timestamp: ${timestamp}`);
  console.log(`X-Gateway-Nonce: ${nonce}`);
  console.log(`X-Gateway-Signature: ${sign(secret, timestamp, nonce, body)}`);
  console.log(`body: ${body}`);
}
