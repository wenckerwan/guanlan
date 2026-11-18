import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const config = readFileSync(new URL('../../../docker/nginx/production.conf', import.meta.url), 'utf8')

test('main API gateway routes only to the HttpOnly BFF, not raw bearer PHP API', () => {
  assert.match(config, /location \/api\/v1\/\s*\{\s*proxy_pass http:\/\/web:3000;/)
  assert.match(config, /location \/api\/\s*\{ return 404; \}/)
  assert.doesNotMatch(config, /proxy_pass http:\/\/api:9501/)
  assert.match(config, /location \/api\/v2\/\s*\{\s*proxy_pass http:\/\/mayuan-api:9501;/)
})

test('security headers cover errors as well as successful pages', () => {
  for (const name of ['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'Strict-Transport-Security']) {
    assert.match(config, new RegExp(`add_header ${name} [^\\n]+ always;`))
  }
  for (const directive of ["frame-ancestors 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "connect-src 'self'"]) assert.ok(config.includes(directive))
  assert.ok(!config.includes("'unsafe-eval'"))
  assert.match(config, /server_tokens off;/)
  assert.match(config, /proxy_hide_header X-Powered-By;/)
})

test('authentication rate limit remains on browser API endpoints', () => {
  assert.match(config, /auth\/\(login\|register\|email\/code\)/)
  assert.match(config, /limit_req zone=guanlan_auth burst=10 nodelay;/)
  assert.match(config, /limit_req_status 429;/)
})
