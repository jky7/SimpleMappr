import {chromium,firefox,expect} from '@playwright/test';
import {mkdir,writeFile,readFile,chmod} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';

const base=process.env.SIMPLEMAPPR_TEST_URL||'http://127.0.0.1:8080';
const origin=new URL(base).origin;
const output=process.env.SIMPLEMAPPR_BROWSER_OUTPUT||'/receipts';
const scenarios=(process.env.SIMPLEMAPPR_BROWSER_SCENARIOS||'preview,mixed,reload,error,import,download').split(',');
const browsers=(process.env.SIMPLEMAPPR_BROWSERS||'chromium,firefox').split(',');
const receipts=[];
await mkdir(output,{recursive:true});
let failures=0;
for(const name of browsers){
 const browser=await ({chromium,firefox}[name]).launch({headless:true});
 const context=await browser.newContext({viewport:{width:1365,height:900},locale:'en-US',acceptDownloads:true,serviceWorkers:'block'});
 const outbound=[];const pageErrors=[];let lastRender=null;
 await context.route('**/*',async route=>{
  const url=new URL(route.request().url());
  if(url.origin!==origin){outbound.push(url.origin+url.pathname);await route.abort();return;}
  await route.continue();
 });
 await context.tracing.start({screenshots:true,snapshots:true,sources:false});
 const page=await context.newPage();page.setDefaultTimeout(15000);page.on('pageerror',error=>pageErrors.push(error.message));
 const receipt={browser:name,version:browser.version(),base:origin,scenarios:[],outbound,pageErrors};
 async function shot(label){await page.screenshot({path:path.join(output,`${name}-${label}.png`),fullPage:true});}
 async function tab(hash){await page.locator(`a[href="#${hash}"]`).click();}
 async function group(type,title,data){
  await tab(type==='coords'?'map-points':type==='wkt'?'map-wkt':'map-regions');
  const titleField=page.locator(`[name="${type}[0][title]"]`);
  const dataField=page.locator(`[name="${type}[0][data]"]`);
  if(!await titleField.isVisible())await titleField.locator('xpath=ancestor::div[contains(@class,"form-item")]/h3').click();
  await titleField.fill(title);await dataField.fill(data);
 }
 async function preview(){
  const previous=await page.locator('#mapOutputImage').getAttribute('src');
  const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/application.json');
  const visible=page.locator('button.submitForm:visible');
  if(await visible.count())await visible.first().click();else await page.locator('a.toolsRefresh').click();
  const render=await response;expect(render.status()).toBe(200);lastRender=await render.json();
  await page.waitForFunction(old=>{const img=document.querySelector('#mapOutputImage');return img&&img.getAttribute('src')!==old&&img.complete&&img.naturalWidth>0;},previous,{timeout:45000});
  const dimensions=await page.locator('#mapOutputImage').evaluate(img=>[img.naturalWidth,img.naturalHeight]);expect(dimensions[0]).toBeGreaterThan(0);
  expect(await page.locator('.mapper-message-error').count()).toBe(0);
  return dimensions;
 }
 async function scenario(label,fn){
  try{const result=await fn();await shot(label);receipt.scenarios.push({name:label,status:'PASS',...result});}
  catch(error){failures++;receipt.scenarios.push({name:label,status:'FAIL',error:error.message.slice(0,2000)});await shot(label+'-failed');}
 }
 try{
  await page.goto(base,{waitUntil:'networkidle'});await page.waitForFunction(()=>window.SimpleMappr&&window.jQuery);
  receipt.assets=await page.locator('script[src]').evaluateAll(nodes=>nodes.map(node=>new URL(node.src).pathname));
  if(scenarios.includes('preview'))await scenario('preview',async()=>{await group('coords','Browser Montréal','45.5019,-73.5674\n43.6532,-79.3832');return {dimensions:await preview()};});
  if(scenarios.includes('mixed'))await scenario('mixed',async()=>{
   await group('wkt','Browser drawing','POLYGON((-84 40,-70 40,-70 50,-84 50,-84 40))');
   await group('regions','Browser Canada','Canada');
   const dimensions=await preview();
   await tab('map-points');expect(await page.locator('[name="coords[0][data]"]').inputValue()).toContain('45.5019');
   await tab('map-wkt');expect(await page.locator('[name="wkt[0][data]"]').inputValue()).toContain('POLYGON');
   await tab('map-regions');expect(await page.locator('[name="regions[0][data]"]').inputValue()).toBe('Canada');await tab('map-preview');
   return {dimensions};
  });
  if(scenarios.includes('reload'))await scenario('reload-mixed',async()=>{
   await page.reload({waitUntil:'networkidle'});await page.waitForFunction(()=>window.SimpleMappr&&window.jQuery);
   await page.waitForFunction(()=>{const image=document.querySelector('#mapOutputImage');return image&&image.getAttribute('src').includes('preview-')&&image.complete&&image.naturalWidth>0;},null,{timeout:45000});
   for(const [type,expected]of [['coords','45.5019'],['wkt','POLYGON'],['regions','Canada']]){
    expect(await page.locator(`[name="${type}[0][data]"]`).inputValue()).toContain(expected);
   }
   await expect(page.locator('#map-loader span.mapper-loading-spinner')).toBeHidden();return {};
  });
  if(scenarios.includes('error'))await scenario('error-recovery',async()=>{
   await tab('map-points');const original=await page.locator('[name="coords[0][data]"]').inputValue();
   await page.route('**/application.json',route=>route.fulfill({status:503,contentType:'application/json',body:'{"status":"failed","error":"Browser contract temporary failure"}'}),{times:1});
   const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/application.json');await page.locator('#map-points button.submitForm').click();expect((await response).status()).toBe(503);
   await expect(page.locator('.mapper-message-error')).toContainText('Browser contract temporary failure');
   await expect(page.locator('#map-loader span.mapper-loading-spinner')).toBeHidden();
   expect(await page.locator('[name="coords[0][data]"]').inputValue()).toBe(original);
   await shot('error-visible');await tab('map-points');await preview();
   for(const mode of ['invalid-json','failed-image']){
    await tab('map-points');
    if(mode==='invalid-json')await page.route('**/application.json',route=>route.fulfill({status:200,contentType:'application/json',body:'{invalid-json'}),{times:1});
    else{
     await page.route('**/browser-contract-broken-image.png',route=>route.fulfill({status:404,body:'not found'}),{times:1});
     await page.route('**/application.json',route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({...lastRender,mapOutputImage:base+'/browser-contract-broken-image.png'})}),{times:1});
    }
    const failed=page.waitForResponse(r=>new URL(r.url()).pathname==='/application.json');await page.locator('#map-points button.submitForm').click();await failed;
    await expect(page.locator('.mapper-message-error')).toBeVisible();await expect(page.locator('#map-loader span.mapper-loading-spinner')).toBeHidden();
    expect(await page.locator('[name="coords[0][data]"]').inputValue()).toBe(original);
    await shot(mode);await tab('map-points');await preview();
   }
   return {dimensions:await page.locator('#mapOutputImage').evaluate(image=>[image.naturalWidth,image.naturalHeight]),failuresRecovered:['HTTP503','invalidJSON','image404']};
  });
  if(scenarios.includes('import'))await scenario('import',async()=>{
   await tab('map-points');const old=await page.locator('#mapOutputImage').getAttribute('src');
   await page.locator('#fileInput').setInputFiles({name:'browser-points.csv',mimeType:'',buffer:Buffer.from('Browser imported points,45.5019,-73.5674\rBrowser imported points,43.6532,-79.3832\r')});
   await page.waitForFunction(previous=>{const image=document.querySelector('#mapOutputImage');return image.getAttribute('src')!==previous&&image.complete&&image.naturalWidth>0;},old,{timeout:45000});
   await tab('map-points');expect(await page.locator('[name="coords[0][data]"]').inputValue()).toContain('45.5019');await tab('map-preview');
   return {};
  });
  if(scenarios.includes('download'))await scenario('download',async()=>{
   await tab('map-preview');await page.locator('a.toolsDownload').click();await page.locator('#file-name').fill('browser-contract');
   const downloads=[];
   await page.locator('#download-png').check();
   await page.route('**/application',route=>route.fulfill({status:503,contentType:'application/json',body:'{"status":"failed","error":"Temporary browser export failure"}'}),{times:1});
   const failedResponse=page.waitForResponse(response=>new URL(response.url()).pathname==='/application');
   await page.locator('.ui-dialog').filter({has:page.locator('#mapExport')}).getByRole('button',{name:'Download',exact:true}).click();expect((await failedResponse).status()).toBe(503);
   await expect(page.locator('.mapper-message-error')).toBeVisible();await expect(page.locator('#mapExport .download-dialog')).toBeVisible();await shot('download-error');
   for(const format of (process.env.SIMPLEMAPPR_BROWSER_FORMATS||'png,svg,pnga,jpg,tif,kml,docx,pptx').split(',')){
    try{
    await page.locator(`#download-${format}`).check();
    if(await page.locator('#download-factor-1').isVisible())await page.locator('#download-factor-1').check();
    const pending=page.waitForEvent('download',{timeout:95000});pending.catch(()=>{});
    const responsePending=page.waitForResponse(response=>response.request().method()==='POST'&&['/application','/kml','/docx','/pptx'].includes(new URL(response.url()).pathname),{timeout:95000});
    await page.locator('.ui-dialog').filter({has:page.locator('#mapExport')}).getByRole('button',{name:'Download',exact:true}).click();
    const response=await responsePending;if(!response.ok())throw new Error(`${format} returned HTTP${response.status()}: ${(await response.text()).slice(0,1000)}`);
    const download=await pending;expect(download.suggestedFilename()).toMatch(new RegExp('\\.'+(format==='pnga'?'png':format)+'$'));const target=path.join(output,`${name}-download.${format}`);await download.saveAs(target);await chmod(target,0o644);const bytes=await readFile(target);
    const valid=format==='png'||format==='pnga'?bytes.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])):
     format==='jpg'?bytes[0]===255&&bytes[1]===216:
     format==='tif'?['49492a00','4d4d002a'].includes(bytes.subarray(0,4).toString('hex')):
     format==='svg'?bytes.toString().includes('<svg'):
     format==='kml'?bytes.toString().includes('<kml'):
     bytes.subarray(0,2).toString()==='PK';
    expect(valid,`Downloaded ${format} has the expected file signature`).toBe(true);await expect(page.locator('.mapper-message-error')).toHaveCount(0);
    downloads.push({format,bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')});
    await expect(page.locator('#mapExport .download-dialog')).toBeVisible();
    }catch(error){failures++;downloads.push({format,status:'FAIL',error:error.message.slice(0,1500)});await shot(`download-${format}-failed`);}
   }
   return {status:downloads.some(download=>download.status==='FAIL')?'FAIL':'PASS',downloads};
  });
  receipt.images=await page.locator('img').evaluateAll(images=>images.map(image=>({src:image.getAttribute('src'),width:image.naturalWidth,height:image.naturalHeight,visible:!!(image.offsetWidth||image.offsetHeight||image.getClientRects().length)})));
  if(outbound.length){failures++;receipt.networkStatus='FAIL';}else receipt.networkStatus='PASS';
  if(pageErrors.length){failures++;receipt.javascriptStatus='FAIL';}else receipt.javascriptStatus='PASS';
 }catch(error){failures++;receipt.fatal=error.message;await shot('fatal').catch(()=>{});}
 finally{await context.tracing.stop({path:path.join(output,`${name}-trace.zip`)});await browser.close();receipts.push(receipt);await writeFile(path.join(output,`${name}.json`),JSON.stringify(receipt,null,2)+'\n');}
}
await writeFile(path.join(output,'browser-contract.json'),JSON.stringify({status:failures?'FAIL':'PASS',failures,receipts},null,2)+'\n');
console.log(JSON.stringify({status:failures?'FAIL':'PASS',failures,browsers}));
process.exitCode=failures?1:0;
