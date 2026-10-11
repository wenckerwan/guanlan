import { enqueueAttempt, isPracticeBank, markAttemptConflict, outboxKey, readBankOutbox, removeAttempt } from '~/lib/practice-study.mjs'

export type PracticeQuestion = {
  questionRef: string; questionVersion: string; type: 'single' | 'multi'; module: string
  moduleLabel?: string; stem: string; options: Record<string, string>; answer?: string
  analysis?: string; event?: string; knowledge?: string; sources?: { label: string; url: string }[]
}
export type QuestionState = {
  attemptCount?: number; firstIsRight?: boolean; lastIsRight?: boolean; lastChosen?: string[]
  lastCorrect?: string[] | string; lastAttemptAt?: string; favorited?: boolean
}
type Bank = { bankId: string; contentVersion: string; questionCount: number; questions: PracticeQuestion[] }
type Outcome = { attemptId: number; isRight: boolean; correct: string[] | string; duplicate: boolean; questionState: QuestionState | null }
type Event = { bankId: string; questionRef: string; questionVersion: string; chosen: string[]; eventId: string }
type PendingEvent = Event & { outboxKey: string; conflict?: boolean }

function errorStatus(error: unknown) {
  const e = error as { statusCode?: number; status?: number }
  return e?.statusCode ?? e?.status ?? 0
}

