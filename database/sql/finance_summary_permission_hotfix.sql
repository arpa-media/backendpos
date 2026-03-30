-- Manual SQL helper if menu permissions are still missing after code patch.
-- Run on the POS database once.

SET @finance_portal_id := (SELECT id FROM access_portals WHERE code = 'finance' LIMIT 1);

INSERT INTO access_menus (
    id, portal_id, code, name, path, sort_order,
    permission_view, permission_create, permission_update, permission_delete,
    is_active, created_at, updated_at
)
SELECT UPPER(REPLACE(UUID(), '-', '')), @finance_portal_id, 'finance-sales-summary', 'Sales Summary', '/finance/sales-summary', 16,
       'sale.view', NULL, NULL, NULL, 1, NOW(), NOW()
WHERE @finance_portal_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM access_menus WHERE code = 'finance-sales-summary');

INSERT INTO access_menus (
    id, portal_id, code, name, path, sort_order,
    permission_view, permission_create, permission_update, permission_delete,
    is_active, created_at, updated_at
)
SELECT UPPER(REPLACE(UUID(), '-', '')), @finance_portal_id, 'finance-category-summary', 'Category Summary', '/finance/category-summary', 17,
       'report.view', NULL, NULL, NULL, 1, NOW(), NOW()
WHERE @finance_portal_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM access_menus WHERE code = 'finance-category-summary');

SET @sales_summary_menu_id := (SELECT id FROM access_menus WHERE code = 'finance-sales-summary' LIMIT 1);
SET @category_summary_menu_id := (SELECT id FROM access_menus WHERE code = 'finance-category-summary' LIMIT 1);
SET @sales_list_menu_id := (SELECT id FROM access_menus WHERE code = 'sales-list' LIMIT 1);
SET @sales_report_menu_id := (SELECT id FROM access_menus WHERE code = 'sales-report' LIMIT 1);

INSERT INTO access_role_menu_permissions (
    id, access_role_id, access_level_id, menu_id,
    can_view, can_create, can_edit, can_delete, created_at, updated_at
)
SELECT UPPER(REPLACE(UUID(), '-', '')), src.access_role_id, src.access_level_id, @sales_summary_menu_id,
       src.can_view, src.can_create, src.can_edit, src.can_delete, NOW(), NOW()
FROM access_role_menu_permissions src
WHERE src.menu_id = @sales_list_menu_id
  AND @sales_summary_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM access_role_menu_permissions dst
      WHERE dst.menu_id = @sales_summary_menu_id
        AND dst.access_role_id = src.access_role_id
        AND ((dst.access_level_id IS NULL AND src.access_level_id IS NULL) OR dst.access_level_id = src.access_level_id)
  );

INSERT INTO access_role_menu_permissions (
    id, access_role_id, access_level_id, menu_id,
    can_view, can_create, can_edit, can_delete, created_at, updated_at
)
SELECT UPPER(REPLACE(UUID(), '-', '')), src.access_role_id, src.access_level_id, @category_summary_menu_id,
       src.can_view, src.can_create, src.can_edit, src.can_delete, NOW(), NOW()
FROM access_role_menu_permissions src
WHERE src.menu_id = @sales_report_menu_id
  AND @category_summary_menu_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM access_role_menu_permissions dst
      WHERE dst.menu_id = @category_summary_menu_id
        AND dst.access_role_id = src.access_role_id
        AND ((dst.access_level_id IS NULL AND src.access_level_id IS NULL) OR dst.access_level_id = src.access_level_id)
  );
