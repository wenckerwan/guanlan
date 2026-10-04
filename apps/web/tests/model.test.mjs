import test from "node:test";
import assert from "node:assert/strict";
import {
  recallSet,
  safeQueue,
  maskConcept,
  previewImport,
  latestDue,
  attemptIntent,
} from "../lib/model.mjs";
import core from "../lib/core.mjs";
test("queue is isolated by authenticated user and refuses grading writes", () => {
  assert.equal(
    safeQueue(
      [
        { userId: "a", type: "visit" },
        { userId: "b", type: "visit" },
        { userId: "a", type: "attempt" },
      ],
      "a",
    ).length,
    1,
  );
});
test("recall changes actual set and wraps", () => {
  let nodes = [{ id: "a" }, { id: "b" }, { id: "c" }];
  assert.deepEqual(
    recallSet(nodes, 1, 2).map((n) => n.id),
    ["b", "c"],
  );
  assert.deepEqual(
    recallSet(nodes, 2, 2).map((n) => n.id),
    ["c", "a"],
  );
});
test("concept clue masks title everywhere", () =>
  assert.equal(
    maskConcept({ title: "实践", summary: "实践决定认识，实践检验认识" }),
    "____决定认识，____检验认识",
  ));
test("import preview rejects prototype and malformed roots", () => {
  assert.throws(() => previewImport(JSON.parse('{"__proto__":{}}')));
  assert.throws(() => previewImport([]));
  assert.equal(previewImport({ version: 1, nodes: { a: {} } }).nodes, 1);
});
test("truthful labs distinguish individual and social productivity and exact phase", () => {
  assert.equal(core.valueModel("individual", 2).unit, 10);
  assert.equal(core.valueModel("social", 2).unit, 5);
  assert.equal(core.phaseModel(100).phase, "液态与气态共存");
  assert.match(core.productionModel("digital", "rigid").boundary, /所有制/);
});
test("recall due uses only latest event per node", () =>
  assert.deepEqual(
    latestDue(
      [
        { nodeId: "a", at: 1, dueAt: 10 },
        { nodeId: "a", at: 2, dueAt: 200 },
        { nodeId: "b", at: 1, dueAt: 20 },
      ],
      100,
    ).map((r) => r.nodeId),
    ["b"],
  ));
test("lost response retry keeps exact attempt identity and body", () => {
  const first = attemptIntent(
    null,
    { exerciseId: "q1", chosen: 1, reason: "because" },
    "id1",
  );
  assert.equal(
    attemptIntent(first, { exerciseId: "q2", chosen: 0 }, "id2"),
    first,
  );
});
