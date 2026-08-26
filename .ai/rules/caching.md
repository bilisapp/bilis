# Caching

## Never cache PHP objects

The database cache store (CACHE_STORE=database, the default here) refuses to
unserialize objects — they come back as `__PHP_Incomplete_Class` and reads
blow up with "tried to access a property on an incomplete object". Tests miss
this because the test suite uses the array driver.

Cache scalars/arrays only and rehydrate DTOs after the read (see
`DocsRepository::sections()` / `render()`). This also survives class renames
across deploys with a warm cache.
