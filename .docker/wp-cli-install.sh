#!/bin/sh
set -eu

until wp core version --allow-root >/dev/null 2>&1; do
  sleep 3
  echo "Waiting for WordPress container..."
done

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
  wp core install \
    --url="http://localhost:8080" \
    --title="WP Login Approval Local" \
    --admin_user="admin" \
    --admin_password="admin" \
    --admin_email="admin@example.com" \
    --skip-email \
    --allow-root
fi

wp plugin activate gatalyn-login-approval --allow-root
wp option update gatalyn_enabled 1 --allow-root
wp option update gatalyn_mode before --allow-root
wp option update gatalyn_token_expiry 60 --allow-root

if ! wp user get tester --field=ID --allow-root >/dev/null 2>&1; then
  wp user create tester tester@example.com \
    --role=subscriber \
    --user_pass="tester123" \
    --display_name="Tester User" \
    --allow-root
fi

echo "WordPress ready: http://localhost:8080"
echo "Mailpit: http://localhost:8025"
echo "Admin: admin / admin"
echo "Test user: tester / tester123"
