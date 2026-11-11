import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { OPERATOR } from '../fixtures/accounts'
import { signIn, signInAsOperator } from '../fixtures/session'

/**
 * WCAG 2.1 AA on the screens every product inherits (ADR-0024).
 *
 * axe-core finds what a component set loses quietly: a control with no name, a
 * contrast ratio a theme pushed under 4.5:1, a dialog without a title. It cannot
 * prove a screen accessible - keyboard order and wording still need a person -
 * but everything it flags is a real defect.
 *
 * Each screen is also saved to `.results/screens/`, so a theme change can be
 * looked at, not only tested.
 */
const managerUrl = process.env.E2E_MANAGER_URL ?? 'https://manager.open-enu.local'

async function audit(page: Page, name: string) {
  // Wait for the page's own content, not for the clock.
  await page.waitForLoadState('networkidle')
  await page.screenshot({ path: `.results/screens/${name}.png`, fullPage: true })

  const { violations } = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    // Nuxt DevTools' own floating button, injected by the dev server only.
    .exclude('#nuxt-devtools-container')
    .analyze()

  const summary = violations.map(v => `${v.id} (${v.impact}): ${v.nodes.map(n => n.target.join(' ')).join(', ')}`)
  expect(summary, `axe found WCAG 2.1 AA violations on ${name}`).toEqual([])
}

test('sign-in', async ({ page }) => {
  await page.goto('/login')
  await expect(page.getByTestId('login-submit')).toBeVisible()
  await audit(page, 'login')
})

test('dashboard and shell', async ({ page }) => {
  await signIn(page)
  await audit(page, 'dashboard')
})

test('a list, and a dialog open over it', async ({ page }) => {
  await signIn(page)
  await page.goto('/example')
  await expect(page.getByTestId('example-new')).toBeVisible()
  await audit(page, 'example-list')

  await page.getByTestId('example-new').click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await audit(page, 'example-dialog')
})

test('a tree in settings', async ({ page }) => {
  await signIn(page)
  await page.goto('/settings/structure')
  await expect(page.getByTestId('unit-new')).toBeVisible()
  await audit(page, 'settings-structure')
})

test('the operator console', async ({ page }) => {
  await signInAsOperator(page, managerUrl, OPERATOR.email, OPERATOR.password)
  await audit(page, 'manager-tenants')
})
