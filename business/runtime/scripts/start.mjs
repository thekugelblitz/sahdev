// scripts/serve.ts
import { createServer } from "node:http";
import path from "node:path";
import { mkdir, readFile, writeFile, unlink } from "node:fs/promises";

// scripts/sqlite-d1.ts
import { DatabaseSync } from "node:sqlite";
import { readdirSync, readFileSync } from "node:fs";
function sqliteD1(file = ":memory:") {
  const sqlite = new DatabaseSync(file);
  sqlite.exec("PRAGMA journal_mode=WAL");
  sqlite.exec("CREATE TABLE IF NOT EXISTS local_migrations (name TEXT PRIMARY KEY)");
  for (const name of readdirSync("drizzle").filter((f) => f.endsWith(".sql")).sort()) {
    if (sqlite.prepare("SELECT name FROM local_migrations WHERE name=?").get(name)) continue;
    sqlite.exec("BEGIN");
    try {
      sqlite.exec(readFileSync("drizzle/" + name, "utf8"));
      sqlite.prepare("INSERT INTO local_migrations(name) VALUES(?)").run(name);
      sqlite.exec("COMMIT");
    } catch (e) {
      sqlite.exec("ROLLBACK");
      throw e;
    }
  }
  function prepare(sql) {
    let params = [];
    return {
      bind(...values) {
        params = values;
        return this;
      },
      async first() {
        return sqlite.prepare(sql).get(...params) || null;
      },
      async all() {
        return { results: sqlite.prepare(sql).all(...params) };
      },
      async run() {
        const r = sqlite.prepare(sql).run(...params);
        return { meta: { changes: Number(r.changes) } };
      },
      runSync() {
        const r = sqlite.prepare(sql).run(...params);
        return { meta: { changes: Number(r.changes) } };
      }
    };
  }
  const db2 = { prepare, async batch(stmts) {
    sqlite.exec("BEGIN IMMEDIATE");
    try {
      const result = stmts.map((s) => s.runSync());
      sqlite.exec("COMMIT");
      return result;
    } catch (e) {
      sqlite.exec("ROLLBACK");
      throw e;
    }
  } };
  return { db: db2, sqlite };
}

// scripts/serve.ts
var { default: worker } = await import("../dist/server/index.js");
var cloud = process.env.SAHDEV_AUTH_MODE === "cloud";
var required = cloud ? ["SAHDEV_ORIGIN"] : ["AUTH0_DOMAIN", "AUTH0_AUDIENCE", "AUTH0_CLIENT_ID", "AUTH0_ORGANIZATION", "SAHDEV_ORIGIN"];
if (required.some((k) => !process.env[k])) throw new Error("Standalone hosting requires: " + required.join(", "));
var origin = new URL(process.env.SAHDEV_ORIGIN);
if (origin.protocol !== "https:" && !["localhost", "127.0.0.1"].includes(origin.hostname)) throw new Error("Use an HTTPS origin for standalone hosting.");
var dataRoot = path.resolve(process.env.SAHDEV_DATA_DIR || ".data/standalone");
await mkdir(dataRoot, { recursive: true });
var { db } = sqliteD1(path.join(dataRoot, "business.db"));
function filePath(key) {
  const resolved = path.resolve(dataRoot, "files", key);
  if (!resolved.startsWith(path.join(dataRoot, "files") + path.sep)) throw new Error("Invalid storage key");
  return resolved;
}
var bucket = { async put(key, bytes, options) {
  const file = filePath(key);
  await mkdir(path.dirname(file), { recursive: true });
  await writeFile(file, bytes);
  await writeFile(file + ".meta", JSON.stringify(options.httpMetadata));
}, async get(key) {
  try {
    const bytes = await readFile(filePath(key)), httpMetadata = JSON.parse(await readFile(filePath(key) + ".meta", "utf8"));
    return { httpMetadata, arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) };
  } catch {
    return null;
  }
}, async delete(key) {
  await Promise.all([unlink(filePath(key)).catch(() => {
  }), unlink(filePath(key) + ".meta").catch(() => {
  })]);
} };
var env = { ...process.env, AUTH_MODE: cloud ? "cloud" : "auth0", DB: db, BUCKET: bucket };
var server = createServer(async (req, res) => {
  try {
    const headers = new Headers();
    for (const [k, v] of Object.entries(req.headers)) {
      if (k.startsWith("oai-") || k.startsWith("x-forwarded-") || k === "host" || k === "connection" || k === "transfer-encoding") continue;
      if (v) headers.set(k, Array.isArray(v) ? v.join(", ") : v);
    }
    const parts = [];
    let size = 0;
    for await (const chunk of req) {
      size += chunk.length;
      if (size > 5 * 1024 * 1024) {
        res.writeHead(413).end();
        return;
      }
      parts.push(chunk);
    }
    const request = new Request(new URL(req.url || "/", origin), { method: req.method, headers, ...!["GET", "HEAD"].includes(req.method || "GET") ? { body: Buffer.concat(parts) } : {} });
    const response = await worker.fetch(request, env);
    res.writeHead(response.status, Object.fromEntries(response.headers));
    res.end(Buffer.from(await response.arrayBuffer()));
  } catch {
    res.writeHead(500, { "Content-Type": "application/json" }).end(JSON.stringify({ error: "Request could not be completed." }));
  }
});
server.requestTimeout = 3e4;
server.headersTimeout = 1e4;
server.maxHeadersCount = 50;
server.listen(Number(process.env.PORT || 8080), process.env.SAHDEV_HOST || "127.0.0.1", () => console.log("Standalone Sahdev is listening with Auth0 authentication."));
