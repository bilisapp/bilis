---
paths:
  - app/Models/ProjectApiKey.php
---

# Models

## API keys are a pair: secret hashed, public stored in plaintext
One row holds both halves of one credential — one name, one `last_used_at`, one revoke, one rate-limit bucket. The secret (`bilis_`) is shown once and kept as sha256. The public half (`bilis_pk_`, `public_key` column) is stored in plaintext on purpose: it is the userinfo of a DSN, which lands in a deploy config and a browser bundle, so it must stay readable from the UI forever. Do not "fix" it by hashing it — the DSN becomes unrecoverable and no secrecy is bought.

`Str::random()` emits alphanumerics only, so no secret key can ever be generated that starts with `bilis_pk_`; the two halves are unambiguous and each lookup only ever matches its own kind.
