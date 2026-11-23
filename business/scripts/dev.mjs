import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
const options={stdio:'inherit',windowsHide:true};
const api=spawn(process.execPath,['--import','tsx','scripts/local-api.ts'],options);
const pkg=JSON.parse(readFileSync('node_modules/astro/package.json','utf8'));
const web=spawn(process.execPath,[path.join('node_modules/astro',pkg.bin.astro),'dev','--host','127.0.0.1'],options);
function stop(){api.kill();web.kill();}process.on('SIGINT',stop);process.on('SIGTERM',stop);api.on('exit',code=>{if(code){stop();process.exitCode=code;}});web.on('exit',stop);
