import test from 'node:test';
import assert from 'node:assert/strict';
import {conceptLayout,edgeGeometry,moduleLinks} from '../lib/graph.mjs';
test('concept positions stay stable when other concepts are hidden',()=>{
 const catalog=Array.from({length:12},(_,i)=>({id:'n'+i,module:'m',title:'概念'+i}));
 const all=conceptLayout(catalog,catalog,false),one=conceptLayout([catalog[8]],catalog,false);
 assert.deepEqual(one[0],all[8]);
});
test('arrows end outside concept cards and reciprocal curves separate',()=>{
 const a={x:0,y:0},b={x:300,y:0};const p=edgeGeometry(a,b,false),q=edgeGeometry(b,a,false);
 assert.ok(p.start.x>60&&p.end.x<240);assert.notEqual(p.control.y,q.control.y);
 assert.ok(!p.path.includes('NaN'));
});
test('module links aggregate authored direction without inventing theory',()=>{
 const nodes=[{id:'a',module:'x'},{id:'b',module:'y'},{id:'c',module:'x'}];
 const rels=[{id:'r1',from:'a',to:'b'},{id:'r2',from:'c',to:'b'},{id:'r3',from:'b',to:'a'}];
 const links=moduleLinks(nodes,rels);assert.equal(links.length,2);assert.equal(links[0].count,2);assert.equal(links[0].relations[0].id,'r1');
});
test('focused neighborhoods fit a readable local canvas across chapters',async()=>{
 const {focusLayout}=await import('../lib/graph.mjs');
 const ns=Array.from({length:15},(_,i)=>({id:'p'+i,module:'m'+(i%7)}));
 const placed=focusLayout(ns,'p0');assert.equal(placed[0].x,500);assert.equal(placed[0].y,450);
 assert.ok(placed.every(n=>n.x>=100&&n.x<=900&&n.y>=50&&n.y<=850));
});
