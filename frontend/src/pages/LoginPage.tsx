import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { api, ensureCsrf, errorMessage } from '../api'
import { useAuth } from '../auth'
import { useOrganization } from '../organization'
import { homePath } from '../types'

const defaultBanner = '/login-background.jpg'

export function LoginPage() {
  const { user, isLoading, refresh } = useAuth()
  const organization = useOrganization()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const name = organization.data?.name || 'BFMS'
  const logoUrl = organization.data?.logo_url
  const banner = organization.data?.banner_url || defaultBanner

  if (!isLoading && user) {
    return <Navigate to={homePath(user.role, user.company)} replace />
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    setPending(true)
    setError(null)

    try {
      await ensureCsrf()
      await api.post('/session/login', { email, password })
      await refresh()
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="login" style={{ backgroundImage: `url("${banner}")` }}>
      <section className="login-copy">
        <div className="login-pills">
          <span>BF</span>
          <span className="login-pill-solid">BFMS</span>
        </div>
        <h1>Books that stay in balance.</h1>
        <p>
          Record journals in Philippine pesos. A draft can be unfinished. A posted entry
          has equal debits and credits, and it stays on the ledger.
        </p>
        <LoginStroke />
      </section>
      <form className="login-card" onSubmit={(event) => void submit(event)}>
        <div className="login-card-brand">
          {logoUrl ? <img src={logoUrl} alt="" /> : <LeafMark />}
          <div>
            <strong>{name}</strong>
            <p>Farm · Grow · Thrive</p>
          </div>
        </div>
        <h2>Sign in</h2>
        <label>
          Email
          <span className="login-field">
            <MailIcon />
            <input
              type="email"
              autoComplete="username"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
            />
          </span>
        </label>
        <label>
          Password
          <span className="login-field">
            <LockIcon />
            <input
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
            />
            <button
              type="button"
              className="login-reveal"
              aria-label={showPassword ? 'Hide password' : 'Show password'}
              onClick={() => setShowPassword((current) => !current)}
            >
              <EyeIcon concealed={!showPassword} />
            </button>
          </span>
        </label>
        {error && <p className="form-error">{error}</p>}
        <button type="submit" className="primary login-submit" disabled={pending}>
          <KeyIcon />
          {pending ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </div>
  )
}

function LeafMark() {
  return (
    <svg className="login-leaf" viewBox="0 0 80 80" aria-hidden="true">
      <path fill="#1f8a4d" d="M40 50c6-16 20-28 36-32-4 16-16 28-34 34-1-1-1.4-1.4-2-2Z" />
      <path fill="#17683c" d="M38 52C28 40 16 32 4 30c2 16 14 26 32 30 1-2 1.4-4 2-8Z" />
      <path d="M39 50c0 8-1 16-3 24" fill="none" stroke="#17683c" strokeWidth="3" strokeLinecap="round" />
    </svg>
  )
}

function LoginStroke() {
  return (
    <svg className="login-stroke" viewBox="0 0 220 28" aria-hidden="true">
      <path
        d="M8 16c28-8 48-10 78-6 22 3 40 2 70-8 18-6 36-6 56 2"
        fill="none"
        stroke="#1f7a45"
        strokeWidth="4"
        strokeLinecap="round"
      />
    </svg>
  )
}

function MailIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <rect x="3.5" y="5.5" width="17" height="13" rx="2" />
      <path d="m4 7 8 6 8-6" />
    </svg>
  )
}

function LockIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <rect x="5" y="10.5" width="14" height="9" rx="2" />
      <path d="M8 10.5V8a4 4 0 0 1 8 0v2.5" />
    </svg>
  )
}

function EyeIcon({ concealed }: { concealed: boolean }) {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M2.5 12S6.5 6.5 12 6.5 21.5 12 21.5 12 17.5 17.5 12 17.5 2.5 12 2.5 12Z" />
      <circle cx="12" cy="12" r="2.5" />
      {concealed && <path d="M5 19 19 5" />}
    </svg>
  )
}

function KeyIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="8" cy="14" r="3.25" />
      <path d="M11 14h9l-2 2.25M17.5 14l2-2.25" />
    </svg>
  )
}
