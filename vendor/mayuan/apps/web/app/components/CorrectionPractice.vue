<script setup lang="ts">
const cases = [
  {
    id: "appearance",
    title: "客观假象与主观错觉",
    wrong: "假象就是人的主观错觉，所以假象不具有客观性。",
    basis:
      "假象是客观事物从反面表现本质的现象，仍具有客观性；错觉是主体对客观事物的歪曲反映。二者不能混同。",
  },
  {
    id: "productivity",
    title: "社会与个别劳动生产率",
    wrong: "某企业提高劳动生产率后，每件商品的价值量必然降低。",
    basis:
      "商品价值量取决于社会必要劳动时间。仅个别企业劳动生产率提高，并不必然改变社会必要劳动时间；社会劳动生产率提高才会使单位商品价值量降低。",
  },
  {
    id: "labor",
    title: "劳动与劳动力",
    wrong: "资本家购买的是劳动，工人的劳动在市场上按价值出售。",
    basis:
      "劳动是劳动力的使用，劳动力才是作为商品出售的对象。劳动力价值由生产与再生产劳动力所需的社会必要劳动时间决定；劳动创造价值。",
  },
];
const index = ref(0),
  response = ref(""),
  revealed = ref(false);
</script>
<template>
  <article class="panel study-card">
    <p class="eyebrow">CORRECT THE CLAIM</p>
    <h2>改错句练习</h2>
    <select
      v-model.number="index"
      aria-label="选择改错情境"
      @change="
        revealed = false;
        response = '';
      "
    >
      <option v-for="(c, i) in cases" :value="i">{{ c.title }}</option>
    </select>
    <p class="boundary">待纠正说法：{{ cases[index].wrong }}</p>
    <textarea
      v-model="response"
      placeholder="指出错误，并改成有条件的准确表述"
      aria-label="改错回答"
    ></textarea>
    <button class="primary" @click="revealed = true">查看辨析依据</button>
    <p v-if="revealed">{{ cases[index].basis }}</p>
    <p v-if="revealed" class="fineprint">
      请自行核对是否区分概念、补足条件并解释理由。此任务只供自评，不按文字匹配评分。
    </p>
  </article>
</template>
