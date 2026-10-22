import {chromium,firefox,expect} from '@playwright/test';
import {writeFile} from 'node:fs/promises';
import path from 'node:path';
const base=process.env.SIMPLEMAPPR_TEST_URL||'http://127.0.0.1:8080';
const origin=new URL(base).origin,output=process.env.SIMPLEMAPPR_BROWSER_OUTPUT||'/receipts';
const receipts=[];
for(const name of ['chromium','firefox']){
 const browser=await({chromium,firefox}[name]).launch({headless:true});
 const context=await browser.newContext();const outbound=[];
 await context.route('**/*',route=>{const url=new URL(route.request().url());if(url.origin!==origin){outbound.push(url.origin+url.pathname);return route.abort();}return route.continue();});
 try{
  const page=await context.newPage();
  for(const endpoint of ['/','/about','/p6-contract-missing-page']){
   const response=await page.goto(base+endpoint,{waitUntil:'networkidle'});expect([200,404]).toContain(response.status());
   expect((await page.locator('body').innerText()).length).toBeGreaterThan(30);
   await page.screenshot({path:path.join(output,`${name}-offline-${endpoint==='/'?'editor':endpoint==='/about'?'about':'404'}.png`),fullPage:true});
  }
  expect(outbound).toEqual([]);receipts.push({browser:name,status:'PASS',pages:['editor','about','404'],outbound});
 }finally{await browser.close();}
}
await writeFile(path.join(output,'offline.json'),JSON.stringify({status:'PASS',receipts},null,2)+'\n');
