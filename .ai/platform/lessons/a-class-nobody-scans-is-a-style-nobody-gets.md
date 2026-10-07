# A class nobody scans is a style nobody gets

**What happened.** The sign-in screens and the panels "looked average". They looked average
because half the design system was not there: the primary button had no background, cards
had no radius and no shadow, table headers no weight. Every template was correct. The
stylesheet the browser received simply did not contain `bg-brand-600` or `rounded-card`.

Tailwind 4 generates a utility only for a class it has *seen*, and it looks for classes in
the app it is building - `frontend/`, `manager/`, `landing/`. The shared components live in
`ui-kit/`, which is none of those. A class used in an app's own page was generated; a class
used **only** in a shared component was not. `bg-brand-50` worked, because a page used it.
`bg-brand-600` did not, because only `UiButton` did.

**Why it is easy to miss.** Nothing fails. There is no build error, no console warning and
no 404: an unknown class is just a class with no rule. The page renders, the tests click the
transparent button by its test id, and the result reads as taste rather than as a bug - so
the report is "it looks so-so", and the instinct is to restyle instead of to ask why the
existing styles are not applied. The theme tokens made it worse: an unused `--radius-card`
is dropped from the output, so even the variable was missing.

**What to do.**

- `ui-kit/app/assets/css/main.css` declares `@source "../../";`. A layer that ships
  components must name itself as a source; do not remove the line, and add one for any new
  package that carries templates.
- When something looks unstyled, read the computed style before writing CSS. A class in the
  DOM with no rule behind it is this problem, not a design problem.
- `e2e/tests/login.spec.ts` asserts that the sign-in button has a real background colour.
  It is the one class that only the shared layer uses and every project renders.
