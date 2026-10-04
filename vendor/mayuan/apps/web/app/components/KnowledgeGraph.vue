<script setup lang="ts">
import {conceptLayout,focusLayout,edgeGeometry,moduleLinks} from '../../lib/graph.mjs';
type Node = {id:string;title:string;module:string;x?:number;y?:number};
type Relation = {id:string;from:string;to:string;label:string;kind?:string};
const props=withDefaults(defineProps<{nodes:Node[];allNodes?:Node[];relations:Relation[];allRelations?:Relation[];modules?:any[];selectedId?:string;mastery:Record<string,any>;star:boolean;overview?:boolean}>(),{overview:false});
const emit=defineEmits<{open:[node:Node];relation:[relation:Relation];chapter:[id:string]}>();
const list=ref(false),selectedLinks=ref<any>(null),relationKind=ref('all'),hovered=ref<string|null>(null);
const neighbors=computed(()=>new Set(props.relations.filter(r=>r.from===hovered.value||r.to===hovered.value).flatMap(r=>[r.from,r.to])));

const catalog=computed(()=>props.allNodes||props.nodes);
const planets=computed(()=> (props.modules||[]).map((m,i)=>({...m,x:180+(i%4)*280,y:130+Math.floor(i/4)*310,count:catalog.value.filter(n=>n.module===m.id).length})));
const concepts=computed(()=>props.selectedId?focusLayout(props.nodes,props.selectedId):conceptLayout(props.nodes,catalog.value,props.star));
const positions=computed(()=>props.overview?planets.value:concepts.value);
const edges=computed(()=>{
 const rels=props.overview?moduleLinks(catalog.value,props.allRelations||props.relations):props.relations.filter(r=>relationKind.value==='all'||r.kind===relationKind.value);
 return rels.map((r:any)=>{const a=positions.value.find((n:any)=>n.id===r.from),b=positions.value.find((n:any)=>n.id===r.to);return a&&b?{...r,geometry:edgeGeometry(a,b,props.star,props.overview?32:25,props.overview)}:null;}).filter(Boolean);
});
const kinds=computed(()=>[...new Set(props.relations.map(r=>r.kind).filter(Boolean))]);
const bounds=computed(()=>{
 const pts=positions.value;if(!pts.length)return {x:0,y:0,w:850,h:450};
 const xs=pts.map((n:any)=>n.x),ys=pts.map((n:any)=>n.y),pad=props.overview?130:105;
 return {x:Math.min(...xs)-pad,y:Math.min(...ys)-pad,w:Math.max(550,Math.max(...xs)-Math.min(...xs)+2*pad),h:Math.max(380,Math.max(...ys)-Math.min(...ys)+2*pad)};
});
const frame=ref({x:0,y:0,w:850,h:450});
function fit(){frame.value={...bounds.value};}
watch([bounds,()=>props.star,()=>props.overview],()=>{fit();selectedLinks.value=null;},{immediate:true});
function zoom(factor:number){const old=frame.value,newW=Math.max(180,Math.min(bounds.value.w*4,old.w*factor)),ratio=newW/old.w;frame.value={x:old.x+(old.w-newW)/2,y:old.y+(old.h-old.h*ratio)/2,w:newW,h:old.h*ratio};}
let drag:any=null;
function down(e:PointerEvent){if((e.target as Element).closest('[role="button"]'))return;const svg=e.currentTarget as SVGSVGElement;drag={x:e.clientX,y:e.clientY,frame:{...frame.value},rect:svg.getBoundingClientRect()};svg.setPointerCapture(e.pointerId);}
function move(e:PointerEvent){if(!drag)return;frame.value={...drag.frame,x:drag.frame.x-(e.clientX-drag.x)*drag.frame.w/drag.rect.width,y:drag.frame.y-(e.clientY-drag.y)*drag.frame.h/drag.rect.height};}
function end(){drag=null;}
function wheel(e:WheelEvent){if(e.ctrlKey){e.preventDefault();zoom(e.deltaY>0?1.12:.89);}}
function color(n:any){return (props.modules||[]).find(m=>m.id===(n.module||n.id))?.color||'#8fbdaf';}
function nodeOpacity(n:any){return hovered.value && n.id!==hovered.value && !neighbors.value.has(n.id) ? .22 : 1;}
function edgeOpacity(r:any){return hovered.value && r.from!==hovered.value && r.to!==hovered.value ? .1 : 1;}
function hoverNode(n:any){hovered.value=n.id;}
function clearHover(){hovered.value=null;}
function openEdge(r:any){if(props.overview)selectedLinks.value=r;else emit('relation',r);}
const dots=Array.from({length:70},(_,i)=>({x:(i*137)%1150,y:(i*83)%610,r:i%5===0?1.8:.8}));
</script>
<template>
 <div class="graph-controls"><div class="row"><button @click="zoom(.8)" aria-label="放大图谱">＋</button><button @click="zoom(1.25)" aria-label="缩小图谱">－</button><button @click="fit">适应画布</button><button @click="list=!list">{{list?'查看图谱':'查看列表'}}</button></div><select v-if="!overview&&kinds.length" v-model="relationKind" aria-label="筛选关系类型"><option value="all">全部关系</option><option v-for="k in kinds" :value="k">{{k}}</option></select></div>
 <p class="canvas-hint">{{overview?'七个章节构成知识体系，选择章节深入概念；连线仅汇总已收录的跨章关系。':'箭头表示已收录关系的方向；点选连线阅读条件与误区。'}} 拖动空白区域平移，Ctrl＋滚轮缩放。</p>
 <div v-if="list" class="concept-list"><button v-for="n in positions" :key="n.id" @click="overview?emit('chapter',n.id):emit('open',n)"><strong>{{n.title}}</strong><span>{{overview?n.count+' 个概念':mastery[n.id]?.mastery==='mastered'?'自评已掌握':'阅读概念'}} →</span></button></div>
 <div v-else :class="['graph','knowledge-canvas',{star}]">
  <svg :viewBox="`${frame.x} ${frame.y} ${frame.w} ${frame.h}`" role="group" :aria-label="overview?'七章节知识总览':'概念关系网络'" @pointerdown="down" @pointermove="move" @pointerup="end" @pointercancel="end" @wheel="wheel">
   <defs><marker id="mayuan-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#91ab9b"/></marker></defs>
   <g v-if="star" class="dust" aria-hidden="true"><circle v-for="(d,i) in dots" :key="i" :cx="d.x" :cy="d.y" :r="d.r"/></g>
   <g v-for="r in edges" :key="r.id" class="canvas-edge" :style="{opacity:edgeOpacity(r)}" tabindex="0" role="button" :aria-label="overview?`${planets.find(n=>n.id===r.from)?.title}到${planets.find(n=>n.id===r.to)?.title}的${r.count}条跨章联系`:`${concepts.find(n=>n.id===r.from)?.title} ${r.label} ${concepts.find(n=>n.id===r.to)?.title}`" @click="openEdge(r)" @keydown.enter="openEdge(r)" @keydown.space.prevent="openEdge(r)">
    <path :d="r.geometry.path" class="hit-path"/><path :d="r.geometry.path" class="visible-path" marker-end="url(#mayuan-arrow)"/>
    <text v-if="!overview" :x="r.geometry.label.x" :y="r.geometry.label.y-5">{{r.label}}</text>
   </g>
   <g v-for="n in positions" :key="n.id" tabindex="0" role="button" :aria-label="overview?'进入'+n.title:n.title" class="canvas-node" :style="{opacity:nodeOpacity(n)}" @pointerenter="hoverNode(n)" @pointerleave="clearHover" @focus="hoverNode(n)" @blur="clearHover" :class="{selected:selectedId===n.id,mastered:mastery[n.id]?.mastery==='mastered'}" @click="overview?emit('chapter',n.id):emit('open',n)" @keydown.enter="overview?emit('chapter',n.id):emit('open',n)" @keydown.space.prevent="overview?emit('chapter',n.id):emit('open',n)">
    <template v-if="star"><circle v-if="overview" class="orbit" :cx="n.x" :cy="n.y" r="88"/><circle :cx="n.x" :cy="n.y" :r="overview?65:23" :style="{fill:color(n)}"/><circle class="core" :cx="n.x" :cy="n.y" :r="overview?43:12"/></template>
    <rect v-else :x="n.x-(overview?92:72)" :y="n.y-(overview?44:25)" :width="overview?184:144" :height="overview?88:50" :rx="overview?18:12"/>
    <circle v-if="!star && !overview" :cx="n.x-61" :cy="n.y" r="4" :style="{fill:color(n)}"/>
     <text :x="n.x" :y="n.y+(star?(overview?4:43):5)" :style="!star&&!overview ? {fontSize: Math.min(12,116/Math.max(1,n.title.length))+'px'} : {}" :class="{planet:overview}">{{n.title}}</text>
    <text v-if="overview" :x="n.x" :y="n.y+(star?104:28)" class="node-meta">{{n.count}} 个概念 · 点击探索</text>
    <text v-else-if="mastery[n.id]?.mastery==='mastered'" :x="n.x+(star?24:58)" :y="n.y-14" class="mastery-check">✓</text>
   </g>
  </svg>
 </div>
 <section v-if="selectedLinks" class="chapter-links panel"><div class="row"><strong>已收录的 {{selectedLinks.count}} 条跨章关系</strong><button @click="selectedLinks=null">收起联系</button></div><button v-for="r in selectedLinks.relations" :key="r.id" @click="emit('relation',r)">{{catalog.find(n=>n.id===r.from)?.title}} · {{r.label}} · {{catalog.find(n=>n.id===r.to)?.title}} ↗</button></section>
 <p class="canvas-hint">自评已掌握以 ✓ 标记；星体位置仅辅助组织阅读，不表示理论重要性或掌握程度。</p>
