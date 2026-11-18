import test from 'node:test'
import assert from 'node:assert/strict'

test('challenge expiry and errors clear the token; disposal removes only its widget', async () => {
  const { mountChallenge } = await import('../utils/turnstile.mjs')
  let callbacks, removed, token, resets = 0
  const api = {
    render: (element, options) => { callbacks = options; return 'own-widget' },
    reset: id => { assert.equal(id, 'own-widget'); ++resets },
    remove: id => { removed = id },
  }
  const widget = mountChallenge(api, {}, 'public-site-key', value => { token = value })
  assert.equal(callbacks.action, 'register')
  callbacks.callback('proof'); assert.equal(token, 'proof')
  callbacks['expired-callback'](); assert.equal(token, '')
  callbacks.callback('proof'); callbacks['error-callback'](); assert.equal(token, '')
  callbacks.callback('proof'); widget.reset(); assert.equal(token, ''); assert.equal(resets, 1)
  widget.remove(); assert.equal(removed, 'own-widget'); assert.equal(token, '')
  callbacks.callback('late-token'); assert.equal(token, '')
})
