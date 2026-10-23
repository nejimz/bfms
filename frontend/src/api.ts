import axios from 'axios'

export const api = axios.create({
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
  },
})

export async function ensureCsrf(): Promise<void> {
  await api.get('/sanctum/csrf-cookie')
}

export function errorMessage(error: unknown): string {
  if (!axios.isAxiosError(error)) {
    return 'Something went wrong. Try again.'
  }

  const data = error.response?.data as
    | { message?: string; errors?: Record<string, string[]> }
    | undefined

  const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined

  return first || data?.message || 'Something went wrong. Try again.'
}
