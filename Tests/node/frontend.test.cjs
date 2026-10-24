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
test('failed Undo and Redo previews leave their destinations available for retry',t=>{
 const e=viewportEditor(t),a='-90,-60,-30,10',b='-80,-60,-20,10',c='-80,-50,-20,20';
 e.p.showMap();e.render(a);e.p.mapPan('right');e.render(b);e.p.mapPan('up');e.render(c);
 e.p.mapUndo();e.requests.at(-1).error({},'error');e.p.mapUndo();assert.equal(e.request().get('bbox_map'),b);e.render(b);
 e.p.mapRedo();e.respond(c);e.$('#mapOutputImage').trigger('error');
 const count=e.requests.length;e.p.mapRedo();assert.equal(e.requests.length,count+1);assert.equal(e.request().get('bbox_map'),c);e.render(c);
});
test('Undo and Redo restore expanded preview width without losing their destinations',t=>{
 const e=viewportEditor(t),bbox='-90,-60,-30,10';
 Object.defineProperty(e.w.document.documentElement,'clientWidth',{value:1400});
 e.$('body').append('<div id="mapToolsCollapse"><a href="#">Toggle settings</a></div><div id="mapTools"></div><div id="map"></div>');e.p.bindPanelToggle();
 e.p.showMap();e.render(bbox);e.$('#mapToolsCollapse a').trigger('click');assert.equal(e.request().get('width'),'1372');e.render(bbox);
 e.p.mapUndo();assert.equal(e.request().get('width'),'900');assert.equal(e.$('#mapToolsCollapse').hasClass('mapTools-collapsed'),true);e.render(bbox);assert.equal(e.$('#mapToolsCollapse').hasClass('mapTools-collapsed'),false);
 const count=e.requests.length;e.p.mapRedo();assert.equal(e.requests.length,count+1);assert.equal(e.request().get('width'),'1372');e.render(bbox);
 e.p.mapUndo();assert.equal(e.request().get('width'),'900');
});
test('a pending preview records submitted data without losing later draft edits',t=>{
 const e=viewportEditor(t);
 e.$('#form-mapper').append('<textarea name="coords[0][data]">0,0</textarea>');
 const field=e.$('[name="coords[0][data]"]');
 e.p.showMap();assert.equal(e.request().get('coords[0][data]'),'0,0');
 field.val('10,10');e.respond('-90,-60,-30,10');
 field.val('20,20');e.$('#mapOutputImage').trigger('load');
 const names=e.p.storageType('do-'),saved=e.w.SimpleMapprStorage.get(names.at(-1));
 assert.equal(saved.coords[0].data,'0,0');
 assert.equal(saved.bbox_map,'-90,-60,-30,10');
 assert.equal(field.val(),'20,20');
 assert.equal(e.w.SimpleMapprStorage.current().coords[0].data,'20,20');
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
test('failed region selection releases the spinner and reports a useful error',t=>{
 const e=finish(t,editor());let request;e.p.vars.fillColor={r:150,g:150,b:150};
 e.$.ajax=options=>(request=options,{});e.p.aQuery({data:e.p});
 request.error({responseJSON:{error:'Selection failed. Please try again.'}},'error');
 assert.equal(e.p.vars.spinner.css('display'),'none');
 assert.equal(e.$('.mapper-message-error').text(),'Selection failed. Please try again.');
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
test('mouse rotation updates the announced slider value before rendering',t=>{
 const e=finish(t,editor());e.$('#form-mapper').append('<input id="rotation" name="rotation">');e.$('body').append('<div id="mapControls"><div class="thumb" aria-valuenow="0"></div><ul class="overview"><li data-rotate="85"></li></ul></div>');
 let rendered=0;e.p.showMap=()=>{rendered++;};e.p.performRotation(e.$('#mapControls li')[0]);
 assert.equal(rendered,1);assert.equal(e.$('#rotation').val(),'85');assert.equal(e.$('#mapControls .thumb').attr('aria-valuenow'),'85');
});
test('added and renumbered groups keep unique accordion targets and field labels',t=>{
 const e=finish(t,editor());e.$.fn.ColorPicker=function(){return this;};
 const groups=e.$('#fieldSetsPoints').addClass('fieldSets');
 for(let i=0;i<3;i++)groups.append(`<div class="form-item fieldset-points"><button class="removemore">Remove</button><h3><a href="#">Layer ${i+1}</a></h3><div><label data-field="title">Legend</label><input class="m-mapTitle" name="coords[${i}][title]"><label data-field="data">Map data</label><textarea name="coords[${i}][data]"></textarea><button class="clearself">Clear</button></div></div>`);
 groups.after('<p><button class="addmore" data-type="coords">Add layer</button></p>');e.p.bindAccordions();e.p.associateGroupLabels();
 e.p.addAccordionPanel('coords');e.p.addAccordionPanel('coords');e.p.removeAccordionPanel(groups.children().eq(3),'coords');
 const ids=Array.from(e.w.document.querySelectorAll('[id]'),node=>node.id);assert.equal(new Set(ids).size,ids.length);
 groups.find('h3').each(function(){const content=e.w.document.getElementById(e.$(this).attr('aria-controls'));assert.equal(content,this.nextElementSibling);assert.equal(content.getAttribute('aria-labelledby'),this.id);});
 groups.find('label').each(function(){assert.equal(e.w.document.getElementById(this.htmlFor).closest('.form-item'),this.closest('.form-item'));});
 assert.equal(groups.children().last().find('textarea').attr('name'),'coords[3][data]');
});
test('Clear All resets marker shape and size on every point layer',t=>{
 const e=finish(t,editor());
 for(let i=0;i<2;i++)e.$('#fieldSetsPoints').append('<div class="fieldset-points"><select class="m-mapShape">'+['','plus','cross','asterisk','circle','square'].map(v=>'<option>'+v+'</option>').join('')+'</select><select class="m-mapSize">'+['','6','8','10','16'].map(v=>'<option>'+v+'</option>').join('')+'</select></div>');
 e.$('#fieldSetsPoints .m-mapShape').val('square');e.$('#fieldSetsPoints .m-mapSize').val('16');
 e.p.clearZone(e.$('#fieldSetsPoints').children());
 assert.deepEqual(Array.from(e.$('#fieldSetsPoints .m-mapShape'),n=>n.value),['circle','circle']);
 assert.deepEqual(Array.from(e.$('#fieldSetsPoints .m-mapSize'),n=>n.value),['10','10']);
});
test('Tab accepts the active country autocomplete suggestion without an error',t=>{
 const e=finish(t,editor());e.$('#fieldSetsRegions').append('<textarea name="regions[0][data]"></textarea>');
 e.$.getJSON=(url,data,respond)=>respond([{label:'Canada',value:'Canada'}]);e.p.bindAutocomplete();
 const field=e.$('#fieldSetsRegions textarea');field.val('Can').autocomplete('search');
 field.autocomplete('instance').menu.next();
 field.trigger(e.$.Event('keydown',{key:'Tab',keyCode:9,which:9}));
 assert.equal(field.val(),'Canada, ');
});
test('Help loads its country-code table without an obsolete waypoint plugin',t=>{
 const e=finish(t,editor()),loaded=[];
 e.$('body').append('<div id="map-help"><h3>Tools</h3><h3>Countries</h3><div id="country-codes"></div></div>');
 e.w.SimpleMappr.loadCodes=element=>loaded.push(element.attr('id'));
 const help=fs.readFileSync(path.join(root,'views/help.html'),'utf8');
 e.w.eval(help.match(/<script>([\s\S]*?)<\/script>/)[1]);
 assert.deepEqual(loaded,['country-codes']);
});

test('fill queries the rendered viewport when form projection or rotation has changed',t=>{
 const e=finish(t,editor());let request;e.p.vars.fillColor={r:150,g:150,b:150};
 e.$('#form-mapper').append('<input id="rendered_bbox" value="-180,-90,180,90"><input id="rendered_projection" value="epsg:4326"><input id="rendered_rotation" value="90"><input id="projection" value="esri:102009"><input id="projection_map" value="esri:102009"><input id="origin" value="-96"><input id="rotation" value="0">');
 e.$('#origin_map').val('0');e.$('#rendered_origin').remove();e.$.ajax=options=>(request=options,{});e.p.aQuery({data:e.p});
 assert.equal(request.data.projection,'epsg:4326');assert.equal(request.data.projection_map,'epsg:4326');assert.equal(request.data.origin,'0');assert.equal(request.data.origin_map,'0');assert.equal(request.data.rotation,'90');
});

test('failed new branches and ocean clicks retain the available Redo destination',t=>{
 const e=viewportEditor(t),a='-90,-60,-30,10',b='-80,-60,-20,10';
 e.p.showMap();e.render(a);e.p.mapPan('right');e.render(b);e.p.mapUndo();e.render(a);
 e.p.mapPan('up');e.requests.at(-1).error({},'error');
 let count=e.requests.length;e.p.mapRedo();assert.equal(e.requests.length,count+1);assert.equal(e.request().get('bbox_map'),b);e.render(b);
 e.p.mapUndo();e.render(a);e.p.mapPan('up');e.respond('-90,-50,-30,20');e.$('#mapOutputImage').trigger('error');
 count=e.requests.length;e.p.mapRedo();assert.equal(e.requests.length,count+1);e.render(b);
 e.p.mapUndo();e.render(a);e.p.vars.fillColor={r:150,g:150,b:150};e.p.aQuery({data:e.p});e.requests.at(-1).success([]);
 count=e.requests.length;e.p.mapRedo();assert.equal(e.requests.length,count+1);assert.equal(e.request().get('bbox_map'),b);
});
test('Ctrl+X activates crop when the keyboard event reaches the preview',t=>{
 const e=finish(t,editor());let cropped=0;e.p.mapCrop=()=>{cropped++;};e.p.bindHotkeys();
 e.$('#mapOutput').trigger(e.$.Event('keydown',{key:'x',ctrlKey:true}));assert.equal(cropped,1);
});
for(const action of ['mapUndo','mapRedo'])for(const failure of ['request','image','malformed'])test(`${action} ${failure} failure retains the displayed map, draft and panel width`,t=>{
 const e=viewportEditor(t),a='-90,-60,-30,10',b='-80,-60,-20,10';
 e.$('body').append('<div id="mapToolsCollapse"><a href="#">Toggle settings</a></div><div id="mapTools"></div><div id="map"></div>');
 e.$('#form-mapper').append('<textarea name="coords[0][data]">0,0</textarea>');
 e.p.showMap();e.render(a);e.$('#width').val('1372');e.p.restorePreviewWidth(1372);e.p.mapPan('right');e.render(b,'esri:102015',-55);
 if(action==='mapRedo'){e.p.mapUndo();e.render(a);}
 e.$('[name="coords[0][data]"]').val('draft kept');
 const doc=JSON.stringify(e.p.readDocument()),image=e.$('#mapOutputImage').attr('src'),width=e.$('#map').width(),history=JSON.stringify(e.w.SimpleMapprStorage.index());
 e.p[action]();
 if(failure==='request')e.requests.at(-1).error({},'error');
 else if(failure==='malformed')e.requests.at(-1).success({});
 else {e.respond('failed viewport','epsg:4326',false);e.$('#mapOutputImage').trigger('error');}
 assert.equal(JSON.stringify(e.p.readDocument()),doc);assert.equal(e.$('#map').width(),width);assert.equal(e.$('#mapOutputImage').attr('src'),image);assert.equal(JSON.stringify(e.w.SimpleMapprStorage.index()),history);
 e.p.mapPan('left');assert.equal(e.request().get('bbox_map'),action==='mapUndo'?b:a);
});

test('a superseded history image cannot replace the rollback view',t=>{
 const e=viewportEditor(t),a='-90,-60,-30,10',b='-80,-60,-20,10';
 e.p.showMap();e.render(a);e.p.mapPan('right');e.render(b);const image=e.$('#mapOutputImage').attr('src');
 e.p.mapUndo();e.respond(a);e.p.mapUndo();e.requests.at(-1).error({},'error');
 assert.equal(e.$('#mapOutputImage').attr('src'),image);assert.equal(e.$('#bbox_map').val(),b);
});
function drag(e,type,x,y,extra={}){e.$('#mapOutputImage').trigger(e.$.Event(type,{button:0,pointerId:1,pointerType:'mouse',clientX:x,clientY:y,...extra}));}
function tf(e){return e.$('#mapOutputImage')[0].style.transform;}
function draggableEditor(t){const e=viewportEditor(t);e.p.bindDragPan();e.p.showMap();e.render('-90,-60,-30,10');return e;}
test('dragging the map slides the image and sends one pixel-offset pan on release',t=>{
 const e=draggableEditor(t),before=e.requests.length;
 drag(e,'pointerdown',100,100);drag(e,'pointermove',130,80);
 assert.equal(tf(e),'translate(30px,-20px)');
 assert.equal(e.requests.length,before,'nothing is rendered while dragging');
 drag(e,'pointerup',130,80);
 assert.equal(e.requests.length,before+1);assert.equal(e.request().get('pan'),'30,-20');
 assert.equal(tf(e),'translate(30px,-20px)','the image stays put until the new render arrives');
 e.render('-95,-55,-35,15');
 assert.equal(tf(e),'');assert.equal(e.$('#pan').val(),'');
});
test('a small wobble is a click and does not pan, so double-click zoom keeps working',t=>{
 const e=draggableEditor(t),before=e.requests.length;
 drag(e,'pointerdown',100,100);drag(e,'pointermove',102,101);drag(e,'pointerup',102,101);
 assert.equal(e.requests.length,before);assert.equal(tf(e),'');
});
test('Escape cancels a drag and the image snaps back without a render',t=>{
 const e=draggableEditor(t),before=e.requests.length;
 drag(e,'pointerdown',100,100);drag(e,'pointermove',160,100);
 e.$('#mapOutput').trigger(e.$.Event('keydown',{key:'Escape'}));drag(e,'pointerup',160,100);
 assert.equal(e.requests.length,before);assert.equal(tf(e),'');
});
test('a failed pan render snaps the image back and does not leave a pending pan behind',t=>{
 const e=draggableEditor(t);
 drag(e,'pointerdown',100,100);drag(e,'pointermove',150,100);drag(e,'pointerup',150,100);
 assert.equal(e.request().get('pan'),'50,0');e.requests.at(-1).error({},'error');
 assert.equal(tf(e),'');assert.equal(e.$('#pan').val(),'');
});
test('touch pointers, other buttons and active crop or zoom tools never start a pan',t=>{
 const e=draggableEditor(t),before=e.requests.length;
 drag(e,'pointerdown',100,100,{pointerType:'touch'});drag(e,'pointermove',200,100,{pointerType:'touch'});drag(e,'pointerup',200,100,{pointerType:'touch'});
 drag(e,'pointerdown',100,100,{button:2});drag(e,'pointermove',200,100);drag(e,'pointerup',200,100);
 e.$('#mapOutput').append('<div class="jcrop-holder"></div>');
 drag(e,'pointerdown',100,100);drag(e,'pointermove',200,100);drag(e,'pointerup',200,100);
 assert.equal(e.requests.length,before);assert.equal(tf(e),'');
});
function signedInEditor(t,hash){
 const e=finish(t,editor());e.w.location.hash=hash;e.$('body').append('<div id="usermaps"></div><textarea id="settings-import"></textarea>');
 const tabs=[];e.p.tabSelector=index=>tabs.push(index);e.p.loadMapList=()=>{};e.p.loadShareList=()=>{};
 e.p.getUserData();return tabs;
}
test('a signed-in user starts on the default Preview tab and the URL tab is left to the restore',t=>{
 for(const hash of ['','foo=bar','tabs=0','tabs=2'])assert.deepEqual(signedInEditor(t,hash),[],hash);
});
test('a tab named in the URL hash opens that tab whatever position the role gives it',t=>{
 for(const [hash,expected] of [['tabs=help',2],['tabs=about',3],['tabs=3',3],['tabs=nope',0],['tabs=',0]]){
  const e=finish(t,editor());e.$.fn.slider=function(){return this;};
  e.$('body').append('<div id="mapTools"><ul><li><a href="#m0">m</a></li></ul><div id="m0"></div></div><div id="tabs"><ul class="navigation"><li><a href="#p0">P</a></li><li><a href="#p1">Q</a></li><li><a href="help?locale=en_US">Help</a></li><li><a href="about?locale=en_US">About</a></li></ul><div id="p0"></div><div id="p1"></div></div>');
  e.$.ajax=()=>{const xhr={abort(){},done(){return xhr;},fail(){return xhr;},always(){return xhr;}};return xhr;};
  e.w.location.hash=hash;e.p.bindTabs();
  assert.equal(e.$('#tabs').tabs('option','active'),expected,hash);
 }
});
function slide(e,from,to){drag(e,'pointerdown',from[0],from[1]);drag(e,'pointermove',to[0],to[1]);drag(e,'pointerup',to[0],to[1]);}
test('the preview frame keeps clipping the slid image until the new render arrives',t=>{
 const e=draggableEditor(t);
 slide(e,[100,100],[150,100]);
 assert.equal(e.$('#mapOutput').hasClass('mapper-panned'),true,'released but still waiting for the render');
 assert.equal(e.$('#mapOutput').hasClass('mapper-panning'),false,'the grab cursor ends on release');
 assert.notEqual(e.$('#mapOutput')[0].style.width,'');
 e.render('-95,-55,-35,15');
 assert.equal(e.$('#mapOutput').hasClass('mapper-panned'),false);assert.equal(e.$('#mapOutput')[0].style.width,'');assert.equal(tf(e),'');
});
test('a second drag while the first render is pending continues from the slid image and sends the total offset',t=>{
 const e=draggableEditor(t);
 slide(e,[100,100],[130,100]);const first=e.requests.length;
 drag(e,'pointerdown',100,100);drag(e,'pointermove',110,100);
 assert.equal(tf(e),'translate(40px,0px)','no snap back to the original place');
 drag(e,'pointerup',110,100);
 assert.equal(e.requests.length,first+1);assert.equal(e.request().get('pan'),'40,0','measured from the last rendered view');
});
test('a render that arrives mid-drag keeps the slide and measures the rest from the new view',t=>{
 const e=draggableEditor(t);
 slide(e,[100,100],[130,100]);
 drag(e,'pointerdown',100,100);drag(e,'pointermove',110,100);
 e.render('-95,-55,-35,15');
 assert.equal(tf(e),'translate(10px,0px)','the image under the pointer does not jump');
 assert.equal(e.$('#mapOutput').hasClass('mapper-panned'),true,'still clipped while the pointer drags');
 drag(e,'pointermove',120,100);drag(e,'pointerup',120,100);
 assert.equal(e.request().get('pan'),'20,0');
});
test('Escape during a second drag returns to the pending slide and not to the original place',t=>{
 const e=draggableEditor(t),before=e.requests.length;
 slide(e,[100,100],[130,100]);
 drag(e,'pointerdown',100,100);drag(e,'pointermove',160,100);
 e.$('#mapOutput').trigger(e.$.Event('keydown',{key:'Escape'}));
 assert.equal(tf(e),'translate(30px,0px)');assert.equal(e.requests.length,before+1);
});
function wheel(e,deltaY,x,y,extra={}){const event=new e.w.WheelEvent('wheel',{deltaY,clientX:x,clientY:y,bubbles:true,cancelable:true,...extra});e.$('#mapOutputImage')[0].dispatchEvent(event);return event;}
function settled(e){return new Promise(resolve=>e.w.setTimeout(resolve,5));}
function wheelEditor(t){const e=draggableEditor(t);e.p.vars.wheelSettleMs=0;e.$('#mapOutputImage').css({width:'900px',height:'450px'});
 // jsdom has no layout: report the image box the way a browser does, moved by the translate and grown by the scale.
 const img=e.$('#mapOutputImage')[0];img.getBoundingClientRect=()=>{const v=e.$(img).data('shownView')||{x:0,y:0,s:1};return {left:v.x,top:v.y,width:900*v.s,height:450*v.s,right:v.x+900*v.s,bottom:v.y+450*v.s};};e.$('#form-mapper').append('<input type="hidden" id="view_rect" name="view_rect">');return e;}
test('one wheel notch up zooms 2x around the pointer in a single render',async t=>{
 const e=wheelEditor(t),before=e.requests.length;
 const event=wheel(e,-100,300,150);
 assert.equal(event.defaultPrevented,true,'the page does not scroll under the map');
 assert.equal(tf(e),'translate(-300px,-150px) scale(2)','the image scales at once around the pointer');
 assert.equal(e.requests.length,before,'nothing is rendered until the wheel settles');
 await settled(e);
 assert.equal(e.requests.length,before+1);assert.equal(e.request().get('view_rect'),'150,75,600,300');assert.equal(e.request().get('pan'),'');
});
test('fast notches add up into one render and keep the same point under the pointer',async t=>{
 const e=wheelEditor(t),before=e.requests.length;
 wheel(e,-100,300,150);wheel(e,-100,300,150);wheel(e,-100,300,150);await settled(e);
 assert.equal(e.requests.length,before+1);assert.equal(e.request().get('view_rect'),'262.5,131.25,375,187.5');
});
test('a notch down zooms out 2x and asks for a view larger than the old map',async t=>{
 const e=wheelEditor(t);
 wheel(e,100,450,225);await settled(e);
 assert.equal(e.request().get('view_rect'),'-450,-225,1350,675');
});
test('trackpad crumbs below one notch put the image back without a render',async t=>{
 const e=wheelEditor(t),before=e.requests.length;
 wheel(e,-10,300,150,{ctrlKey:true});wheel(e,-15,300,150,{ctrlKey:true});
 assert.notEqual(tf(e),'');await settled(e);
 assert.equal(e.requests.length,before);assert.equal(tf(e),'');
});
test('a wheel zoom during a pending pan combines with it instead of jumping back',async t=>{
 const e=wheelEditor(t);
 slide(e,[100,100],[130,100]);assert.equal(e.request().get('pan'),'30,0');
 wheel(e,-100,300,150);assert.equal(tf(e),'translate(-240px,-150px) scale(2)');
 await settled(e);
 assert.equal(e.request().get('view_rect'),'120,75,570,300');assert.equal(e.request().get('pan'),'','the pan is folded into the view, not sent twice');
});
test('a render that lands mid-wheel keeps the zoom under the pointer and measures from the new view',async t=>{
 const e=wheelEditor(t);
 slide(e,[100,100],[130,100]);e.p.vars.wheelSettleMs=1000;
 wheel(e,-100,300,150);e.render('-95,-55,-35,15');
 assert.equal(tf(e),'translate(-300px,-150px) scale(2)');
 e.p.settleWheel();assert.equal(e.request().get('view_rect'),'150,75,600,300');
});
test('the wheel does nothing while the crop, zoom or query tool is active',async t=>{
 const e=wheelEditor(t),before=e.requests.length;
 e.$('#mapOutput').append('<div class="jcrop-holder"></div>');
 const event=wheel(e,-100,300,150);await settled(e);
 assert.equal(event.defaultPrevented,false);assert.equal(e.requests.length,before);assert.equal(tf(e),'');
});
test('arrow pans and other zooms clear a pending view so the server never gets both',async t=>{
 const e=wheelEditor(t);
 wheel(e,-100,300,150);await settled(e);assert.notEqual(e.request().get('view_rect'),'');
 e.p.mapPan('right');assert.equal(e.request().get('view_rect'),'');assert.equal(e.request().get('pan'),'right');
});
