# Magento 2 [Yotpo](https://www.yotpo.com/) Extension

---

This library includes the core files of the Yotpo Reviews & SMSBump extension.
The directories hierarchy is as positioned in a standard magento 2 project library

This library will also include different version packages as magento 2 extensions

---

## Docs
- [Linter](./docs/Maintenance.md)

## Requirements
Magento 2.0+ (Up to module verion 2.4.5)

Magento 2.1+ (Module version 2.7.5 up to 2.7.7)

Magento 2.2+ (Module version 2.8.0 and above)

Magento 2.4.8 (Module version 4.3.2 and above)

## ✓ Install via [composer](https://getcomposer.org/download/) (recommended)
Run the following command under your Magento 2 root dir:

```
composer require yotpo/magento2-module-yotpo-core
php bin/magento maintenance:enable
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento maintenance:disable
php bin/magento cache:flush
```

## Install manually under app/code
1. Download & place the contents of [Yotpo's Core Module](https://github.com/YotpoLtd/magento2-module-yotpo-core) under {YOUR-MAGENTO2-ROOT-DIR}/app/code/Yotpo/Core.
2. Download & place the contents of this repository under {YOUR-MAGENTO2-ROOT-DIR}/app/code/Yotpo/Yotpo  
3. Run the following commands under your Magento 2 root dir:
```
php bin/magento maintenance:enable
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento maintenance:disable
php bin/magento cache:flush
```

## Usage

After the installation, Go to The Magento 2 admin panel

Go to Stores -> Settings -> Configuration, change store view (not to be default config) and click on Yotpo Product Reviews Software on the left sidebar

Insert Your account app key and secret


https://www.yotpo.com/

Copyright © 2018 Yotpo. All rights reserved.  

![Yotpo Logo](https://yap.yotpo.com/assets/images/logo_login.png)


## Publish new version
1. You need to change the reference to the version number in all occurrences in all 4 repositories:
    * magento2-module-core
    * magento2-module-reviews
    * magento2-module-messaging
    * magento2-module-combined


2. After you've merged it, you'll need to create a new tag with the new version number in all 4 repos:
    * git tag {VERSION}
    * git push origin {VERSION}


3. Check to see that the new version exists in packagist (https://packagist.org/).