</template>
<style scoped>
.graph-controls{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin:16px 0}.graph-controls button{padding:6px 12px;min-height:40px}.canvas-hint{font-size:11px;color:#7c8b80;line-height:1.8}.knowledge-canvas{overflow:hidden;border:1px solid #dce5dc;border-radius:18px;background:#fafcf8;min-height:430px;position:relative}.knowledge-canvas svg{display:block;width:100%;height:520px;min-width:0!important;touch-action:none;user-select:none;cursor:grab}.knowledge-canvas.star{background:radial-gradient(ellipse at 48% 48%,#234e4b,#112f30 75%);border-color:#244647}.star svg{height:560px}.dust{fill:#bfd8d1;opacity:.35;pointer-events:none}.canvas-edge{color:#99aea0;cursor:pointer}.visible-path{fill:none;stroke:currentColor;stroke-width:1.5}.hit-path{fill:none;stroke:transparent;stroke-width:18}.canvas-edge text{fill:#788c7e;font-size:10px;text-anchor:middle;paint-order:stroke;stroke:#fafcf8;stroke-width:5px;stroke-linejoin:round}.canvas-edge:hover,.canvas-edge:focus{color:#b99141}.canvas-edge:hover .visible-path,.canvas-edge:focus .visible-path{stroke-width:3}.canvas-node{cursor:pointer;outline:none}.canvas-node rect{fill:#fffefb;stroke:#cedacf;stroke-width:1.5;filter:drop-shadow(0 3px 5px #1d48250a)}.canvas-node:hover rect,.canvas-node:focus rect,.canvas-node.selected rect{stroke:#267967;stroke-width:3}.canvas-node text{font-size:12px;fill:#29473c;text-anchor:middle;font-weight:600;pointer-events:none}.canvas-node .planet{font-size:18px}.canvas-node .node-meta{font-size:10px;font-weight:400;fill:#819888}.canvas-node.mastered rect{fill:#e4f0e5}.mastery-check{fill:#1b7d52!important;font-size:13px!important}.star .canvas-edge{color:#678f8b;opacity:.7}.star .canvas-edge text{fill:#9abdb5;stroke:#163b3b}.star .canvas-node circle{opacity:.8;stroke:#d5ede2;stroke-width:1}.star .canvas-node circle.orbit{fill:none;stroke-dasharray:4 6;opacity:.3}.star .canvas-node circle.core{fill:#ffffff12;stroke:none}.star .canvas-node text{fill:#e6f2e9}.star .canvas-node .planet{fill:#effbf0;paint-order:stroke;stroke:#173f38;stroke-width:2px}.star .canvas-node:hover circle,.star .canvas-node:focus circle,.star .canvas-node.selected circle{stroke:#f1d393;stroke-width:3}.star .canvas-node .node-meta{fill:#95b8ab}.concept-list{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.concept-list button{text-align:left;display:flex;flex-direction:column;gap:4px}.concept-list span{color:#879887;font-size:11px}.chapter-links{margin-top:14px}.chapter-links>button{margin:8px 8px 0 0;font-size:12px}@media(max-width:700px){.knowledge-canvas svg,.star svg{height:360px}.knowledge-canvas{min-height:360px}.concept-list{grid-template-columns:repeat(2,1fr)}.graph-controls{margin:10px 0}.canvas-hint{font-size:10px}.graph-controls .row{gap:5px}.graph-controls button{font-size:11px;padding:6px 10px}}
</style>
