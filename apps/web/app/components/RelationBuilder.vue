<script setup lang="ts">
const props = defineProps<{ nodes: any[]; relations: any[] }>();
const from = ref(props.relations[0]?.from || ""),
  to = ref(props.relations[0]?.to || ""),
  label = ref(""),
  index = ref(0),
  result = ref<any>(null),
  checked = ref(false);
const labels = computed(() => [
  ...new Set(props.relations.map((r) => r.label)),
]);
function rotate() {
  index.value = (index.value + 1) % props.relations.length;
  const r = props.relations[index.value];
  from.value = r.from;
  to.value = r.to;
  label.value = "";
  checked.value = false;
}
function check() {
  result.value = props.relations.find(
    (r) =>
      r.from === from.value && r.to === to.value && r.label === label.value,
  );
  checked.value = true;
}
</script>
<template>
  <article class="panel study-card">
    <p class="eyebrow">BUILD A RELATION</p>
    <h2>关系拼接台</h2>
    <p>选择起点、关系词与终点，把概念连成有条件的命题。</p>
    <div class="toolbar">
      <select v-model="from" aria-label="关系起点" @change="checked = false">
        <option v-for="n in nodes" :value="n.id">{{ n.title }}</option>
      </select>
      <select v-model="label" aria-label="关系词" @change="checked = false">
        <option value="">选择关系词</option>
        <option v-for="l in labels">{{ l }}</option>
      </select>
      <select v-model="to" aria-label="关系终点" @change="checked = false">
        <option v-for="n in nodes" :value="n.id">{{ n.title }}</option>
      </select>
    </div>
    <div class="row">
      <button class="primary" :disabled="!label" @click="check">
        核对收录关系</button
      ><button @click="rotate">换一对概念</button>
    </div>
    <template v-if="checked && result"
      ><h3>已收录这条关系</h3>
      <p>{{ result.explanation }}</p>
      <p>条件：{{ result.condition }}</p>
      <p class="boundary">易错：{{ result.trap }}</p></template
    >
    <p v-else-if="checked" class="boundary">
      当前资料未收录这个方向与关系词的组合。未收录不等于逻辑上必然错误；请结合概念定义、适用条件与教材核查。
    </p>
  </article>
</template>
