# Iterasi 2 — HR Export Contract

## Endpoint internal
`GET /api/internal/pos/auth-context`

### Header wajib
`X-POS-SYNC-TOKEN: <shared-token>`

### Query params
- `active_only=1|0`
- `since=2026-03-20T00:00:00+07:00` atau `since=2026-03-20`

## Command
```bash
php artisan hr:export-pos-auth-context --stdout
php artisan hr:export-pos-auth-context --active-only=1 --since=2026-03-20 --path=exports/hr-pos-sync.json
```

## Shape payload utama
```json
{
  "meta": {
    "source": "hr",
    "contract": "hr-pos-auth-context",
    "version": 2,
    "exported_at": "...",
    "filters": {
      "active_only": true,
      "since": "..."
    },
    "entity_counts": {
      "outlets": 0,
      "users": 0,
      "employees": 0,
      "assignments": 0
    },
    "validation": {
      "is_valid": false,
      "warning_counts": {},
      "warnings": {}
    },
    "checksums": {
      "outlets": "...",
      "users": "...",
      "employees": "...",
      "assignments": "...",
      "snapshot": "..."
    }
  },
  "outlets": [],
  "users": [],
  "employees": [],
  "assignments": []
}
```

## Catatan desain
- contract ini sengaja tidak bergantung ke direct DB coupling dengan POS
- checksum dipakai sebagai fondasi skip/update logic pada Iterasi 3
- warning validation tetap dikirim walaupun export sukses
- command dan endpoint wajib menghasilkan payload kontrak yang sama
