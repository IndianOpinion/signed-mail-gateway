declare module "*?raw" {
  const content: string;
  export default content;
}

declare namespace Cloudflare {
  interface Env {
    NONCE_STORE: DurableObjectNamespace;
    GATEWAY_SECRET: string;
    SENDERS: string;
    ENABLED_DOMAINS: string;
  }
}
