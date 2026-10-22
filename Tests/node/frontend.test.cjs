const assert=require('node:assert/strict');
const test=require('node:test');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'../..');
function editor(original=false){
 const dom=new JSDOM('<!doctype html><html><body><form id="form-mapper"><input id="m-mapSaveTitle"><input id="save[title]" name="save[title]"><input id="file-name"><input id="download_factor"><input id="download_filetype"><input id="grid_space"><input id="bbox_rubberband"><input id="rendered_origin"><input id="origin_map"><input id="fileInput" type="file"><select class="m-mapShape"><option value="circle">Circle</option><option value="square">Square</option></select><div id="fieldSetsPoints"></div><div id="fieldSetsWKT"></div><div id="fieldSetsRegions"></div><div id="upload-panel"></div><div id="mapSave"></div></form><div id="map-loader"><span class="mapper-loading-spinner"></span></div><div id="mapOutput"><img id="mapOutputImage"></div><div id="mapper-loading-error-message">Could not load map. Please retry.</div></body></html>',{url:'http://localhost/',runScripts:'outside-only'});
 const w=dom.window;
 w.eval(fs.readFileSync(path.join(root,'node_modules/jquery/dist/jquery.js'),'utf8'));
 const builtManifest=JSON.parse(fs.readFileSync(path.join(root,'public/build/manifest.json')));
 w.eval(fs.readFileSync(path.join(root,'public/build',builtManifest.assets['jquery-ui'].file),'utf8'));
 for(const name of ['map-document','editor-storage'])w.eval(fs.readFileSync(path.join(root,'public/javascript',name+'.js'),'utf8'));
 let source=fs.readFileSync(path.join(root,'public/javascript/simplemappr.js'),'utf8');
 source=source.replace('settings: _private.settings','settings: _private.settings, _test: _private');
 w.eval(source);
 w.SimpleMapprStorage.init('guest',false);
 const $=w.jQuery,p=w.SimpleMappr._test;
 return {dom,w,$,p};
}
function finish(t,e){t.after(()=>e.dom.window.close());return e;}
function viewportEditor(t){
 const e=finish(t,editor());let tick=1000;const requests=[];
 e.w.Date.prototype.getTime=()=>++tick;
 e.$('#origin_map,#bbox_rubberband').each(function(){e.$(this).attr('name',this.id);});
 for(const name of ['bbox_map','projection_map','projection','origin','rotation','width','height','pan'])e.$('#form-mapper').append(`<input type="hidden" id="${name}" name="${name}">`);
 for(const name of ['rendered_bbox','rendered_projection','rendered_rotation'])e.$('#mapOutput').append(`<input type="hidden" id="${name}">`);
 e.$('#projection').val('epsg:4326');e.$('#width').val('900');e.$('#height').val('450');
 e.$.fn.slider=function(){return this;};
 e.$.ajax=options=>(requests.push(options),{abort(){}});
 function respond(bbox,projection='epsg:4326',origin=false){
  requests.at(-1).success({size:[900,450],mapOutputImage:'/map-'+requests.length+'.png',rendered_bbox:bbox,rendered_projection:projection,rendered_origin:origin,rendered_rotation:0});
 }
 function render(bbox,projection,origin){respond(bbox,projection,origin);e.$('#mapOutputImage').trigger('load');}
 return {...e,requests,respond,render,request:()=>new URLSearchParams(requests.at(-1).data)};
}
test('Undo and Redo restore completed pan viewports and their projection source tuple',t=>{
 const e=viewportEditor(t),a='-90,-60,-30,10',b='-80,-60,-20,10',c='-80,-50,-20,20';
 e.p.showMap();e.render(a);
 e.p.mapPan('right');e.render(b,'esri:102015',-60);
 e.p.mapPan('up');e.render(c,'esri:102015',-55);
 e.p.mapUndo();assert.equal(e.request().get('bbox_map'),b);assert.equal(e.request().get('projection_map'),'esri:102015');assert.equal(e.request().get('origin_map'),'-60');e.render(b,'esri:102015',-60);
 e.p.mapRedo();assert.equal(e.request().get('bbox_map'),c);assert.equal(e.request().get('origin_map'),'-55');e.render(c,'esri:102015',-55);
 e.p.mapUndo();assert.equal(e.request().get('bbox_map'),b);
});
test('failed and superseded previews do not become undo destinations',t=>{
 const e=viewportEditor(t),a='-90,-60,-30,10',b='-80,-60,-20,10';
 e.p.showMap();e.render(a);
 e.p.mapPan('right');e.requests.at(-1).error({},'error');
 e.p.mapPan('up');e.respond('failed-image');e.$('#mapOutputImage').trigger('error');
 e.p.mapPan('left');const superseded=e.requests.at(-1);
 e.p.mapPan('right');superseded.success({size:[900,450],mapOutputImage:'/stale.png',rendered_bbox:'stale'});e.render(b);
 e.p.mapUndo();assert.equal(e.request().get('bbox_map'),a);
});
test('successive Undo and Redo traverse each viewport and a new preview discards the old future',t=>{
 const e=viewportEditor(t),a='-90,-60,-30,10',b='-80,-60,-20,10',c='-80,-50,-20,20',d='-70,-50,-10,20';
 e.p.showMap();e.render(a);e.p.mapPan('right');e.render(b);e.p.mapPan('up');e.render(c);
 e.p.mapUndo();e.render(b);e.p.mapUndo();assert.equal(e.request().get('bbox_map'),a);e.render(a);
 e.p.mapRedo();assert.equal(e.request().get('bbox_map'),b);e.render(b);
 e.p.mapRedo();assert.equal(e.request().get('bbox_map'),c);e.render(c);
 e.p.mapUndo();e.render(b);e.p.mapUndo();e.render(a);e.p.mapPan('right');e.render(d);
 const count=e.requests.length;e.p.mapRedo();assert.equal(e.requests.length,count);
 e.p.mapUndo();assert.equal(e.request().get('bbox_map'),a);
});
test('jQuery and UI expose the required live editor widget APIs',t=>{
 const e=finish(t,editor());
 assert.equal(e.$.fn.jquery,'3.7.1');assert.equal(e.$.ui.version,'1.14.2');
 for(const name of ['tabs','dialog','accordion','slider','autocomplete','draggable'])assert.equal(typeof e.$.fn[name],'function',name);
});
test('mixed WKT and region groups reset border and hatch flags per group',t=>{
 const e=finish(t,editor());
 for(const [id,method,key] of [['fieldSetsWKT','loadWKT','wkt'],['fieldSetsRegions','loadRegions','regions']]){
  for(let i=0;i<2;i++)for(const flag of ['border','hatch'])e.$('#'+id).append(`<input type="checkbox" name="${key}[${i}][${flag}]">`);
  e.p[method]({map:{[key]:[{title:'one',data:'x',border:'on',hatch:'on'},{title:'two',data:'y'}]}});
  assert.equal(e.$(`[name="${key}[0][border]"]`).prop('checked'),true);
  assert.equal(e.$(`[name="${key}[1][border]"]`).prop('checked'),false);
  assert.equal(e.$(`[name="${key}[1][hatch]"]`).prop('checked'),false);
 }
});
test('HTTP failures release spinner, expose text error, preserve form',t=>{
 const e=finish(t,editor());let options;e.$.ajax=o=>(options=o,{abort(){}});
 e.$('#m-mapSaveTitle').val('keep me');e.p.showSpinner();e.p.postData('a=1');
 options.error({responseJSON:{error:'<script>untrusted</script>'}},'error');
 assert.equal(e.p.vars.spinner.css('display'),'none');
 assert.equal(e.$('#m-mapSaveTitle').val(),'keep me');
 assert.equal(e.$('.mapper-message-error').text(),'<script>untrusted</script>');
 assert.equal(e.$('.mapper-message-error script').length,0);
});
test('a new render aborts the old request and ignores its response',t=>{
 const e=finish(t,editor());let calls=[],aborted=0;e.$.ajax=o=>(calls.push(o),{abort(){aborted++;}});
 e.p.postData('first=1');e.p.postData('second=1');
 assert.equal(aborted,1);
 calls[0].success({size:[1,1],mapOutputImage:'stale.png'});
 assert.notEqual(e.$('#mapOutputImage').attr('src'),'stale.png');
});
test('image failures release loading state and allow another image',t=>{
 const e=finish(t,editor());e.p.showSpinner();e.p.drawMap({size:[900,450],mapOutputImage:'/missing.png'});
 e.$('#mapOutputImage').trigger('error');assert.equal(e.p.vars.spinner.css('display'),'none');assert.equal(e.$('[role="alert"]').length,1);
 e.p.loadCropSettings=()=>{};e.p.showSpinner();e.p.drawMap({size:[900,450],mapOutputImage:'/next.png'});
 e.$('#mapOutputImage').trigger('load');assert.equal(e.p.vars.spinner.css('display'),'none');
});
test('empty save title can be corrected without reopening the dialog',t=>{
 const e=finish(t,editor());let buttons,requests=0;
 e.$.fn.dialog=function(options){if(typeof options==='object')buttons=options.buttons;return this;};
 e.p.setFormOptions=()=>{};e.$.ajax=()=>{requests++;};e.p.mapSave();buttons[0].click.call(e.$('#mapSave')[0]);
 e.$('#m-mapSaveTitle').val('Corrected');buttons[0].click.call(e.$('#mapSave')[0]);assert.equal(requests,1);
});
test('Tab and Enter remain available to inputs and form submit previews',t=>{
 const e=finish(t,editor());let rendered=0;e.p.missingFieldSetTitle=()=>false;e.p.destroyRedo=()=>{};e.p.showMap=()=>{rendered++;};e.p.tabSelector=()=>{};
 e.p.disableDefaultButtons();const event=e.$.Event('keypress',{which:9,keyCode:9});e.$('#m-mapSaveTitle').trigger(event);assert.equal(event.isDefaultPrevented(),false);
 e.$('#form-mapper').trigger('submit');assert.equal(rendered,1);
});
test('cancelled file choice is safe and unexpected MIME uploads through parser boundary',t=>{
 const e=finish(t,editor());let requests=0,body;
 e.$.ajax=options=>{requests++;body=options.data;return e.$.Deferred().promise();};
 e.p.bindUpload();const input=e.$('#fileInput')[0];e.$(input).trigger('change');assert.equal(requests,0);
 Object.defineProperty(input,'files',{value:[new e.w.File(['Species,45,-120\rSpecies,46,-121'],'data.csv',{type:'application/vnd.ms-excel'})],configurable:true});
 e.$(input).trigger('change');assert.equal(requests,1);assert.equal(body.get('file').name,'data.csv');assert.equal(body.get('input_format'),'auto');
});
test('failed import preserves existing inputs and releases loading and picker',t=>{
 const e=finish(t,editor());let deferred=e.$.Deferred();e.$.ajax=()=>deferred.promise();
 e.$('#m-mapSaveTitle').val('Existing map');e.p.bindUpload();const input=e.$('#fileInput')[0];
 Object.defineProperty(input,'files',{value:[new e.w.File(['bad'],'data.json')],configurable:true});e.$(input).trigger('change');
 deferred.reject({responseJSON:{error:'Malformed input'}});
 assert.equal(e.$('#m-mapSaveTitle').val(),'Existing map');assert.equal(e.p.vars.spinner.css('display'),'none');assert.equal(input.disabled,false);assert.equal(e.$('[role=alert]').text(),'Malformed input');
});
test('a failed download leaves the export dialog usable and preserves its request body',async t=>{
 const e=finish(t,editor());let request,finished=0;e.w.AbortController=global.AbortController;
 e.p.settings.csrfToken='local-csrf';e.p.finishDownload=()=>{finished++;};
 e.w.fetch=async(url,options)=>{request={url,...options};return {ok:false};};
 await e.p.downloadFile('/application/','coords%5B0%5D%5Btitle%5D=sp%C3%A9cies','png');
 assert.equal(finished,1);assert.equal(request.headers['X-CSRF-Token'],'local-csrf');
 assert.equal(request.body,'coords%5B0%5D%5Btitle%5D=sp%C3%A9cies');assert.equal(e.$('[role="alert"]').length,1);
});
test('an interrupted download aborts within its configured deadline',async t=>{
 const e=finish(t,editor());let finished=0;e.w.AbortController=global.AbortController;e.p.settings.downloadTimeout=5;e.p.finishDownload=()=>{finished++;};
 e.w.fetch=(url,options)=>new Promise((resolve,reject)=>{options.signal.addEventListener('abort',()=>reject(new Error('aborted')));});
 await e.p.downloadFile('/application/','a=1','png');assert.equal(finished,1);assert.equal(e.$('[role="alert"]').length,1);
});
test('the custom built UI bundle provides all required widgets',t=>{
 const e=finish(t,editor());const manifest=JSON.parse(fs.readFileSync(path.join(root,'public/build/manifest.json')));
 e.w.eval(fs.readFileSync(path.join(root,'public/build',manifest.assets['jquery-ui'].file),'utf8'));
 for(const name of ['tabs','dialog','accordion','slider','autocomplete','draggable'])assert.equal(typeof e.$.fn[name],'function',name);
 assert.equal(typeof e.$.effects.effect.slide,'function');
});
test('retained legacy crop, rotation and color plugins load against upgraded libraries',t=>{
 const e=finish(t,editor());
 for(const name of ['jquery.Jcrop.js','jquery.colorpicker.js','jquery.tinycircleslider.js']){
  e.w.eval(fs.readFileSync(path.join(root,'public/javascript',name),'utf8'));
 }
 for(const name of ['Jcrop','ColorPicker','tinycircleslider'])assert.equal(typeof e.$.fn[name],'function',name);
});
test('imported point groups reset omitted shape and size to circle and ten',t=>{
 const e=finish(t,editor());e.$('#fieldSetsPoints').append('<input name="coords[0][title]"><textarea name="coords[0][data]"></textarea><select name="coords[0][shape]"><option>star</option><option>circle</option></select><select name="coords[0][size]"><option>50</option><option>10</option></select>');
 e.$('[name="coords[0][shape]"]').val('star');e.$('[name="coords[0][size]"]').val('50');e.p.loadCoordinates({map:{coords:[{title:'import',data:'0,0'}]}});
 assert.equal(e.$('[name="coords[0][shape]"]').val(),'circle');assert.equal(e.$('[name="coords[0][size]"]').val(),'10');
});
test('restoring SVG export preferences preserves explicit false preview options',t=>{
 const e=finish(t,editor());e.$('body').append('<input type="checkbox" id="legend"><input type="checkbox" id="scalebar"><input type="radio" id="download-svg">');
 e.$.fn.slider=function(){return this;};e.p.setRotation=()=>{};e.p.resetJbbox=()=>{};
 e.p.loadSettings({map:{save:{title:'test'},download_filetype:'svg',options:{legend:false,scalebar:false}}});
 assert.equal(e.$('#legend').prop('checked'),false);assert.equal(e.$('#scalebar').prop('checked'),false);
});
test('saved-map load applies actual PHP response groups, title, flags and viewport before preview',t=>{
 const e=finish(t,editor()),response=JSON.parse(fs.readFileSync(path.join(root,'Tests/fixtures/editor-map-response.json'),'utf8'));
 for(const group of ['coords','wkt','regions']){
  const parent=e.$(group==='coords'?'#fieldSetsPoints':group==='wkt'?'#fieldSetsWKT':'#fieldSetsRegions');
  for(let index=0;index<3;index++)parent.append(`<div class="fieldset-${group==='coords'?'points':group}"><input name="${group}[${index}][title]"><textarea name="${group}[${index}][data]"></textarea><input name="${group}[${index}][color]"><input type="checkbox" name="${group}[${index}][border]"><input type="checkbox" name="${group}[${index}][hatch]"><input type="checkbox" name="${group}[${index}][shadow]"><select name="${group}[${index}][shape]"><option>circle</option><option>star</option><option>square</option><option>triangle</option></select><select name="${group}[${index}][size]"><option>10</option></select></div>`);
 }
 e.$('#form-mapper').append('<input type="checkbox" id="countries" name="layers[countries]"><input id="bbox_map" name="bbox_map"><input id="projection_map" name="projection_map"><input id="origin_map" name="origin_map"><input id="origin" name="origin"><select id="projection" name="projection"><option>esri:102009</option></select>');
 e.p.addAccordionPanel=()=>e.$('#fieldSetsPoints').append('<div class="fieldset-points"><input name="coords[3][title]"><textarea name="coords[3][data]"></textarea><input name="coords[3][color]"><select name="coords[3][shape]"><option>triangle</option></select><select name="coords[3][size]"><option>10</option></select></div>');
 e.$.fn.slider=function(){return this;};e.p.setRotation=()=>{};e.p.resetJbbox=()=>{};e.p.tabSelector=()=>{};e.p.toggleUndo=()=>{};e.p.toggleRedo=()=>{};e.p.activateEmbed=()=>{};e.p.bindStorage=()=>{};
 let preview;e.p.showMap=()=>{preview=e.p.readDocument();};e.$.ajax=options=>{options.success(response);return {abort(){}};};
 e.p.loadMap(e.$('<a data-id="1038">')[0]);assert.equal(preview.coords.length,4);assert.equal(preview.coords.filter(row=>row.data).length,4);assert.equal(preview.layers.countries,'on');assert.equal(preview.save.title,'P5 comparison fixture');assert.equal(preview.origin,'-96');assert.equal(preview.projection_map,'epsg:4326');
});
test('loading coordinates preserves raw invalid rows for correction and matching diagnostics',t=>{
 const e=finish(t,editor()),raw='45,-73\n?*{}\\ invalid row';e.$('#fieldSetsPoints').append('<input name="coords[0][title]"><textarea name="coords[0][data]"></textarea>');e.p.loadCoordinates({map:{coords:[{title:'raw',data:raw}]}});assert.equal(e.$('[name="coords[0][data]"]').val(),raw);
});
test('legacy false origins load as projection defaults while zero remains numeric',t=>{
 const e=finish(t,editor());e.$('#form-mapper').append('<input id="origin" name="origin"><select id="projection"><option>esri:102009</option></select>');
 e.$.fn.slider=function(){return this;};e.p.setRotation=()=>{};e.p.resetJbbox=()=>{};
 e.p.loadSettings({map:{save:{title:'legacy'},projection:'esri:102009',origin:false,origin_map:false}});assert.equal(e.$('#origin').val(),'-96');assert.equal(e.$('#origin_map').val(),'');
 e.p.loadSettings({map:{save:{title:'zero'},projection:'esri:102009',origin:0,origin_map:0}});assert.equal(e.$('#origin').val(),'0');assert.equal(e.$('#origin_map').val(),'0');
});
test('geographic render origin sentinel stays blank for KML and settings requests while zero survives',t=>{
 const e=finish(t,editor());
 for(const [origin,expected] of [[false,''],[null,''],[undefined,''],[0,'0'],[-96,'-96']]){
  e.p.resetFormValues({rendered_origin:origin});assert.equal(e.$('#origin_map').val(),expected);
 }
});
test('tabs expose a single focusable tab and arrow keyboard navigation still activates panels',t=>{
 const e=finish(t,editor());e.$('body').append('<div id="test-tabs"><ul><li><a href="#test-one">One</a></li><li><a href="#test-two">Two</a></li></ul><div id="test-one">first</div><div id="test-two">second</div></div>');
 const tabs=e.$('#test-tabs');tabs.tabs({activate(){e.p.accessibleTabs(tabs);}});e.p.accessibleTabs(tabs);
 assert.equal(tabs.find('li[role=tab]').length,0);assert.equal(tabs.find('a[role=tab]').length,2);assert.equal(tabs.find('a[role=tab][tabindex=0]').length,1);
 tabs.find('a[role=tab]').first().trigger('focus').trigger(e.$.Event('keydown',{key:'ArrowRight'}));
 assert.equal(tabs.tabs('option','active'),1);assert.equal(e.w.document.activeElement.textContent,'Two');
 assert.equal(tabs.find('li[aria-controls],li[aria-selected],li[aria-expanded],li[tabindex]').length,0);assert.equal(tabs.find('a[role=tab][aria-selected=true]').text(),'Two');
});
test('accordion headings act as buttons with labeled regions without nested focusable links',t=>{
 const e=finish(t,editor());e.$('#fieldSetsPoints').addClass('fieldSets').append('<div class="form-item"><h3><a href="#">Layer One</a></h3><div><input></div></div>');
 e.p.bindAccordions();e.p.associateGroupLabels();assert.equal(e.$('#fieldSetsPoints').attr('role'),undefined);assert.equal(e.$('#fieldSetsPoints h3').attr('role'),'button');assert.equal(e.$('#fieldSetsPoints h3 a').attr('href'),undefined);assert.equal(e.$('#fieldSetsPoints .ui-accordion-content').attr('role'),'region');
});
test('keyboard rotation updates canonical form rotation and announced slider value',t=>{
 const e=finish(t,editor());e.$('#form-mapper').append('<input id="rotation" name="rotation">');e.$('body').append('<div id="mapControls"><div class="thumb"></div><ul class="overview"><li></li></ul></div>');e.p.setRotation(45);assert.equal(e.$('#rotation').val(),'45');assert.equal(e.$('#mapControls .thumb').attr('aria-valuenow'),'45');
});
