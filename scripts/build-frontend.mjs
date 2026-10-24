import {readFile,writeFile,mkdir,rm} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {gzipSync} from 'node:zlib';
import {transform,build} from 'esbuild';
const output='public/build',modules=new Set();
const sha=bytes=>createHash('sha256').update(bytes).digest('hex');
async function uiModule(name){
 const filename=path.posix.normalize(`node_modules/jquery-ui/ui/${name}.js`);
 if(modules.has(filename))return '';
 const contents=await readFile(filename,'utf8');let dependencies='';
 const amd=contents.match(/define\s*\(\s*\[([\s\S]*?)\]/);
 for(const match of (amd?.[1]||'').matchAll(/"([^"]+)"/g)){
  if(match[1]==='jquery')continue;
  const relative=path.posix.relative('node_modules/jquery-ui/ui',path.posix.resolve(path.posix.dirname(filename),match[1]));
  dependencies+=await uiModule(relative);
 }
 modules.add(filename);return dependencies+'\n'+contents;
}
let ui='';
for(const name of ['widgets/tabs','widgets/dialog','widgets/accordion','widgets/slider','widgets/autocomplete','widgets/draggable','effects/effect-slide'])ui+=await uiModule(name);
const retained=['jquery.colorpicker.js','jquery.Jcrop.js','jquery.tinycircleslider.js'];
const manifest={schemaVersion:1,assets:{},retainedInputs:{},jqueryUiModules:[...modules].map(f=>f.replace('node_modules/jquery-ui/ui/',''))};
let editor='';
for(const name of [...retained,'map-document.js','editor-storage.js','simplemappr.js']){
 const source=await readFile(`public/javascript/${name}`,'utf8');editor+='\n'+source;
 if(retained.includes(name))manifest.retainedInputs[name]=sha(source);
}
const adminFiles=['simplemappr.admin.js'];let admin='';
for(const name of adminFiles){const source=await readFile(`public/javascript/${name}`,'utf8');admin+='\n'+source;manifest.retainedInputs[name]=sha(source);}
await rm(output,{recursive:true,force:true});await mkdir(output,{recursive:true});
for(const [name,source]of [['jquery',await readFile('node_modules/jquery/dist/jquery.js','utf8')],['jquery-ui',ui],['editor',editor],['admin',admin]]){
 const {code}=await transform(source,{minify:true,target:'es2020',legalComments:'inline'});
 const digest=sha(code),file=`${name}.${digest.slice(0,12)}.js`;
 await writeFile(`${output}/${file}`,code);manifest.assets[name]={file,sha256:digest,bytes:Buffer.byteLength(code),gzipBytes:gzipSync(code).length};
}
for(const [name,contents]of [['editor-css','@import "../public/stylesheets/raw/fonts.css";\n@import "../node_modules/jquery-ui/themes/base/all.css";\n@import "../public/stylesheets/raw/styles.css";\n@import "../public/stylesheets/raw/editor-responsive.css";'],['pages-css','@import "../public/stylesheets/raw/fonts.css";\n@import "../public/stylesheets/raw/pages.css";']]){
 const css=await build({stdin:{contents,resolveDir:path.join(process.cwd(),'scripts'),loader:'css',sourcefile:name+'.css'},bundle:true,minify:true,write:false,outdir:output,assetNames:'images/[name]-[hash]',loader:{'.png':'file','.gif':'file','.jpg':'file','.svg':'file','.woff2':'file'}});
 for(const item of css.outputFiles){
  let file=path.relative(path.resolve(output),item.path);const digest=sha(item.contents);
  if(file.endsWith('.css'))file=`${name}.${digest.slice(0,12)}.css`;
  await mkdir(path.dirname(path.join(output,file)),{recursive:true});await writeFile(path.join(output,file),item.contents);
  manifest.assets[file.endsWith('.css')?name:file]={file,sha256:digest,bytes:item.contents.length,gzipBytes:gzipSync(item.contents).length};
 }
}
let licenses='SimpleMappr frontend third-party notices\n\n';
for(const [name,file]of [['jQuery 3.7.1','node_modules/jquery/LICENSE.txt'],['jQuery UI 1.14.2','node_modules/jquery-ui/LICENSE.txt'],['Inter 5.3.0 (@fontsource-variable/inter, SIL OFL 1.1)','node_modules/@fontsource-variable/inter/LICENSE']])licenses+=name+'\n'+await readFile(file,'utf8')+'\n\n';
for(const name of retained){const source=await readFile(`public/javascript/${name}`,'utf8');licenses+=name+'\n'+source.slice(0,source.indexOf('*/')+2)+'\n\n';}
const licenseFile='licenses.'+sha(licenses).slice(0,12)+'.txt';await writeFile(`${output}/${licenseFile}`,licenses);
manifest.assets.licenses={file:licenseFile,sha256:sha(licenses),bytes:Buffer.byteLength(licenses),gzipBytes:gzipSync(licenses).length};
await writeFile(`${output}/manifest.json`,JSON.stringify(manifest,null,2)+'\n');
console.log(`Built ${Object.keys(manifest.assets).length} content-hashed local assets.`);
