import { expect, test } from '@playwright/test'
import { signIn } from '../fixtures/session'

/**
 * The tenant's tree, built in the browser (ADR-0025): a unit, a unit below it,
 * and the picker that must never offer a unit's own subtree as its new parent.
 */
test('an owner builds the tree and cannot move a unit under itself', async ({ page }) => {
  const stamp = Date.now()
  const region = `Region ${stamp}`
  const site = `Site ${stamp}`

  await signIn(page)
  await page.goto('/settings/structure')

  await page.getByTestId('unit-new').click()
  await page.getByTestId('unit-form-name').fill(region)
  await page.getByTestId('unit-form-kind').fill('region')
  await page.getByTestId('unit-form-save').click()
  await expect(page.getByTestId('unit-name').filter({ hasText: region })).toBeVisible()

  await page.getByRole('button', { name: new RegExp(`${region}$`) }).first().click()
  await page.getByTestId('unit-form-name').fill(site)
  await page.getByTestId('unit-form-kind').fill('site')
  await page.getByTestId('unit-form-save').click()

  const child = page.getByTestId('unit-name').filter({ hasText: site })
  await expect(child).toBeVisible()
  // Indented one level: the tree is read back from the server, not drawn from the form.
  await expect(child).toHaveAttribute('style', /padding-inline-start: 1\.25rem/)

  await page.getByRole('button', { name: new RegExp(`${region}$`) }).nth(2).click()
  await page.getByRole('dialog').getByRole('combobox').click()
  await expect(page.getByRole('option', { name: region, exact: false })).toHaveCount(0)
  await expect(page.getByRole('option', { name: site, exact: false })).toHaveCount(0)
})