export function usePracticeStudy() {
  const auth = useAuth()
  const bank = shallowRef<Bank | null>(null)
  const states = ref<Record<string, QuestionState>>({})
  const favorites = ref<string[]>([])
  const guestResults = ref<Record<string, { chosen: string[]; isRight: boolean; correct: string }>>({})
  const pending = ref<PendingEvent[]>([])
  const loading = ref(true)
  const message = ref('')
  const error = ref('')
  const generation = ref(0)
  const identity = computed(() => auth.token.value && auth.user.value?.id ? String(auth.user.value.id) : '')
  const loggedIn = computed(() => Boolean(identity.value))
  const key = computed(() => bank.value && identity.value ? outboxKey(identity.value, bank.value.bankId, bank.value.contentVersion) : '')

  function clearView() {
    generation.value++
    bank.value = null
    states.value = {}
    favorites.value = []
    guestResults.value = {}
    pending.value = []
    message.value = ''
    error.value = ''
  }

  function loadPending() {
    pending.value = import.meta.client && bank.value && identity.value ? readBankOutbox<Event>(localStorage, identity.value, bank.value.bankId) : []
  }

  async function load() {
    clearView()
    loading.value = true
    await auth.restore()
    const epoch = generation.value
    const account = identity.value
    try {
      const result = await auth.request<Bank>('/practice/banks/current-affairs-2026')
      if (epoch !== generation.value || account !== identity.value) return
      if (!isPracticeBank(result)) throw new Error('invalid practice bank payload')
      if (account) {
        const [state, favs] = await Promise.all([
          auth.request<{ questions: Record<string, QuestionState> }>('/study/practice/banks/current-affairs-2026/state'),
          auth.request<{ targetId: string }[]>('/study/favorites?targetType=practice_question'),
        ])
        if (epoch !== generation.value || account !== identity.value) return
        states.value = state.questions ?? {}
        favorites.value = favs.map(item => item.targetId.split(':').at(-1) ?? '').filter(Boolean)
      }
      bank.value = result
      if (account) loadPending()
    } catch (cause) {
      if (epoch !== generation.value || account !== identity.value) return
      const status = errorStatus(cause)
      error.value = status === 404 ? '题库尚未发布，请稍后再试。' : status === 401 ? '登录状态已失效，请重新登录。' : '题库加载失败，请检查网络后重试。'
    } finally {
      if (epoch === generation.value) loading.value = false
    }
  }

  async function send(event: Event, outbox: string, account: string, epoch: number) {
    if (readAuthToken() !== auth.token.value) { await reconcileIdentity(); return null }
    try {
      const body: Event = { bankId: event.bankId, questionRef: event.questionRef, questionVersion: event.questionVersion, chosen: event.chosen, eventId: event.eventId }
      const outcome = await auth.request<Outcome>('/study/practice/attempts', { method: 'POST', body })
      if (import.meta.client) removeAttempt(localStorage, outbox, event.eventId)
      if (epoch !== generation.value || account !== identity.value) return null
      loadPending()
      if (outcome.questionState) states.value = { ...states.value, [event.questionRef]: outcome.questionState }
      else void refreshAccountState()
      message.value = outcome.duplicate ? '该次作答已同步。' : '作答已保存到账号。'
      error.value = ''
      return outcome
    } catch (cause) {
      if (epoch !== generation.value || account !== identity.value) return null
      const status = errorStatus(cause)
      if (status === 401) error.value = '登录状态已失效，待同步作答保留在原账号队列中。'
      else if (status === 409) {
        if (import.meta.client) markAttemptConflict(localStorage, outbox, event.eventId)
        error.value = '这次作答与当前版本冲突，已保留在本地。可核对后清除该条本地待同步记录。'
      }
      else if (status === 422) error.value = '答案格式不符合题目要求，请核对后重新作答。'
      else error.value = '保存失败，作答仍在待同步队列中。可稍后重试。'
      loadPending()
      return null
    }
  }

  async function submit(question: PracticeQuestion, chosen: string[]) {
    if (!chosen.length || !bank.value) return null
    if (readAuthToken() !== auth.token.value) { await reconcileIdentity(); return null }
    if (!loggedIn.value) {
      if (!question.answer) { error.value = '试玩题暂不能判分，请稍后重试。'; return null }
      const correct = question.answer.split('').sort().join('')
      const isRight = chosen.slice().sort().join('') === correct
      guestResults.value = { ...guestResults.value, [question.questionRef]: { chosen: [...chosen], isRight, correct } }
      message.value = '本机试玩结果不会同步到账号。'
      return { isRight, correct, duplicate: false, questionState: null }
    }
    if (!key.value || !import.meta.client) return null
    const account = identity.value
    const epoch = generation.value
    const outbox = key.value
    const existing = pending.value.find(item => item.questionRef === question.questionRef)
    if (existing) { error.value = '这题有待同步作答，请先重试保存。'; return null }
    const event: Event = { bankId: bank.value.bankId, questionRef: question.questionRef, questionVersion: question.questionVersion, chosen: [...chosen].sort(), eventId: crypto.randomUUID() }
    try { enqueueAttempt(localStorage, outbox, event) }
    catch { error.value = '浏览器无法保存待同步作答，请检查本机存储。'; return null }
    loadPending()
    return await send(event, outbox, account, epoch)
  }

  async function retryPending() {
    if (!bank.value || !identity.value) return
    if (readAuthToken() !== auth.token.value) { await reconcileIdentity(); return }
    const account = identity.value
    const epoch = generation.value
    for (const event of [...pending.value]) {
      if (epoch !== generation.value || account !== identity.value) break
      if (!event.conflict) await send(event, event.outboxKey, account, epoch)
    }
  }

  function discardPending(eventId: string) {
    if (!import.meta.client || readAuthToken() !== auth.token.value) { void reconcileIdentity(); return }
    const event = pending.value.find(item => item.eventId === eventId)
    if (!event || !bank.value || !identity.value) return
    removeAttempt(localStorage, event.outboxKey, eventId)
    loadPending()
    message.value = '仅清除了这条本地待同步记录；服务端已有作答未删除。'
  }

  async function toggleFavorite(question: PracticeQuestion) {
    if (!loggedIn.value || !bank.value) { error.value = '登录后可将题目收藏到账号。'; return }
    const account = identity.value
    const epoch = generation.value
    const favorited = !favorites.value.includes(question.questionRef)
    try {
      await auth.request('/study/favorites', { method: 'PUT', body: {
        targetType: 'practice_question', targetId: `${bank.value.bankId}:${question.questionRef}`,
        title: question.stem.slice(0, 80), url: `/practice/current-affairs#q-${question.questionRef}`, favorited,
      } })
      if (epoch !== generation.value || account !== identity.value) return
      favorites.value = favorited ? [...favorites.value, question.questionRef] : favorites.value.filter(ref => ref !== question.questionRef)
    } catch { error.value = '收藏未保存，请稍后重试。' }
  }

  async function reconcileIdentity() {
    const cookieToken = readAuthToken()
    if (cookieToken === auth.token.value) return
    clearView()
    auth.token.value = cookieToken
    auth.user.value = null
    if (cookieToken) await auth.fetchUser()
    await load()
  }

  async function refreshAccountState() {
    if (!identity.value || !bank.value) return
    const account = identity.value
    const epoch = generation.value
    try {
      const state = await auth.request<{ questions: Record<string, QuestionState> }>('/study/practice/banks/current-affairs-2026/state')
      if (epoch === generation.value && account === identity.value) states.value = state.questions ?? {}
    } catch { /* Retain the last verified state; a later load can retry. */ }
  }

  function onStorage(event: StorageEvent) {
    if (bank.value && identity.value && event.key?.startsWith(`guanlan.practice.outbox.v1:${encodeURIComponent(identity.value)}:${encodeURIComponent(bank.value.bankId)}:`)) {
      loadPending()
      if (event.newValue === null) void refreshAccountState()
    }
    void reconcileIdentity()
  }
  onMounted(() => {
    window.addEventListener('focus', reconcileIdentity)
    window.addEventListener('storage', onStorage)
  })
  onBeforeUnmount(() => {
    window.removeEventListener('focus', reconcileIdentity)
    window.removeEventListener('storage', onStorage)
  })
  watch(identity, (next, previous) => { if (next !== previous) void load() })
  return { bank, states, favorites, guestResults, pending, loading, loggedIn, identity, message, error, load, submit, retryPending, discardPending, toggleFavorite }
}
