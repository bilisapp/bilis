---
paths:
  - 'resources/views/vendor/mail/**'
---

# Mail

## Every email uses the Bilis mail theme; components are unindented HTML blocks
All Markdown mail (alerts, invitations, Fortify resets, contact) renders through the published components here and `themes/bilis.css` (`config('mail.markdown.theme')`). The theme's hex values are copies of app.css's light tokens (mail clients read no custom properties) — change them together. Achromatic like the app: one neutral button for every `color`, hue only on an alert's state dot. Sizes follow DESIGN.md's 16/14/12/11 ramp. A component (e.g. `alert-*.blade.php`) must be one unindented HTML block with no blank lines: the body goes through the Markdown parser, which treats indented HTML as code and parses Markdown after a blank line — keeping it one block is also what keeps a log message from becoming a link. Each html component needs a `text/` twin for the plain-text part. The alert email is `resources/views/mail/alert.blade.php`, fed by `AlertNotification::toMail()`.
