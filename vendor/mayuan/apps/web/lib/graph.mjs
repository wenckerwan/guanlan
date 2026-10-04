export function conceptLayout(nodes,catalog,star=false){
 const modules=[...new Set(catalog.map(n=>n.module))];
 const active=[...new Set(nodes.map(n=>n.module))];
 return nodes.map(n=>{
  const members=catalog.filter(a=>a.module===n.module),index=members.findIndex(a=>a.id===n.id);
  const moduleIndex=modules.indexOf(n.module);
  const cx=active.length>1?(moduleIndex%3)*850:0,cy=active.length>1?Math.floor(moduleIndex/3)*650:0;
  if(!star)return {...n,x:cx+100+(index%4)*185,y:cy+95+Math.floor(index/4)*100};
  const ring=index<8?0:1,slot=ring?index-8:index,count=ring?Math.max(1,members.length-8):Math.min(8,members.length);
  const theta=slot/count*Math.PI*2-Math.PI/2,radius=ring?260:140;
  return {...n,x:cx+400+Math.cos(theta)*radius,y:cy+330+Math.sin(theta)*radius};
 });
}
export function focusLayout(nodes,selectedId){
 const neighbors=nodes.filter(n=>n.id!==selectedId).sort((a,b)=>a.id.localeCompare(b.id));
 return nodes.map(n=>{if(n.id===selectedId)return {...n,x:500,y:450};const index=neighbors.findIndex(a=>a.id===n.id),theta=index/Math.max(1,neighbors.length)*Math.PI*2-Math.PI/2,radius=neighbors.length>8?350:260;return {...n,x:500+Math.cos(theta)*radius,y:450+Math.sin(theta)*radius};});
}
export function edgeGeometry(a,b,star=false,bend=35,macro=false){
 const dx=b.x-a.x,dy=b.y-a.y,len=Math.hypot(dx,dy)||1;
 const control={x:(a.x+b.x)/2-dy/len*bend,y:(a.y+b.y)/2+dx/len*bend};
 function clip(center){const vx=control.x-center.x,vy=control.y-center.y,L=Math.hypot(vx,vy)||1,ux=vx/L,uy=vy/L;
  const distance=star?(macro?74:29):Math.min((macro?96:76)/Math.max(Math.abs(ux),.0001),(macro?48:29)/Math.max(Math.abs(uy),.0001))+4;
  return {x:center.x+ux*distance,y:center.y+uy*distance};}
 const start=clip(a),end=clip(b);
 return {start,end,control,path:`M ${start.x} ${start.y} Q ${control.x} ${control.y} ${end.x} ${end.y}`,label:{x:(start.x+2*control.x+end.x)/4,y:(start.y+2*control.y+end.y)/4}};
}
export function moduleLinks(nodes,relations){
 const lookup=new Map(nodes.map(n=>[n.id,n.module])),groups=new Map();
 for(const r of relations){const from=lookup.get(r.from),to=lookup.get(r.to);if(!from||!to||from===to)continue;
  const key=from+'>'+to;if(!groups.has(key))groups.set(key,{id:key,from,to,count:0,relations:[]});const group=groups.get(key);group.count++;group.relations.push(r);}
 return [...groups.values()];
}
