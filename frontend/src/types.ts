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
  reference: string | null
  amount: string
  cash: string
  credit: string
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
  reference: string | null
  status: 'draft' | 'posted'
  account_id: string
  amount: string
  cash: string
  credit: string
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
  return role === 'admin'
}

export function canViewDashboard(role: string | undefined): boolean {
  return role === 'admin' || role === 'manager'
}

export function homePath(role: string | undefined): string {
  return canViewDashboard(role) ? '/dashboard' : '/purchase-expense'
}

export type BusinessSummaryAccount = {
  id: string
  code: string
  name: string
  type: AccountType
  amount: string
  cash: string
  credit: string
  entries: number
}

export type BusinessSummary = {
  id: string
  name: string
  currency: string
  classification_name: string | null
  income: string
  expense: string
  result: string
  drafts: number
  accounts: BusinessSummaryAccount[]
}

export type IncomeExpensePoint = {
  key: string
  sales: string
  operation_expense: string
  purchase_expense: string
}

export type IncomeExpenseChart = {
  id: string
  name: string
  currency: string
  classification_name: string | null
  business_count: number
  months: IncomeExpensePoint[]
}

export type IncomeExpenseChartReport = {
  data: IncomeExpenseChart[]
  meta: {
    group: 'business' | 'classification'
    from: string
    to: string
    months: Array<{ key: string; year: number; month: number }>
  }
}

export type ItemActivityCell = {
  key: string
  quantity: string
  amount: string
}

export type ItemActivityTotal = {
  quantity: string
  amount: string
  entries: number
  average: string | null
}

export type ItemActivityRow = {
  name: string
  periods: ItemActivityCell[]
  total: ItemActivityTotal
}

export type ItemActivityGrain = 'day' | 'week' | 'month' | 'year'

export type ItemActivityReport = {
  data: ItemActivityRow[]
  meta: {
    book: string
    grain: ItemActivityGrain
    account_code: string
    account_name: string
    currency: string
    item_label: string
    year: number | null
    month: number | null
    from: string
    to: string
    periods: Array<{ key: string; label: string }>
    years: number[]
    totals: {
      periods: ItemActivityCell[]
      total: ItemActivityTotal
    }
  }
}

export type YearlySummaryMonth = {
  month: number
  sales: string
  operation_expense: string
  purchase_expense: string
}

export type YearlySummaryBusiness = {
  id: string
  name: string
  currency: string
  sales: string
  operation_expense: string
  purchase_expense: string
  months: YearlySummaryMonth[]
}

export type YearlySummaryClassification = {
  id: string | null
  name: string
  businesses: YearlySummaryBusiness[]
}

export type YearlySummaryReport = {
  data: YearlySummaryClassification[]
  meta: {
    year: number
    years: number[]
  }
}

export type YearlySummaryDay = {
  day: number
  sales: string
  operation_expense: string
  purchase_expense: string
}

export type YearlySummaryRecordLine = {
  name: string
  quantity: string
  amount: string
}

export type YearlySummaryRecord = {
  id: string
  account_code: string
  account_name: string
  particulars: string
  reference: string | null
  amount: string
  cash: string
  credit: string
  lines: YearlySummaryRecordLine[]
}

export type YearlySummaryRecords = {
  data: {
    id: string
    name: string
    currency: string
    year: number
    month: number
    day: number
    entries: YearlySummaryRecord[]
  }
}

export type YearlySummaryDays = {
  data: {
    id: string
    name: string
    currency: string
    year: number
    month: number
    sales: string
    operation_expense: string
    purchase_expense: string
    days: YearlySummaryDay[]
  }
}

export type BusinessSummaryReport = {
  data: BusinessSummary[]
  meta: {
    period: 'month' | 'year'
    year: number
    month: number | null
    from: string
    to: string
    years: number[]
    totals: {
      currency: string
      income: string
      expense: string
      result: string
    } | null
  }
}

export type Membership = {
  id: string
  name: string
  description: string | null
  currency: string
}

export type Category = {
  id: string
  name: string
  classifications_count: number
}

export type Classification = {
  id: string
  name: string
  businesses_count: number
  category_id: string | null
  category_name: string | null
  logo_url: string | null
}

export type Location = {
  id: string
  name: string
  businesses_count: number
}

export type DirectoryUser = {
  id: string
  name: string
  email: string
  role: 'admin' | 'manager' | 'staff'
  is_active: boolean
  companies: Array<{ id: string; name: string }>
}

export type Business = Membership & {
  accounts_count: number
  journals_count: number
  classification_id: string | null
  classification_name: string | null
  location_id: string | null
  location_name: string | null
  logo_url: string | null
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

export type ChartAccountEntryPage = {
  entries: ChartAccountEntry[]
  page: number
  lastPage: number
  total: number
  drafts: number
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

export function readChartAccountEntryPage(payload: {
  data: Array<Resource<Omit<ChartAccountEntry, 'id' | 'account' | 'lines'>>>
  included?: IncludedRecord[]
  meta?: {
    current_page?: number
    last_page?: number
    total?: number
    drafts?: number
  }
}): ChartAccountEntryPage {
  const entries = readChartAccountEntries(payload)

  return {
    entries,
    page: payload.meta?.current_page ?? 1,
    lastPage: payload.meta?.last_page ?? 1,
    total: payload.meta?.total ?? entries.length,
    drafts: payload.meta?.drafts ?? entries.filter((entry) => entry.status === 'draft').length,
  }
}
