import { expect, test } from '@playwright/test'

// Demo owner created by `php artisan pulseboard:demo`, which reads the same variables.
const OWNER = {
  email: process.env.E2E_EMAIL || process.env.DEMO_OWNER_EMAIL || 'demo@example.com',
  password: process.env.E2E_PASSWORD || process.env.DEMO_PASSWORD || '',
}

if (!OWNER.password) {
  throw new Error('Set DEMO_PASSWORD (the value used by `php artisan pulseboard:demo`) or E2E_PASSWORD before running the e2e smoke.')
}

test('login → dashboard → product CRUD → transaction → analytics', async ({ page }) => {
  const productName = `E2E smoke ${Date.now()}`

  await test.step('login lands on the dashboard', async () => {
    await page.goto('/login')
    await page.getByLabel('E-mail').fill(OWNER.email)
    await page.getByLabel('Senha', { exact: true }).fill(OWNER.password)
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
    await expect(page.getByText('Receita', { exact: true }).first()).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Top 5 produtos' })).toBeVisible()
  })

  await test.step('create, edit and delete a product', async () => {
    await page.getByRole('link', { name: 'Produtos' }).first().click()
    await page.getByRole('button', { name: 'Novo produto' }).click()

    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Nome').fill(productName)
    await dialog.getByLabel('Preço').fill('12,50')
    await dialog.getByRole('button', { name: /Criar|Salvar/ }).click()
    await expect(dialog).toBeHidden()

    await page.getByRole('searchbox').fill(productName)
    const link = page.getByRole('link', { name: productName })
    await expect(link).toBeVisible()
    await link.click()

    await expect(page.getByRole('heading', { level: 1, name: productName })).toBeVisible()
    await page.getByRole('button', { name: 'Editar' }).click()
    await page.getByRole('dialog').getByLabel('Preço').fill('15,00')
    await page.getByRole('dialog').getByRole('button', { name: 'Salvar' }).click()
    await expect(page.getByText(/R\$\s15,00/).first()).toBeVisible()

    await page.getByRole('button', { name: 'Excluir' }).click()
    await page.getByRole('alertdialog').getByRole('button', { name: /Excluir|Remover|Arquivar/ }).click()
    await expect(page).toHaveURL(/\/products$/)
  })

  await test.step('open a transaction', async () => {
    await page.getByRole('link', { name: 'Transações' }).first().click()
    await page.locator('tbody a[href^="/transactions/"]').first().click()

    await expect(page.getByRole('heading', { name: 'Itens' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Ciclo de vida' })).toBeVisible()
    await expect(page.getByRole('list', { name: 'Histórico de status' }).getByRole('listitem').first()).toContainText('Criada como')
    await expect(page.getByRole('button', { name: /Editar|Excluir/ })).toHaveCount(0)
  })

  await test.step('analytics tabs keep the period', async () => {
    await page.goto('/analytics?period=90d')
    await expect(page).toHaveURL(/\/analytics\/revenue\?period=90d$/)

    await page.getByRole('link', { name: 'Produtos', exact: true }).last().click()
    await expect(page).toHaveURL(/\/analytics\/products\?period=90d$/)
    await expect(page.getByRole('heading', { name: 'Ranking de produtos' })).toBeVisible()

    await page.getByRole('link', { name: 'Transações', exact: true }).last().click()
    await expect(page).toHaveURL(/\/analytics\/transactions\?period=90d$/)
    await expect(page.getByText('Esta aba considera todos os status')).toBeVisible()
  })
})
