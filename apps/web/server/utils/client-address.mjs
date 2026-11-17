import { isIP } from 'node:net'
import { createHmac } from 'node:crypto'

const normalize = value => String(value || '').replace(/^::ffff:(?=\d+\.)/, '')

export function clientAddress(peer, forwarded, trustedPeers = '') {
  peer = normalize(peer)
  const trusted = String(trustedPeers).split(',').map(value => normalize(value.trim()))
  return trusted.includes(peer) && isIP(forwarded || '') ? normalize(forwarded) : peer
}

export function signedAddressHeaders(ip, method, path, secret, timestamp = Math.floor(Date.now() / 1000)) {
  if (!isIP(ip) || String(secret || '').length < 32) return {}
  return {
    'X-Guanlan-Client-IP': ip,
    'X-Guanlan-Client-Time': String(timestamp),
    'X-Guanlan-Client-Signature': createHmac('sha256', secret).update(`${timestamp}\n${method}\n${path}\n${ip}`).digest('hex'),
  }
}
