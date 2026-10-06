// Public, read-only adapter. No authentication or arbitrary target URL is forwarded.
export default defineEventHandler(async () => {
  const [subjects, home] = await Promise.all([
    $fetch<{ data: unknown[] }>('https://guanlan.wencker.top/api/v1/subjects', { timeout: 12000 }),
    $fetch<{ data: unknown }>('https://guanlan.wencker.top/api/v1/home', { timeout: 12000 }),
  ])
  return { subjects: subjects.data, home: home.data }
})
