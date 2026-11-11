# theme/ - this project's look

A Nuxt layer that every app extends **before** `ui-kit` (ADR-0024). The platform ships this
README and nothing else here, so `make platform-update` never touches what a project adds.

Empty, the apps look like the platform. To make them look like the product, add:

```
theme/
├── nuxt.config.ts          css: [<this layer>/assets/theme.css], favicons in app.head
├── assets/theme.css        the role tokens, unlayered on :root; @font-face
├── public/                 fonts, logo, favicons - served at the site root
└── components/BrandMark.vue   replaces the platform's mark in every shell
```

`nuxt.config.ts` is what makes it a layer: without it the apps ignore the directory.

Set **values**, never classes. The roles - `accent`, `canvas`, `surface`, `fg`, `border-input`,
`ok`, `warn`, `crit`, `nav`, ... - are listed in `ui-kit/app/assets/css/main.css`; a component
reads only those, so changing one here changes every screen. Keep the status colours
readable: they mean the same in every product and are never a tenant's to change.

See `.ai/platform/docs/frontend-conventions.md` → *Components, tokens and the theme*.
