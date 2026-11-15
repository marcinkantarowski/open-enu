import { expect, test } from '@playwright/test'
import { signIn } from '../fixtures/session'

/**
 * A tenant's own role, made and unmade in the browser (ADR-0025): the boxes
 * ticked are what the server stores, read back when the role is opened again.
 */
test('an owner makes a role, finds it as ticked when reopened, and deletes it', async ({ page }) => {
  const name = `Desk ${Date.now()}`

  await signIn(page)
  await page.goto('/settings/roles')

  await page.getByTestId('role-new').click()
  await page.getByTestId('role-form-name').fill(name)
  await page.getByTestId('role-permission-example.manage').getByRole('checkbox').click()
  await page.getByTestId('role-form-save').click()

  const row = page.getByRole('row').filter({ hasText: name })
  await expect(row).toBeVisible()
  await expect(row).toContainText(/\b1 (of|z) \d+/)

  await page.getByRole('button', { name: new RegExp(`${name}$`) }).first().click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByTestId('role-permission-example.manage').getByRole('checkbox')).toHaveAttribute('aria-checked', 'true')
  await expect(dialog.getByTestId('role-permission-example.view').getByRole('checkbox')).toHaveAttribute('aria-checked', 'false')
  await dialog.getByRole('button', { name: /cancel|anuluj/i }).click()

  page.once('dialog', confirm => confirm.accept())
  await page.getByRole('button', { name: new RegExp(`${name}$`) }).nth(1).click()
  await expect(row).toHaveCount(0)
})
