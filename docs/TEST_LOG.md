# Test Log — Mini ERP

## 2026-06-09 — AR/AP Unified Ledger Session

### Commands run

```bash
php artisan migrate:status
php artisan route:list --path=accounting
php artisan test
npm run build
```

### Results

| Command | Result |
|---|---|
| `migrate:status` | All ran. 1 pending locally: `2026_06_08_900050_add_balance_type_to_account_codes` — đã chạy trên VPS, local DB chưa có vì migration được tạo trong session VPS. Không ảnh hưởng production. |
| `route:list` | Xác nhận route `accounting.ar-ap-opening-balance.pay` (POST) tồn tại |
| `php artisan test` | **41 passed, 163 assertions, 6.59s** — 0 failures |
| `npm run build` | OK (10.21s, no errors) — đã chạy trong session code |

### Test suite coverage

- `Tests\Feature\PayrollTest` — payroll journal entries, union fee, attendance snapshot
- `Tests\Feature\TaxTest` — tax summary, HTKK XML export
- Tổng 41 tests; chưa có test cho AR/AP service (chưa được yêu cầu)

### Pending migration (local only)

```
2026_06_08_900050_add_balance_type_to_account_codes — Pending (local)
```

Để đồng bộ local DB:
```bash
php artisan migrate
```

---

## 2026-06-05 — Phase E Bank Enhancements

| Command | Result |
|---|---|
| `php artisan test` | Passed (not recorded) |
| `npm run build` | OK |
| VPS deploy | Thành công — docker compose up, migrations ran including `900050` |

---

## Notes

- `--compact` flag không tồn tại trong Laravel version hiện tại — dùng `--path=` để filter routes thay thế.
- `php artisan about` có thể dùng để xem tổng quan environment.
- Không có `npm run lint` hay `npm run typecheck` trong `package.json` — chỉ có `dev` và `build`.
