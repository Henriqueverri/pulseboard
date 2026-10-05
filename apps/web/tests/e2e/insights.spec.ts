import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'

// Demo owner created by `php artisan pulseboard:demo`, which reads the same variables.
const OWNER = {
  email: process.env.E2E_EMAIL || process.env.DEMO_OWNER_EMAIL || 'demo@example.com',
  password: process.env.E2E_PASSWORD || process.env.DEMO_PASSWORD || '',
}

// ScriptedLlmClient::PLACEHOLDER_TEXT: the scripted provider fills every text field with it.
const SCRIPTED_TEXT = 'Resposta de demonstração gerada pelo provedor roteirizado, sem modelo de linguagem.'

if (!OWNER.password) {
  throw new Error('Set DEMO_PASSWORD (the value used by `php artisan pulseboard:demo`) or E2E_PASSWORD before running the e2e smoke.')
}

async function expectNoAxeViolations(page: Page) {
  const { violations } = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze()

  expect(violations.map(violation => `${violation.id}: ${violation.nodes.map(node => node.target.join(' ')).join(', ')}`)).toEqual([])
}

/**
 * Needs the API with AI_ENABLED=true and AI_PROVIDER=scripted (no key, no network), so
 * `pulseboard:demo` opts the demo organization in. It expects no summary generated yet for
 * the last 90 days: run it against a freshly seeded disposable database.
 */
test('insights: opt-in → not generated → generate → evidence → destination with the period', async ({ page }) => {
  const card = page.getByRole('region', { name: 'Insights do período' })

  await test.step('login', async () => {
    await page.goto('/login')
    await page.getByLabel('E-mail').fill(OWNER.email)
    await page.getByLabel('Senha', { exact: true }).fill(OWNER.password)
    await page.getByRole('button', { name: 'Entrar' }).click()
    await expect(page).toHaveURL(/\/dashboard$/)
  })

  await test.step('the demo organization is opted in', async () => {
    await page.getByRole('link', { name: 'Insights' }).first().click()
    await expect(page.getByRole('heading', { level: 1, name: 'Insights' })).toBeVisible()
    await expect(page.getByRole('switch', { name: 'Ativar insights nesta organização' })).toBeChecked()
    await expect(page.getByText('A geração por IA está desligada neste ambiente.')).toHaveCount(0)
    await expectNoAxeViolations(page)
  })

  await test.step('the dashboard shows the not generated state for the period', async () => {
    await page.goto('/dashboard?period=90d')
    await expect(card.getByText('Gere uma leitura dos indicadores de últimos 90 dias')).toBeVisible()
    await expect(card.getByRole('button', { name: 'Gerar insights do período' })).toBeEnabled()
    await expect(card.getByText('são enviados à OpenAI')).toBeVisible()
    await expectNoAxeViolations(page)
  })

  await test.step('generate and read the result', async () => {
    await card.getByRole('button', { name: 'Gerar insights do período' }).click()

    await expect(card.getByText(SCRIPTED_TEXT).first()).toBeVisible()
    await expect(card.getByText('Gerado por IA', { exact: true })).toBeVisible()
    await expect(card.getByRole('heading', { level: 2, name: 'Insights do período' })).toBeFocused()
    await expect(card.getByRole('list', { name: 'Achados do período' }).getByRole('listitem')).toHaveCount(1)
    await expect(card.getByText(/Gerado por IA \(scripted\) em/)).toBeVisible()
    // The period ends today, a server-side caveat.
    await expect(card.getByText('O período termina hoje ou depois')).toBeVisible()
  })

  await test.step('the evidence shows the same number as the dashboard', async () => {
    const kpiRevenue = page.getByRole('region', { name: 'Indicadores do período' })
      .locator('div', { has: page.getByText('Receita', { exact: true }) })
      .locator('p.tabular')
      .first()
    const evidence = card.locator('dl').first()

    await expect(evidence.locator('dt')).toHaveText('Receita')
    const value = (await kpiRevenue.textContent())?.trim() ?? ''
    expect(value).toMatch(/R\$/)
    await expect(evidence.locator('dd > span').first()).toHaveText(value)
  })

  await test.step('the result has no axe violations on desktop and mobile', async () => {
    await expectNoAxeViolations(page)

    await page.setViewportSize({ width: 390, height: 844 })
    await expect(card.getByText(SCRIPTED_TEXT).first()).toBeVisible()
    await expectNoAxeViolations(page)
    await page.setViewportSize({ width: 1280, height: 720 })
  })

  await test.step('a reload shows the cached summary without generating again', async () => {
    await page.reload()
    await expect(card.getByText(SCRIPTED_TEXT).first()).toBeVisible()
    await expect(card.getByRole('button', { name: 'Gerar insights do período' })).toHaveCount(0)
  })

  await test.step('the finding links to its destination with the same period', async () => {
    const link = card.getByRole('link', { name: 'Ver Dashboard, últimos 90 dias' })
    await expect(link).toHaveAttribute('href', '/dashboard?period=90d')

    await link.click()
    await expect(page).toHaveURL(/\/dashboard\?period=90d$/)
    await expect(card.getByText(SCRIPTED_TEXT).first()).toBeVisible()
  })
})
