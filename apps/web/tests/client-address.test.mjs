import test from 'node:test'
import assert from 'node:assert/strict'

test('only trusted gateway peers may supply a single visitor address', async () => {
  const { clientAddress } = await import('../server/utils/client-address.mjs')
  assert.equal(clientAddress('172.18.0.5', '198.51.100.8', '172.18.0.5'), '198.51.100.8')
  assert.equal(clientAddress('198.51.100.9', '203.0.113.123', '172.18.0.5'), '198.51.100.9')
  assert.equal(clientAddress('172.18.0.5', '1.2.3.4, 5.6.7.8', '172.18.0.5'), '172.18.0.5')
  assert.equal(clientAddress('::ffff:172.18.0.5', '2001:db8::1', '172.18.0.5'), '2001:db8::1')
})

test('forwarded identity is signed and bound to method, API path and time', async () => {
  const { signedAddressHeaders } = await import('../server/utils/client-address.mjs')
  const { createHmac } = await import('node:crypto')
  const secret = 'a'.repeat(64), ip = '198.51.100.8', path = '/api/v1/auth/email/code'
  const result = signedAddressHeaders(ip, 'POST', path, secret, 1700000000)
  assert.equal(result['X-Guanlan-Client-IP'], ip)
  assert.equal(result['X-Guanlan-Client-Time'], '1700000000')
  assert.equal(result['X-Guanlan-Client-Signature'], createHmac('sha256', secret).update(`1700000000\nPOST\n${path}\n${ip}`).digest('hex'))
  assert.deepEqual(signedAddressHeaders(ip, 'POST', path, ''), {})
})
