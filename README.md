# yii2-oci2pdo
Yii2 extension to simulate a PDO connection to Oracle database using PHP OCI8
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
