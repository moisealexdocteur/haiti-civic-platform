const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/portal-wizard.js','utf8');
const scan = source.slice(source.indexOf('    function scanCardOnServer'),source.indexOf('    var departmentInput'));
function setup(response, status=200, timeout=false) {
 let sent, csrf, url;
 class XHR {
  open(method, path) { assert.equal(method,'POST'); url=path; }
  setRequestHeader() {}
  send(data) { sent=data; this.status=status; this.responseText=JSON.stringify(response); timeout ? this.ontimeout() : this.onload(); }
 }
 const context={XMLHttpRequest:XHR,FormData,Promise,Error,JSON,encodeURIComponent,slug:'demo',
  csrfField:()=>({name:'csrf_test',value:'token'}),updateCsrf:p=>{csrf=p.csrf},t:k=>k};
 vm.createContext(context); vm.runInContext(scan,context);
 return {run:()=>context.scanCardOnServer(new Blob(['synthetic'])), read:()=>({sent,csrf,url})};
}
test('prefill works without native TextDetector or BarcodeDetector',async()=>{
 const fields={ninu:'0000000000',firstName:'TEST',lastName:'EXEMPLE'};
 const s=setup({ok:true,fields,csrf:{hash:'renewed'}});
 assert.deepEqual(JSON.parse(JSON.stringify(await s.run())),fields);
 assert.equal(s.read().url,'/inscription/demo/carte/lire');
 assert.equal(s.read().sent.get('csrf_test'),'token');
 assert.equal(s.read().sent.get('card').name,'card.jpg');
 assert.equal(s.read().csrf.hash,'renewed');
});
test('rate limiting retains the server message',async()=>{
 await assert.rejects(setup({ok:false,message:'retry'},429).run(),e=>e.scanMessage==='retry');
});
test('timeout leaves manual entry available through an error',async()=>{
 await assert.rejects(setup({},200,true).run(),e=>e.scanMessage==='scanBusy');
});
