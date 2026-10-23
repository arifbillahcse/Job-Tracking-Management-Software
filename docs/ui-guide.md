# UI guide (Phase 6)

## Principles
* Bootstrap 5.3 (bundled in `public/assets/vendor/`, Inter in `public/assets/fonts/`; no CDN, strict CSP, no inline scripts or styles).
* One accent colour (`--jt-primary`); each section keeps the client's colours: amber = Ready, rose = More than 30 days, green = In transit, slate = Delivered. Colour is never the only signal: every status has a text badge and an icon.
* Design tokens live at the top of `public/assets/css/app.css`. Change a token, not individual rules.

## Responsive behaviour
| Width | Shell | Tables |
|---|---|---|
| >= 992px | fixed dark sidebar | full tables |
| 576-991px | offcanvas menu from the top bar | horizontal scroll inside the card where wide (permission matrix) |
| < 576px | offcanvas menu, 16px gutters | job/user/role tables become labelled cards (`table-cards`, `data-label`) |

Checked in Chromium at 360, 768 and 1280px on 15+ screens including import preview, with an automated test for horizontal page overflow and console errors (0 found).

## Accessibility
* Skip link, a single `h1` per page, `lang`, landmarks (`nav`, `main`), `aria-current` on the active menu item, labelled form controls, alert roles on messages.
* Failed saves move focus to the first invalid field and set `aria-invalid`.
* Visible focus rings (3px) on links, buttons, tabs, menu items and checkboxes.
* Text contrast measured in the browser on 12 key screens: every text element meets WCAG AA (4.5:1, or 3:1 for large text). Fixes made in Phase 6: amber chips/tabs, muted text, outline buttons, sidebar headings.
* `prefers-reduced-motion` disables animation.
* Not verified: screen readers (NVDA/VoiceOver), real phones/tablets, Safari/Firefox. Only Chromium was available.

## Behaviour
* Double-submit protection and a busy state on every form (`data-loading`).
* Unsaved-changes warning on job and user forms (`data-unsaved`).
* Destructive actions ask for confirmation (`data-confirm` / modals).
* Print: job details and lists print without the sidebar, menus and buttons.

## Not done (deliberately)
* Dark mode: the tokens make it feasible; left out because the client's sheet is light and it would double the visual testing.
* Toast notifications: messages are inline alerts at the top of the page, which keep working without JavaScript and are announced to screen readers.
