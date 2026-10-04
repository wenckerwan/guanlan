export default defineEventHandler((event) =>
  proxyRequest(
    event,
    `${useRuntimeConfig(event).apiBase}/api/v2/${getRouterParam(event, "path") || ""}`,
  ),
);
