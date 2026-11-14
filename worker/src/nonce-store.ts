import { DurableObject } from "cloudflare:workers";

/** Old keys deleted after each successful claim. Keeps every claim fast. */
export const SWEEP_PER_CLAIM = 128;
/** Old keys deleted per batch inside the alarm. */
export const SWEEP_PER_ALARM_BATCH = 1000;
/** Batches per alarm run before it hands over to the next run. */
export const SWEEP_ALARM_BATCHES = 10;

const KEY_RE = /^[0-9]{10}\.[0-9a-f]{32,64}$/;

/**
 * Replay guard. One instance, addressed by a fixed name, holds every claimed
 * `<timestamp>.<nonce>` key. SQLite storage inside one Durable Object gives a
 * single strongly consistent writer, and the claim is one synchronous
 * statement, so two concurrent claims of one key give exactly one `true`.
 *
 * Only the key and the claim time are stored. Nothing about the message.
 */
export class NonceStore extends DurableObject {
  private readonly sql: SqlStorage;

  constructor(ctx: DurableObjectState, env: unknown) {
    super(ctx, env as Cloudflare.Env);
    this.sql = ctx.storage.sql;
    this.sql.exec(
      "CREATE TABLE IF NOT EXISTS nonces (k TEXT PRIMARY KEY, claimed_at INTEGER NOT NULL) WITHOUT ROWID",
    );
    this.sql.exec("CREATE TABLE IF NOT EXISTS meta (name TEXT PRIMARY KEY, value INTEGER NOT NULL)");
  }

  /**
   * Claims a key. Returns true when the key is new, false when it was seen.
   *
   * @param key `<timestamp>.<nonce>`, as signed by the client.
   * @param now The Worker's clock in Unix seconds.
   * @param windowSeconds How long keys are kept: twice the skew.
   */
  async claim(key: string, now: number, windowSeconds: number): Promise<boolean> {
    if (
      !KEY_RE.test(key) ||
      !Number.isInteger(now) ||
      !Number.isInteger(windowSeconds) ||
      windowSeconds < 1
    ) {
      throw new TypeError("invalid claim");
    }
    const cursor = this.sql.exec(
      "INSERT INTO nonces (k, claimed_at) VALUES (?, ?) ON CONFLICT (k) DO NOTHING",
      key,
      now,
    );
    if (cursor.rowsWritten === 0) {
      return false;
    }
    this.sweep(now - windowSeconds, SWEEP_PER_CLAIM);
    this.sql.exec(
      "INSERT INTO meta (name, value) VALUES ('window', ?) ON CONFLICT (name) DO UPDATE SET value = excluded.value",
      windowSeconds,
    );
    if ((await this.ctx.storage.getAlarm()) === null) {
      await this.ctx.storage.setAlarm(Date.now() + windowSeconds * 1000);
    }
    return true;
  }

  /**
   * Sweep for when traffic stops. Deletes keys older than the window by the
   * object's own clock, then schedules itself again while keys remain.
   */
  override async alarm(): Promise<void> {
    const windowSeconds = this.window();
    const cutoff = Math.floor(Date.now() / 1000) - windowSeconds;
    let full = false;
    for (let i = 0; i < SWEEP_ALARM_BATCHES; i++) {
      full = this.sweep(cutoff, SWEEP_PER_ALARM_BATCH) === SWEEP_PER_ALARM_BATCH;
      if (!full) {
        break;
      }
    }
    if (full) {
      await this.ctx.storage.setAlarm(Date.now() + 1000);
      return;
    }
    if (this.hasKeys()) {
      await this.ctx.storage.setAlarm(Date.now() + windowSeconds * 1000);
    }
  }

  /** Deletes up to `limit` of the oldest keys whose timestamp is below `cutoff`. */
  private sweep(cutoff: number, limit: number): number {
    if (cutoff <= 0) {
      return 0;
    }
    const bound = String(cutoff).padStart(10, "0");
    return this.sql.exec(
      "DELETE FROM nonces WHERE k IN (SELECT k FROM nonces WHERE k < ? ORDER BY k LIMIT ?)",
      bound,
      limit,
    ).rowsWritten;
  }

  private window(): number {
    const row = this.sql.exec<{ value: number }>("SELECT value FROM meta WHERE name = 'window'").toArray()[0];
    return row === undefined ? 600 : row.value;
  }

  private hasKeys(): boolean {
    return this.sql.exec("SELECT 1 FROM nonces LIMIT 1").toArray().length > 0;
  }
}
