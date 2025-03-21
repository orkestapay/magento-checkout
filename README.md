# Orkestapay-Magento-Checkout

Extensión de pagos con Checkout de Orkestapay para Magento2 (v2.4.0)

## Instalación

Ir a la carpeta raíz del proyecto de Magento y seguir los siguiente pasos:

```bash
composer require orkestapay/magento-checkout
php bin/magento module:enable Orkestapay_Checkout --clear-static-content
php bin/magento setup:upgrade
php bin/magento cache:clean
```

## Actualización

En caso de ya contar con el módulo instalado y sea necesario actualizar, seguir los siguientes pasos:

```bash
composer clear-cache
composer update orkestapay/magento-checkout
bin/magento setup:upgrade
php bin/magento cache:clean
```
