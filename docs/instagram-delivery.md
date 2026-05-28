# Instagram delivery (xanderbot)

## Stable fix for legacy campaigns & ad sets

Old campaigns/ad sets can keep delivering on **Audience Network** even after targeting is FB+IG. Meta ties placement history to the **ad id**.

**One-time stable repair:**

```bash
php artisan meta:enable-instagram --force-adsets --reprovision
```

Or in UI: **Enable IG (all existing)** on the Ads page (same as `--reprovision`).

This will:

1. Force all ad sets to **facebook + instagram** only (valid positions, no `ig_search`).
2. Rebuild creatives with `instagram_user_id`.
3. For ads with FB/AN today but **0 IG**: create a **new Meta ad** in the same ad set, **pause** the old Meta ad, update local `meta_ad_id`.
4. Other ads: attach IG creative + pause/active refresh.

Past impressions stay on the old Meta ad. **IG live** appears on the **new** ad after new spend (often 24–48h).

## Commands

```bash
php artisan meta:debug-ad-ig 8 --run
php artisan meta:enable-instagram --force-adsets --reprovision
php artisan meta:backfill-ig-enabled
```

Local ad IDs are usually **5–8**, not 1.

## IG enabled vs IG live

| Status | Meaning |
|--------|---------|
| **IG enabled** | Ad set FB+IG, creative has `instagram_user_id` |
| **IG live** | Meta insights show `instagram` impressions > 0 |

## Insights notes

- **last_7d** often excludes **today** (shows $0 on new ads).
- Use **today** breakdown in debug for current delivery.
- After reprovision, confirm the **new** `meta_ad_id` in debug output.
