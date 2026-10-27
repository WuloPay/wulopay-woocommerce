#!/bin/sh
# Installs WordPress + WooCommerce, activates the plugin, makes a ZAR store with one R100 product.
# Then add your sandbox key in WooCommerce, Settings, Payments, WuloPay (or set WULOPAY_KEY_ID / WULOPAY_SECRET).
set -e
cd "$(dirname "$0")"
wp() { docker compose run --rm -T cli "$@"; }
until wp core is-installed 2>/dev/null || wp core install --url=http://localhost:8088 --title="WuloPay Test Shop" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email; do sleep 3; done
wp plugin install woocommerce --activate
wp plugin activate wulopay-woocommerce
wp option update woocommerce_currency ZAR
wp option update woocommerce_default_country ZA:GP
wp option update woocommerce_coming_soon no
wp rewrite structure "/%postname%/" --hard
wp wc tool run install_pages --user=admin >/dev/null
wp wc product create --name="Test product" --regular_price=100 --user=admin --porcelain
if [ -n "$WULOPAY_KEY_ID" ] && [ -n "$WULOPAY_SECRET" ]; then
  wp option update woocommerce_wulopay_settings "{\"enabled\":\"yes\",\"title\":\"Card (Visa, Mastercard)\",\"description\":\"You will pay securely on WuloPay's payment page.\",\"environment\":\"sandbox\",\"sandbox_key_id\":\"$WULOPAY_KEY_ID\",\"sandbox_secret\":\"$WULOPAY_SECRET\",\"webhook_secret\":\"${WULOPAY_WEBHOOK_SECRET:-}\",\"debug\":\"yes\"}" --format=json
fi
echo "Store ready: http://localhost:8088 (admin / admin)"
