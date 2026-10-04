<script setup lang="ts">
import * as THREE from 'three';
import { Plus, Minus, Scan, Type, Maximize, Minimize } from 'lucide-vue-next';
import { createUniverse } from '../../lib/universe/scene.mjs';
import { createLabel, createFlight, createPicker, prefersReducedMotion } from '../../lib/universe/interaction.mjs';
import { hashUnit } from '../../lib/universe/layout.mjs';
type NodeT = {id:string;title:string;module:string};
type RelationT = {id:string;from:string;to:string;label:string};
const props=defineProps<{nodes:NodeT[];relations:RelationT[];modules:any[];selectedId?:string;chapter:string;matchIds:string[];searching:boolean}>();
const emit=defineEmits<{open:[node:NodeT];relation:[relation:RelationT];webglFail:[]}>();
const container=ref<HTMLElement|null>(null), wrapper=ref<HTMLElement|null>(null);
const hovered=ref(''), fullscreen=ref(false), labels=ref(true);
let universe:any, picker:any, flight:any;
const meshes=new Map<string,any>(), points=new Map<string,THREE.Vector3>(), lines:any[]=[], captions:any[]=[], chapterLabels:any[]=[];
const reduced= prefersReducedMotion();
function accent(id:string){return props.modules.find(m=>m.id===id)?.color||'#66d6af';}
function paint(){
  const focus=hovered.value||props.selectedId;
  const adjacent=new Set<string>(focus?[focus]:[]);
  for(const r of props.relations)if(r.from===focus||r.to===focus){adjacent.add(r.from);adjacent.add(r.to);}
  for(const [id,mesh] of meshes){
    const node=mesh.userData.node;
    const matches= !props.searching||props.matchIds.includes(id);
    const inChapter=props.chapter==='all'||node.module===props.chapter;
    const active=focus?adjacent.has(id):matches&&inChapter;
    mesh.material.opacity=active?1:.12;
    mesh.scale.setScalar(id===focus?1.7:active?1.05:.75);
    mesh.userData.label.element.style.opacity=active?'1':'.12';
    const globalOverview=!focus&&!props.searching&&props.chapter==='all';
    mesh.userData.label.visible=labels.value&&!globalOverview&&(active||!props.searching);
    mesh.userData.label.element.classList.toggle('network-label-selected',id===props.selectedId);
  }
  for(const line of lines){const r=line.userData.relation;
    const a=meshes.get(r.from),b=meshes.get(r.to);
    const active=focus?r.from===focus||r.to===focus:a.material.opacity>.5&&b.material.opacity>.5;
    line.material.opacity=active?(focus?.75:.25):.025;
  }
  for(const label of chapterLabels) label.visible=!props.selectedId&&!props.searching;
}
function fit(){
  if(!universe)return;
  const target=new THREE.Vector3();
  let candidates=[...meshes.values()];
  if(props.searching)candidates=candidates.filter(m=>props.matchIds.includes(m.userData.node.id));
  else if(props.chapter!=='all')candidates=candidates.filter(m=>m.userData.node.module===props.chapter);
  if(!candidates.length)candidates=[...meshes.values()];
  if(!candidates.length)return;
  for(const m of candidates)target.add(m.position);target.divideScalar(candidates.length);
  const radius=Math.max(30,...candidates.map(m=>m.position.distanceTo(target)))+18;
  const aspect=universe.camera.aspect;
  const distance=radius/Math.tan(THREE.MathUtils.degToRad(27.5))*Math.max(1,1/aspect)*.85;
  flight.flyTo({x:target.x,y:target.y+distance*.35,z:target.z+distance},target);
}
function focus(){
  paint();
  if(!universe)return;
  const position=points.get(props.selectedId||'');
  if(position){flight.flyTo({x:position.x,y:position.y+35,z:position.z+100},position);}
  else fit();
}
function zoom(factor:number){if(!universe)return;flight.cancel();universe.controls.enabled=true;const d=universe.camera.position.clone().sub(universe.controls.target);d.setLength(Math.min(1600,Math.max(20,d.length()*factor)));universe.camera.position.copy(universe.controls.target).add(d);}
async function toggleFullscreen(){if(!wrapper.value)return;if(document.fullscreenElement)await document.exitFullscreen();else await wrapper.value.requestFullscreen().catch(()=>{});}
function fullscreenChange(){fullscreen.value=!!document.fullscreenElement;}
onMounted(()=>{
  if(!container.value)return;
  universe=createUniverse(container.value,{quality:'high'});
  if(!universe){emit('webglFail');return;}
  universe.renderer.setClearColor(0x101419,1);
  universe.controls.maxDistance=1600;
  flight=createFlight(universe.camera,universe.controls,{reducedMotion:reduced,duration:.7});
  const geometry=new THREE.SphereGeometry(1.8,16,12);
  for(const n of props.nodes){
    const mi=props.modules.findIndex(m=>m.id===n.module), members=props.nodes.filter(a=>a.module===n.module), i=members.findIndex(a=>a.id===n.id);
    const centerAngle=mi/Math.max(1,props.modules.length)*Math.PI*2;
    const angle=i*2.399963229728653;
    const radius=12+Math.sqrt(i)*11;
    const position=new THREE.Vector3(Math.cos(centerAngle)*115+Math.cos(angle)*radius,(hashUnit(n.id)-.5)*34,Math.sin(centerAngle)*78+Math.sin(angle)*radius*.7);
    const mesh=new THREE.Mesh(geometry,new THREE.MeshBasicMaterial({color:accent(n.module),transparent:true}));
    mesh.position.copy(position);mesh.userData={kind:'node',node:n};
    const label=createLabel(n.title,'network-label');label.position.set(0,4,0);mesh.add(label);mesh.userData.label=label;
    universe.scene.add(mesh);meshes.set(n.id,mesh);points.set(n.id,position);captions.push(label);
  }
  for(const m of props.modules){
    const member=props.nodes.filter(n=>n.module===m.id), center=new THREE.Vector3();
    for(const n of member)center.add(points.get(n.id)!);if(member.length)center.divideScalar(member.length);
    const label=createLabel(m.title,'network-chapter');label.position.copy(center).add(new THREE.Vector3(0,32,0));label.element.style.color=m.color;universe.scene.add(label);chapterLabels.push(label);
  }
  for(const r of props.relations){
    const a=points.get(r.from),b=points.get(r.to);if(!a||!b)continue;
    const middle=a.clone().lerp(b,.5);middle.y+=a.distanceTo(b)*.08;
    const curve=new THREE.QuadraticBezierCurve3(a,middle,b);
    const line=new THREE.Line(new THREE.BufferGeometry().setFromPoints(curve.getPoints(20)),new THREE.LineBasicMaterial({color:accent(props.nodes.find(n=>n.id===r.from)?.module||''),transparent:true,opacity:.25}));
    line.userData={kind:'relation',relation:r};universe.scene.add(line);lines.push(line);
  }
  picker=createPicker({camera:universe.camera,domElement:universe.renderer.domElement,getPickables:()=>[...meshes.values(),...lines],onOpen:(t:any)=>{if(t.type==='node')emit('open',t.node);else if(t.type==='relation')emit('relation',t.relation);},onHover:(t:any)=>{hovered.value=t?.node?.id||'';universe.renderer.domElement.style.cursor=t?'pointer':'grab';paint();}});
  universe.addUpdater((elapsed:number)=>{
    for(const [id,m] of meshes){if(!reduced)m.position.y=points.get(id)!.y+Math.sin(elapsed*.5+hashUnit(id)*6.28)*.5;}
  });
  document.addEventListener('fullscreenchange',fullscreenChange);
  paint();fit();
});
watch(()=>[props.selectedId,props.chapter,props.searching,props.matchIds.join(',')],focus);
watch(labels,paint);
onBeforeUnmount(()=>{document.removeEventListener('fullscreenchange',fullscreenChange);picker?.dispose();flight?.cancel();universe?.dispose();});
</script>
<template>
  <div ref="wrapper" class="network-stage">
    <div ref="container" class="network-canvas" role="group" aria-label="三维概念关系图" />
    <div class="network-badge"><span class="network-live"></span>马原 · {{ nodes.length }} 个概念 <small>{{ relations.length }} 条关系</small></div>
    <div class="network-tools">
      <button title="放大" aria-label="放大" @click="zoom(.8)"><Plus :size="16" /></button>
      <button title="缩小" aria-label="缩小" @click="zoom(1.25)"><Minus :size="16" /></button>
      <button title="适应画布" aria-label="适应画布" @click="fit"><Scan :size="16" /></button>
      <button title="切换标签" aria-label="切换概念标签" :aria-pressed="labels" @click="labels=!labels"><Type :size="16" /></button>
      <button :title="fullscreen?'退出全屏':'全屏'" aria-label="切换全屏" @click="toggleFullscreen"><component :is="fullscreen?Minimize:Maximize" :size="16" /></button>
    </div>
    <div class="network-bottom"><span>{{ selectedId ? '概念聚焦' : chapter === 'all' ? '全部知识' : modules.find(m=>m.id===chapter)?.title }}</span><small v-if="hovered">{{ nodes.find(n=>n.id===hovered)?.title }}</small></div>
  </div>
