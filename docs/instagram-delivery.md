# Instagram delivery (xanderbot)

## Ads created today

- Meta **`last_7d` insights exclude today**, so debug can show `last_7d: $0` while **`today`** has all impressions. That is normal for new ads.
- Use **`today`** or Ads Manager “Today” for placement breakdown on new ads.
- UI uses **`today`** preset for ads created in the last 3 days when showing platform columns.

## IG enabled vs IG live

| Status | Meaning |
|--------|---------|
| **IG enabled** | Ad set FB+IG, creative has `instagram_user_id` on Meta |
| **IG live** | Meta insights show `instagram` impressions > 0 |

## Audience Network on new ads

If today's breakdown shows **audience_network** on an ad created today, the ad set was usually created with **Audience Network** in manual placements (now stripped on create). Fix:

```bash
php artisan meta:enable-instagram --force-adsets
```

Or create a **new ad set** (Automatic placements) and a new ad.

## Commands

```bash
php artisan meta:debug-ad-ig {local_id} --run
php artisan meta:enable-instagram --force-adsets
```

Local ad IDs are in `ads.id` (often 5–8, not 1).
