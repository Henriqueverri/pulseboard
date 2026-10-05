import { expect, test } from '@playwright/test'

// Demo owner created by `php artisan pulseboard:demo`, which reads the same variables.
const OWNER = {
  email: process.env.E2E_EMAIL || process.env.DEMO_OWNER_EMAIL || 'demo@example.com',
  password: process.env.E2E_PASSWORD || process.env.DEMO_PASSWORD || '',
}

const API_URL = process.env.E2E_API_URL || process.env.NUXT_PUBLIC_API_URL || 'http://localhost:8000/api/v1'

if (!OWNER.password) {
  throw new Error('Set DEMO_PASSWORD (the value used by `php artisan pulseboard:demo`) or E2E_PASSWORD before running the e2e smoke.')
}

/**
 * The key is created by this test through the UI and only lives in memory: nothing is
 * hard-coded in the repository or in CI secrets. The ingested transaction stays in the
 * database (transactions cannot be deleted), so run it against a disposable database.
 */
test('API key → ingestion → lifecycle in the UI → revocation', async ({ page, playwright }) => {
  const runId = Date.now()
  const externalId = `e2e_order_${runId}`
  const keyName = `E2E ${runId}`
  let secret = ''
  let sku = ''

  await test.step('login', async () => {
    await page.goto('/login')
    await page.getByLabel('E-mail').fill(OWNER.email)
    await page.getByLabel('Senha', { exact: true }).fill(OWNER.password)
    await page.getByRole('button', { name: 'Entrar' }).click()
    await expect(page).toHaveURL(/\/dashboard$/)
  })

  await test.step('pick an active product SKU', async () => {
    await page.goto('/products?status=active')
    const cell = page.locator('tbody td .font-mono').first()
    await expect(cell).toBeVisible()
    sku = (await cell.textContent())?.trim() ?? ''
    expect(sku).not.toBe('')
  })

  await test.step('create an API key and read it once', async () => {
    await page.getByRole('link', { name: 'API Keys' }).first().click()
    await expect(page.getByRole('heading', { level: 1, name: 'API Keys' })).toBeVisible()

    await page.getByRole('button', { name: 'Nova chave' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Nome').fill(keyName)
    await dialog.getByRole('button', { name: 'Criar chave' }).click()

    const secretDialog = page.getByRole('dialog', { name: 'Copie sua API Key agora' })
    await expect(secretDialog.getByText('Esta é a única vez que a chave aparece.')).toBeVisible()
    secret = await secretDialog.locator('#api-key-secret').inputValue()
    expect(secret).toMatch(/^pb_[A-Za-z0-9]{12}_[A-Za-z0-9]{40}$/)

    await secretDialog.getByRole('button', { name: 'Já guardei a chave' }).click()
    await expect(secretDialog).toBeHidden()
    await expect(page.locator('#api-key-secret')).toHaveCount(0)
    await expect(page.getByRole('row', { name: new RegExp(keyName) })).toContainText('Ativa')
  })

  const api = await playwright.request.newContext()
  const headers = () => ({ 'Authorization': `Bearer ${secret}`, 'Accept': 'application/json', 'Content-Type': 'application/json' })

  await test.step('ingest a pending transaction, replay it and mark it as paid', async () => {
    const createdAt = new Date(Date.now() - 120_000).toISOString()
    const payload = {
      external_id: externalId,
      status: 'pending',
      occurred_at: createdAt,
      currency: 'BRL',
      customer: { external_id: `e2e_cus_${runId}`, name: 'Cliente E2E', email: `e2e_${runId}@example.com` },
      items: [{ sku, quantity: 1, unit_price: '10.00' }],
    }

    const created = await api.post(`${API_URL}/ingest/transactions`, { headers: headers(), data: payload })
    expect(created.status()).toBe(201)
    expect(created.headers()['x-request-id']).toBeTruthy()

    const replay = await api.post(`${API_URL}/ingest/transactions`, { headers: headers(), data: payload })
    expect(replay.status()).toBe(200)

    const paid = await api.post(`${API_URL}/ingest/transactions/${externalId}/status-changes`, {
      headers: headers(),
      data: { status: 'paid', occurred_at: new Date(Date.now() - 60_000).toISOString() },
    })
    expect(paid.status()).toBe(201)
  })

  await test.step('find it by external id and read its lifecycle', async () => {
    await page.goto(`/transactions?q=${externalId}`)
    const row = page.locator('tbody tr').filter({ hasText: externalId })
    await expect(row).toHaveCount(1)
    await expect(row).toContainText('Integração')
    await row.locator('a[href^="/transactions/"]').first().click()

    await expect(page.getByRole('heading', { name: 'Ciclo de vida' })).toBeVisible()
    const steps = page.getByRole('list', { name: 'Histórico de status' }).getByRole('listitem')
    await expect(steps).toHaveCount(2)
    await expect(steps.nth(0)).toContainText('Criada como Pendente')
    await expect(steps.nth(1)).toContainText('Pendente → Pago')
    await expect(steps.nth(1)).toContainText('Status atual')
    await expect(page.getByText(externalId).first()).toBeVisible()
  })

  await test.step('revoke the key and see the integration rejected', async () => {
    await page.goto('/settings/api-keys')
    await page.getByRole('button', { name: `Revogar a chave ${keyName}` }).click()
    await page.getByRole('alertdialog').getByRole('button', { name: 'Revogar chave' }).click()
    await expect(page.getByRole('row', { name: new RegExp(keyName) })).toContainText('Revogada')

    const rejected = await api.get(`${API_URL}/ingest/transactions/${externalId}`, { headers: headers() })
    expect(rejected.status()).toBe(401)
    expect((await rejected.json()).code).toBe('invalid_api_key')
  })

  await api.dispose()
})