</template>
<style>
.network-stage{position:relative;overflow:hidden;background:#101419;min-height:480px;border-radius:8px;}
.network-stage:fullscreen{border-radius:0;height:100dvh;}
.network-canvas{width:100%;height:max(520px,calc(100dvh - 275px));position:relative;}
.network-stage:fullscreen .network-canvas{height:100dvh;}
.network-badge{position:absolute;top:16px;left:18px;display:flex;align-items:center;gap:8px;color:#dce4e9;font-size:12px;pointer-events:none;}
.network-badge small{font-size:10px;color:#87929c;margin-left:5px;}
.network-live{width:6px;height:6px;background:#6cd5b4;border-radius:50%;}
.network-tools{position:absolute;right:14px;bottom:46px;display:grid;gap:6px;}
.network-tools button{display:grid;place-items:center;width:34px;height:34px;min-height:34px;padding:0;background:#20282de6;color:#d4dde3;border:1px solid #465158;border-radius:6px;font-size:17px;}
.network-tools button:hover{background:#35434b;}
.network-tools button[aria-pressed=false]{opacity:.5;}
.network-bottom{position:absolute;bottom:14px;left:18px;right:60px;display:flex;gap:15px;font-size:11px;color:#7e939f;pointer-events:none;}
.network-bottom small{color:#dfe9ef;font-size:11px;}
.universe-label.network-label{border-radius:3px;padding:3px 6px;font-size:10px;font-weight:400;background:#101419b0;color:#b8c9d2;white-space:nowrap;max-width:190px;overflow:hidden;text-overflow:ellipsis;}
.universe-label.network-label-selected{background:#d8eee8;color:#142d26;font-weight:600;}
.universe-label.network-chapter{font-size:14px;font-weight:600;background:transparent;letter-spacing:0;}
@media(max-width:700px){.network-canvas{height:520px;}.network-badge small{display:none;}}
</style>
