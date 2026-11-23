import { build } from 'esbuild';
import { readdir, readFile, writeFile, mkdir, copyFile } from 'node:fs/promises';
import path from 'node:path';
const assets={};const mime={'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.svg':'image/svg+xml','.json':'application/json','.webmanifest':'application/manifest+json','.txt':'text/plain'};
async function walk(dir){for(const ent of await readdir(dir,{withFileTypes:true})){const file=path.join(dir,ent.name);if(ent.isDirectory())await walk(file);else{const ext=path.extname(file);if(!mime[ext])throw new Error('Unsupported asset: '+file);assets['/'+path.relative('dist/client',file).replaceAll('\\','/')]={type:mime[ext],body:await readFile(file,'utf8')};}}}
await walk('dist/client');
await mkdir('dist/server',{recursive:true});
await build({entryPoints:['worker/index.ts'],outfile:'dist/server/index.js',bundle:true,format:'esm',target:'es2022',minify:true,plugins:[{name:'embed-astro',setup(b){b.onLoad({filter:/assets\.generated\.json$/},()=>({contents:JSON.stringify(assets),loader:'json'}));}}]});
await mkdir('dist/.openai',{recursive:true});await copyFile('.openai/hosting.json','dist/.openai/hosting.json');
console.log('Built Astro frontend and Sites Worker with '+Object.keys(assets).length+' bundled assets.');
