import {DatabaseSync} from 'node:sqlite';import {readdirSync,readFileSync} from 'node:fs';
export function sqliteD1(file=':memory:'){
 const sqlite=new DatabaseSync(file);sqlite.exec('PRAGMA journal_mode=WAL');sqlite.exec('CREATE TABLE IF NOT EXISTS local_migrations (name TEXT PRIMARY KEY)');
 for(const name of readdirSync('drizzle').filter(f=>f.endsWith('.sql')).sort()){if(sqlite.prepare('SELECT name FROM local_migrations WHERE name=?').get(name))continue;sqlite.exec('BEGIN');try{sqlite.exec(readFileSync('drizzle/'+name,'utf8'));sqlite.prepare('INSERT INTO local_migrations(name) VALUES(?)').run(name);sqlite.exec('COMMIT');}catch(e){sqlite.exec('ROLLBACK');throw e;}}
 function prepare(sql:string){
  let params:any[]=[];
  return {
   bind(...values:any[]){params=values;return this;},
   async first(){return sqlite.prepare(sql).get(...params)||null;},
   async all(){return {results:sqlite.prepare(sql).all(...params)};},
   async run(){const r=sqlite.prepare(sql).run(...params);return {meta:{changes:Number(r.changes)}};},
   runSync(){const r=sqlite.prepare(sql).run(...params);return {meta:{changes:Number(r.changes)}};}
  };
 }
 const db={prepare,async batch(stmts:any[]){sqlite.exec('BEGIN IMMEDIATE');try{const result=stmts.map(s=>s.runSync());sqlite.exec('COMMIT');return result;}catch(e){sqlite.exec('ROLLBACK');throw e;}}} as unknown as D1Database;
 return {db,sqlite};
}
