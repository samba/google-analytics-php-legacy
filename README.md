# Google Analytics Legacy for PHP

This library provides a PHP interface for Google Analytics (legacy/classic), with an API modeled (somewhat loosely) after `ga.js`.

# Usage

The full usage is extensively documented in [ga.php](./lib/ga.php), so here I'll provide only a basic example:

```php
<?php

require('lib/ga.php');

$tracker = GoogleAnalyticsTracker::readclient('UA-XXXXX-Y');

$tracker->setUserAgent($_SERVER['HTTP_USER_AGENT']);
$tracker->setCustomVar(1, 'User ID', $_SESSION['user_id'], 2);
$tracker->trackEvent('Subscription', 'Update', 'add service XYZ');

?>
```


# License
google-analytics-php-legacy is licensed under the [BSD license](./LICENSE)
