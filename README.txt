# TPB Volunteer System Update
## December 28, 2025

### Folder Structure (extract to site root)
```
/volunteer/index.php  ← new volunteer workspace
/api/claim.php        ← two-tier approval API
/sql/volunteer_system_updates.sql  ← run in phpMyAdmin
```

### Deploy Order
1. **Run SQL first** in phpMyAdmin (sql/volunteer_system_updates.sql)
2. **Upload files** - extract zip to site root, overwrites existing

### Make Yourself PM
After SQL runs:
```sql
INSERT INTO user_skill_progression (user_id, skill_set_id, status, is_primary)
VALUES (YOUR_USER_ID, 8, 'active', 1);
```

### What's New
- Sub-nav: My Work | Available | Completed | PM
- Two-tier approval (claim + completion)
- PM-only task creation
