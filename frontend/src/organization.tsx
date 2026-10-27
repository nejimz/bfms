import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { api } from './api'
import { readResource } from './types'

export type Organization = {
  id: string
  name: string
  logo_url: string | null
  icon_url: string | null
  banner_url: string | null
}

const defaultName = 'BFMS'
const defaultIcon = '/favicon.svg'

export function useOrganization() {
  return useQuery({
    queryKey: ['organization'],
    queryFn: async () => {
      const response = await api.get('/api/v1/organization')
      return readResource<Omit<Organization, 'id'>>(response.data.data)
    },
  })
}

export function markLetters(name: string): string {
  const words = name.trim().split(/\s+/).filter((word) => word.length > 0)
  const letters = words.length >= 2 ? `${words[0][0] ?? ''}${words[1][0] ?? ''}` : name.trim().slice(0, 2)

  return letters.toLocaleUpperCase() || 'BF'
}

export function OrganizationMark({ subtitle, tone = 'sidebar' }: { subtitle?: string; tone?: 'sidebar' | 'page' }) {
  const organization = useOrganization()
  const name = organization.data?.name || defaultName
  const logoUrl = organization.data?.logo_url

  return (
    <div className={tone === 'page' ? 'brand login-brand' : 'brand'}>
      {logoUrl ? (
        <img className="brand-logo" src={logoUrl} alt="" />
      ) : (
        <span className="brand-mark">{markLetters(name)}</span>
      )}
      <div>
        <strong>{name}</strong>
        {subtitle && <small>{subtitle}</small>}
      </div>
    </div>
  )
}

export function OrganizationHeading() {
  const organization = useOrganization()
  const name = organization.data?.name
  const logoUrl = organization.data?.logo_url

  if (!name) {
    return null
  }

  return (
    <div className="report-brand">
      {logoUrl && <img src={logoUrl} alt="" />}
      <span>{name}</span>
    </div>
  )
}

function iconType(url: string): string {
  const path = url.split('?')[0]?.toLowerCase() ?? ''

  if (path.endsWith('.png')) {
    return 'image/png'
  }

  if (path.endsWith('.webp')) {
    return 'image/webp'
  }

  if (path.endsWith('.jpg') || path.endsWith('.jpeg')) {
    return 'image/jpeg'
  }

  return 'image/png'
}

export function LoginBanner() {
  const organization = useOrganization()
  const bannerUrl = organization.data?.banner_url

  if (!bannerUrl) {
    return null
  }

  return <img className="login-banner" src={bannerUrl} alt="" />
}

export function OrganizationEffects() {
  const organization = useOrganization()
  const name = organization.data?.name
  const iconUrl = organization.data?.icon_url

  useEffect(() => {
    if (!name) {
      return
    }

    document.title = name
    const icon = document.querySelector<HTMLLinkElement>('link[rel="icon"]')

    if (!icon) {
      return
    }

    if (iconUrl) {
      icon.href = iconUrl
      icon.type = iconType(iconUrl)
      return
    }

    icon.href = defaultIcon
    icon.type = 'image/svg+xml'
  }, [name, iconUrl])

  return null
}
