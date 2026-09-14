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

Magento 2.4.9 (Module version 4.3.7 and above)

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
1. You need to change the reference to the version number in all occurrences in these repositories:
    * magento2-module-core
    * magento2-module-reviews
    * magento2-module-combined (kept only because the standard update script installs
  through it — dropping it would block that script until it's rewritten; not needed
  otherwise)
    * magento2-module-messaging — only when combined is also being bumped. Messaging itself
  needs no code changes, but combined pins an exact messaging version, and that pinned
  messaging pins an exact core version. If messaging isn't bumped to match, combined's
  dependency graph becomes unsatisfiable (two different core versions required at once) and
  the release breaks. Skip this repo entirely if core/reviews change without combined.


2. After you've merged it, you'll need to create a new tag with the new version number in all repos:
    * git tag {VERSION}
    * git push origin {VERSION}


3. Check to see that the new version exists in packagist (https://packagist.org/).


4. Update the extension version in Adobe Commerce Cloud (https://commercedeveloper.adobe.com/extensions/), packaged/released there. Login with credentials from the password manager (ask Vladi/Marto if you don't have them). Submit a new version to update version, release notes, description, etc.
    * Technical submission requires zipping the reviews extension and uploading it. Before zipping, change `"name": "yotpo/module-yotpo"` in composer.json (uncommitted — Adobe Commerce Cloud expects that exact package name and errors otherwise):
      ```
      zip -r yotpo_module-yotpo-reviews-4.3.4.zip magento2-module-reviews/ -x 'magento2-module-reviews/.git/*' -x 'magento2-module-reviews/.gitignore'
      ```
      (excluding `.git` and `.gitignore` is required, or the upload errors)
    * Adobe then runs an automated test — monitor progress via Test Reports (Ctrl+F on the submission page).
