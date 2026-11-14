/**
 * Reads the request body as raw bytes with a hard cap.
 *
 * A declared `Content-Length` above the cap is refused before a single byte is
 * read. The stream is then read with a running byte count and cancelled as
 * soon as it passes the cap, so a missing or false length cannot get a larger
 * body through and the Worker never buffers more than cap plus one chunk.
 * The chunks are then copied into one ArrayBuffer, returned as a byte view,
 * which is what the signature is computed over.
 */

export type BodyResult = { ok: true; bytes: Uint8Array } | { ok: false };

export async function readBodyCapped(request: Request, maxBytes: number): Promise<BodyResult> {
  const declared = request.headers.get("content-length");
  if (declared !== null && /^\d+$/.test(declared.trim()) && Number(declared.trim()) > maxBytes) {
    return { ok: false };
  }
  if (request.body === null) {
    return { ok: true, bytes: new Uint8Array(0) };
  }

  const reader = request.body.getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;
  for (;;) {
    const step: ReadableStreamReadResult<unknown> = await reader.read();
    if (step.done) {
      break;
    }
    const value = step.value;
    const chunk = value instanceof Uint8Array ? value : new Uint8Array(value as ArrayBuffer);
    total += chunk.byteLength;
    if (total > maxBytes) {
      await reader.cancel().catch(() => undefined);
      return { ok: false };
    }
    chunks.push(chunk);
  }

  const bytes = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return { ok: true, bytes };
}
