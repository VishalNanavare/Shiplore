-- The order ownership/escalation admin actions (force-claim, force-release,
-- set-priority, override-delivery) are gated on 'order.manage', which never
-- existed as a permission code — so the controls were always hidden/denied.
-- Create it and grant it to every role that can already update order status
-- (i.e. the order-managing roles, incl. Super Admin).
INSERT INTO permissions (code, module, action, description, scope_class, created_at, updated_at)
SELECT 'order.manage', 'order', 'manage', 'Manage order ownership, claims & delivery (admin)', 'platform', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'order.manage');

INSERT INTO role_permissions (role_id, permission_id, created_at, updated_at)
SELECT DISTINCT rp.role_id, (SELECT id FROM permissions WHERE code = 'order.manage'), NOW(), NOW()
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code = 'order.update.status'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp2
    WHERE rp2.role_id = rp.role_id
      AND rp2.permission_id = (SELECT id FROM permissions WHERE code = 'order.manage')
  );
