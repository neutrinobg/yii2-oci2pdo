[![Latest Stable Version](https://poser.pugx.org/buonzz/laravel-4-freegeoip/v/stable.svg)](https://packagist.org/packages/bobsbg/yii2-oci2pdo)
[![Total Downloads](https://poser.pugx.org/buonzz/laravel-4-freegeoip/downloads.svg)](https://packagist.org/packages/bobsbg/yii2-oci2pdo)
[![Latest Unstable Version](https://poser.pugx.org/buonzz/laravel-4-freegeoip/v/unstable.svg)](https://packagist.org/packages/bobsbg/yii2-oci2pdo)

# yii2-oci2pdo
Yii2 extension to simulate a PDO connection to Oracle database using PHP OCI8

# Installation

Install With Composer
The preferred way to install this extension is through composer.
Either run

```
php composer.phar require bobsbg/yii2-oci2pdo
```
or add

```
"bobsbg/yii2-oci2pdo": "*"
```
to the require section of your composer.json file.

# Usage / configuration

```php
'db' => [
    'class' => 'bobsbg\oci2pdo\OciDbConnection',
    'dsn' => 'oci:dbname=DBNAME;charset=UTF8',
    'username' => 'scott',
    'password' => 'tiger',
    'charset' => 'utf8',
],
```
