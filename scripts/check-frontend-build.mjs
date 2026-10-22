import {readFile,writeFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
const manifest=()=>JSON.parse(execFileSync(process.execPath,['-e',"process.stdout.write(require('fs').readFileSync('public/build/manifest.json','utf8'))"],{encoding:'utf8'}));
const before=manifest();
for(const asset of Object.values(before.assets)){
 const bytes=await readFile('public/build/'+asset.file);
 assert.equal(createHash('sha256').update(bytes).digest('hex'),asset.sha256);
 assert.equal(bytes.length,asset.bytes);
}
execFileSync(process.execPath,['scripts/build-frontend.mjs']);assert.deepEqual(manifest(),before);
for(const [filename,key,extra]of [['public/javascript/simplemappr.js','editor','\nwindow.simplemapprBuildIdentity = 1;\n'],['public/stylesheets/raw/editor-responsive.css','editor-css','\nbody{--simplemappr-build-identity:1}\n']]){
 const original=await readFile(filename);
 try{await writeFile(filename,Buffer.concat([original,Buffer.from(extra)]));execFileSync(process.execPath,['scripts/build-frontend.mjs']);assert.notEqual(manifest().assets[key].file,before.assets[key].file);}
 finally{await writeFile(filename,original);}
}
execFileSync(process.execPath,['scripts/build-frontend.mjs']);assert.deepEqual(manifest(),before);
console.log('Manifest hashes, deterministic builds and changed-source request paths passed.');
