import {build} from 'esbuild';
import {resolve} from 'node:path';
await build({entryPoints:[resolve('../../app/resources/js/backup-keygen.js')],outfile:resolve('../../app/public/backup-keygen.js'),nodePaths:[resolve('node_modules')],bundle:true,format:'iife',target:'es2022',minify:true,legalComments:'eof'});
