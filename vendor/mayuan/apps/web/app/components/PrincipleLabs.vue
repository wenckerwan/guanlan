<script setup lang="ts">
import core from "../../lib/core.mjs";
const lab = ref("value");
const factor = ref(2),
  productivity = ref("social"),
  temperature = ref(20);
const technology = ref("digital"),
  organization = ref("adaptive");
const result = computed(() =>
  lab.value === "value"
    ? core.valueModel(productivity.value, factor.value)
    : lab.value === "phase"
      ? core.phaseModel(temperature.value)
      : core.productionModel(technology.value, organization.value),
);
</script>
<template>
  <section>
    <p class="eyebrow">CHANGE ONE CONDITION</p>
    <h1>原理实验室</h1>
    <p>改变条件，观察结果；示意模型只在说明的边界内有效。</p>
    <div class="tabs">
      <button
        v-for="[id, label] in [
          ['value', '劳动与价值'],
          ['phase', '量变与质变'],
          ['production', '生产关系适应'],
        ]"
        :class="{ active: lab === id }"
        @click="lab = id"
      >
        {{ label }}
      </button>
    </div>
    <article class="panel study-card">
      <template v-if="lab === 'value'">
        <h2>谁的生产率改变了？</h2>
        <select v-model="productivity" aria-label="生产率类型">
          <option value="social">社会劳动生产率</option>
          <option value="individual">个别劳动生产率</option>
        </select>
        <label
          >生产率倍数 {{ factor
          }}<input
            v-model.number="factor"
            type="range"
            min="1"
            max="5"
            step="0.5"
        /></label>
        <div class="stats">
          <div>
            <small>单位商品价值量</small><strong>{{ result.unit }}</strong>
          </div>
          <div>
            <small>产量示意</small><strong>{{ result.quantity }}</strong>
          </div>
          <div>
            <small>总价值示意</small><strong>{{ result.total }}</strong>
          </div>
        </div>
        <p>
          以相同劳动时间、基准单位价值 10
          为示意：社会生产率改变单位价值量；个别生产率提高不改变社会必要劳动时间决定的单位价值量。忽略市场价格波动。
        </p>
      </template>
      <template v-if="lab === 'phase'">
        <h2>变化在什么条件下发生？</h2>
        <label
          >温度 {{ temperature }} °C<input
            v-model.number="temperature"
            type="range"
            min="-20"
            max="120"
        /></label>
        <strong class="lab-number">{{ result.phase }}</strong>
        <p>{{ result.boundary }}</p>
      </template>
      <template v-if="lab === 'production'">
        <h2>组织方式与技术条件</h2>
        <select v-model="technology" aria-label="技术条件">
          <option value="manual">手工生产</option>
          <option value="industrial">工业生产</option>
          <option value="digital">数字协作</option>
        </select>
        <select v-model="organization" aria-label="组织方式">
          <option value="adaptive">组织适应</option>
          <option value="rigid">组织僵化</option>
        </select>
        <h3>{{ result.result }}</h3>
        <p>{{ result.explanation }}</p>
        <p class="boundary">{{ result.boundary }}</p>
      </template>
    </article>
  </section>
</template>
