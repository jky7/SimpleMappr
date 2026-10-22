import {chromium,expect} from '@playwright/test';
import {mkdir,writeFile} from 'node:fs/promises';
import path from 'node:path';
const base=process.env.SIMPLEMAPPR_TEST_URL||'http://127.0.0.1:8080';
const output=process.env.SIMPLEMAPPR_BROWSER_OUTPUT||'/receipts';
await mkdir(output,{recursive:true});
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1365,height:900}});
const receipts=[];
try{
 await page.goto(base,{waitUntil:'networkidle'});await page.waitForFunction(()=>window.SimpleMappr&&window.jQuery);
 const profile=await(await page.request.get(base+'/_runtime')).json();
 expect(profile.assetProfile).toBe('gray-relief-v1');
 const keys=['rivers','oceans','roads','railroads','rivernames','placenames','physicalLabels','marineLabels','reliefgrey'];
 async function render(label){
  const old=await page.locator('#mapOutputImage').getAttribute('src');
  const response=page.waitForResponse(response=>new URL(response.url()).pathname==='/application.json');
  await page.locator('a.toolsRefresh').click();const result=await response;expect(result.status()).toBe(200);
  await page.waitForFunction(old=>{const image=document.querySelector('#mapOutputImage');return image.getAttribute('src')!==old&&image.complete&&image.naturalWidth>0;},old);
  const dimensions=await page.locator('#mapOutputImage').evaluate(image=>[image.naturalWidth,image.naturalHeight]);
  receipts.push({control:label,status:'PASS',dimensions});await page.screenshot({path:path.join(output,'profile-'+label+'.png'),fullPage:true});
 }
 for(const key of keys){
  for(const other of keys)await page.locator(`[name="layers[${other}]"]`).uncheck();
  await page.locator(`[name="layers[${key}]"]`).check();await render(key);
 }
 for(const key of keys)await page.locator(`[name="layers[${key}]"]`).check();
 await render('combined');
 await page.reload({waitUntil:'networkidle'});await page.waitForFunction(()=>window.SimpleMappr&&window.jQuery);
 for(const key of keys)await expect(page.locator(`[name="layers[${key}]"]`)).toBeChecked();
 await render('combined-reloaded');
 await writeFile(path.join(output,'profiles.json'),JSON.stringify({status:'PASS',profile:profile.assetProfile,receipts},null,2)+'\n');
}finally{await browser.close();}
