import { expect, test } from '@playwright/test'
import { OPERATOR, OWNER, PRIMARY_TENANT } from '../fixtures/accounts'
import { signInAsOperator } from '../fixtures/session'

/**
 * The one legitimate crossing between the two realms (ADR-0008).
 *
 * Three things have to be true at once, and only a browser can check them
 * together: the operator token works on `/api/manager` and nowhere else, the
 * minted tenant token is a genuine `aud: app` token the tenant app accepts, and
 * the person being impersonated - or anyone looking over a shoulder - can see
 * that it is happening.
 */
const managerUrl = process.env.E2E_MANAGER_URL ?? 'https://manager.open-enu.local'

test('an operator can view a workspace as one of its members, and the banner says so', async ({ browser }) => {
  const context = await browser.newContext({ ignoreHTTPSErrors: true })
  const page = await context.newPage()

  // Both confirmations are deliberate friction in the product; accept them.
  page.on('dialog', dialog => dialog.accept())

  await signInAsOperator(page, managerUrl, OPERATOR.email, OPERATOR.password)

  await page.getByRole('link', { name: PRIMARY_TENANT.name }).first().click()
  // The owner, by name: other suites invite members who cannot read the audit
  // trail, and the first row of the list is whoever sorts first.
  const owner = page.getByRole('row').filter({ hasText: OWNER.email }).getByTestId('impersonate')
  await expect(owner).toBeVisible()

  // The console hands the session to the tenant app in a new tab rather than
  // rendering tenant screens itself.
  const [impersonated] = await Promise.all([
    context.waitForEvent('page'),
    owner.click(),
  ])

  await impersonated.waitForLoadState()

  const banner = impersonated.getByTestId('impersonation-banner')
  await expect(banner, 'a support session the customer cannot see is a trust problem').toBeVisible()

  // The token arrived in the fragment and was consumed: nothing resembling a JWT
  // may remain in the address bar, where it would sit in history.
  expect(impersonated.url()).not.toMatch(/eyJ/)

  // A change made in the session is recorded against both people, and the
  // trail shows it - it must never read as the customer's own. The token lives
  // in memory only, so every move from here is a click, never a page load: the
  // search box reaches the seeded project ("Getting started") with or without
  // DEMO_CONTENT putting it in the menu.
  const name = `Support ${Date.now()}`
  await impersonated.keyboard.press('/')
  await impersonated.getByTestId('global-search').fill('getting')
  await impersonated.getByTestId('global-search-result').first().click()
  await impersonated.getByTestId('example-new').click()
  await impersonated.getByTestId('example-name').fill(name)
  await impersonated.getByTestId('example-create-save').click()
  await expect(impersonated.getByTestId('example-item-name').filter({ hasText: name })).toBeVisible()

  await impersonated.getByRole('link', { name: /activity|aktywność/i }).click()
  const entry = impersonated.getByRole('row').filter({ hasText: 'example.project.created' }).first()
  await expect(entry, 'an impersonated change must name the session it came from').toContainText(/support session|sesja wsparcia/i)
  await entry.getByTestId('audit-details').click()
  await expect(impersonated.getByTestId('audit-snapshot')).toContainText(name)

  await context.close()
})

test('an operator token is refused by the tenant API', async ({ request }) => {
  // Both firewalls verify against the same signing key, so realm isolation rests
  // entirely on the `aud` claim. If that check regresses, this is the test that
  // notices (ADR-0007).
  const login = await request.post(`${process.env.E2E_API_BASE}/api/manager/login`, {
    data: { email: OPERATOR.email, password: OPERATOR.password },
  })

  expect(login.ok()).toBe(true)
  const { token } = await login.json() as { token: string }

  const refused = await request.get(`${process.env.E2E_API_BASE}/api/projects`, {
    headers: { Authorization: `Bearer ${token}` },
  })

  expect(refused.status(), 'a manager token must not open a tenant endpoint').toBe(401)
})
