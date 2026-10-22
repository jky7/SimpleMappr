import {chromium,firefox,expect} from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {mkdir,writeFile} from 'node:fs/promises';
import path from 'node:path';
const base=process.env.SIMPLEMAPPR_TEST_URL||'http://127.0.0.1:8080';
const origin=new URL(base).origin;
const output=process.env.SIMPLEMAPPR_BROWSER_OUTPUT||'/receipts';
await mkdir(output,{recursive:true});
const receipts=[];const keyboard=[];let failures=0;
for(const name of (process.env.SIMPLEMAPPR_BROWSERS||'chromium,firefox').split(',')){
 const browser=await({chromium,firefox}[name]).launch({headless:true});
 const context=await browser.newContext({viewport:{width:1365,height:900},serviceWorkers:'block'});
 await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
 const page=await context.newPage();await page.goto(base,{waitUntil:'networkidle'});await page.waitForFunction(()=>window.SimpleMappr&&window.jQuery);
 const assets=await page.locator('script[src]').evaluateAll(nodes=>nodes.map(node=>new URL(node.src).pathname));
 async function scan(state){
  const result=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa']).analyze();
  const violations=result.violations.map(({id,impact,description,help,helpUrl,nodes})=>({id,impact,description,help,helpUrl,nodes:nodes.map(({target,html,failureSummary,any,all,none})=>({target,html,failureSummary,any,all,none}))}));
  const incomplete=result.incomplete.map(({id,impact,help,nodes})=>({id,impact,help,nodes:nodes.map(({target,html,failureSummary})=>({target,html,failureSummary}))}));
  const receipt={browser:name,version:browser.version(),state,assets,violations,incomplete};
  failures+=violations.length;receipts.push(receipt);
  await page.screenshot({path:path.join(output,`${name}-${state}.png`),fullPage:true});
  await writeFile(path.join(output,`${name}-${state}.json`),JSON.stringify(receipt,null,2)+'\n');
  console.log(JSON.stringify({browser:name,state,violations:violations.map(item=>({id:item.id,impact:item.impact,targets:item.nodes.map(node=>node.target)}))}));
 }
 await page.locator('a[href="#map-points"]').click();
 try{
  await page.locator('a[href="#map-points"]').focus();await page.keyboard.press('ArrowRight');await expect(page.locator('#map-wkt')).toBeVisible();
  await page.keyboard.press('Home');await expect(page.locator('#map-preview')).toBeVisible();await page.locator('a[href="#map-points"]').click();
  const title=page.locator('[name="coords[0][title]"]'),data=page.locator('[name="coords[0][data]"]');
  await title.fill('Keyboard coordinates');await data.fill('45.5019,-73.5674');
  await title.focus();await page.keyboard.press('Tab');await expect(data).toBeFocused();
  await page.keyboard.press('End');await page.keyboard.press('Enter');expect(await data.inputValue()).toBe('45.5019,-73.5674\n');
  await page.keyboard.press('Tab');await expect(page.locator('[name="coords[0][shape]"]')).toBeFocused();
  const pending=page.waitForResponse(response=>new URL(response.url()).pathname==='/application.json');
  await page.locator('#map-points button.submitForm').focus();await page.keyboard.press('Enter');expect((await pending).status()).toBe(200);
  await page.waitForFunction(()=>{const image=document.querySelector('#mapOutputImage');return image.getAttribute('src').includes('preview-')&&image.complete&&image.naturalWidth>0;});
  await page.locator('#mapOutput').focus();await page.keyboard.press('Control+d');await expect(page.getByRole('dialog',{name:'Download',exact:true})).toBeVisible();
  await page.keyboard.press('Escape');await expect(page.getByRole('dialog',{name:'Download',exact:true})).toHaveCount(0);await expect(page.locator('#mapOutput')).toBeFocused();
  keyboard.push({browser:name,status:'PASS',checks:['ArrowRight changes tab','Home returns to Preview','Tab to textarea','Enter inserts newline','Tab to marker select','Enter previews','CtrlD opens download','Escape closes and restores map focus']});
 }catch(error){failures++;keyboard.push({browser:name,status:'FAIL',error:error.message});}
 await page.locator('a[href="#map-points"]').click();await scan('desktop-points');
 await page.locator('a[href="#map-preview"]').click();await page.locator('a.toolsDownload').click();await scan('download-dialog');
 await page.getByRole('dialog',{name:'Download',exact:true}).getByRole('button',{name:'Cancel',exact:true}).click();
 await page.setViewportSize({width:375,height:812});await page.locator('a[href="#map-points"]').click();
 const dimensions=await page.evaluate(()=>({clientWidth:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth}));
 keyboard.at(-1).narrow=dimensions;if(dimensions.clientWidth!==375||dimensions.scrollWidth>375){failures++;keyboard.at(-1).layoutStatus='FAIL';}else keyboard.at(-1).layoutStatus='PASS';
 await scan('narrow-points');await page.locator('a[href="#map-preview"]').click();await page.screenshot({path:path.join(output,`${name}-narrow-preview.png`),fullPage:true});
 await browser.close();
}
await writeFile(path.join(output,'accessibility.json'),JSON.stringify({status:failures?'FAIL':'PASS',failures,keyboard,receipts},null,2)+'\n');
process.exitCode=failures?1:0;
