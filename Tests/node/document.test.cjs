const assert=require('node:assert/strict');
const test=require('node:test');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'../..');
const fixture={schema_version:1,coords:[{title:'Montréal 😀',data:'45,-73',shadow:false,size:0},{title:'Zero',data:'0,0',shadow:true}],wkt:[{title:'one',data:'POINT (0 0)',border:'on',hatch:1},{title:'two',data:'POINT (1 1)',border:false,hatch:0}],regions:[{title:'CAN',data:'CAN',border:false}],projection:'esri:102009',projection_map:'esri:102009',bbox_map:'-12,-13,15,16',origin:0,origin_map:0,layers:{countries:'on',lakesOutline:false},options:{legend:'on'},save:{title:'mixed'},csrf:'secret',download_token:'secret'};
function editor(t,prepare){
 const dom=new JSDOM('',{url:'http://localhost',runScripts:'outside-only'});t.after(()=>dom.window.close());
 if(prepare)prepare(dom.window);
 for(const name of ['map-document','editor-storage'])dom.window.eval(fs.readFileSync(path.join(root,'public/javascript',name+'.js'),'utf8'));
 dom.window.SimpleMapprStorage.init('owner-one',true);
 return dom.window;
}
test('map document roundtrips independent mixed groups, flags, viewport zero and case-sensitive layers',t=>{
 const w=editor(t),doc=w.SimpleMapprDocument.normalize(fixture);
 assert.equal(doc.origin_map,0);assert.equal(doc.coords[0].shadow,false);assert.equal(doc.wkt[1].hatch,0);assert.equal(doc.layers.lakesOutline,false);
 assert.ok(!JSON.stringify(doc).includes('secret'));
 assert.deepEqual(JSON.parse(JSON.stringify(w.SimpleMapprDocument.normalize(doc))),JSON.parse(JSON.stringify(doc)));
});
test('legacy wrapper and field entries migrate to version one without transport fields',t=>{
 const w=editor(t),doc=w.SimpleMapprDocument.normalize({map:fixture});assert.equal(doc.schema_version,1);
 const from=w.SimpleMapprDocument.fromEntries([{name:'coords[0][title]',value:'test'},{name:'coords[0][data]',value:'0,0'},{name:'layers[lakesOutline]',value:'on'},{name:'origin_map',value:'0'},{name:'csrf',value:'secret'}]);
 assert.equal(from.coords[0].data,'0,0');assert.equal(from.layers.lakesOutline,'on');assert.equal(from.origin_map,'0');assert.ok(!JSON.stringify(from).includes('secret'));
});
test('future versions, nested values and group overflow fail before applying inputs',t=>{
 const w=editor(t),doc=w.SimpleMapprDocument;
 assert.throws(()=>doc.normalize({...fixture,schema_version:2}));assert.throws(()=>doc.normalize({...fixture,wkt:[{title:'bad',data:{x:1}}]}));assert.throws(()=>doc.normalize({...fixture,coords:Array(11).fill({title:'too many',data:'0,0'})}));
});
test('storage preserves current document and unrelated keys while limiting known history',t=>{
 const w=editor(t),store=w.SimpleMapprStorage;w.localStorage.setItem('unrelated','keep');
 store.set('do-123',fixture);store.current({...fixture,save:{title:'draft'}});assert.equal(store.current().save.title,'draft');
 store.crop('{"x":1}');store.flush();assert.equal(w.localStorage.getItem('unrelated'),'keep');assert.equal(store.crop(),'{"x":1}');assert.equal(store.index().length,0);assert.equal(store.current(),null);
});
test('legacy jStorage migration imports recognized maps and excludes secrets and unrelated origin keys',t=>{
 const w=editor(t,w=>{w.localStorage.setItem('jStorage',JSON.stringify({'do-123':fixture,'undo-124':fixture,unrelated:'value',jStorage_meta:{}}));w.localStorage.setItem('unrelated','keep');});
 assert.equal(w.SimpleMapprStorage.index().join(','),'do-123,undo-124');assert.equal(w.localStorage.getItem('unrelated'),'keep');assert.ok(!w.localStorage.getItem('simplemappr.editor.v1.owner-one').includes('secret'));
});
test('localStorage quota or policy failures retain an in-memory editor document',t=>{
 const w=editor(t,w=>{Object.defineProperty(w,'localStorage',{get(){throw new Error('disabled');}});});
 w.SimpleMapprStorage.set('do-123',fixture);assert.equal(w.SimpleMapprStorage.get('do-123').coords[0].title,'Montréal 😀');
});

test('guest and separate owner scopes never restore another owner private document',t=>{
 const w=editor(t),store=w.SimpleMapprStorage;store.set('do-123',fixture);
 store.init('guest',false);assert.equal(store.current(),null);assert.equal(store.index().length,0);
 store.init('owner-two',false);assert.equal(store.current(),null);
 store.init('owner-one',false);assert.equal(store.current().coords[0].title,'Montréal 😀');
});
test('guest initialization does not adopt unscoped legacy private history',t=>{
 const w=editor(t,w=>w.localStorage.setItem('jStorage',JSON.stringify({'do-123':fixture})));
 w.SimpleMapprStorage.init('guest',false);assert.equal(w.SimpleMapprStorage.current(),null);
});
test('real PHP saved-map response accepts empty dictionaries and preserves all source groups',t=>{
 const w=editor(t),response=JSON.parse(fs.readFileSync(path.join(root,'Tests/fixtures/editor-map-response.json'),'utf8'));
 const doc=w.SimpleMapprDocument.normalize(response);assert.equal(doc.coords.length,4);assert.equal(doc.coords.map(row=>row.data.split('\n').length).reduce((a,b)=>a+b),9);assert.equal(doc.save.title,'P5 comparison fixture');assert.equal(doc.layers.countries,'on');assert.equal(Object.keys(doc.options).length,0);
 assert.throws(()=>w.SimpleMapprDocument.normalize({...response.map,options:['legend']}));
 const withUnderscore=w.SimpleMapprDocument.fromEntries([{name:'layers[stateprovinces_polygon]',value:'on'},{name:'layers[lakesOutline]',value:'on'}]);assert.equal(withUnderscore.layers.stateprovinces_polygon,'on');assert.equal(withUnderscore.layers.lakesOutline,'on');
});
test('UI and rendering boundary share checkbox flag values',t=>{
 const w=editor(t);for(const value of [true,1,'1',' true ','ON','yes','YES'])assert.equal(w.SimpleMapprDocument.enabled(value),true);
 for(const value of [false,0,'0','false','off','',null])assert.equal(w.SimpleMapprDocument.enabled(value),false);
});
test('large valid documents evict old history to persist the newest input under browser quota',t=>{
 const w=editor(t),store=w.SimpleMapprStorage,large={...fixture,coords:Array.from({length:4},(_,index)=>({title:'Group '+index,data:'0,0\n'.repeat(88000)}))};
 for(let version=1;version<=3;version++)store.set('do-10'+version,{...large,save:{title:'Version '+version}});
 assert.equal(store.current().save.title,'Version 3');store.init('owner-one',false);assert.equal(store.current().save.title,'Version 3');assert.equal(store.current().coords[0].data.length,352000);assert.ok(store.index().length<3);
});
