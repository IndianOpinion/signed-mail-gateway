import { describe, expect, it } from "vitest";
import wranglerToml from "../wrangler.toml?raw";
import { isValidEmail, parseAddress } from "../src/address";
import { filterHeaders } from "../src/headers";
import { mapProviderError, maskProviderMessage } from "../src/provider";
import { DOMAIN, FROM, harness, json, message, providerError, send, signedRequest } from "./helpers";
import { logLines } from "./setup";

describe("sender domains (cases 23 to 27)", () => {
  it("T22: a From on an unmapped domain gives 403 domain_unknown", async () => {
    const h = harness();
    const res = await send(h, message({ from: "news@other.example.org" }));
    expect(res.status).toBe(403);
    expect(await json(res)).toMatchObject({ error: "domain_unknown", domain: "other.example.org" });
    expect(h.binding.calls).toHaveLength(0);
  });

  it("T23: a mapped domain whose binding is absent gives 403 domain_unknown and a log line", async () => {
    const h = harness({
      SENDERS: JSON.stringify({ [DOMAIN]: "MAIL_SENDER", "two.example.org": "MISSING_BINDING" }),
      ENABLED_DOMAINS: `${DOMAIN},two.example.org`,
    });
    const res = await send(h, message({ from: "news@two.example.org" }));
    expect(res.status).toBe(403);
    expect((await json(res)).error).toBe("domain_unknown");
    expect(
      logLines.some((l) => l.includes('"outcome":"misconfigured_binding"') && l.includes("two.example.org")),
    ).toBe(true);
  });

  it("T24: a mapped but not enabled domain gives 403 domain_disabled", async () => {
    const h = harness({ ENABLED_DOMAINS: "other.example.org" });
    const res = await send(h, message());
    expect(res.status).toBe(403);
    expect((await json(res)).error).toBe("domain_disabled");
  });

  it("T25: the domain match is case-insensitive", async () => {
    const h = harness({
      SENDERS: JSON.stringify({ "Mail.Example.ORG": "MAIL_SENDER" }),
      ENABLED_DOMAINS: "MAIL.example.org",
    });
    const res = await send(h, message({ from: "News@MAIL.Example.Org" }));
    expect(res.status).toBe(200);
    expect(await json(res)).toMatchObject({ domain: "mail.example.org" });
  });

  it("accepts SENDERS given as an object (a TOML table) and maps several domains to one binding", async () => {
    const h = harness({
      SENDERS: { [DOMAIN]: "MAIL_SENDER", "two.example.org": "MAIL_SENDER" },
      ENABLED_DOMAINS: `${DOMAIN},two.example.org`,
    });
    expect((await send(h, message({ from: "a@two.example.org" }))).status).toBe(200);
    expect((await send(h, message())).status).toBe(200);
  });

  it("T64: the sample wrangler.toml lists allowed sender addresses on every binding", () => {
    const blocks = wranglerToml.split(/^\[\[send_email\]\]\s*$/m).slice(1);
    expect(blocks.length).toBeGreaterThan(0);
    for (const block of blocks) {
      const body = block.split(/^\[/m)[0] ?? "";
      expect(body).toMatch(/^allowed_sender_addresses\s*=\s*\[[^\]]*"[^"]+"/m);
    }
  });

  it("T64: top-level keys in wrangler.toml come before the first table", () => {
    const firstTable = wranglerToml.search(/^\[/m);
    const head = wranglerToml.slice(0, firstTable);
    for (const keyName of ["name", "main", "compatibility_date"]) {
      expect(head).toMatch(new RegExp(`^${keyName}\\s*=`, "m"));
    }
    expect(wranglerToml).toMatch(/^compatibility_date = "2026-1[0-2]-\d\d"$/m);
    expect(wranglerToml).toMatch(/new_sqlite_classes = \["NonceStore"\]/);
  });

  it("T64: the From is passed as given and a refusal comes back as a provider error, never a rewrite", async () => {
    const h = harness();
    h.binding.failWith = providerError("E_VALIDATION_ERROR", "invalid sender");
    const res = await send(h, message({ from: "other-sender@mail.example.org" }));
    expect(h.binding.calls).toHaveLength(1);
    expect((h.binding.calls[0] as { from: unknown }).from).toBe("other-sender@mail.example.org");
    expect(res.status).toBe(400);
    expect(await json(res)).toMatchObject({ error: "provider_error", provider_code: "E_VALIDATION_ERROR" });
  });

  it("a binding error that says the sender is not allowed is reported as sender_not_allowed", async () => {
    const h = harness();
    h.binding.failWith = providerError(
      "E_VALIDATION_ERROR",
      "sender other@mail.example.org is not allowed by allowed_sender_addresses",
    );
    const res = await send(h, message({ from: "other@mail.example.org" }));
    expect(res.status).toBe(403);
    const body = await json(res);
    expect(body).toMatchObject({
      ok: false,
      error: "sender_not_allowed",
      provider_code: "E_VALIDATION_ERROR",
    });
    expect(String(body.message)).not.toContain("@");
  });
});

describe("message validation (cases 28 to 38)", () => {
  it.each([
    ["missing to", { to: undefined }],
    ["empty to array", { to: [] }],
    ["one bad address in bcc", { bcc: ["ok@example.com", "not-an-address"] }],
  ] as [string, Record<string, unknown>][])("T26: %s gives 400 bad_recipient", async (_label, extra) => {
    const h = harness();
    const m = message(extra);
    if ("to" in extra && extra.to === undefined) {
      delete m.to;
    }
    const res = await send(h, m);
    expect(res.status).toBe(400);
    expect((await json(res)).error).toBe("bad_recipient");
    expect(h.binding.calls).toHaveLength(0);
  });

  it("T27: bare, Name <a@b>, quoted name and object forms all parse", () => {
    expect(parseAddress("a@example.org")).toEqual({ email: "a@example.org" });
    expect(parseAddress("Ann Example <a@example.org>")).toEqual({
      email: "a@example.org",
      name: "Ann Example",
    });
    expect(parseAddress('"Example, Ann" <a@example.org>')).toEqual({
      email: "a@example.org",
      name: "Example, Ann",
    });
    expect(parseAddress({ email: "a@example.org", name: "Ann" })).toEqual({
      email: "a@example.org",
      name: "Ann",
    });
    expect(parseAddress({ email: "a@example.org" })).toEqual({ email: "a@example.org" });
    expect(parseAddress("<a@example.org>")).toEqual({ email: "a@example.org" });
  });

  it("T28: an address with CR, over 254 characters, or no dot in the domain is invalid", () => {
    expect(parseAddress("a@example.org\r")).toBeNull();
    expect(parseAddress("Name <a@example.org\nBcc: x@example.org>")).toBeNull();
    expect(parseAddress({ email: "a@exa\rmple.org" })).toBeNull();
    const long = `${"a".repeat(64)}@${"b".repeat(60)}.${"c".repeat(60)}.${"d".repeat(60)}.${"e".repeat(10)}.org`;
    expect(long.length).toBeGreaterThan(254);
    expect(isValidEmail(long)).toBe(false);
    expect(isValidEmail("a@localhost")).toBe(false);
    expect(isValidEmail("a b@example.org")).toBe(false);
    expect(isValidEmail("a..b@example.org")).toBe(false);
    expect(isValidEmail("a@-example.org")).toBe(false);
    expect(isValidEmail("first.last+tag@sub.example.org")).toBe(true);
  });

  it("T29/T102: display names: over 100 characters cut, tabs become spaces, spaces only means no name", () => {
    expect(parseAddress(`${"n".repeat(150)} <a@example.org>`)).toEqual({
      email: "a@example.org",
      name: "n".repeat(100),
    });
    expect(parseAddress({ email: "a@example.org", name: "Ann\tExample" })).toEqual({
      email: "a@example.org",
      name: "Ann Example",
    });
    expect(parseAddress({ email: "a@example.org", name: "   " })).toEqual({ email: "a@example.org" });
    expect(parseAddress('"   " <a@example.org>')).toEqual({ email: "a@example.org" });
  });

  it("T30: 11 recipients across to, cc and bcc gives 400 too_many_recipients", async () => {
    const h = harness();
    const addr = (i: number) => `r${String(i)}@example.com`;
    const res = await send(
      h,
      message({ to: [0, 1, 2, 3].map(addr), cc: [4, 5, 6].map(addr), bcc: [7, 8, 9, 10].map(addr) }),
    );
    expect(res.status).toBe(400);
    expect((await json(res)).error).toBe("too_many_recipients");
    const ten = await send(
      h,
      message({ to: [0, 1, 2, 3].map(addr), cc: [4, 5, 6].map(addr), bcc: [7, 8, 9].map(addr) }),
    );
    expect(ten.status).toBe(200);
  });

  it("T31: bad from and bad reply_to give their codes", async () => {
    const h = harness();
    expect((await json(await send(h, message({ from: "nobody" })))).error).toBe("bad_from");
    expect((await json(await send(h, message({ from: undefined })))).error).toBe("bad_from");
    expect((await json(await send(h, message({ reply_to: "not an address" })))).error).toBe("bad_reply_to");
    expect((await send(h, message({ reply_to: "" }))).status).toBe(200);
  });

  it("T32: subject: CRLF folded; empty and 999 characters refused; 998 accepted", async () => {
    const h = harness();
    expect((await send(h, message({ subject: "Line one\r\n\r\nline two  " }))).status).toBe(200);
    expect((h.binding.calls[0] as { subject: string }).subject).toBe("Line one line two");
    expect((await json(await send(h, message({ subject: "" })))).error).toBe("bad_subject");
    expect((await json(await send(h, message({ subject: "\r\n " })))).error).toBe("bad_subject");
    expect((await json(await send(h, message({ subject: "s".repeat(999) })))).error).toBe("bad_subject");
    expect((await send(h, message({ subject: "s".repeat(998) }))).status).toBe(200);
    expect((await json(await send(h, message({ subject: 5 })))).error).toBe("bad_subject");
  });

  it("T33: no text and no html gives no_body; html only is accepted", async () => {
    const h = harness();
    expect((await json(await send(h, message({ text: "" })))).error).toBe("no_body");
    expect((await json(await send(h, message({ text: undefined })))).error).toBe("no_body");
    const res = await send(h, message({ text: undefined, html: "<p>Hi</p>" }));
    expect(res.status).toBe(200);
    expect(h.binding.calls[0]).not.toHaveProperty("text");
  });

  it.each([["[]"], ['"x"'], ["{"], ["null"]])("T34: body %s gives 400 bad_json", async (body) => {
    const h = harness();
    const res = await h.fetch(await signedRequest({ body }));
    expect(res.status).toBe(400);
    expect((await json(res)).error).toBe("bad_json");
  });

  it("T35: an attachments field gives 400 attachments_unsupported; an empty array is accepted", async () => {
    const h = harness();
    const res = await send(h, message({ attachments: [{ filename: "a.txt", content: "eA==" }] }));
    expect(res.status).toBe(400);
    expect((await json(res)).error).toBe("attachments_unsupported");
    expect((await json(await send(h, message({ attachments: "x" })))).error).toBe("attachments_unsupported");
    expect((await send(h, message({ attachments: [] }))).status).toBe(200);
    expect((await send(h, message({ attachments: null }))).status).toBe(200);
  });

  it("T36: header filter", () => {
    const { kept, dropped } = filterHeaders(
      {
        "List-Id": "<news.example.org>",
        "X-Custom": "  yes  ",
        "X-SES-Tags": "a=b",
        Bcc: "x@example.com",
        "X-Line": "a\nb",
        "X-Big": "v".repeat(2049),
        "X-Max": "v".repeat(2048),
        "Bad Name": "v",
        "X-Empty": "   ",
        "X-Number": 5,
      },
      ["x-ses-"],
    );
    expect(kept).toEqual({ "List-Id": "<news.example.org>", "X-Custom": "yes", "X-Max": "v".repeat(2048) });
    expect(dropped).toEqual(["X-SES-Tags", "Bcc", "X-Line", "X-Big", "(invalid)", "X-Empty", "X-Number"]);
  });

  it("T36: dropped headers are listed in the reply and never fail the message", async () => {
    const h = harness();
    const res = await send(
      h,
      message({ headers: { "X-SES-Tags": "SECRET-HEADER-VALUE", "Bad Name!": "v" } }),
    );
    expect(res.status).toBe(200);
    expect(await json(res)).toMatchObject({ dropped_headers: ["X-SES-Tags", "(invalid)"] });
    expect(h.binding.calls[0]).not.toHaveProperty("headers");
  });

  it("T36: STRIP_HEADER_PREFIXES replaces the default list", async () => {
    const h = harness({ STRIP_HEADER_PREFIXES: "X-Internal-" });
    const res = await send(h, message({ headers: { "X-SES-Tags": "a", "X-Internal-Id": "b" } }));
    expect(await json(res)).toMatchObject({ dropped_headers: ["X-Internal-Id"] });
    expect((h.binding.calls[0] as { headers: unknown }).headers).toEqual({ "X-SES-Tags": "a" });
  });

  it("drops allowlisted headers past the provider's limit of 20", () => {
    const raw: Record<string, string> = {};
    const names = [
      "In-Reply-To",
      "References",
      "Thread-Index",
      "Thread-Topic",
      "List-Unsubscribe",
      "List-Id",
      "List-Archive",
      "List-Help",
      "List-Owner",
      "List-Post",
      "List-Subscribe",
      "Precedence",
      "Auto-Submitted",
      "Content-Language",
      "Keywords",
      "Comments",
      "Importance",
      "Priority",
      "Sensitivity",
      "Organization",
      "Expires",
    ];
    for (const name of names) {
      raw[name] = name === "List-Unsubscribe" ? "<https://example.org/u>" : "v";
    }
    const { kept, dropped } = filterHeaders(raw, []);
    expect(Object.keys(kept)).toHaveLength(20);
    expect(dropped).toEqual(["Expires"]);
  });

  it("T37: List-Unsubscribe-Post needs an https List-Unsubscribe", () => {
    const post = { "List-Unsubscribe-Post": "List-Unsubscribe=One-Click" };
    expect(
      filterHeaders(
        { "List-Unsubscribe": "<mailto:u@example.org>, <https://example.org/u?id=1>", ...post },
        [],
      ).kept,
    ).toHaveProperty("List-Unsubscribe-Post");
    const mailtoOnly = filterHeaders({ "List-Unsubscribe": "<mailto:u@example.org>", ...post }, []);
    expect(mailtoOnly.kept).not.toHaveProperty("List-Unsubscribe-Post");
    expect(mailtoOnly.kept).toHaveProperty("List-Unsubscribe");
    expect(mailtoOnly.dropped).toEqual(["List-Unsubscribe-Post"]);
    expect(filterHeaders(post, []).dropped).toEqual(["List-Unsubscribe-Post"]);
    expect(
      filterHeaders({ "List-Unsubscribe": "<https://example.org/u>", "List-Unsubscribe-Post": "yes" }, [])
        .dropped,
    ).toEqual(["List-Unsubscribe-Post"]);
  });

  it("T38: the binding gets a bare string for a nameless address and an object for a named one", async () => {
    const h = harness();
    await send(
      h,
      message({
        to: ["a@example.com", "Bee <b@example.com>"],
        cc: { email: "c@example.com", name: "Cee" },
        bcc: "d@example.com",
        reply_to: "Desk <desk@example.org>",
        html: "<p>Hi</p>",
        headers: { "List-Id": "<news.example.org>" },
      }),
    );
    expect(h.binding.calls[0]).toEqual({
      from: { email: FROM, name: "Example News" },
      to: ["a@example.com", { email: "b@example.com", name: "Bee" }],
      cc: [{ email: "c@example.com", name: "Cee" }],
      bcc: ["d@example.com"],
      replyTo: { email: "desk@example.org", name: "Desk" },
      subject: "Hello",
      text: "Hello, reader.",
      html: "<p>Hi</p>",
      headers: { "List-Id": "<news.example.org>" },
    });
  });

  it("T39: the success reply shape carries the binding's id", async () => {
    const h = harness();
    const res = await send(h, message({ headers: { "X-SES-Tags": "a" } }));
    expect(res.status).toBe(200);
    expect(await json(res)).toEqual({
      ok: true,
      id: "msg-1",
      domain: DOMAIN,
      dropped_headers: ["X-SES-Tags"],
    });
  });

  it("T59: a body with a UTF-8 BOM and a valid signature gives bad_json", async () => {
    const h = harness();
    const json8 = new TextEncoder().encode(JSON.stringify(message()));
    const body = new Uint8Array(json8.byteLength + 3);
    body.set([0xef, 0xbb, 0xbf]);
    body.set(json8, 3);
    const res = await h.fetch(await signedRequest({ body }));
    expect(res.status).toBe(400);
    expect((await json(res)).error).toBe("bad_json");
  });

  it("invalid UTF-8 gives bad_json", async () => {
    const h = harness();
    const res = await h.fetch(await signedRequest({ body: new Uint8Array([0x7b, 0xff, 0x7d]) }));
    expect((await json(res)).error).toBe("bad_json");
  });

  it.each([[[]], [{}], [["X-A", "b"]], ["X-A: b"]])(
    "T60: headers %j: nothing kept, nothing dropped",
    async (headers) => {
      const h = harness();
      const res = await send(h, message({ headers }));
      expect(res.status).toBe(200);
      expect((await json(res)).dropped_headers).toEqual([]);
      expect(h.binding.calls[0]).not.toHaveProperty("headers");
    },
  );

  it("T68: a single to string with a comma gives 400 bad_recipient", async () => {
    const h = harness();
    const res = await send(h, message({ to: "a@example.com, b@example.com" }));
    expect(res.status).toBe(400);
    expect((await json(res)).error).toBe("bad_recipient");
  });

  it("T99: Unicode in subject and names is passed as given", async () => {
    const h = harness();
    await send(h, message({ from: "समाचार <news@mail.example.org>", subject: "नमस्ते" }));
    expect(h.binding.calls[0]).toMatchObject({
      from: { email: FROM, name: "समाचार" },
      subject: "नमस्ते",
    });
  });
});

describe("provider errors (cases 40 to 42)", () => {
  it("T40: an address in the binding's error message is masked", async () => {
    const h = harness();
    h.binding.failWith = providerError(
      "E_DELIVERY_FAILED",
      "Delivery to reader@example.com failed: mailbox full",
    );
    const res = await send(h, message());
    expect(res.status).toBe(502);
    const body = await json(res);
    expect(body).toEqual({
      ok: false,
      error: "provider_error",
      provider_code: "E_DELIVERY_FAILED",
      message: "Delivery to [address] failed: mailbox full",
      domain: DOMAIN,
    });
  });

  it("T40: masked messages are cut to 300 characters", () => {
    expect(Array.from(maskProviderMessage("é".repeat(400)))).toHaveLength(300);
    expect(maskProviderMessage("<a.b+c@x.example.org>")).toBe("<[address]>");
  });

  it.each([
    ["E_VALIDATION_ERROR", 400],
    ["E_FIELD_MISSING", 400],
    ["E_TOO_MANY_RECIPIENTS", 400],
    ["E_TOO_MANY_ATTACHMENTS", 400],
    ["E_HEADER_NOT_ALLOWED", 400],
    ["E_HEADER_USE_API_FIELD", 400],
    ["E_HEADER_VALUE_INVALID", 400],
    ["E_HEADER_VALUE_TOO_LONG", 400],
    ["E_HEADER_NAME_INVALID", 400],
    ["E_HEADERS_TOO_LARGE", 400],
    ["E_HEADERS_TOO_MANY", 400],
    ["E_RECIPIENT_NOT_ALLOWED", 403],
    ["E_CONTENT_TOO_LARGE", 413],
    ["E_RECIPIENT_SUPPRESSED", 422],
    ["E_SENDER_NOT_VERIFIED", 424],
    ["E_SENDER_DOMAIN_NOT_AVAILABLE", 424],
    ["E_ACCOUNT_SUSPENDED", 424],
    ["E_RATE_LIMIT_EXCEEDED", 429],
    ["E_DAILY_LIMIT_EXCEEDED", 429],
    ["E_DELIVERY_FAILED", 502],
    ["E_INTERNAL_SERVER_ERROR", 503],
    ["E_SOMETHING_NEW", 502],
  ])("T41: %s maps to %i", async (code, status) => {
    const h = harness();
    h.binding.failWith = providerError(code, "refused");
    const res = await send(h, message());
    expect(res.status).toBe(status);
    expect(await json(res)).toMatchObject({ error: "provider_error", provider_code: code });
  });

  it.each([
    ["E_DAILY_LIMIT_EXCEEDED", "refused"],
    ["E_SENDING_QUOTA_REACHED", "refused"],
    ["E_LIMIT", "daily sending limit reached"],
    [undefined, "Your quota is exhausted"],
  ])("T42: code %s with message %j gives 429 E_DAILY_LIMIT_EXCEEDED", async (code, text) => {
    const h = harness();
    h.binding.failWith = providerError(code, text);
    const res = await send(h, message());
    expect(res.status).toBe(429);
    expect(await json(res)).toMatchObject({ provider_code: "E_DAILY_LIMIT_EXCEEDED" });
  });

  it("T43: an error with no code gives 502 E_SEND_FAILED", async () => {
    const h = harness();
    h.binding.failWith = new Error("socket closed");
    const res = await send(h, message());
    expect(res.status).toBe(502);
    expect(await json(res)).toMatchObject({ error: "provider_error", provider_code: "E_SEND_FAILED" });
    expect(mapProviderError("plain string").providerCode).toBe("E_SEND_FAILED");
    expect(mapProviderError(null).providerCode).toBe("E_SEND_FAILED");
  });

  it("a code with odd characters is not echoed", () => {
    expect(mapProviderError({ code: "E_<script>", message: "x" }).providerCode).toBe("E_UNKNOWN");
  });

  it("a recipient error that mentions the sender is not taken for sender_not_allowed", () => {
    expect(
      mapProviderError({ code: "E_RECIPIENT_NOT_ALLOWED", message: "sender not allowed to send here" }),
    ).toMatchObject({
      status: 403,
      error: "provider_error",
      providerCode: "E_RECIPIENT_NOT_ALLOWED",
    });
  });
});
