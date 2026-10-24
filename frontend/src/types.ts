export type AccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense'

export type Account = {
  id: string
  code: string
  name: string
  type: AccountType
  is_active: boolean
}

export type JournalLine = {
  id: string
  account_id: string
  description: string | null
  debit: string
  credit: string
}

export type Journal = {
  id: string
  date: string
  reference: string | null
  memo: string | null
  currency: string
  status: 'draft' | 'posted'
  posted_at: string | null
  lines: JournalLine[]
}

export type ChartAccountEntryLine = {
  id: string
  name: string
  quantity: string
  amount: string
}

export type ChartAccountEntry = {
  id: string
  date: string
  account_id: string
  particulars: string
  amount: string
  currency: string
  status: 'draft' | 'posted'
  posted_at: string | null
  account: Pick<Account, 'id' | 'code' | 'name'> | null
  lines: ChartAccountEntryLine[]
}

export type ChartAccountColumn = {
  id: string
  code: string
  name: string
}

export type ChartAccountColumnRow = {
  id: string
  date: string
  particulars: string
  status: 'draft' | 'posted'
  account_id: string
  amount: string
}

export type ChartAccountColumnReport = {
  data: ChartAccountColumnRow[]
  meta: {
    year: number
    sort: 'asc' | 'desc'
    currency: string
    years: number[]
    columns: ChartAccountColumn[]
  }
}

export type AccountColumnReport = {
  id: string
  name: string
  accounts: Array<Pick<Account, 'id' | 'code' | 'name'>>
}

export function canWriteReport(role: string | undefined): boolean {
  return role === 'owner' || role === 'accountant'
}

export type Membership = {
  id: string
  name: string
  description: string | null
  currency: string
  role: string
}

export type Category = {
  id: string
  name: string
  businesses_count: number
}

export type Business = Membership & {
  accounts_count: number
  journals_count: number
  category_id: string | null
  category_name: string | null
}

export type Session = {
  id: string
  name: string
  email: string
  role: string
  company: {
    id: string
    name: string
    description: string | null
    currency: string
  }
  companies: Membership[]
}

export type TrialBalance = {
  data: Array<{
    account_id: string
    code: string
    name: string
    type: AccountType
    debit: string
    credit: string
  }>
  meta: {
    from: string
    to: string
    currency: string
    debit_total: string
    credit_total: string
    difference: string
  }
}

type Resource<T> = {
  id: string
  type?: string
  attributes: T
  relationships?: {
    account?: { data?: { id: string; type?: string } | null }
    lines?: { data?: Array<{ id: string; type?: string }> | null }
  }
}

export function readResource<T>(resource: Resource<T>): T & { id: string } {
  return { id: resource.id, ...resource.attributes }
}

export function readJournal(payload: {
  data: Resource<Omit<Journal, 'id' | 'lines'>>
  included?: Array<Resource<Omit<JournalLine, 'id'>>>
}): Journal {
  const lines = (payload.included ?? [])
    .filter((item) => item.type === 'journal-lines')
    .map((item) => readResource(item))

  return { ...readResource(payload.data), lines }
}

function includedAccounts(included: IncludedRecord[] | undefined): Map<string, Account> {
  return new Map(
    (included ?? [])
      .filter((item) => item.type === 'accounts')
      .map((item) => [item.id, readResource(item as Resource<Omit<Account, 'id'>>)]),
  )
}

type IncludedRecord = Resource<Omit<Account, 'id'> | Omit<ChartAccountEntryLine, 'id'>>

function includedLines(included: IncludedRecord[] | undefined): Map<string, ChartAccountEntryLine> {
  return new Map(
    (included ?? [])
      .filter((item) => item.type === 'chart-account-entry-lines')
      .map((item) => [item.id, readResource(item as Resource<Omit<ChartAccountEntryLine, 'id'>>)]),
  )
}

function entryLines(
  resource: Resource<Omit<ChartAccountEntry, 'id' | 'account' | 'lines'>>,
  lines: Map<string, ChartAccountEntryLine>,
): ChartAccountEntryLine[] {
  return (resource.relationships?.lines?.data ?? [])
    .map((line) => lines.get(line.id))
    .filter((line): line is ChartAccountEntryLine => line !== undefined)
}

export function readChartAccountEntry(payload: {
  data: Resource<Omit<ChartAccountEntry, 'id' | 'account' | 'lines'>>
  included?: IncludedRecord[]
}): ChartAccountEntry {
  const accounts = includedAccounts(payload.included)
  const accountId = payload.data.relationships?.account?.data?.id ?? payload.data.attributes.account_id

  return {
    ...readResource(payload.data),
    account: accounts.get(accountId) ?? null,
    lines: entryLines(payload.data, includedLines(payload.included)),
  }
}

export function readChartAccountEntries(payload: {
  data: Array<Resource<Omit<ChartAccountEntry, 'id' | 'account' | 'lines'>>>
  included?: IncludedRecord[]
}): ChartAccountEntry[] {
  const accounts = includedAccounts(payload.included)
  const lines = includedLines(payload.included)

  return payload.data.map((resource) => {
    const accountId = resource.relationships?.account?.data?.id ?? resource.attributes.account_id

    return {
      ...readResource(resource),
      account: accounts.get(accountId) ?? null,
      lines: entryLines(resource, lines),
    }
  })
}
