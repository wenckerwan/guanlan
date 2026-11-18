import { normalizeAppearance } from '~/utils/appearance.mjs'

export function useAppearance() {
  const preference = useCookie<string>('guanlan.appearance', {
    default: () => 'system',
    maxAge: 60 * 60 * 24 * 365,
    sameSite: 'lax',
    path: '/',
  })
  const appearance = computed({
    get: () => normalizeAppearance(preference.value),
    set: (value: string) => { preference.value = normalizeAppearance(value) },
  })
  return { appearance }
}
